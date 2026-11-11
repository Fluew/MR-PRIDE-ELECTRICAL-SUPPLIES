<?php

use App\Enums\TeamRole;
use App\Jobs\ProcessImportBatch;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\ColumnSchema;
use App\Services\Import\DocumentExtractor;
use App\Services\Import\ImportService;
use App\Services\Import\ValueParser;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\OcrEngine;
use App\Services\MaterialList\WordTable;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'imp-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->url = "/{$this->team->slug}/imports";
});

function impAs($t, ?User $u = null, ?array $cookies = null)
{
    return $t->actingAs($u ?? $t->admin)->withCookies($cookies ?? $t->cookies)->withCredentials();
}

function impCsv(string $csv, string $name = 'list.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

function impXlsx(array $rows, string $name = 'quote.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('inventory import keeps missing values empty, generates SKUs, and never touches existing products or stock', function () {
    Product::create(['team_id' => $this->team->id, 'sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 20, 'cost_price' => 10, 'stock' => 7, 'is_active' => true, 'unit' => 'pcs']);
    $csv = "MR PRIDE PRICE LIST\nItem Code,Description,Category,UOM,Cost,Selling Price,Qty\n"
        ."WR-35,THHN Wire 3.5mm,Wires,roll,900,1250,12\n"
        .",LED Bulb 9W,,,,,\n"
        ."CB-40,Circuit Breaker 40A,,pcs,11,25,\n"
        ."Total,,,,,,12\n";

    $res = impAs($this)->post("{$this->url}/inventory", ['file' => impCsv($csv)])->assertCreated()->json();
    expect($res['status'])->toBe(ImportBatch::READY)->and($res['extraction_method'])->toBe('csv');
    $batch = ImportBatch::sole();
    Storage::disk('local')->assertExists($batch->stored_path);

    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy(fn ($r) => $r->values['name'] ?? $r->raw);
    expect($rows['THHN Wire 3.5mm']->status)->toBe(ImportRow::READY)
        // The stock column is noted once for the file, not on every row.
        ->and($rows['THHN Wire 3.5mm']->issues)->toBe([])
        ->and($batch->summary['stock_in_file'])->toBe(2)
        ->and($batch->summary['inactive'])->toBe(1) // LED Bulb 9W: no unit or price
        ->and($rows['LED Bulb 9W']->status)->toBe(ImportRow::READY)
        ->and($rows['LED Bulb 9W']->values['price'])->toBeNull()
        ->and($rows['Circuit Breaker 40A']->status)->toBe(ImportRow::EXISTING)
        ->and($batch->summary['skipped'])->toBe(1)   // the Total line, kept but skipped
        ->and($batch->summary['unparsed_lines'])->toBe(['MR PRIDE PRICE LIST']);

    impAs($this)->post("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 2]);

    $wire = Product::where('sku', 'WR-35')->sole();
    expect((float) $wire->price)->toBe(1250.0)->and($wire->stock)->toBe(0)->and($wire->is_active)->toBeTrue();
    $bulb = Product::where('name', 'LED Bulb 9W')->sole();
    expect($bulb->sku)->toBe('GEN-000001') // no SKU and no category in the file
        ->and($bulb->price)->toBeNull()->and($bulb->unit)->toBeNull()->and($bulb->cost_price)->toBeNull()
        ->and($bulb->is_active)->toBeFalse()
        ->and($bulb->missing_fields)->toEqual(['unit', 'cost_price', 'price', 'category']);
    $existing = Product::where('sku', 'CB-40')->sole();
    expect((float) $existing->price)->toBe(20.0)->and($existing->stock)->toBe(7);
    expect(ImportBatch::sole()->status)->toBe(ImportBatch::COMPLETED)
        ->and(AuditLog::where('event', 'import.completed')->exists())->toBeTrue();

    // A completed batch cannot be imported again.
    impAs($this)->post("{$this->url}/batches/{$batch->id}/confirm")->assertStatus(422);
    expect(Product::count())->toBe(3);
});

test('supplier quotation import calculates, flags and matches before saving unverified quotes', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WR-35', 'name' => 'THHN Wire 3.5mm', 'price' => 1300, 'cost_price' => 950, 'stock' => 0, 'is_active' => true, 'unit' => 'roll']);
    $bulb = Product::create(['team_id' => $this->team->id, 'sku' => 'LED-9W', 'name' => 'LED Bulb 9W Daylight', 'price' => 120, 'cost_price' => 80, 'stock' => 0, 'is_active' => true, 'unit' => 'pcs']);
    $breaker = Product::create(['team_id' => $this->team->id, 'sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 400, 'cost_price' => 300, 'stock' => 0, 'is_active' => true, 'unit' => 'pcs']);

    $file = impXlsx([
        ['VOLT SUPPLY — QUOTATION Q-7781'],
        ['Code', 'Particulars', 'UOM', 'Qty', 'Price', 'Total'],
        ['WR-35', 'THHN Wire 3.5mm 150m', 'roll', 10, 1200, null],          // amount calculated
        ['LED-9W', 'LED bulb 9 watts', 'pcs', 100, null, '8,500.00'],         // unit price implied: 85
        ['CB-40', 'Breaker 40A 2P', 'pcs', 5, 350, 2000],                     // inconsistent: 5 × 350 = 1750
        ['', 'Mystery item', 'set', 1, 99, 99],                               // no product match
        ['', 'VAT 12%', '', '', '', 1200],
    ]);

    $res = impAs($this)->post("{$this->url}/supplier_quotation", ['file' => $file, 'supplier_id' => $supplier->id, 'quoted_on' => now()->toDateString(), 'currency' => 'PHP', 'reference' => 'Q-7781'])
        ->assertCreated()->json();
    $batch = ImportBatch::findOrFail($res['id']);
    $rows = $batch->rows()->get()->keyBy(fn ($r) => $r->values['description'] ?? $r->raw);

    $w = $rows['THHN Wire 3.5mm 150m'];
    expect($w->values['amount'])->toEqual(12000.0)->and($w->sources['amount'])->toBe('calculated')->and($w->matched_product_id)->toBe($wire->id)->and($w->status)->toBe(ImportRow::READY);
    $b = $rows['LED bulb 9 watts'];
    expect($b->values['unit_price'])->toEqual(85.0)->and($b->sources['unit_price'])->toBe('calculated');
    expect($rows['Breaker 40A 2P']->status)->toBe(ImportRow::NEEDS_REVIEW)
        ->and(collect($rows['Breaker 40A 2P']->issues)->pluck('message')->implode(' '))->toContain('1750');
    expect($rows['Mystery item']->status)->toBe(ImportRow::NEEDS_REVIEW);
    expect($rows['VAT 12%']->status)->toBe(ImportRow::SKIPPED);

    // Not confirmable while rows need review.
    impAs($this)->post("{$this->url}/batches/{$batch->id}/confirm")->assertStatus(422);

    // Staff correct the misread amount and skip the unknown item.
    impAs($this)->patchJson("{$this->url}/batches/{$batch->id}/rows/{$rows['Breaker 40A 2P']->id}", ['values' => ['amount' => '1750']])->assertOk();
    impAs($this)->patchJson("{$this->url}/batches/{$batch->id}/rows/{$rows['Mystery item']->id}", ['skipped' => true])->assertOk();

    impAs($this)->post("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 3]);

    $quotes = SupplierQuote::orderBy('id')->get();
    expect($quotes)->toHaveCount(3)
        ->and($quotes->every(fn ($q) => $q->verified_at === null && $q->supplier_id === $supplier->id && $q->reference === 'Q-7781'))->toBeTrue()
        ->and($quotes->firstWhere('product_id', $bulb->id)->unit_price_source)->toBe('calculated')
        ->and($quotes->firstWhere('product_id', $wire->id)->amount_source)->toBe('calculated')
        ->and($quotes->firstWhere('product_id', $breaker->id)->amount_source)->toBe('supplier');
    // Inventory prices are untouched.
    expect((float) $wire->fresh()->price)->toBe(1300.0)->and((float) $wire->fresh()->cost_price)->toBe(950.0);
    expect($batch->fresh()->rows()->where('row_number', $rows['Breaker 40A 2P']->row_number)->value('sources'))->toContain('edited');
});

test('OCR documents need staff to confirm they checked the values, and look-alike digits are reported', function () {
    $this->mock(DocumentExtractor::class, fn ($m) => $m->shouldReceive('extract')->andReturn([
        'type' => 'image', 'method' => 'ocr', 'pages' => 1,
        'rows' => [['SKU', 'Product Name', 'Unit', 'Price'], ['BX-1', 'Junction Box', 'pcs', 'l5O']],
    ]));
    $png = UploadedFile::fake()->image('scan.png', 50, 50);
    $id = impAs($this)->post("{$this->url}/inventory", ['file' => $png])->assertCreated()->json('id');
    $batch = ImportBatch::findOrFail($id);
    // Images go to the queue; the test queue runs it at once.
    expect($batch->status)->toBe(ImportBatch::READY)->and($batch->usedOcr())->toBeTrue();
    $row = $batch->rows()->sole();
    expect($row->values['price'])->toEqual(150.0)->and(collect($row->issues)->pluck('message')->implode(' '))->toContain('read as "l5O"');

    impAs($this)->post("{$this->url}/batches/{$id}/confirm")->assertStatus(422)->assertJsonPath('errors.ocr_checked.0', fn ($m) => str_contains($m, 'OCR'));
    impAs($this)->post("{$this->url}/batches/{$id}/confirm", ['ocr_checked' => true])->assertOk();
    expect(Product::where('sku', 'BX-1')->exists())->toBeTrue();
});

test('a document with no recognisable columns becomes editable lines, never imported silently', function () {
    $id = impAs($this)->post("{$this->url}/inventory", ['file' => impCsv("Junction box 4x4 - 3 pcs\nPVC pipe 1/2 - 10 lengths\n")])->assertCreated()->json('id');
    $rows = ImportBatch::findOrFail($id)->rows()->get();
    expect($rows)->toHaveCount(2)
        ->and(collect($rows[0]->issues)->pluck('message')->implode(' '))->toContain('No column headers were recognised');
});

test('a failed extraction leaves no rows and no records, and the batch says why', function () {
    $this->mock(DocumentExtractor::class, fn ($m) => $m->shouldReceive('extract')->andThrow(new ExtractionException('This PDF is password-protected. Please remove the password and upload it again.', 'protected')));
    $pdf = UploadedFile::fake()->createWithContent('locked.pdf', "%PDF-1.4\n%fake");
    $id = impAs($this)->post("{$this->url}/inventory", ['file' => $pdf])->assertCreated()->json('id');

    $batch = ImportBatch::findOrFail($id);
    expect($batch->status)->toBe(ImportBatch::FAILED)->and($batch->error)->toContain('password-protected')
        ->and($batch->rows()->count())->toBe(0)->and(Product::count())->toBe(0);
    impAs($this)->post("{$this->url}/batches/{$id}/confirm")->assertStatus(422);
});

test('a retried extraction replaces its rows instead of duplicating them, and the final failure is recorded', function () {
    $calls = 0;
    $this->mock(DocumentExtractor::class, function ($m) use (&$calls) {
        $m->shouldReceive('extract')->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Tesseract crashed');
            }

            return ['type' => 'pdf', 'method' => 'pdf-text', 'pages' => 2, 'rows' => [['SKU', 'Name'], ['A-1', 'Alpha'], ['SKU', 'Name'], ['B-2', 'Beta']]];
        });
    });
    $batch = ImportBatch::create(['team_id' => $this->team->id, 'module' => 'inventory', 'status' => 'queued', 'original_filename' => 'two-pages.pdf',
        'stored_path' => 'imports/x.pdf', 'file_type' => 'pdf', 'file_size' => 10, 'file_hash' => 'x', 'uploaded_by_id' => $this->admin->id]);
    Storage::disk('local')->put('imports/x.pdf', '%PDF-1.4');

    $job = new ProcessImportBatch($batch->id);
    expect(fn () => $job->handle(app(ImportService::class)))->toThrow(RuntimeException::class);
    expect($batch->fresh()->status)->toBe(ImportBatch::PROCESSING)->and($batch->rows()->count())->toBe(0);

    // Retry: both pages' rows (header repeated on page 2) in one preview.
    $job->handle(app(ImportService::class));
    $job->handle(app(ImportService::class)); // a duplicate delivery of the job does nothing
    expect($batch->fresh()->status)->toBe(ImportBatch::READY)->and($batch->rows()->pluck('values')->pluck('name')->all())->toBe(['Alpha', 'Beta'])
        ->and($batch->fresh()->attempts)->toBe(2);

    $other = ImportBatch::create(['team_id' => $this->team->id, 'module' => 'inventory', 'status' => 'processing', 'original_filename' => 'b.pdf',
        'stored_path' => 'imports/x.pdf', 'file_type' => 'pdf', 'file_size' => 10, 'file_hash' => 'x']);
    (new ProcessImportBatch($other->id))->failed(new RuntimeException('timeout'));
    expect($other->fresh()->status)->toBe(ImportBatch::FAILED)->and($other->fresh()->error)->toContain('after 3 attempts');
});

test('each import needs its module: inventory staff cannot import supplier quotations', function () {
    $staff = User::factory()->create(['role' => 'staff_inventory', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $cookies = createTrustedDeviceFor($staff)['cookies'];
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'S', 'status' => 'Active']);

    impAs($this, $staff, $cookies)->post("{$this->url}/inventory", ['file' => impCsv("SKU,Name\nA,Alpha\n")])->assertCreated();
    impAs($this, $staff, $cookies)->post("{$this->url}/supplier_quotation", ['file' => impCsv("Item,Price\nA,1\n"), 'supplier_id' => $supplier->id, 'quoted_on' => now()->toDateString()])->assertForbidden();
});

test('a real multi-page digital PDF is combined into one preview', function () {
    if (! app(OcrEngine::class)->pdfTextAvailable()) {
        $this->markTestSkipped('Poppler pdftotext is not installed.');
    }
    $page = fn ($rows) => '<table><tr><th>SKU</th><th>Product Name</th><th>Unit</th><th>Selling Price</th></tr>'.$rows.'</table>';
    $html = '<html><head><style>td,th{padding:4px 28px;text-align:left}</style></head><body style="font-family: DejaVu Sans">'.$page('<tr><td>PG-1</td><td>Page One Item</td><td>pcs</td><td>10.00</td></tr>')
        .'<div style="page-break-after: always"></div>'.$page('<tr><td>PG-2</td><td>Page Two Item</td><td>box</td><td>20.00</td></tr>').'</body></html>';
    $path = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
    $dompdf = new Dompdf;
    $dompdf->loadHtml($html);
    $dompdf->render();
    file_put_contents($path, $dompdf->output());

    $id = impAs($this)->post("{$this->url}/inventory", ['file' => new UploadedFile($path, 'two-pages.pdf', 'application/pdf', null, true)])->assertCreated()->json('id');
    $batch = ImportBatch::findOrFail($id);
    expect($batch->status)->toBe(ImportBatch::READY)->and($batch->extraction_method)->toBe('pdf-text')
        ->and($batch->rows()->get()->pluck('values')->pluck('sku')->all())->toBe(['PG-1', 'PG-2']);
});

test('an OCR supplier quotation with missing fields is held until staff complete them', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WR-35', 'name' => 'THHN Wire 3.5mm', 'price' => 1300, 'cost_price' => 950, 'stock' => 0, 'is_active' => true, 'unit' => 'roll']);
    $this->mock(DocumentExtractor::class, fn ($m) => $m->shouldReceive('extract')->andReturn([
        'type' => 'pdf', 'method' => 'pdf-ocr', 'pages' => 2,
        'rows' => [['Item', 'Particulars', 'UOM', 'Qty', 'Price', 'Total'], ['WR-35', 'THHN Wire 3.5mm', '', '1O', '', '']],
    ]));

    $id = impAs($this)->post("{$this->url}/supplier_quotation", ['file' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%scan"), 'supplier_id' => $supplier->id, 'quoted_on' => now()->toDateString(), 'currency' => 'PHP'])
        ->assertCreated()->json('id');
    $batch = ImportBatch::findOrFail($id);
    $row = $batch->rows()->sole();
    $messages = collect($row->issues)->pluck('message')->implode(' ');

    expect($batch->page_count)->toBe(2)
        ->and($row->status)->toBe(ImportRow::NEEDS_REVIEW)
        ->and($row->matched_product_id)->toBe($wire->id)
        ->and($row->values['quantity'])->toEqual(10.0)
        ->and($row->values['unit_price'])->toBeNull()
        ->and($messages)->toContain('No unit price')->toContain('Unit is missing')->toContain('read by OCR as "1O"');
    impAs($this)->post("{$this->url}/batches/{$id}/confirm", ['ocr_checked' => true])->assertStatus(422);
    expect(SupplierQuote::count())->toBe(0);

    impAs($this)->patchJson("{$this->url}/batches/{$id}/rows/{$row->id}", ['values' => ['unit' => 'roll', 'unit_price' => '1180']])->assertOk()->assertJsonPath('status', ImportRow::READY);
    impAs($this)->post("{$this->url}/batches/{$id}/confirm")->assertStatus(422); // OCR check still required
    impAs($this)->post("{$this->url}/batches/{$id}/confirm", ['ocr_checked' => true])->assertOk()->assertJson(['created' => 1]);

    $quote = SupplierQuote::sole();
    expect((float) $quote->unit_price)->toBe(1180.0)->and((float) $quote->amount)->toBe(11800.0)->and($quote->amount_source)->toBe('calculated')
        ->and($quote->unit_price_source)->toBe('supplier')->and($quote->verified_at)->toBeNull()
        ->and((float) $wire->fresh()->cost_price)->toBe(950.0);
});

test('splits combined quantity and unit cells and warns when the exact file was already imported', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Apex Electric', 'status' => 'Active']);
    $wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WR-35', 'name' => 'THHN Wire 3.5mm', 'price' => 1300, 'cost_price' => 950, 'stock' => 0, 'is_active' => true, 'unit' => 'roll']);

    $csvContent = "Item Code,Description,Qty,Unit Price,Amount\nWR-35,THHN Wire 3.5mm,10 rolls,₱1200.00/roll,12000.00\n";
    $id1 = impAs($this)->post("{$this->url}/supplier_quotation", [
        'file' => impCsv($csvContent, 'apex-quote.csv'),
        'supplier_id' => $supplier->id,
        'quoted_on' => now()->toDateString(),
        'currency' => 'PHP',
    ])->assertCreated()->json('id');

    $row1 = ImportBatch::findOrFail($id1)->rows()->sole();
    expect($row1->values['quantity'])->toEqual(10.0)
        ->and($row1->values['unit'])->toBe('roll')
        ->and($row1->values['unit_price'])->toEqual(1200.0)
        ->and($row1->status)->toBe(ImportRow::READY);

    impAs($this)->post("{$this->url}/batches/{$id1}/confirm")->assertOk();

    // Re-uploading the exact same file flags duplicate_batch in summary
    $id2 = impAs($this)->post("{$this->url}/supplier_quotation", [
        'file' => impCsv($csvContent, 'apex-quote-copy.csv'),
        'supplier_id' => $supplier->id,
        'quoted_on' => now()->toDateString(),
        'currency' => 'PHP',
    ])->assertCreated()->json('id');

    $batch2 = ImportBatch::findOrFail($id2);
    expect($batch2->summary['duplicate_batch']['id'] ?? null)->toBe($id1)
        ->and($batch2->summary['duplicate_batch']['filename'] ?? null)->toBe('apex-quote.csv');
});

test('WordTable merges horizontally split Tesseract blocks on the same row and wraps first-column descriptions', function () {
    $words = [
        // Header row
        ['p' => 1, 'b' => 1, 'l' => 1, 'x' => 20, 'y' => 40, 'w' => 110, 'h' => 18, 'c' => 95, 't' => 'Description'],
        ['p' => 1, 'b' => 1, 'l' => 1, 'x' => 260, 'y' => 40, 'w' => 40, 'h' => 18, 'c' => 95, 't' => 'Unit'],
        ['p' => 1, 'b' => 1, 'l' => 1, 'x' => 360, 'y' => 40, 'w' => 35, 'h' => 18, 'c' => 95, 't' => 'Qty'],
        ['p' => 1, 'b' => 1, 'l' => 1, 'x' => 450, 'y' => 40, 'w' => 80, 'h' => 18, 'c' => 95, 't' => 'Price'],
        // Row 1 split across block 2 (left) and block 3 (right) on the same vertical y=80
        ['p' => 1, 'b' => 2, 'l' => 1, 'x' => 20, 'y' => 80, 'w' => 140, 'h' => 18, 'c' => 92, 't' => 'Circuit Breaker'],
        ['p' => 1, 'b' => 3, 'l' => 1, 'x' => 260, 'y' => 81, 'w' => 30, 'h' => 18, 'c' => 92, 't' => 'pcs'],
        ['p' => 1, 'b' => 3, 'l' => 1, 'x' => 360, 'y' => 80, 'w' => 25, 'h' => 18, 'c' => 90, 't' => '1S'],
        ['p' => 1, 'b' => 3, 'l' => 1, 'x' => 450, 'y' => 80, 'w' => 60, 'h' => 18, 'c' => 92, 't' => '850.00'],
        // Wrapped continuation of column 0 (Description) at y=99 (gap 1px < 0.8*18)
        ['p' => 1, 'b' => 2, 'l' => 2, 'x' => 20, 'y' => 99, 'w' => 95, 'h' => 18, 'c' => 91, 't' => 'Bolt-on 60A'],
    ];

    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
    foreach ($words as $i => $w) {
        $tsv .= "5\t{$w['p']}\t{$w['b']}\t1\t{$w['l']}\t".($i + 1)."\t{$w['x']}\t{$w['y']}\t{$w['w']}\t{$w['h']}\t{$w['c']}\t{$w['t']}\n";
    }

    $table = WordTable::build(
        [$tsv],
        fn (array $cells) => ColumnSchema::headerMap(ImportBatch::SUPPLIER_QUOTATION, $cells) !== null,
        true
    );

    expect($table['aligned'])->toBeTrue()
        ->and($table['rows'])->toHaveCount(2)
        ->and($table['rows'][1])->toBe(['Circuit Breaker Bolt-on 60A', 'pcs', '1S', '850.00']);

    $parsedNum = ValueParser::number('1S', true);
    expect($parsedNum['value'])->toEqual(15.0)->and($parsedNum['corrected_from'])->toBe('1S');

    $parsedDate = ValueParser::date('2026-10-25');
    expect($parsedDate['value'])->toBe('2026-10-25')->and($parsedDate['problem'])->toBeNull();
});

test('never treats column headers or field labels as record values, keeps missing fields NULL, and computes confidence', function () {
    $csv = "Supplier Name:\nSKU,Product Name,Category,Unit,Cost Price,Selling Price\n"
        ."Product Name,Description,,,,\n"
        ."EL-100,Industrial Contactor 3P,Controls,pcs,650,950\n"
        .",Unpriced Panel Box,,,,\n";

    $id = impAs($this)->post("{$this->url}/inventory", ['file' => impCsv($csv)])->assertCreated()->json('id');
    $batch = ImportBatch::findOrFail($id);
    $rows = $batch->rows()->orderBy('row_number')->get();

    // Only the 2 actual product rows are extracted; "Supplier Name:" and repeated header "Product Name,Description" are excluded from records
    expect($rows)->toHaveCount(2);

    $completeRow = $rows[0];
    expect($completeRow->values['sku'])->toBe('EL-100')
        ->and($completeRow->values['name'])->toBe('Industrial Contactor 3P')
        ->and($completeRow->sources['name'])->toBe('document')
        ->and($completeRow->sources['price'])->toBe('document')
        ->and($completeRow->sources['_confidence'])->toBe('high');

    $incompleteRow = $rows[1];
    expect($incompleteRow->values['name'])->toBe('Unpriced Panel Box')
        ->and($incompleteRow->values['sku'])->toBeNull()
        ->and($incompleteRow->values['category'])->toBeNull()
        ->and($incompleteRow->values['unit'])->toBeNull()
        ->and($incompleteRow->values['cost_price'])->toBeNull()
        ->and($incompleteRow->values['price'])->toBeNull()
        ->and($incompleteRow->sources['sku'])->toBeNull()
        ->and($incompleteRow->sources['price'])->toBeNull()
        ->and($incompleteRow->sources['_confidence'])->toBe('medium');

    // Also verify a file containing ONLY column headers/field labels produces 0 record rows
    $headerOnlyId = impAs($this)->post("{$this->url}/inventory", ['file' => impCsv("SKU,Unit,Selling Price\nProduct Name:\n")])->assertCreated()->json('id');
    expect(ImportBatch::findOrFail($headerOnlyId)->rows()->count())->toBe(0);
});
