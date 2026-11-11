<?php

use App\Enums\TeamRole;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\OcrEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Inventory export (CSV, Excel, PDF, PNG, JPG) and import (PDF, Excel, CSV, JPG, PNG).
 */
function invActor(Team $team, string $role = 'staff_inventory'): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function invProduct(Team $team, array $attrs = []): Product
{
    static $n = 0;
    $n++;

    return Product::create($attrs + [
        'team_id' => $team->id, 'sku' => "SKU-{$n}", 'name' => "Product {$n}", 'category' => 'Wiring',
        'unit' => 'pcs', 'price' => 120.50, 'cost_price' => 80, 'stock' => 40, 'reorder_level' => 10, 'is_active' => true,
    ]);
}

function invXlsx(array $rows, string $name = 'stock.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

function invPng(): UploadedFile
{
    $img = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent('stock-photo.png', (string) ob_get_clean());
}

/** Fake Tesseract (and its version check) returning $text. */
function invFakeOcr(string $text): void
{
    Process::fake(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--version')
        ? Process::result('tesseract 5.4.0')
        : Process::result($text));
}

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'inv-team', 'name' => 'Inventory Team']);
    [$this->user, $this->cookies] = invActor($this->team);
});

// --- Export ---------------------------------------------------------------------------------

it('exports the inventory in every format', function (string $format, string $type, string $magic) {
    invProduct($this->team, ['name' => 'Circuit Breaker 60A', 'brand' => 'Schneider']);

    $res = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format={$format}")
        ->assertOk();

    expect($res->headers->get('Content-Type'))->toContain($type)
        ->and($res->headers->get('Content-Disposition'))->toContain(".{$format}")
        ->and($res->getContent() === false ? $res->streamedContent() : $res->getContent())->toStartWith($magic);
})->with([
    'csv' => ['csv', 'text/csv', "\xEF\xBB\xBFSKU,"],
    'excel' => ['xlsx', 'spreadsheetml', "PK\x03\x04"],
    'pdf' => ['pdf', 'application/pdf', '%PDF-'],
    'png' => ['png', 'image/png', "\x89PNG"],
    'jpg' => ['jpg', 'image/jpeg', "\xFF\xD8\xFF"],
]);

it('keeps the old CSV export address working', function () {
    invProduct($this->team, ['name' => 'Circuit Breaker 60A']);

    $csv = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export-csv")->assertOk()->streamedContent();

    expect($csv)->toContain('Circuit Breaker 60A');
});

it('exports only the products matching the current filters', function () {
    invProduct($this->team, ['name' => 'Plenty Wire', 'stock' => 500]);
    invProduct($this->team, ['name' => 'Almost Gone Tape', 'stock' => 3]);
    invProduct($this->team, ['name' => 'Other Team Item', 'team_id' => Team::factory()->create()->id]);

    $csv = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=csv&status=Low+Stock")->assertOk()->streamedContent();

    expect($csv)->toContain('Almost Gone Tape')->not->toContain('Plenty Wire')->not->toContain('Other Team Item');
});

it('stores text as text in Excel so formulas never run', function () {
    invProduct($this->team, ['name' => '=HYPERLINK("http://evil.test","x")']);

    $content = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=xlsx")->assertOk()->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'inv');
    file_put_contents($path, $content);
    $cell = IOFactory::load($path)->getActiveSheet()->getCell('B2');

    expect($cell->getDataType())->toBe('s')->and($cell->getValue())->toStartWith('=HYPERLINK');
});

it('splits large image exports into a zip of images', function () {
    foreach (range(1, 51) as $i) {
        invProduct($this->team);
    }

    $res = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=png")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'inv');
    file_put_contents($path, $res->getContent());
    $zip = new ZipArchive;
    $zip->open($path);

    expect($res->headers->get('Content-Type'))->toBe('application/zip')
        ->and($zip->numFiles)->toBe(2);
});

it('rejects unknown export formats', function () {
    $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=exe")
        ->assertSessionHasErrors('format');
});

// --- Import ---------------------------------------------------------------------------------
// Imports go through the reviewed document import (/imports/inventory): the file is read into
// preview rows, staff review them, and only new products are created on confirm. Stock and
// prices of existing products are never changed by an import.

function invImport($t, UploadedFile $file): ImportBatch
{
    $id = $t->actingAs($t->user)->withCookies($t->cookies)
        ->post("/{$t->team->slug}/imports/inventory", ['file' => $file])->assertCreated()->json('id');

    return ImportBatch::findOrFail($id);
}

/** @return list<list<mixed>> */
function invRowValues(ImportBatch $batch, array $fields): array
{
    return $batch->rows()->orderBy('row_number')->get()->map(fn ($r) => array_map(fn ($f) => $r->values[$f] ?? null, $fields))->all();
}

it('the old one-step inventory import address is gone', function () {
    $this->actingAs($this->user)->withCookies($this->cookies)
        ->post("/{$this->team->slug}/inventory/import-csv", ['file' => invXlsx([['SKU', 'Name'], ['A', 'B']])])
        ->assertNotFound();
});

it('reads an Excel file that it exported, showing existing products without changing them', function () {
    $existing = invProduct($this->team, ['sku' => 'BRK-60', 'name' => 'Circuit Breaker 60A', 'brand' => 'Schneider', 'stock' => 12, 'price' => 450]);

    $content = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=xlsx")->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    file_put_contents($path, $content);

    $batch = invImport($this, new UploadedFile($path, 'export.xlsx', null, null, true));
    $row = $batch->rows()->sole();

    expect($batch->status)->toBe(ImportBatch::READY)
        ->and($row->status)->toBe(ImportRow::EXISTING)
        ->and($row->matched_product_id)->toBe($existing->id)
        ->and([$row->values['sku'], $row->values['name'], $row->values['brand'], $row->values['stock'], $row->values['price']])
        ->toEqual(['BRK-60', 'Circuit Breaker 60A', 'Schneider', 12.0, 450.0]);
});

it('finds the header row below a title and creates only new products, with no stock', function () {
    $file = invXlsx([
        ['Mr. Pride stock list - October'],
        [],
        ['SKU', 'Product Name', 'Category', 'Unit', 'Selling Price', 'Stock'],
        ['WR-35', 'THHN Wire 3.5mm', 'Wiring', 'roll', 68, 25],
        ['', 'Missing SKU', 'Wiring', 'pcs', 10, 1],
    ]);

    $batch = invImport($this, $file);
    expect(invRowValues($batch, ['sku', 'name']))->toEqual([['WR-35', 'THHN Wire 3.5mm'], [null, 'Missing SKU']]);

    $this->actingAs($this->user)->withCookies($this->cookies)
        ->post("/{$this->team->slug}/imports/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 2]);

    expect(Product::where('team_id', $this->team->id)->where('sku', 'WR-35')->first())
        ->name->toBe('THHN Wire 3.5mm')->stock->toBe(0);
    expect(Product::where('name', 'Missing SKU')->value('sku'))->toBe('WIR-000001'); // generated from its category
});

it('reads a photo of a stock list with OCR and reports misread digits', function () {
    invFakeOcr(ocrTsv([
        'INVENTORY COUNT - Branch 2',
        'SKU      Product Name          Stock    Selling Price',
        'BRK-60   Circuit Breaker 60A      1O           450.00',
        'LED-9    LED Bulb 9W              l5               85',
        '                                         Page 1 of 1',
    ]));

    $batch = invImport($this, invPng());

    expect($batch->status)->toBe(ImportBatch::READY)
        ->and($batch->extraction_method)->toBe('ocr')
        ->and(invRowValues($batch, ['sku', 'name', 'stock', 'price']))
        ->toEqual([['BRK-60', 'Circuit Breaker 60A', 10.0, 450.0], ['LED-9', 'LED Bulb 9W', 15.0, 85.0]])
        ->and($batch->rows()->get()->flatMap->issues->pluck('message')->implode(' '))->toContain('read as "1O"');

    // OCR values need an explicit check before import.
    $this->actingAs($this->user)->withCookies($this->cookies)
        ->post("/{$this->team->slug}/imports/batches/{$batch->id}/confirm")->assertStatus(422);
});

it('turns a photo without readable column headers into lines for staff to fix', function () {
    invFakeOcr(ocrTsv(['just some blurry words', 'nothing useful']));

    $batch = invImport($this, invPng());

    expect($batch->rows()->get()->flatMap->issues->pluck('message')->implode(' '))->toContain('No column headers were recognised');
});

it('rejects files that are not what their name says, before storing anything', function (string $name, string $content) {
    $this->actingAs($this->user)->withCookies($this->cookies)->withCredentials()
        ->postJson("/{$this->team->slug}/imports/inventory", ['file' => UploadedFile::fake()->createWithContent($name, $content)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(ImportBatch::count())->toBe(0);
})->with([
    'fake excel' => ['stock.xlsx', 'this is not a spreadsheet'],
    'fake pdf' => ['stock.pdf', 'not a pdf'],
]);

// --- Round trips with the real tools (skipped when they are not installed) ------------------

it('reads a PDF it exported, keeping every column apart', function () {
    invProduct($this->team, ['sku' => 'BRK-60', 'name' => 'Molded Case Circuit Breaker 100A 3P', 'brand' => 'Schneider', 'stock' => 1200, 'price' => 1450, 'cost_price' => 980]);
    invProduct($this->team, ['sku' => 'TP-7', 'name' => 'Tape', 'stock' => 0, 'price' => 35]);

    $pdf = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=pdf")->assertOk()->getContent();
    $batch = invImport($this, UploadedFile::fake()->createWithContent('inventory.pdf', $pdf));

    expect($batch->extraction_method)->toBe('pdf-text')
        ->and(invRowValues($batch, ['sku', 'name', 'brand', 'stock', 'price', 'cost_price']))->toEqual([
            ['TP-7', 'Tape', null, 0.0, 35.0, 80.0],
            ['BRK-60', 'Molded Case Circuit Breaker 100A 3P', 'Schneider', 1200.0, 1450.0, 980.0],
        ]);
})->skip(fn () => ! app(OcrEngine::class)->pdfTextAvailable(), 'Poppler (pdftotext) is not installed');

it('reads a PNG it exported with real OCR', function () {
    invProduct($this->team, ['sku' => 'BRK-60', 'name' => 'Circuit Breaker 60A', 'stock' => 25, 'price' => 450]);

    $png = $this->actingAs($this->user)->withCookies($this->cookies)
        ->get("/{$this->team->slug}/inventory/export?format=png")->assertOk()->getContent();
    $batch = invImport($this, UploadedFile::fake()->createWithContent('inventory.png', $png));
    $row = $batch->rows()->orderBy('row_number')->first();

    expect([$row->values['name'], $row->values['stock'], $row->values['price']])->toEqual(['Circuit Breaker 60A', 25.0, 450.0]);
})->skip(fn () => ! app(OcrEngine::class)->tesseractAvailable(), 'Tesseract is not installed');
