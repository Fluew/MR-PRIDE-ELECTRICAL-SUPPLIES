<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\HeaderMapper;
use App\Services\MaterialList\MaterialParser;
use App\Services\MaterialList\ProductMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reading material lists from PDF, Excel, CSV and image files (used by the staff "Import material
 * list" action on the quotations page): file checks, OCR, column detection, parsing and matching.
 */
beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->breaker = Product::create([
        'team_id' => $this->team->id, 'sku' => 'BRK-60A-SCH', 'name' => 'Circuit Breaker 60A', 'brand' => 'Schneider',
        'category' => 'Breakers', 'unit' => 'pcs', 'price' => 850.00, 'stock' => 20, 'is_active' => true,
    ]);
    $this->wire = Product::create([
        'team_id' => $this->team->id, 'sku' => 'THHN-35', 'name' => 'THHN Wire 3.5mm', 'brand' => 'Phelps Dodge',
        'category' => 'Wires', 'unit' => 'roll', 'price' => 2400.00, 'stock' => 10, 'is_active' => true,
    ]);
});

/**
 * Read a file the way the staff import does: check its content, read it, parse the lines and
 * match them to catalog products.
 *
 * @param  array<string, int>|null  $mapping  columns chosen by hand when the headers are not recognised
 */
function parseMaterialFile(UploadedFile $file, ?array $mapping = null, ?int $headerIndex = null): array
{
    $reader = app(FileReader::class);
    $path = $file->getRealPath() ?: $file->getPathname();
    $read = $reader->read($path, $reader->detectType($path, $file->getClientOriginalName()));

    $parsed = isset($read['rows'])
        ? MaterialParser::parseTable($read['rows'], $mapping, $headerIndex, $read['method'])
        : MaterialParser::parseText(MaterialParser::cleanLines($read['pages']), $read['method']);
    $parsed['items'] = (new ProductMatcher(test()->team->id))->matchAll($parsed['items']);

    return ['method' => $read['method']] + $parsed;
}

/** The reason a file could not be read. */
function parseFailure(UploadedFile $file): ?string
{
    try {
        parseMaterialFile($file);
    } catch (ExtractionException $e) {
        return $e->reason;
    }

    return null;
}

function csvFile(string $content, string $name = 'materials.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function xlsxFile(array $rows, string $name = 'materials.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $book->getActiveSheet()->setCellValue('C5', '=2*5'); // a formula: we read its saved value, never run it
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

/** Smallest valid PDF with one text line per entry (Helvetica), so the parser has a real text layer. */
function pdfFile(array $lines, string $name = 'materials.pdf'): UploadedFile
{
    $y = 780;
    $stream = "BT /F1 11 Tf\n";
    foreach ($lines as $line) {
        $stream .= sprintf("1 0 0 1 50 %d Tm (%s) Tj\n", $y, str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line));
        $y -= 18;
    }
    $stream .= 'ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

/** A real (tiny) PNG, so content checks pass; OCR itself is faked. */
function pngFile(): UploadedFile
{
    $img = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();

    return UploadedFile::fake()->createWithContent('photo.png', $png);
}

function fakeTesseract(?string $text): void
{
    Process::fake(function (PendingProcess $process) use ($text) {
        $cmd = implode(' ', (array) $process->command);
        if ($text === null) {
            return Process::result('', 'not found', 1);
        }

        return str_contains($cmd, '--version') ? Process::result('tesseract 5.3.0') : Process::result($text);
    });
}

// --- Header recognition ---------------------------------------------------------------------

it('recognises header variations regardless of case and punctuation', function () {
    expect(HeaderMapper::fieldFor(' QTY. '))->toBe('quantity')
        ->and(HeaderMapper::fieldFor('Amount Required'))->toBe('quantity')
        ->and(HeaderMapper::fieldFor('Item Description'))->toBe('description')
        ->and(HeaderMapper::fieldFor('U.O.M'))->toBe('unit')
        ->and(HeaderMapper::fieldFor('Unit of Measure'))->toBe('unit')
        ->and(HeaderMapper::fieldFor('Preferred Brand'))->toBe('brand')
        ->and(HeaderMapper::fieldFor('Technical Details'))->toBe('specs')
        ->and(HeaderMapper::fieldFor('Unit Price'))->toBe('unit_price')
        ->and(HeaderMapper::fieldFor('Qty (pcs)'))->toBe('quantity')
        ->and(HeaderMapper::fieldFor('Supplier Phone'))->toBeNull();
});

// --- CSV ------------------------------------------------------------------------------------

it('reads a CSV with its own column order, semicolons and extra columns', function () {
    $csv = "Project: Warehouse fit-out\n\nNo.;Preferred Brand;Item Description;QTY.;U.O.M;Supplier Phone;Remarks\n"
        ."1;Schneider;Circuit breaker BRK-60A-SCH;10;pcs;0917;urgent\n"
        ."2;Phelps Dodge;THHN wire 3.5mm;5;rolls;0917;\n"
        ."3;;LED panel light 600x600;abc;pcs;;\n";

    $res = parseMaterialFile(csvFile($csv));

    expect($res['method'])->toBe('csv')->and($res['confident'])->toBeTrue();
    $items = $res['items'];
    expect($items)->toHaveCount(3);
    expect($items[0])->toMatchArray(['description' => 'Circuit breaker BRK-60A-SCH', 'quantity' => 10, 'unit' => 'pcs', 'brand' => 'Schneider', 'remarks' => 'urgent']);
    expect($items[0]['match']['product_id'])->toBe($this->breaker->id);   // exact code → matched
    expect($items[1]['match'])->toBeNull();                              // name only → suggestion
    expect(collect($items[1]['suggestions'])->pluck('product_id'))->toContain($this->wire->id);
    expect($items[2]['errors'])->toContain('Quantity "abc" is not a valid number.'); // kept, flagged
});

it('reads Windows-1252 CSV files without garbling the text', function () {
    $csv = mb_convert_encoding("Description,Qty\nCañería conduit 20mm,12\n", 'Windows-1252', 'UTF-8');

    expect(parseMaterialFile(csvFile($csv))['items'][0]['description'])->toBe('Cañería conduit 20mm');
});

it('reads columns chosen by hand when the headers are not recognised', function () {
    $file = csvFile("Thing,How many,Who\nBreaker 60A,4,Ana\nLED bulb,20,Ben\n");

    $res = parseMaterialFile($file);
    expect($res['confident'])->toBeFalse()
        ->and($res['items'])->toBe([])
        ->and($res['columns'])->toBe(['Thing', 'How many', 'Who']);

    $mapped = parseMaterialFile($file, ['description' => 0, 'quantity' => 1], 0);

    expect($mapped['items'])->toHaveCount(2)
        ->and($mapped['items'][1])->toMatchArray(['description' => 'LED bulb', 'quantity' => 20]);
});

it('flags duplicate lines but keeps them', function () {
    $res = parseMaterialFile(csvFile("Item,Qty\nLED bulb,10\nLED bulb,10\n"));

    expect($res['items'])->toHaveCount(2)
        ->and($res['items'][1]['warnings'][0])->toContain('duplicate');
});

// --- Excel ----------------------------------------------------------------------------------

it('reads an Excel sheet with a title row, and never runs formulas', function () {
    $file = xlsxFile([
        ['MATERIAL REQUEST - BLDG A'],
        [],
        ['Product Name', 'Specification', 'Quantity', 'Unit'],
        ['Circuit Breaker 60A', 'Bolt-on', 3, 'pcs'],
        ['THHN Wire 3.5mm', 'Stranded', 0, 'roll'], // quantity replaced by formula =2*5 (saved value 10)
    ]);

    $res = parseMaterialFile($file);

    expect($res['method'])->toBe('spreadsheet');
    expect($res['items'][0])->toMatchArray(['description' => 'Circuit Breaker 60A', 'specs' => 'Bolt-on', 'quantity' => 3]);
    expect($res['items'][1]['quantity'])->toEqual(10);
});

// --- PDF ------------------------------------------------------------------------------------

it('reads the text layer of a PDF without OCR', function () {
    fakeTesseract(null); // OCR unavailable: must not be needed for text PDFs
    $file = pdfFile([
        'ABC Contractors - Material List',
        '10 pcs Circuit Breaker 60A Schneider',
        'LED panel light 600x600 - 24 pcs',
        'Please deliver to site office',
        'Page 1 of 1',
    ]);

    $res = parseMaterialFile($file);

    expect($res['method'])->toBe('pdf-text');
    $items = collect($res['items']);
    expect($items->pluck('quantity')->all())->toEqual([10, 24])
        ->and($items->pluck('description')->all())->toBe(['Circuit Breaker 60A Schneider', 'LED panel light 600x600'])
        ->and($items->every(fn ($i) => $i['needs_review']))->toBeTrue();
    // Lines without a quantity are kept aside for review, not turned into items or dropped.
    expect($res['unparsed_lines'])->toContain('Please deliver to site office')
        ->not->toContain('Page 1 of 1');
});

// --- Images (OCR) ---------------------------------------------------------------------------

it('runs Tesseract on images and parses the recognised text', function () {
    fakeTesseract("MATERIALS\n1O pcs Circuit Breaker 60A\nLED bulb x 20\nThank you\n");

    $res = parseMaterialFile(pngFile());

    expect($res['method'])->toBe('ocr');
    expect(collect($res['items'])->pluck('quantity')->all())->toEqual([10, 20]); // "1O" (OCR) → 10
});

it('ignores stray OCR marks after the unit', function () {
    // Real Tesseract output for "25 m  THHN Wire" in a column layout.
    fakeTesseract('25 m_— THHN Wire 3.5mm
4 box ~ Electrical Tape
');

    $res = parseMaterialFile(pngFile());

    expect(collect($res['items'])->map(fn ($i) => [$i['quantity'], $i['unit'], $i['description']])->all())
        ->toEqual([[25, 'm', 'THHN Wire 3.5mm'], [4, 'box', 'Electrical Tape']]);
});

it('explains when OCR is not available', function () {
    fakeTesseract(null);

    expect(parseFailure(pngFile()))->toBe('ocr_unavailable');
});

// --- Bad files ------------------------------------------------------------------------------

it('rejects unsupported and disguised files', function () {
    expect(parseFailure(UploadedFile::fake()->createWithContent('list.txt', "Item,Qty\nA,1")))->toBe('unsupported')
        ->and(parseFailure(UploadedFile::fake()->createWithContent('list.pdf', 'this is not a pdf')))->toBe('corrupted')
        ->and(parseFailure(UploadedFile::fake()->createWithContent('photo.jpg', 'not an image')))->toBe('corrupted')
        ->and(parseFailure(UploadedFile::fake()->createWithContent('list.xlsx', 'PK not really')))->toBe('corrupted');
});

// --- Text cleaning --------------------------------------------------------------------------

it('removes page numbers and running headers but keeps real lines', function () {
    $lines = MaterialParser::cleanLines([
        "ACME Corp Confidential\n• 5 rolls THHN 3.5mm\nPage 1 of 2",
        "ACME Corp Confidential\n- 2 pcs Panel board 12-way\n2",
    ]);

    expect($lines)->toBe(['5 rolls THHN 3.5mm', '2 pcs Panel board 12-way']);
});

it('allows staff to manually correct OCR mistakes in the material list preview before creating the quotation', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $this->team->id,
        'password_changed_at' => now(),
    ]);
    $this->team->members()->attach($admin, ['role' => TeamRole::Owner->value]);
    $cookies = createTrustedDeviceFor($admin)['cookies'];

    $csv = csvFile("Item Description,Qty,Unit\nCircuit Breaker BRK-60A-SCH,abc,pcs\n");

    // 1. Preview shows 0 valid items and 1 invalid item because Qty is "abc"
    $preview = $this->actingAs($admin)->withCookies($cookies)
        ->post("/{$this->team->slug}/quotations/import-materials", [
            'file' => $csv,
            'client_name' => 'BuildCorp Solutions',
            'execute' => false,
        ], ['Accept' => 'application/json']);

    // Since all items are invalid on raw read, the controller returns 422 unless valid items exist;
    // now test with 1 valid + 1 OCR-misread item that the user corrects before executing:
    $csv2 = csvFile("Item Description,Qty,Unit\nTHHN Wire 3.5mm,2,roll\nCircuit Breaker BRK-60A-SCH,abc,pcs\n");
    $preview2 = $this->actingAs($admin)->withCookies($cookies)
        ->post("/{$this->team->slug}/quotations/import-materials", [
            'file' => $csv2,
            'client_name' => 'BuildCorp Solutions',
            'execute' => false,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->json();

    expect($preview2['valid_count'])->toBe(1)
        ->and($preview2['invalid_count'])->toBe(1);

    // 2. Staff corrects the second row's quantity from null ("abc") to 6 in the preview and submits execute=1
    $correctedItems = [
        ['description' => 'THHN Wire THHN-35', 'quantity' => 2, 'unit' => 'roll', 'brand' => 'Phelps Dodge', 'skip' => false],
        ['description' => 'Circuit Breaker BRK-60A-SCH', 'quantity' => 6, 'unit' => 'pcs', 'brand' => 'Schneider', 'skip' => false],
    ];

    $this->actingAs($admin)->withCookies($cookies)
        ->post("/{$this->team->slug}/quotations/import-materials", [
            'file' => csvFile("Item Description,Qty,Unit\nTHHN Wire 3.5mm,2,roll\nCircuit Breaker BRK-60A-SCH,abc,pcs\n"),
            'client_name' => 'BuildCorp Solutions',
            'execute' => true,
            'items' => json_encode($correctedItems),
        ])
        ->assertRedirect();

    $quotation = Quotation::where('team_id', $this->team->id)->where('client_name', 'BuildCorp Solutions')->sole();
    expect($quotation->items)->toHaveCount(2)
        ->and($quotation->items->pluck('quantity')->all())->toEqual([2, 6])
        ->and($quotation->items->pluck('sku')->all())->toContain('BRK-60A-SCH', 'THHN-35');
});
