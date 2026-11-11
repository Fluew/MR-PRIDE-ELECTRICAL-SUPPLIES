<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\HeaderMapper;
use App\Services\MaterialList\OcrEngine;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The staff "Import material list" action on the Quotations page: finding the description and
 * quantity columns under many header wordings, explaining which columns are missing, and reading
 * the columns staff choose by hand when the headers are not recognised.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'mlm-team']);
    $this->staff = User::factory()->create(['role' => 'staff_quotations', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->staff, ['role' => TeamRole::Member->value]);
    $this->cookies = createTrustedDeviceFor($this->staff)['cookies'];
});

/** Post a file to the material-list import: a preview (JSON) unless $execute. */
function mlmImport($t, UploadedFile $file, array $extra = [], bool $execute = false): TestResponse
{
    return $t->actingAs($t->staff)->withCookies($t->cookies)->post("/{$t->team->slug}/quotations/import-materials", [
        'file' => $file,
        'client_name' => 'Metro Builders',
        'execute' => $execute ? '1' : '0',
    ] + $extra, $execute ? [] : ['Accept' => 'application/json']);
}

function mlmCsv(string $content, string $name = 'materials.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function mlmXlsx(array $rows, string $name = 'materials.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'mlm').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

/** A real (tiny) PNG so the content checks pass; what OCR reads is faked with mlmFakeOcr(). */
function mlmPng(string $name = 'list.png'): UploadedFile
{
    $img = imagecreatetruecolor(60, 30);
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

/**
 * Fake OCR and pdftotext: every image, scanned PDF page and PDF text layer reads as these lines
 * (word positions from ocrTsv(), so two or more spaces are a column gap).
 *
 * @param  array<int, string>  $lines
 */
function mlmFakeOcr(array $lines): void
{
    app()->instance(OcrEngine::class, new class(ocrTsv($lines), implode("\n", $lines)) extends OcrEngine
    {
        public function __construct(private string $tsv, private string $text) {}

        public function tesseractAvailable(): bool
        {
            return true;
        }

        public function imageToTsv(string $imagePath): string
        {
            return $this->tsv;
        }

        public function imageToText(string $imagePath): string
        {
            return $this->text;
        }

        public function pdfToTsv(string $pdfPath, int $pages): array
        {
            return [$this->tsv];
        }

        public function pdfToText(string $pdfPath, int $pages): array
        {
            return [$this->text];
        }

        public function pdfTextTsv(string $pdfPath): ?string
        {
            return $this->tsv;
        }
    });
}

/** Description and quantity of each previewed line. */
function mlmLines(array $json): array
{
    return array_map(fn ($i) => [$i['description'], $i['quantity']], $json['items']);
}

// --- Header wordings ------------------------------------------------------------------------

it('reads an Excel file with Description and Qty columns', function () {
    $json = mlmImport($this, mlmXlsx([
        ['Description', 'Qty', 'Unit'],
        ['Circuit Breaker 60A', 4, 'pcs'],
        ['THHN Wire 3.5mm', 2, 'rolls'],
    ]))->assertOk()->json();

    expect($json['success'])->toBeTrue()
        ->and($json['confident'])->toBeTrue()
        ->and($json['valid_count'])->toBe(2)
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]])
        ->and($json['mapping'])->toBe(['description' => 0, 'quantity' => 1, 'unit' => 2])
        ->and($json['header_index'])->toBe(0)
        ->and($json['columns'])->toBe(['Description', 'Qty', 'Unit']);
    expect(Quotation::count())->toBe(0); // a preview saves nothing
});

it('reads an Excel file with Product Name and Stock Quantity columns', function () {
    $json = mlmImport($this, mlmXlsx([
        ['Product Name', 'Brand', 'Stock Quantity'],
        ['LED Panel Light 600x600', 'Philips', 12],
        ['PVC Conduit 20mm', '', 50],
    ]))->assertOk()->json();

    expect(mlmLines($json))->toEqual([['LED Panel Light 600x600', 12], ['PVC Conduit 20mm', 50]])
        ->and($json['items'][0]['brand'])->toBe('Philips')
        ->and($json['mapping'])->toBe(['description' => 0, 'brand' => 1, 'quantity' => 2]);
});

it('recognises other header wordings, whatever the case, spacing and punctuation', function (string $header, string $delimiter) {
    $csv = $header."\n".implode($delimiter, ['Circuit Breaker 60A', '4', 'pcs'])."\n".implode($delimiter, ['THHN Wire 3.5mm', '2', 'rolls'])."\n";

    $json = mlmImport($this, mlmCsv($csv))->assertOk()->json();

    expect(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]])
        ->and($json['items'][1]['unit'])->toBe('rolls');
})->with([
    'Material Name / Available Quantity / U.O.M' => ['Material Name;Available Quantity;U.O.M', ';'],
    "PRODUCT_NAME / Q'ty / Unit" => ["PRODUCT_NAME,Q'ty,Unit", ','],
    'Item Name / On Hand / UOM' => ["Item Name\tOn Hand\tUOM", "\t"],
    'Product / Current Stock / Unit' => ['Product,Current Stock,Unit', ','],
    'Product Description / Stock_Quantity / Units' => ['Product Description,Stock_Quantity,Units', ','],
    'spaced and dotted' => ['  ITEM   DESCRIPTION ,QTY.,Unit of Measure', ','],
    'Material / Quantity / Unit' => ['Material|Quantity|Unit', '|'],
]);

it('finds the header row below a title, an address and the supplier details', function () {
    $csv = "Bill of Materials and Quantities\n\"123 Rizal Ave, Makati City\"\nSupplier: ABC Trading\nDate: 2026-10-01\n\n"
        ."No.,Item Description,Qty,Unit\n1,Circuit Breaker 60A,4,pcs\n2,THHN Wire 3.5mm,2,rolls\n";

    $json = mlmImport($this, mlmCsv($csv))->assertOk()->json();

    expect($json['header_index'])->toBe(4) // blank lines are not counted
        ->and($json['columns'])->toBe(['No.', 'Item Description', 'Qty', 'Unit'])
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]]);

    // The same layout in Excel.
    $json = mlmImport($this, mlmXlsx([
        ['Bill of Materials and Quantities'],
        ['Supplier: ABC Trading', null, 'Tel: 8123 4567'],
        [],
        ['Item', 'Particulars', 'QTY', 'U.O.M'],
        [1, 'Circuit Breaker 60A', 4, 'pcs'],
    ]))->assertOk()->json();

    expect($json['header_index'])->toBe(2)
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4]]);
});

it('never takes a document title or supplier details for the column headers', function () {
    expect(HeaderMapper::isHeaderRow(['Bill of Materials and Quantities']))->toBeFalse()
        ->and(HeaderMapper::isHeaderRow(explode(' ', 'Bill of Materials and Quantities'), true))->toBeFalse()
        ->and(HeaderMapper::isHeaderRow(['Supplier: ABC Trading', 'Qty: 10']))->toBeFalse();

    // A title alone gives no columns: staff are asked to choose them.
    $json = mlmImport($this, mlmCsv("Bill of Materials and Quantities\nCircuit Breaker 60A;4\nLED Bulb 9W;20\n"))
        ->assertStatus(422)->json();

    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['header_detected'])->toBeFalse()
        ->and($json['mapping'])->toBe([]);
});

// --- Missing columns --------------------------------------------------------------------------

it('explains that the quantity column is missing and names the columns found', function () {
    $json = mlmImport($this, mlmCsv("Description,Unit,Brand\nCircuit Breaker 60A,pcs,Schneider\nLED Bulb 9W,pcs,Philips\n"))
        ->assertStatus(422)->json();

    expect($json['success'])->toBeFalse()
        ->and($json['needs_mapping'])->toBeTrue()
        ->and($json['message'])->toContain('No quantity column was found.')
        ->and($json['message'])->toContain('Columns found: "Description", "Unit", "Brand".')
        ->and($json['missing'])->toBe(['quantity'])
        ->and($json['mapping'])->toBe(['description' => 0, 'unit' => 1, 'brand' => 2])
        ->and($json['header_index'])->toBe(0)
        ->and($json['header_detected'])->toBeTrue()
        ->and($json['columns'])->toBe(['Description', 'Unit', 'Brand'])
        ->and($json['preview'][1])->toBe(['Circuit Breaker 60A', 'pcs', 'Schneider'])
        ->and(collect($json['fields'])->where('required', true)->pluck('key')->all())->toBe(['description', 'quantity'])
        ->and(collect($json['fields'])->pluck('label')->all())->toContain('Description', 'Quantity', 'Unit', 'Brand', 'Item code');
    expect(Quotation::count())->toBe(0);
});

it('explains that the description column is missing', function () {
    $json = mlmImport($this, mlmCsv("Qty,Unit\n4,pcs\n2,rolls\n"))->assertStatus(422)->json();

    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['message'])->toContain('No description column was found.')
        ->and($json['message'])->toContain('Columns found: "Qty", "Unit".')
        ->and($json['message'])->not->toContain('quantity column')
        ->and($json['missing'])->toBe(['description']);
});

// --- Choosing the columns by hand -------------------------------------------------------------

it('reads the columns chosen by hand when the headers are not recognised, and imports them', function () {
    $csv = "Thing,How many,Who\nCircuit Breaker 60A,4,Ana\nLED Bulb 9W,20,Ben\n";

    $json = mlmImport($this, mlmCsv($csv))->assertStatus(422)->json();
    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['message'])->toContain('No description or quantity column was found.')
        ->and($json['columns'])->toBe(['Thing', 'How many', 'Who'])
        ->and($json['header_index'])->toBe(0) // suggested; staff can choose "No header row"
        ->and($json['missing'])->toBe(['description', 'quantity']);

    $choice = ['mapping' => json_encode(['description' => 0, 'quantity' => 1, 'unit' => null]), 'header_row' => '0'];
    $json = mlmImport($this, mlmCsv($csv), $choice)->assertOk()->json();
    expect(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['LED Bulb 9W', 20]])
        ->and($json['confident'])->toBeTrue()
        ->and($json['mapping'])->toBe(['description' => 0, 'quantity' => 1])
        ->and($json['header_index'])->toBe(0);

    // The form can also send the choice as fields: mapping[description]=0.
    mlmImport($this, mlmCsv($csv), ['mapping' => ['description' => '0', 'quantity' => '1'], 'header_row' => 0])
        ->assertOk()->assertJsonPath('valid_count', 2);

    mlmImport($this, mlmCsv($csv), $choice, true)->assertRedirect()->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->items->map(fn ($i) => [$i->product_name, $i->quantity, $i->sku])->all())
        ->toBe([['Circuit Breaker 60A', 4, 'CUSTOM'], ['LED Bulb 9W', 20, 'CUSTOM']]);
});

it('reads every row as data when there is no header row', function () {
    $json = mlmImport($this, mlmCsv("Circuit Breaker 60A,4\nLED Bulb 9W,20\n"), [
        'mapping' => json_encode(['description' => 0, 'quantity' => 1]),
        'header_row' => '-1',
    ])->assertOk()->json();

    expect(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['LED Bulb 9W', 20]])
        ->and($json['header_index'])->toBeNull();
});

it('refuses column choices that cannot be used', function (mixed $mapping, string $message) {
    $json = mlmImport($this, mlmCsv("Thing,How many\nCircuit Breaker 60A,4\n"), ['mapping' => $mapping, 'header_row' => 0])
        ->assertStatus(422)->json();

    expect($json['success'])->toBeFalse()->and($json['message'])->toContain($message);
    expect(Quotation::count())->toBe(0);
})->with([
    'one column for two fields' => [json_encode(['description' => 0, 'quantity' => 0]), '"Description" and "Quantity" are both set to column A. Choose a different column for each.'],
    'a column outside the file' => [json_encode(['description' => 0, 'quantity' => 40]), 'The column chosen for quantity is not a column of the file.'],
    'not a column number' => [json_encode(['description' => 0, 'quantity' => 'B']), 'The column chosen for quantity is not a column of the file.'],
    'not readable' => ['{oops', 'The chosen columns could not be read.'],
    'no quantity chosen' => [json_encode(['description' => 0, 'quantity' => null]), 'No quantity column was found. Columns found: "Thing", "How many". Choose the quantity column below'],
]);

it('keeps the column choice panel open when the chosen columns are incomplete', function () {
    $json = mlmImport($this, mlmCsv("Thing,How many\nCircuit Breaker 60A,4\n"), ['mapping' => json_encode(['description' => 0]), 'header_row' => 0])
        ->assertStatus(422)->json();

    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['mapping'])->toBe(['description' => 0])
        ->and($json['missing'])->toBe(['quantity']);
});

it('refuses a header row that is not a row number', function () {
    mlmImport($this, mlmCsv("Thing,How many\nCircuit Breaker 60A,4\n"), ['mapping' => json_encode(['description' => 0, 'quantity' => 1]), 'header_row' => 'first'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['header_row' => 'Choose the header row from the rows shown, or "No header row".']);

    // The page reads the preview as JSON, so form errors come back as JSON too.
    $this->actingAs($this->staff)->withCookies($this->cookies)
        ->post("/{$this->team->slug}/quotations/import-materials", ['file' => mlmCsv("Description,Qty\nBox,1\n"), 'execute' => '0'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please enter the client or company name.');
});

// --- Never invent values ----------------------------------------------------------------------

it('reports rows with a blank quantity or description as invalid and never imports them', function () {
    $csv = "Description,Qty\nCircuit Breaker 60A,4\nLED Bulb 9W,\n,5\n";

    $json = mlmImport($this, mlmCsv($csv))->assertOk()->json();
    expect($json['valid_count'])->toBe(1)
        ->and($json['invalid_count'])->toBe(2)
        ->and($json['items'][1])->toMatchArray(['description' => 'LED Bulb 9W', 'quantity' => null])
        ->and($json['items'][1]['errors'])->toBe(['Missing quantity.'])
        ->and($json['items'][2])->toMatchArray(['description' => '', 'quantity' => 5])
        ->and($json['items'][2]['errors'])->toBe(['Missing item description.']);

    mlmImport($this, mlmCsv($csv), [], true)->assertSessionHasNoErrors();

    expect(Quotation::sole()->items->map(fn ($i) => [$i->product_name, $i->quantity])->all())
        ->toBe([['Circuit Breaker 60A', 4]]);
});

it('explains when the columns are found but no row has a description and a quantity', function () {
    $json = mlmImport($this, mlmCsv("Description,Qty\nCircuit Breaker 60A,\nLED Bulb 9W,0\n"))->assertStatus(422)->json();

    expect($json['message'])->toBe('The "Description" and "Qty" columns were found, but none of the 2 rows has both a description and a quantity greater than zero. Check the file, or choose different columns.')
        ->and($json['needs_mapping'])->toBeTrue()
        ->and($json['mapping'])->toBe(['description' => 0, 'quantity' => 1]);

    // Columns chosen by hand that hold no quantities.
    $json = mlmImport($this, mlmCsv("Thing,How many,Who\nCircuit Breaker 60A,4,Ana\n"), [
        'mapping' => json_encode(['description' => 0, 'quantity' => 2]),
        'header_row' => '-1',
    ])->assertStatus(422)->json();

    expect($json['message'])->toStartWith('Column A (description) and column C (quantity) were chosen, but none of the 2 rows has both');
    expect(Quotation::count())->toBe(0);
});

it('refuses to create a quotation when every corrected line is skipped or blank', function () {
    mlmImport($this, mlmCsv("Description,Qty\nCircuit Breaker 60A,4\n"), [
        'items' => json_encode([['description' => 'Circuit Breaker 60A', 'quantity' => null, 'skip' => false], ['description' => 'X', 'quantity' => 2, 'skip' => true]]),
    ], true)->assertSessionHasErrors(['file' => 'No line has both a description and a quantity greater than zero. Correct a line, or restore a skipped one.']);

    expect(Quotation::count())->toBe(0);
});

it('keeps the catalog match by product code when staff send back the corrected lines', function () {
    Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-2060', 'name' => 'Breaker 2P 60A', 'category' => 'Breakers', 'price' => 520, 'stock' => 5, 'is_active' => true]);
    $csv = "Item Code,Description,Qty\nBRK-2060,Breaker,3\n";

    $preview = mlmImport($this, mlmCsv($csv))->assertOk()->json();
    expect($preview['items'][0]['item_code'])->toBe('BRK-2060')
        ->and($preview['items'][0]['match']['sku'])->toBe('BRK-2060');

    // The page sends the previewed lines back as they are (with any corrections).
    mlmImport($this, mlmCsv($csv), ['items' => json_encode($preview['items'])], true)->assertSessionHasNoErrors();

    expect(Quotation::sole()->items->map(fn ($i) => [$i->sku, (float) $i->unit_price])->all())->toBe([['BRK-2060', 520.0]]);
});

// --- Duplicates and unreadable files ------------------------------------------------------------

it('refuses a file that was already imported, even with columns chosen by hand', function () {
    $csv = "Thing,How many\nCircuit Breaker 60A,4\n";
    $choice = ['mapping' => json_encode(['description' => 0, 'quantity' => 1]), 'header_row' => '0'];

    mlmImport($this, mlmCsv($csv), $choice, true)->assertSessionHasNoErrors();
    $number = Quotation::sole()->quotation_number;

    mlmImport($this, mlmCsv($csv, 'renamed.csv'), $choice)->assertStatus(409)
        ->assertJsonPath('duplicate_of', $number)
        ->assertJsonPath('message', "This file was already imported as quotation {$number}. Open that quotation instead of importing it again.");
    mlmImport($this, mlmCsv($csv), $choice, true)->assertSessionHasErrors('file');

    expect(Quotation::count())->toBe(1);
});

it('explains why an unreadable file cannot be imported, and saves nothing', function (string $name, string $content, string $message) {
    $json = mlmImport($this, UploadedFile::fake()->createWithContent($name, $content))->assertStatus(422)->json();

    expect($json['success'])->toBeFalse()
        ->and($json['message'])->toBe($message)
        ->and($json)->not->toHaveKey('needs_mapping');

    mlmImport($this, UploadedFile::fake()->createWithContent($name, $content), [], true)->assertSessionHasErrors(['file' => $message]);

    expect(Quotation::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'a fake Excel file' => ['list.xlsx', 'PK not really a workbook', "This file doesn't look like a valid .xlsx file. It may be damaged, or saved in a different format."],
    'a damaged PDF' => ['list.pdf', "%PDF-1.4\n garbage garbage", 'We could not open this PDF. It may be damaged. Please re-save it, or upload an Excel/CSV file.'],
    'not a PDF at all' => ['list.pdf', 'this is not a pdf', "This file doesn't look like a valid .pdf file. It may be damaged, or saved in a different format."],
    'an empty file' => ['list.csv', '', 'The file is empty.'],
]);

// --- PDF and images (OCR faked) -----------------------------------------------------------------

it('reads a material table from an image, a scanned PDF and a text PDF', function (Closure $file, string $method) {
    mlmFakeOcr([
        'Bill of Materials and Quantities',
        'Supplier: ABC Trading',
        'No.  Product Name             Stock Quantity   Unit',
        '1    Circuit Breaker 60A      4                pcs',
        '2    THHN Wire 3.5mm          2                rolls',
    ]);

    $json = mlmImport($this, $file())->assertOk()->json();

    expect($json['method'])->toBe($method)
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]])
        ->and($json['items'][1]['unit'])->toBe('rolls')
        ->and($json['columns'])->toBe(['No.', 'Product Name', 'Stock Quantity', 'Unit']);
    if ($method !== 'pdf-text') {
        expect($json['warning'])->toContain('OCR');
    }
})->with([
    'PNG' => [fn () => mlmPng(), 'ocr'],
    'JPEG' => [function () {
        $img = imagecreatetruecolor(60, 30);
        ob_start();
        imagejpeg($img);

        return UploadedFile::fake()->createWithContent('list.jpg', (string) ob_get_clean());
    }, 'ocr'],
    'scanned PDF' => [fn () => textPdf([], 'scan.pdf'), 'pdf-ocr'],
    'text PDF' => [fn () => textPdf(['Bill of Materials and Quantities for the warehouse', 'Circuit Breaker 60A and THHN Wire 3.5mm'], 'list.pdf'), 'pdf-text'],
]);

it('lets staff choose the columns of a picture whose headers are not recognised', function () {
    mlmFakeOcr([
        'Thing                 How many',
        'Circuit Breaker 60A   4',
        'LED Bulb 9W           20',
    ]);

    $json = mlmImport($this, mlmPng())->assertStatus(422)->json();
    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['message'])->toContain('No table with a description and a quantity column, and no lines like "10 pcs Circuit breaker", were found')
        ->and($json['preview'])->toBe([['Thing', 'How many'], ['Circuit Breaker 60A', '4'], ['LED Bulb 9W', '20']]);

    $choice = ['mapping' => json_encode(['description' => 0, 'quantity' => 1]), 'header_row' => '0'];
    $json = mlmImport($this, mlmPng(), $choice)->assertOk()->json();
    expect(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['LED Bulb 9W', 20]])
        ->and($json['method'])->toBe('ocr');

    mlmImport($this, mlmPng(), $choice, true)->assertSessionHasNoErrors();
    expect(Quotation::sole()->items)->toHaveCount(2);
});

it('explains when a picture has a table without a quantity column', function () {
    mlmFakeOcr([
        'Description            Unit     Brand',
        'Circuit Breaker 60A    pcs      Schneider',
        'LED Bulb 9W            pcs      Philips',
    ]);

    $json = mlmImport($this, mlmPng())->assertStatus(422)->json();

    expect($json['needs_mapping'])->toBeTrue()
        ->and($json['message'])->toContain('No quantity column was found. Columns found: "Description", "Unit", "Brand".');
});

it('explains when a document has no table and no material lines', function () {
    mlmFakeOcr(['Dear Sir,', 'Please send us your best price for the items we discussed.', 'Thank you.']);

    $json = mlmImport($this, mlmPng())->assertStatus(422)->json();

    expect($json['message'])->toBe('No table with a description and a quantity column, and no lines like "10 pcs Circuit breaker", were found in this file. Please check the file, or upload an Excel or CSV file.')
        ->and($json)->not->toHaveKey('needs_mapping');
});

// --- PDF and images (real Tesseract and Poppler; skipped where they are not installed) ----------

/** A picture of a typed material list: a title, the supplier, then a table. */
function mlmTableImage(string $format = 'png'): string
{
    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    $lines = [
        [[40, 'Bill of Materials and Quantities']],
        [[40, 'Supplier: ABC Trading']],
        [[40, 'Material Name'], [560, 'Qty'], [720, 'Unit']],
        [[40, 'Circuit Breaker 60A'], [560, '4'], [720, 'pcs']],
        [[40, 'THHN Wire 3.5mm'], [560, '2'], [720, 'rolls']],
        [[40, 'LED Panel Light 600x600'], [560, '12'], [720, 'pcs']],
    ];
    $img = imagecreatetruecolor(1000, 80 + 60 * count($lines));
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $black = imagecolorallocate($img, 0, 0, 0);
    foreach ($lines as $r => $cells) {
        foreach ($cells as [$x, $text]) {
            imagettftext($img, 22, 0, $x, 70 + $r * 60, $black, $font, $text);
        }
    }
    ob_start();
    $format === 'jpg' ? imagejpeg($img, null, 95) : imagepng($img);

    return (string) ob_get_clean();
}

function mlmPdf(string $html, string $name): UploadedFile
{
    $pdf = new Dompdf;
    $pdf->loadHtml('<html><body style="font-family: DejaVu Sans; font-size: 12px">'.$html.'</body></html>');
    $pdf->render();

    return UploadedFile::fake()->createWithContent($name, (string) $pdf->output());
}

it('reads a typed material list from a real PNG, JPEG and scanned PDF with Tesseract', function (string $kind) {
    $ocr = app(OcrEngine::class);
    if (! $ocr->tesseractAvailable() || ($kind === 'scan' && ! $ocr->pdfRendererAvailable())) {
        $this->markTestSkipped('Tesseract or Poppler is not installed.');
    }

    $file = match ($kind) {
        'png' => UploadedFile::fake()->createWithContent('list.png', mlmTableImage('png')),
        'jpg' => UploadedFile::fake()->createWithContent('list.jpg', mlmTableImage('jpg')),
        // A scanned page: only a picture, no text layer.
        'scan' => mlmPdf('<img style="width: 100%" src="data:image/png;base64,'.base64_encode(mlmTableImage('png')).'">', 'scan.pdf'),
    };

    $json = mlmImport($this, $file)->assertOk()->json();

    expect($json['method'])->toBe($kind === 'scan' ? 'pdf-ocr' : 'ocr')
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2], ['LED Panel Light 600x600', 12]])
        ->and($json['columns'])->toBe(['Material Name', 'Qty', 'Unit'])
        ->and($json['warning'])->toContain('OCR');
})->with(['png', 'jpg', 'scan']);

it('reads the table of a real text PDF with Poppler, and lets staff map unknown headers', function () {
    if (! app(OcrEngine::class)->pdfTextAvailable()) {
        $this->markTestSkipped('Poppler (pdftotext) is not installed.');
    }
    $table = fn (array $head) => mlmPdf(
        '<h3>Bill of Materials and Quantities</h3><p>Supplier: ABC Trading</p><table cellpadding="6">'
        .'<tr><th align="left">'.implode('</th><th align="left">', $head).'</th></tr>'
        .'<tr><td>Circuit Breaker 60A</td><td>4</td><td>Site A</td></tr>'
        .'<tr><td>THHN Wire 3.5mm</td><td>2</td><td>Site B</td></tr></table>',
        'list-'.md5(implode($head)).'.pdf',
    );

    $json = mlmImport($this, $table(['Product Description', 'Available Quantity', 'Remarks']))->assertOk()->json();
    expect($json['method'])->toBe('pdf-text')
        ->and(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]]);

    // Headers that are not recognised: staff choose the columns from the rows shown.
    $unknown = $table(['Thing', 'How many', 'Where']);
    $json = mlmImport($this, $unknown)->assertStatus(422)->json();
    $header = collect($json['preview'])->search(fn ($row) => ($row[0] ?? null) === 'Thing');
    expect($json['needs_mapping'])->toBeTrue()->and($header)->not->toBeFalse();

    $json = mlmImport($this, $unknown, ['mapping' => json_encode(['description' => 0, 'quantity' => 1, 'remarks' => 2]), 'header_row' => (string) $header])
        ->assertOk()->json();
    expect(mlmLines($json))->toEqual([['Circuit Breaker 60A', 4], ['THHN Wire 3.5mm', 2]]);
});
