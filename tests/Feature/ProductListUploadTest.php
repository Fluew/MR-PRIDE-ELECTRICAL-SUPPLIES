<?php

use App\Enums\TeamRole;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\OcrEngine;
use App\Services\MaterialList\ProductListExtractor;
use App\Services\MaterialList\ProductListHeaders;
use App\Services\MaterialList\ProductListParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Client "Upload product list" on the public quotation form: header recognition (Description,
 * Quantity, Unit and their abbreviations), reading PDFs, images, Excel and CSV files, flagging
 * values for review instead of guessing, file safety, and saving the confirmed products.
 *
 * Tests marked "real OCR" run Tesseract and Poppler and are skipped when they are not installed.
 */
beforeEach(function () {
    Storage::fake('local');
    Team::factory()->create(['slug' => 'mr-pride']);
});

// ------------------------------------------------------------------------------------ helpers

function plExtract(UploadedFile $file, array $extra = [])
{
    return test()->post('/quotation/product-list/extract', ['file' => $file] + $extra, ['Accept' => 'application/json']);
}

/** [description, quantity, unit] of each row in an extract response. */
function plRows(array $json): array
{
    return array_map(fn ($i) => [$i['description'], $i['quantity'], $i['unit']], $json['items']);
}

function plXlsx(array $sheets, string $name = 'list.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->removeSheetByIndex(0);
    foreach ($sheets as $title => $rows) {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows, null, 'A1', true);
    }
    $path = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A PDF with a real text layer and columns: each page is a list of rows, each row a list of
 * cells placed at the x positions given (so pdftotext sees a table).
 *
 * @param  array<int, array<int, array<int, string>>>  $pages
 */
function plTablePdf(array $pages, array $xs = [50, 300, 400, 480], string $name = 'list.pdf', bool $encrypted = false): UploadedFile
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
    $kids = [];
    $n = 4;
    foreach ($pages as $rows) {
        $stream = "BT /F1 11 Tf\n";
        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $text) {
                $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
                $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $xs[$c], 780 - $r * 20, str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text));
            }
        }
        $stream .= 'ET';
        $objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents '.($n + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
        $objects[$n + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        $kids[] = "{$n} 0 R";
        $n += 2;
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

    $trailerExtra = '';
    if ($encrypted) { // a password-protected PDF names its encryption dictionary in the trailer
        $objects[$n] = '<< /Filter /Standard /V 1 /R 2 /O (x) /U (y) /P -4 >>';
        $trailerExtra = "/Encrypt {$n} 0 R ";
    }

    return UploadedFile::fake()->createWithContent($name, plPdfBytes($objects, $trailerExtra));
}

/** A scanned PDF: each page is only a JPEG image (no text layer). */
function plScannedPdf(array $jpegPaths, string $name = 'scan.pdf'): UploadedFile
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $n = 3;
    foreach ($jpegPaths as $jpeg) {
        [$w, $h] = getimagesize($jpeg);
        $bytes = file_get_contents($jpeg);
        $height = (int) round(595 * $h / $w); // the page has the picture's proportions, like a real scan
        $content = "q 595 0 0 {$height} 0 0 cm /Im1 Do Q";
        $objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 '.$height.'] /Contents '.($n + 1).' 0 R /Resources << /XObject << /Im1 '.($n + 2).' 0 R >> >> >>';
        $objects[$n + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        $objects[$n + 2] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($bytes)." >>\nstream\n{$bytes}\nendstream";
        $kids[] = "{$n} 0 R";
        $n += 3;
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

    return UploadedFile::fake()->createWithContent($name, plPdfBytes($objects));
}

function plPdfBytes(array $objects, string $trailerExtra = ''): string
{
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[$i] = strlen($pdf);
        $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R {$trailerExtra}>>\nstartxref\n{$xref}\n%%EOF";
}

/** A picture of a table written with $font (a handwriting-style font imitates handwriting). Returns the file path. */
function plDrawTable(array $rows, string $font, string $type = 'png', array $xs = [80, 900, 1250], int $size = 34): string
{
    $img = imagecreatetruecolor(1700, 120 + count($rows) * 90);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $ink = imagecolorallocate($img, 20, 20, 60);
    foreach ($rows as $r => $cells) {
        foreach (array_values($cells) as $c => $text) {
            imagettftext($img, $size, 0, $xs[$c], 100 + $r * 90, $ink, $font, $text);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'pl').'.'.$type;
    $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 92);

    return $path;
}

function plNeedsRealOcr(bool $pdf = false): void
{
    $ocr = new OcrEngine;
    if (! $ocr->tesseractAvailable() || ($pdf && (! $ocr->pdfRendererAvailable() || ! $ocr->pdfTextAvailable()))) {
        test()->markTestSkipped('Tesseract/Poppler are not installed.');
    }
}

function plFont(string $file): string
{
    $path = str_contains($file, '/') ? $file : "C:/Windows/Fonts/{$file}";
    if (! is_file($path)) {
        test()->markTestSkipped("Font {$file} is not available.");
    }

    return $path;
}

const PL_TYPED_FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

/**
 * Fake OCR returning the given lines as Tesseract TSV (5 px per character, 30 px per line).
 * A word written as "~word" is returned with low confidence (30). Counts the calls.
 */
function plFakeOcr(array $lines): object
{
    $fake = new class($lines) extends OcrEngine
    {
        public int $calls = 0;

        public function __construct(private array $lines) {}

        public function tesseractAvailable(): bool
        {
            return true;
        }

        public function imageToTsv(string $imagePath): string
        {
            $this->calls++;
            $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
            $tsv .= "1\t1\t0\t0\t0\t0\t0\t0\t400\t300\t-1\t\n";
            foreach ($this->lines as $n => $line) {
                preg_match_all('/\S+/u', $line, $words, PREG_OFFSET_CAPTURE);
                foreach ($words[0] as $i => [$word, $byte]) {
                    $x = mb_strlen(substr($line, 0, $byte)) * 5;
                    $conf = str_starts_with($word, '~') ? 30 : 95;
                    $word = ltrim($word, '~');
                    $tsv .= implode("\t", [5, 1, 1, 1, $n + 1, $i + 1, $x, 20 + $n * 30, mb_strlen($word) * 5, 12, $conf, $word])."\n";
                }
            }

            return $tsv;
        }
    };
    app()->instance(OcrEngine::class, $fake);

    return $fake;
}

// ============================================================ 1. header recognition

it('recognises every accepted header wording, whatever the case and punctuation', function (string $header, string $field) {
    expect(ProductListHeaders::resolve($header)[0] ?? null)->toBe($field);
})->with([
    ['Description', 'description'], ['DESC', 'description'], ['Descr.', 'description'], ['Dscr', 'description'],
    ['Product', 'description'], ['Products', 'description'], ['Product Name', 'description'], ['product description', 'description'],
    ['Item', 'description'], ['Item Name', 'description'], ['Item Description', 'description'], ['Material', 'description'],
    ['Materials', 'description'], ['Material Description', 'description'], ['PARTICULARS', 'description'], ['Prod', 'description'],
    ['Quantity', 'quantity'], ['Qty', 'quantity'], ['QTY.', 'quantity'], ['Qnty', 'quantity'], ['Quant', 'quantity'],
    ["Q'ty", 'quantity'], ['QTY', 'quantity'], ['No.', 'quantity'], ['Count', 'quantity'], ['Number of Items', 'quantity'],
    ['Unit', 'unit'], ['Units', 'unit'], ['UOM', 'unit'], ['U.O.M.', 'unit'], ['Unit of Measure', 'unit'],
    ['Unit of Measurement', 'unit'], ['U/M', 'unit'], ['UM', 'unit'], ['Measure', 'unit'], ['  unit  ', 'unit'],
    ['Qty (pcs)', 'quantity'], ['Description / Specification', 'description'],
]);

it('tolerates common OCR mistakes in headers', function (string $header, string $field) {
    $found = ProductListHeaders::resolve($header);
    expect($found[0])->toBe($field)->and($found[4])->toBeTrue(); // flagged as a fuzzy match
})->with([
    ['Quantlty', 'quantity'], ['Descriptlon', 'description'], ['0ty', 'quantity'], ['U0M', 'unit'],
    ['Materia1s', 'description'], ['Partlculars', 'description'], ['Uorn', 'unit'],
]);

it('does not take unrelated columns for Description, Quantity or Unit', function (string $header) {
    expect(ProductListHeaders::resolve($header))->toBeNull();
})->with(['Unit Price', 'Unit Cost', 'Item No.', 'Item Code', 'Brand', 'Remarks', 'Amount', 'Total', 'Material Cost',
    'Supplier', 'Date', 'Signature', 'Category', 'Location', 'Status', 'Discount', 'Quality', 'Product Code', 'Model']);

it('uses the column data to decide what "No." and "UM" mean', function () {
    // A 1, 2, 3 count is a line number, so the quantity column is missing: the client is asked.
    $lineNumbers = ProductListParser::parseTable([['No.', 'Product', 'UM'], ['1', 'LED bulb', 'pcs'], ['2', 'Tape', 'rolls']]);
    expect($lineNumbers['status'])->toBe('needs_mapping')
        ->and($lineNumbers['choice']['missing'])->toBe(['quantity'])
        ->and($lineNumbers['notes'][0])->toContain('counts the lines');

    // Numbers that are not a count, beside the products: quantities. "UM" holding units is the unit.
    $quantities = ProductListParser::parseTable([['Product', 'No.', 'UM'], ['LED bulb', '12', 'pcs'], ['Tape', '4', 'rolls']]);
    expect($quantities['status'])->toBe('ok')
        ->and(array_map(fn ($i) => [$i['description'], $i['quantity'], $i['unit']], $quantities['items']))
        ->toBe([['LED bulb', 12.0, 'pcs'], ['Tape', 4.0, 'rolls']]);

    // A first "No." column with odd numbers could be either: the client confirms it.
    $unclear = ProductListParser::parseTable([['No.', 'Description', 'Unit'], ['5', 'LED bulb', 'pcs'], ['9', 'Tape', 'rolls']]);
    expect($unclear['status'])->toBe('needs_mapping')
        ->and($unclear['choice']['ambiguous'][0])->toMatchArray(['field' => 'quantity', 'column' => 0, 'label' => 'No.']);

    // "UM" over values that are not units is not trusted as the unit.
    $notUnits = ProductListParser::parseTable([['Description', 'Qty', 'UM'], ['LED bulb', '10', '450'], ['Tape', '4', '120']]);
    expect($notUnits['choice']['ambiguous'][0]['field'])->toBe('unit');
});

// ============================================================ 2. spreadsheets and CSV

it('reads an Excel file with full headers', function () {
    $json = plExtract(plXlsx(['Request' => [
        ['Description', 'Quantity', 'Unit'],
        ['PVC Pipe 1/2 inch', 20, 'pcs'],
        ['Electrical Wire 2.0mm²', 50, 'meters'],
        ['Circuit Breaker 20A', 5, 'pcs'],
    ]]))->assertOk()->json();

    expect($json['status'])->toBe('ok')
        ->and($json['method'])->toBe('spreadsheet')
        ->and($json['ocr'])->toBeFalse()
        ->and($json['message'])->toBeNull()
        ->and(plRows($json))->toBe([['PVC Pipe 1/2 inch', 20, 'pcs'], ['Electrical Wire 2.0mm²', 50, 'meters'], ['Circuit Breaker 20A', 5, 'pcs']])
        ->and(collect($json['items'])->pluck('reliable')->unique()->all())->toBe([true])
        ->and($json['items'][0]['ref'])->toBe('Row 2');
});

it('reads an Excel file with abbreviated headers on several worksheets', function () {
    $json = plExtract(plXlsx([
        'Cover' => [['Material request for Site B'], ['Prepared by: J. Cruz']],
        'Lighting' => [['DESC', 'QTY', 'UOM'], ['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls']],
        'Wiring' => [['ITEM', 'QUANT', 'UNIT'], ['Copper Wire 3.5mm²', 100, 'meters'], ['PVC Elbow', 25, 'pcs']],
    ]))->assertOk()->json();

    expect(plRows($json))->toBe([
        ['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Copper Wire 3.5mm²', 100, 'meters'], ['PVC Elbow', 25, 'pcs'],
    ])->and($json['items'][2]['ref'])->toBe('Sheet "Wiring": Row 2');
});

it('reads a CSV with mixed-case headers, a semicolon delimiter and Windows-1252 text', function () {
    $csv = mb_convert_encoding("dEsCrIpTiOn;qTy;Uom\nCâble THHN 3.5mm²;100;meters\nLED Bulb 12W;10;PCS\n", 'Windows-1252', 'UTF-8');

    $json = plExtract(UploadedFile::fake()->createWithContent('list.csv', $csv))->assertOk()->json();

    expect(plRows($json))->toBe([['Câble THHN 3.5mm²', 100, 'meters'], ['LED Bulb 12W', 10, 'PCS']]);
});

it('reads headers in any order and ignores extra columns', function () {
    $csv = "No.,Item Code,Brand,Unit,Qty,Description,Unit Price,Amount,Remarks\n"
        ."1,PV-12,Neltex,pcs,20,PVC Pipe 1/2 inch,85.00,1700.00,urgent\n"
        ."2,WR-20,Phelps Dodge,meters,50,Electrical Wire 2.0mm²,32.50,1625.00,\n"
        .",,,,,,,3325.00,Total\n";

    $json = plExtract(UploadedFile::fake()->createWithContent('quote.csv', $csv))->assertOk()->json();

    expect(plRows($json))->toBe([['PVC Pipe 1/2 inch', 20, 'pcs'], ['Electrical Wire 2.0mm²', 50, 'meters']]);
});

it('keeps decimal quantities and the units as written', function () {
    $json = plExtract(UploadedFile::fake()->createWithContent('l.csv', "Description,Qty,Unit\nTHHN wire,2.5,Meters\nConduit,3,lengths\nTape,\"1,000\",rolls\n"))->json();

    expect($json['items'][0])->toMatchArray(['quantity' => 2.5, 'unit' => 'Meters'])
        ->and($json['items'][2])->toMatchArray(['quantity' => 1000, 'unit' => 'rolls']);
});

// ============================================================ 3. PDFs

it('reads a printed PDF with full headers from its text layer', function () {
    plNeedsRealOcr(pdf: true);

    $json = plExtract(plTablePdf([[
        ['ABC Builders - Material Request'],
        ['Description', 'Quantity', 'Unit'],
        ['PVC Pipe 1/2 inch', '20', 'pcs'],
        ['Electrical Wire 2.0mm²', '50', 'meters'],
        ['Circuit Breaker 20A', '5', 'pcs'],
        ['Prepared by: Juan Dela Cruz'],
    ]]))->assertOk()->json();

    expect($json['method'])->toBe('pdf-text')
        ->and(plRows($json))->toBe([['PVC Pipe 1/2 inch', 20, 'pcs'], ['Electrical Wire 2.0mm²', 50, 'meters'], ['Circuit Breaker 20A', 5, 'pcs']])
        ->and(collect($json['items'])->pluck('reliable')->unique()->all())->toBe([true]);
});

it('reads products across several PDF pages, with or without the header repeated', function () {
    plNeedsRealOcr(pdf: true);

    $json = plExtract(plTablePdf([
        [['DESC', 'QTY', 'UOM'], ['LED Bulb 12W', '10', 'pcs'], ['Electrical Tape', '15', 'rolls'], ['Page 1 of 3']],
        [['DESC', 'QTY', 'UOM'], ['Junction Box', '8', 'pcs'], ['Page 2 of 3']],
        [['Copper Wire 3.5mm²', '100', 'meters'], ['Page 3 of 3']],
    ]))->assertOk()->json();

    expect(plRows($json))->toBe([
        ['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Junction Box', 8, 'pcs'], ['Copper Wire 3.5mm²', 100, 'meters'],
    ]);
});

it('reads a numbered list without a table, and asks for what is missing', function () {
    plNeedsRealOcr(pdf: true);

    $json = plExtract(plTablePdf([[
        ['Materials for Site B'],
        ['1. LED Bulb 12W - 10 pcs'],
        ['2. Electrical Tape 15 rolls'],
        ['3. Junction Box'],
    ]]))->assertOk()->json();

    expect(plRows($json))->toBe([['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Junction Box', null, null]])
        ->and($json['items'][2]['errors'])->toBe(['quantity' => 'Quantity is missing.'])
        // Free text is never "verified": the client confirms it.
        ->and(collect($json['items'])->pluck('reliable')->unique()->all())->toBe([false]);
});

it('reads a scanned PDF with abbreviated headers (real OCR)', function () {
    plNeedsRealOcr(pdf: true);
    $page = plDrawTable([['DESC', 'QTY', 'UOM'], ['LED Bulb 12W', '10', 'pcs'], ['Electrical Tape', '15', 'rolls'], ['Junction Box', '8', 'pcs']], base_path(PL_TYPED_FONT), 'jpg');

    $json = plExtract(plScannedPdf([$page]))->assertOk()->json();

    expect($json['method'])->toBe('pdf-ocr')
        ->and($json['ocr'])->toBeTrue()
        ->and(plRows($json))->toBe([['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Junction Box', 8, 'pcs']])
        // OCR is never presented as verified.
        ->and(collect($json['items'])->pluck('reliable')->unique()->all())->toBe([false]);
});

it('reads a handwritten-style scanned PDF over two pages (real OCR)', function () {
    plNeedsRealOcr(pdf: true);
    $font = plFont('segoepr.ttf');
    $pages = [
        plDrawTable([['Description', 'Quantity', 'Unit'], ['Electrical Tape', '15', 'rolls'], ['PVC Elbow', '25', 'pcs']], $font, 'jpg'),
        plDrawTable([['Description', 'Quantity', 'Unit'], ['Junction Box', '8', 'pcs']], $font, 'jpg'),
    ];

    $json = plExtract(plScannedPdf($pages))->assertOk()->json();

    expect(plRows($json))->toBe([['Electrical Tape', 15, 'rolls'], ['PVC Elbow', 25, 'pcs'], ['Junction Box', 8, 'pcs']]);
});

// ============================================================ 4. images

it('reads a handwritten-style PNG list (real OCR), flagging what it could not read', function () {
    plNeedsRealOcr();
    $path = plDrawTable([['Description', 'Quantity', 'Unit'], ['LED Bulb 12W', '10', 'pcs'], ['Electrical Tape', '15', 'rolls'], ['Junction Box', '8', 'pcs'], ['PVC Elbow', '25', 'pcs']], plFont('Inkfree.ttf'));

    $json = plExtract(new UploadedFile($path, 'handwritten.png', null, null, true))->assertOk()->json();
    $rows = collect($json['items']);

    expect($json['method'])->toBe('ocr')->and($rows)->toHaveCount(4);
    // Every description and quantity is either read correctly, or flagged — never silently guessed.
    // (The unit is optional and kept as read; staff verify units.)
    $expected = [['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Junction Box', 8, 'pcs'], ['PVC Elbow', 25, 'pcs']];
    foreach ($json['items'] as $i => $item) {
        foreach (['description' => 0, 'quantity' => 1] as $field => $k) {
            $flagged = isset($item['errors'][$field]) || isset($item['checks'][$field]);
            if (! $flagged && $field !== 'description') {
                expect($item[$field])->toEqual($expected[$i][$k]);
            }
        }
        expect($item['reliable'])->toBeFalse();
    }
    // Each row keeps its own values: no quantity moved to another product.
    expect($rows->pluck('description')->map(fn ($d) => strtok((string) $d, ' '))->all())->toBe(['LED', 'Electrical', 'Junction', 'PVC']);
});

it('reads a handwritten-style JPEG list (real OCR)', function () {
    plNeedsRealOcr();
    $path = plDrawTable([['Item', 'Qty', 'U/M'], ['Electrical Tape', '15', 'rolls'], ['Junction Box', '8', 'pcs'], ['PVC Elbow', '25', 'pcs']], plFont('segoepr.ttf'), 'jpg');

    $json = plExtract(new UploadedFile($path, 'handwritten.jpg', null, null, true))->assertOk()->json();

    expect(plRows($json))->toBe([['Electrical Tape', 15, 'rolls'], ['Junction Box', 8, 'pcs'], ['PVC Elbow', 25, 'pcs']]);
});

it('reads a 48-megapixel phone-sized photo of a list (real OCR)', function () {
    plNeedsRealOcr();
    $font = base_path(PL_TYPED_FONT);
    $img = imagecreatetruecolor(8000, 6000); // 48 MP, like a modern phone camera; the list fills the frame
    imagefill($img, 0, 0, imagecolorallocate($img, 250, 250, 245));
    $ink = imagecolorallocate($img, 20, 20, 60);
    foreach ([['Description', 'Qty', 'Unit'], ['LED Bulb 12W', '10', 'pcs'], ['Electrical Tape', '15', 'rolls'], ['Junction Box', '8', 'pcs']] as $r => $cells) {
        foreach ($cells as $c => $text) {
            imagettftext($img, 170, 0, [300, 4600, 6100][$c], 900 + $r * 1100, $ink, $font, $text);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'pl').'.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);

    $json = plExtract(new UploadedFile($path, 'phone-photo.jpg', null, null, true))->assertOk()->json();

    expect(plRows($json))->toBe([['LED Bulb 12W', 10, 'pcs'], ['Electrical Tape', 15, 'rolls'], ['Junction Box', 8, 'pcs']]);
});

it('flags handwritten entries that could not be read clearly instead of guessing', function () {
    plFakeOcr([
        'Description         Quantity   Unit',
        'LED Bulb 12W        10         pcs',
        '~Elctrcl ~Tpe       ~1S        rolls',
        'Junction Box        1O         pcs',
        'PVC Elbow           25         ~pc5',
    ]);

    $json = plExtract(UploadedFile::fake()->image('list.png', 300, 400))->assertOk()->json();
    $items = $json['items'];

    expect($json['message'])->toBe('Some handwritten or scanned entries could not be read clearly. Please review the highlighted fields.')
        ->and($items[0]['errors'] + $items[0]['checks'])->toBe([])
        ->and($items[1]['checks'])->toHaveKey('description')
        ->and($items[1]['quantity'])->toBeNull() // "1S" is not a number: left empty, never guessed
        ->and($items[1]['quantity_raw'])->toBe('1S')
        ->and($items[1]['errors'])->toHaveKey('quantity')
        ->and($items[2]['quantity'])->toEqual(10) // "1O": the letter O read as 0, taken as 10 but flagged
        ->and($items[2]['checks']['quantity'])->toContain('Read as "1O"')
        // A doubtful unit is not flagged for the client: it is optional, kept as read for staff to verify.
        ->and($items[3]['unit'])->toBe('pc5')
        ->and($items[3]['errors'] + $items[3]['checks'])->toBe([]);
});

it('adds lone numbers a second OCR pass found where the first read nothing, flagged for review', function () {
    $head = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
    $first = $head
        ."5\t1\t1\t1\t1\t1\t100\t300\t200\t40\t96\tJunction\n"
        ."5\t1\t1\t1\t1\t2\t320\t300\t90\t40\t96\tBox\n"
        ."5\t1\t1\t1\t2\t1\t1600\t420\t40\t40\t95\t25\n";
    $second = $head
        ."5\t1\t1\t1\t1\t1\t1600\t300\t20\t40\t76\t8\n"     // missed by the first pass: added
        ."5\t1\t1\t1\t1\t2\t1600\t420\t40\t40\t66\t2g\n"    // not a plain number
        ."5\t1\t1\t1\t1\t3\t1610\t425\t30\t30\t90\t26\n"    // where the first pass read "25": ignored
        ."5\t1\t1\t1\t1\t4\t900\t300\t20\t40\t40\t3\n"      // too unsure
        ."5\t1\t1\t1\t1\t5\t1000\t160\t20\t40\t88\t=\n";    // not a number

    $merged = OcrEngine::addMissedNumbers($first, $second);
    $added = array_slice(explode("\n", trim($merged)), 4);

    expect($added)->toHaveCount(1)
        ->and(explode("\t", $added[0]))->toBe(['5', '1', '9001', '1', '1', '1', '1600', '300', '20', '40', (string) OcrEngine::ADDED_NUMBER_CONFIDENCE, '8'])
        ->and(OcrEngine::addMissedNumbers($first, $head))->toBe($first);
});

it('refuses product photos and blurry images that hold no written list', function () {
    plFakeOcr(['~xx', 'Schneider']);

    plExtract(UploadedFile::fake()->image('breaker.jpg', 300, 400))
        ->assertStatus(422)
        ->assertJson(['reason' => 'no_text', 'keep_file' => false])
        ->assertJsonPath('message', ProductListExtractor::NO_WRITING);
});

// ============================================================ 5. missing headers, invalid files, failures

it('asks the client to choose the columns when the headers are missing, then reads them', function () {
    $file = fn () => UploadedFile::fake()->createWithContent('list.csv', "Thing,How many,Kind\nLED Bulb 12W,10,pcs\nTape,15,rolls\n");

    $json = plExtract($file())->assertOk()->json();
    expect($json['status'])->toBe('needs_mapping')
        ->and($json['message'])->toStartWith('Unable to identify the required headers. Please include Description and Quantity.')
        ->and($json['items'])->toBe([])
        ->and($json['choice']['preview'][1])->toBe(['LED Bulb 12W', '10', 'pcs']);

    $mapped = plExtract($file(), ['mapping' => ['description' => 0, 'quantity' => 1, 'unit' => 2], 'header_row' => 0])->assertOk()->json();
    expect($mapped['status'])->toBe('ok')
        ->and(plRows($mapped))->toBe([['LED Bulb 12W', 10, 'pcs'], ['Tape', 15, 'rolls']]);

    plExtract($file(), ['mapping' => ['description' => 0, 'quantity' => 0]])->assertStatus(422)
        ->assertJsonPath('message', 'Please choose a different column for each.');
});

it('reads lists with only Description and Quantity: the unit is left empty, not guessed', function (Closure $file) {
    $json = plExtract($file())->assertOk()->json();

    expect($json['status'])->toBe('ok')
        ->and(plRows($json))->toBe([['LED Bulb 12W', 10, null], ['Electrical Wire 2.0mm²', 50, null], ['PVC Pipe 1/2 inch', 20, null]])
        ->and(collect($json['items'])->every(fn ($i) => $i['errors'] === [] && $i['checks'] === []))->toBeTrue()
        ->and($json['message'])->toBeNull()
        ->and($json['notes'])->toBe([]);
})->with([
    'CSV' => fn () => UploadedFile::fake()->createWithContent('two.csv', "Description,Quantity\nLED Bulb 12W,10\nElectrical Wire 2.0mm²,50\nPVC Pipe 1/2 inch,20\n"),
    'Excel with abbreviations' => fn () => plXlsx(['List' => [['DESC', 'QTY'], ['LED Bulb 12W', 10], ['Electrical Wire 2.0mm²', 50], ['PVC Pipe 1/2 inch', 20]]]),
    'CSV with ITEM / QNTY' => fn () => UploadedFile::fake()->createWithContent('two.csv', "ITEM,QNTY\nLED Bulb 12W,10\nElectrical Wire 2.0mm²,50\nPVC Pipe 1/2 inch,20\n"),
    'CSV with PRODUCT / QUANT' => fn () => UploadedFile::fake()->createWithContent('two.csv', "Product;Quant\nLED Bulb 12W;10\nElectrical Wire 2.0mm²;50\nPVC Pipe 1/2 inch;20\n"),
]);

it('reads a two-column printed PDF (text layer)', function () {
    plNeedsRealOcr(pdf: true);

    $json = plExtract(plTablePdf([[['Description', 'Quantity'], ['LED Bulb 12W', '10'], ['PVC Pipe 1/2 inch', '20']]]))->assertOk()->json();

    expect(plRows($json))->toBe([['LED Bulb 12W', 10, null], ['PVC Pipe 1/2 inch', 20, null]]);
});

it('reads a two-column handwritten-style image (real OCR)', function () {
    plNeedsRealOcr();
    $path = plDrawTable([['Description', 'Qty'], ['Electrical Tape', '15'], ['Junction Box', '8'], ['PVC Elbow', '25']], plFont('segoepr.ttf'), 'jpg', [80, 1100]);

    $json = plExtract(new UploadedFile($path, 'handwritten.jpg', null, null, true))->assertOk()->json();

    expect(plRows($json))->toBe([['Electrical Tape', 15, null], ['Junction Box', 8, null], ['PVC Elbow', 25, null]]);
});

it('keeps an optional Unit column exactly as written, without checking or converting it', function () {
    $csv = "Description,Qty,Unit\nLED Bulb,10,pcs\nTHHN wire,2.5,Meters\nNut,4,blorps\nTape,15,\nPipe,5 pcs,box\nBolt,7 pcs,\nWasher,3,10\n";

    $items = collect(plExtract(UploadedFile::fake()->createWithContent('l.csv', $csv))->json('items'))->keyBy('description');

    expect($items->map(fn ($i) => $i['unit'])->all())->toBe([
        'LED Bulb' => 'pcs',
        'THHN wire' => 'Meters',  // not converted or lower-cased
        'Nut' => 'blorps',        // unknown units are kept for staff to verify
        'Tape' => null,           // empty: left empty
        'Pipe' => 'box',          // the Unit column wins over "5 pcs"; staff verify
        'Bolt' => 'pcs',          // written with the quantity in the document
        'Washer' => null,         // "10" is not a unit: left out rather than guessed
    ])
        ->and($items->every(fn ($i) => ! isset($i['errors']['unit']) && ! isset($i['checks']['unit'])))->toBeTrue();
});

it('flags invalid quantities and formulas without changing them', function () {
    $csv = "Description,Qty,Unit\nLED,0,pcs\nTape,-3,rolls\nWire,\"2,5\",m\nBolt,ten,pcs\n=HYPERLINK(\"http://x\"),1,pcs\n";

    $items = plExtract(UploadedFile::fake()->createWithContent('l.csv', $csv))->json('items');
    $by = collect($items)->keyBy('description');

    expect($by['LED']['errors']['quantity'])->toBe('Quantity must be greater than zero.')
        ->and($by['Tape']['errors']['quantity'])->toBe('Quantity must be greater than zero.')
        ->and($by['Wire']['errors']['quantity'])->toBe('"2,5" could mean 2.5 or 25. Please enter the quantity.')
        ->and($by['Bolt']['errors']['quantity'])->toBe('"ten" is not a valid quantity. Please enter a number.')
        ->and($by['=HYPERLINK("http://x")']['errors']['description'])->toContain('spreadsheet formula');
});

it('rejects unsupported, disguised, damaged, protected and empty files with clear messages', function (Closure $file, string $reason, string $message) {
    plExtract($file())->assertStatus(422)
        ->assertJson(['reason' => $reason, 'keep_file' => false])
        ->assertJsonPath('message', $message);
})->with([
    'executable renamed to .pdf' => [fn () => UploadedFile::fake()->createWithContent('list.pdf', "MZ\x90\x00\x03\x00\x00\x00PE\0\0"), 'unsupported', 'This file is not a document or spreadsheet. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.'],
    'PHP script renamed to .csv' => [fn () => UploadedFile::fake()->createWithContent('list.csv', "<?php system(\$_GET['c']);"), 'unsupported', 'This file is not a document or spreadsheet. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.'],
    'shell script renamed to .csv' => [fn () => UploadedFile::fake()->createWithContent('list.csv', "#!/bin/sh\nrm -rf /\n"), 'unsupported', 'This file is not a document or spreadsheet. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.'],
    'unsupported type' => [fn () => UploadedFile::fake()->createWithContent('list.docx', 'PK'), 'invalid', 'This file type is not supported. Please upload a PDF, PNG, JPG, JPEG, XLS, XLSX or CSV file.'],
    'damaged Excel' => [fn () => UploadedFile::fake()->createWithContent('list.xlsx', "PK\x03\x04garbage"), 'corrupted', "This file doesn't look like a valid .xlsx file. It may be damaged, or saved in a different format."],
    'password-protected Excel' => [fn () => UploadedFile::fake()->createWithContent('list.xlsx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 600)), 'protected', 'This Excel file is password-protected. Please remove the password and upload it again.'],
    'password-protected PDF' => [fn () => plTablePdf([[['Description', 'Qty', 'Unit']]], name: 'locked.pdf', encrypted: true), 'protected', 'This PDF is password-protected. Please remove the password and upload it again.'],
    'empty spreadsheet' => [fn () => plXlsx(['Sheet1' => [[null]]]), 'empty', 'This spreadsheet is empty.'],
    'empty CSV' => [fn () => UploadedFile::fake()->createWithContent('list.csv', ",,\n,,\n"), 'empty', 'This CSV file is empty.'],
    'over 25 MB' => [fn () => UploadedFile::fake()->create('big.pdf', 25 * 1024 + 1, 'application/pdf'), 'invalid', 'File size exceeds the 25 MB limit. Please upload a smaller file.'],
]);

it('accepts a file of exactly 25 MB and reads a large spreadsheet without running out of memory', function () {
    // Exactly 25 MB is allowed: this damaged PDF fails the content check, not the size check.
    plExtract(UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4'.str_repeat("\0", 25 * 1024 * 1024 - 8)))
        ->assertStatus(422)->assertJson(['reason' => 'corrupted']);

    // A CSV of about 24 MB is read (the first rows are what the client reviews).
    $row = 'LED Bulb 12W warm white E27 '.str_repeat('x', 60).",10,pcs\n";
    $csv = "Description,Qty,Unit\n".str_repeat($row, intdiv(24 * 1024 * 1024, strlen($row)));
    $json = plExtract(UploadedFile::fake()->createWithContent('big.csv', $csv))->assertOk()->json();

    expect($json['status'])->toBe('ok')
        ->and($json['items'])->toHaveCount(ProductListParser::MAX_ITEMS)
        ->and($json['items'][0]['quantity'])->toEqual(10);

    // A workbook with 60,000 rows: only the rows that are read are loaded into memory.
    $rows = [['Description', 'Quantity', 'Unit']];
    for ($i = 1; $i <= 60000; $i++) {
        $rows[] = ["Cable tie {$i}00mm", $i % 50 + 1, 'packs'];
    }
    $xlsx = plXlsx(['List' => $rows], 'big.xlsx');
    $before = memory_get_usage();
    $json = plExtract($xlsx)->assertOk()->json();

    expect($json['items'][0])->toMatchArray(['description' => 'Cable tie 100mm', 'quantity' => 2, 'unit' => 'packs'])
        ->and(memory_get_usage() - $before)->toBeLessThan(64 * 1024 * 1024);
});

it('rejects PDFs with too many pages', function () {
    plExtract(plTablePdf(array_fill(0, 21, [['Description', 'Qty', 'Unit'], ['LED', '1', 'pcs']])))
        ->assertStatus(422)->assertJson(['reason' => 'too_many_pages', 'keep_file' => false]);
});

it('keeps the file when OCR is unavailable or times out, with a helpful message', function () {
    app()->instance(OcrEngine::class, new class extends OcrEngine
    {
        public function imageToTsv(string $imagePath): string
        {
            throw new ExtractionException('Text recognition took too long or failed. Please type your material list below.', 'ocr_failed');
        }
    });

    plExtract(UploadedFile::fake()->image('list.png', 300, 400))
        ->assertStatus(422)
        ->assertJson(['reason' => 'ocr_failed', 'keep_file' => true])
        ->assertJsonPath('message', 'Reading this file took too long or failed. Please try a clearer or smaller file. You can also send it as it is and our team will read it.');
});

it('never shows technical details when processing fails on the server', function () {
    app()->instance(ProductListExtractor::class, new class extends ProductListExtractor
    {
        public function __construct() {}

        public function extract(string $path, string $originalName, ?array $mapping = null, ?int $headerRow = null, ?string $sheet = null): array
        {
            throw new RuntimeException('SQLSTATE[HY000] secret stack details at /var/www/app.php:12');
        }
    });

    $response = plExtract(UploadedFile::fake()->createWithContent('l.csv', "Description,Qty,Unit\nLED,1,pcs\n"))->assertStatus(500);

    expect($response->json())->toBe(['message' => 'Unable to process this file. Please check the document and try again.', 'reason' => 'server_error', 'keep_file' => true])
        ->and($response->getContent())->not->toContain('SQLSTATE')->not->toContain('app.php');
});

// ============================================================ 6. duplicates

it('reads the same file once, even when it is sent twice', function () {
    $ocr = plFakeOcr(['Description   Qty   Unit', 'LED Bulb      10    pcs']);
    $image = UploadedFile::fake()->image('list.png', 300, 400);
    $copy = new UploadedFile($image->getPathname(), 'list-copy.png', null, null, true);

    $first = plExtract($image)->assertOk()->json();
    $second = plExtract($copy)->assertOk()->json();

    expect($ocr->calls)->toBe(1)
        ->and($second['file']['hash'])->toBe($first['file']['hash'])
        ->and(plRows($second))->toBe(plRows($first));
});

it('points out repeated rows without removing them', function () {
    $json = plExtract(UploadedFile::fake()->createWithContent('l.csv', "Description,Qty,Unit\nLED Bulb,10,pcs\nTape,2,rolls\nled bulb,10,PCS\n"))->json();

    expect($json['items'])->toHaveCount(3)
        ->and($json['items'][2]['duplicate_of'])->toBe('Row 2');
});

// ============================================================ 7. submitting the confirmed products

function plRequest(array $overrides = []): array
{
    return array_merge([
        'company' => 'Acme Builders', 'contactName' => 'Juan Dela Cruz', 'email' => 'juan@acme.test', 'phone' => '09171234567',
        'projectType' => 'Industrial Facility', 'timeline' => '1-3 months',
    ], $overrides);
}

it('saves the products the client confirmed, exactly as confirmed, with the file', function () {
    $csv = "Description,Qty,Unit\nLED Bulb 12W,10,pcs\nTHHN wire,2.5,meters\n";
    plExtract(UploadedFile::fake()->createWithContent('list.csv', $csv))->assertOk();

    test()->post('/quotation', plRequest([
        'productFiles' => [UploadedFile::fake()->createWithContent('list.csv', $csv)],
        'products' => [
            ['description' => 'LED Bulb 12W (warm white)', 'quantity' => '12', 'unit' => 'pcs', 'source' => 'list.csv'],
            ['description' => 'THHN wire', 'quantity' => '2.5', 'unit' => 'meters', 'source' => 'list.csv'],
            ['description' => 'Junction box', 'quantity' => '3', 'unit' => null, 'source' => null], // added by the client: no unit
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    expect($quotation->request_details['product_list'])->toBe([
        ['description' => 'LED Bulb 12W (warm white)', 'quantity' => 12, 'unit' => 'pcs', 'source' => 'list.csv'],
        ['description' => 'THHN wire', 'quantity' => 2.5, 'unit' => 'meters', 'source' => 'list.csv'],
        ['description' => 'Junction box', 'quantity' => 3, 'unit' => null, 'source' => null],
    ])
        ->and($quotation->items->map(fn ($i) => [$i->sku, $i->product_name, $i->quantity, (float) $i->unit_price])->all())->toBe([
            ['CLIENT-LIST', 'LED Bulb 12W (warm white) (12 pcs)', 12, 0.0],
            ['CLIENT-LIST', 'THHN wire (2.5 meters)', 1, 0.0],
            ['CLIENT-LIST', 'Junction box (3)', 3, 0.0],
        ])
        ->and($quotation->files)->toHaveCount(1)
        ->and($quotation->notes)->toContain("--- Products from the client's list (confirmed by the client) ---")
        ->and($quotation->notes)->toContain('• THHN wire: 2.5 meters (list.csv)')
        ->and($quotation->notes)->toContain('• Junction box: 3 (unit not given)');
});

it('refuses products with unresolved required fields', function (array $product, string $key, string $message) {
    test()->post('/quotation', plRequest(['products' => [['description' => 'Tape', 'quantity' => 1, 'unit' => 'rolls'], $product]]))
        ->assertSessionHasErrors([$key => $message]);

    expect(Quotation::count())->toBe(0);
})->with([
    'missing description' => [['description' => '', 'quantity' => 2, 'unit' => 'pcs'], 'products.1.description', 'Product 2: the description is missing.'],
    'missing quantity' => [['description' => 'LED', 'quantity' => '', 'unit' => 'pcs'], 'products.1.quantity', 'Product 2: the quantity is missing.'],
    'zero quantity' => [['description' => 'LED', 'quantity' => 0, 'unit' => 'pcs'], 'products.1.quantity', 'Product 2: the quantity must be greater than zero.'],
    'too many decimals' => [['description' => 'LED', 'quantity' => '1.2345', 'unit' => 'pcs'], 'products.1.quantity', 'Product 2: use at most 3 decimal places for the quantity.'],
    'number as unit' => [['description' => 'LED', 'quantity' => 2, 'unit' => '10'], 'products.1.unit', 'Product 2: the unit must be a unit name such as pcs, m or box.'],
    'formula' => [['description' => '=cmd|calc', 'quantity' => 2, 'unit' => 'pcs'], 'products.1.description', 'Product 2: the description looks like a spreadsheet formula. Please type the product name.'],
]);

it('does not read a list again when the request is sent', function () {
    $ocr = plFakeOcr(['Description   Qty   Unit', 'LED Bulb      10    pcs', 'Tape          15    rolls']);
    $image = UploadedFile::fake()->image('list.png', 300, 400);
    plExtract($image)->assertOk();

    test()->post('/quotation', plRequest([
        'productFiles' => [new UploadedFile($image->getPathname(), 'list.png', null, null, true)],
        'products' => [['description' => 'LED Bulb', 'quantity' => 10, 'unit' => 'pcs', 'source' => 'list.png']],
    ]))->assertSessionHasNoErrors();

    expect($ocr->calls)->toBe(1)->and(Quotation::sole()->files)->toHaveCount(1);
});

// ============================================================ 8. reading lists for staff (no client review)

it('reads the uploaded lists when the request is sent and keeps the rows for staff, without client confirmation', function () {
    test()->post('/quotation', plRequest([
        'productFiles' => [
            UploadedFile::fake()->createWithContent('list.csv', "Description,Qty,Unit\nLED Bulb 12W,10,pcs\nTHHN wire,2.5,meters\nJunction box,,pcs\n"),
            UploadedFile::fake()->createWithContent('two-columns.csv', "Item,Quantity\nPVC Elbow,25\n"),
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    expect($quotation->files)->toHaveCount(2)
        ->and($quotation->request_details['product_list'])->toBeNull() // nothing confirmed by the client
        ->and($quotation->request_details['extracted_products'])->toBe([
            ['file' => 'list.csv', 'status' => 'read', 'method' => 'csv', 'message' => null, 'rows' => [
                ['ref' => 'Row 2', 'description' => 'LED Bulb 12W', 'quantity' => 10, 'unit' => 'pcs', 'issues' => []],
                ['ref' => 'Row 3', 'description' => 'THHN wire', 'quantity' => 2.5, 'unit' => 'meters', 'issues' => []],
                ['ref' => 'Row 4', 'description' => 'Junction box', 'quantity' => null, 'unit' => 'pcs', 'issues' => ['Quantity: Quantity is missing.']],
            ]],
            ['file' => 'two-columns.csv', 'status' => 'read', 'method' => 'csv', 'message' => null, 'rows' => [
                ['ref' => 'Row 2', 'description' => 'PVC Elbow', 'quantity' => 25, 'unit' => null, 'issues' => []],
            ]],
        ])
        // Rows with a description and a quantity become unpriced lines for staff to verify.
        ->and($quotation->items->map(fn ($i) => [$i->sku, $i->product_name, $i->quantity, (float) $i->unit_price])->all())->toBe([
            ['CLIENT-LIST', 'LED Bulb 12W (10 pcs)', 10, 0.0],
            ['CLIENT-LIST', 'THHN wire (2.5 meters)', 1, 0.0],
            ['CLIENT-LIST', 'PVC Elbow (25)', 25, 0.0],
        ])
        ->and($quotation->notes)->toContain('--- Products read from the uploaded lists (automatic, not checked by the client) ---')
        ->and($quotation->notes)->toContain('• Junction box: ? pcs [check]');
});

it('keeps doubtful OCR values for staff, marked for checking', function () {
    plFakeOcr([
        'Description         Quantity',
        'LED Bulb 12W        10',
        'Junction Box        1O',
    ]);

    test()->post('/quotation', plRequest(['productFiles' => [UploadedFile::fake()->image('handwritten.png', 300, 400)]]))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    $rows = $quotation->request_details['extracted_products'][0]['rows'];
    expect($quotation->request_details['extracted_products'][0]['method'])->toBe('ocr')
        ->and($rows[1]['quantity'])->toBe(10)
        ->and($rows[1]['issues'][0])->toContain('Read as "1O"')
        ->and($quotation->items->pluck('product_name')->all())->toBe(['LED Bulb 12W (10)', 'Junction Box (10) [check]']);
});

it('never stops a request because a list could not be read', function (Closure $setup, UploadedFile $file, string $status, string $message) {
    $setup();

    test()->post('/quotation', plRequest(['productFiles' => [$file]]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    expect($quotation->files)->toHaveCount(1)
        ->and($quotation->items)->toHaveCount(0)
        ->and($quotation->request_details['extracted_products'][0])->toMatchArray(['status' => $status, 'message' => $message, 'rows' => []]);
})->with([
    'columns not found' => [fn () => null, fn () => UploadedFile::fake()->createWithContent('odd.csv', "Thing,How many\nLED Bulb,10\nTape,4\n"), 'columns_not_found', 'The Description and Quantity columns were not found automatically. Please open the file.'],
    'OCR not installed' => [fn () => fakeImageOcr(available: false), fn () => UploadedFile::fake()->image('list.jpg', 300, 400), 'not_read', 'Not read automatically: text recognition (OCR) is not available on the server. Please open the file.'],
]);

it('reads the same file sent twice only once', function () {
    $csv = "Description,Qty\nLED Bulb,10\n";
    test()->post('/quotation', plRequest(['productFiles' => [
        UploadedFile::fake()->createWithContent('a.csv', $csv),
        UploadedFile::fake()->createWithContent('a-copy.csv', $csv),
    ]]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    expect($quotation->files)->toHaveCount(1)
        ->and($quotation->request_details['extracted_products'])->toHaveCount(1)
        ->and($quotation->items)->toHaveCount(1);
});

it('shows quotation staff the rows read from the uploaded lists', function () {
    test()->post('/quotation', plRequest([
        'productFiles' => [UploadedFile::fake()->createWithContent('list.csv', "Description,Qty\nPVC Elbow,25\n")],
    ]))->assertSessionHasNoErrors();

    $team = Team::first();
    $staff = User::factory()->create(['role' => 'staff_quotations', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    test()->actingAs($staff)->withCookies(createTrustedDeviceFor($staff)['cookies'])
        ->get('/mr-pride/quotations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('quotations.data.0.request_details.extracted_products.0.file', 'list.csv')
            ->where('quotations.data.0.request_details.extracted_products.0.rows.0', ['ref' => 'Row 2', 'description' => 'PVC Elbow', 'quantity' => 25, 'unit' => null, 'issues' => []])
            ->where('quotations.data.0.files.0.original_name', 'list.csv')
        );
});

it('shows quotation staff the confirmed products in the request', function () {
    test()->post('/quotation', plRequest([
        'products' => [
            ['description' => 'THHN wire', 'quantity' => '2.5', 'unit' => 'meters', 'source' => 'list.csv'],
            ['description' => 'PVC Elbow', 'quantity' => '25', 'unit' => null, 'source' => 'two-columns.csv'],
        ],
    ]))->assertSessionHasNoErrors();

    $team = Team::first();
    $staff = User::factory()->create(['role' => 'staff_quotations', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    test()->actingAs($staff)->withCookies(createTrustedDeviceFor($staff)['cookies'])
        ->get('/mr-pride/quotations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotations/AdminIndex')
            ->where('quotations.data.0.request_details.product_list.0', ['description' => 'THHN wire', 'quantity' => 2.5, 'unit' => 'meters', 'source' => 'list.csv'])
            ->where('quotations.data.0.request_details.product_list.1', ['description' => 'PVC Elbow', 'quantity' => 25, 'unit' => null, 'source' => 'two-columns.csv'])
        );
});
