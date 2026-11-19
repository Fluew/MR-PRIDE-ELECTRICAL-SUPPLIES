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
    'unsupported type' => [fn () => UploadedFile::fake()->createWithContent('list.zip', 'PK'), 'invalid', 'This file type is not supported. Please upload a PDF, Word, Excel, PowerPoint, OpenDocument, text, CSV or picture file (JPG, PNG, TIFF, BMP, WEBP, GIF, HEIC).'],
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
        'company' => 'Acme Builders', 'contactName' => 'Juan Dela Cruz', 'email' => 'juan@acme.test', 'phoneCountry' => '+63', 'phone' => '9171234567',
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

// ============================================================ 9. Expanded file formats (public quotation request)
//
// Real Tesseract and Poppler are not needed here: documents are read directly, and the picture-conversion and OCR
// hand-off steps are checked with the fake OCR above. What these tests do NOT prove is Tesseract's own accuracy.

/** A ZIP file (Office/OpenDocument container) with the given [name => bytes] entries, in order, as an upload. */
function fxZip(array $entries, string $name, bool $storedFirst = false): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    foreach ($entries as $entry => $bytes) {
        $zip->addFromString($entry, $bytes);
        if ($storedFirst && $entry === array_key_first($entries)) {
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
    }
    $zip->close();

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

function fxEsc(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** @param  list<string|list<list<string>>>  $blocks  a string is a paragraph; an array of rows is a table */
function fxDocx(array $blocks, array $extra = [], string $name = 'list.docx'): UploadedFile
{
    $body = '';
    foreach ($blocks as $block) {
        if (is_string($block)) {
            $body .= '<w:p><w:r><w:t>'.fxEsc($block).'</w:t></w:r></w:p>';
        } else {
            $body .= '<w:tbl>';
            foreach ($block as $row) {
                $body .= '<w:tr>'.implode('', array_map(fn ($c) => '<w:tc><w:p><w:r><w:t>'.fxEsc($c).'</w:t></w:r></w:p></w:tc>', $row)).'</w:tr>';
            }
            $body .= '</w:tbl>';
        }
    }

    return fxZip([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>',
    ] + $extra, $name);
}

function fxPptx(array $slides, string $name = 'list.pptx'): UploadedFile
{
    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
    ];
    foreach ($slides as $i => $blocks) {
        $xml = '';
        foreach ($blocks as $block) {
            if (is_string($block)) {
                $xml .= '<a:p><a:r><a:t>'.fxEsc($block).'</a:t></a:r></a:p>';
            } else {
                $xml .= '<a:tbl>'.implode('', array_map(fn ($row) => '<a:tr>'.implode('', array_map(fn ($c) => '<a:tc><a:txBody><a:p><a:r><a:t>'.fxEsc($c).'</a:t></a:r></a:p></a:txBody></a:tc>', $row)).'</a:tr>', $block)).'</a:tbl>';
            }
        }
        $entries['ppt/slides/slide'.($i + 1).'.xml'] = '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'.$xml.'</p:sld>';
    }

    return fxZip($entries, $name);
}

function fxOdf(string $kind, string $content, string $name): UploadedFile
{
    return fxZip([
        'mimetype' => "application/vnd.oasis.opendocument.{$kind}",
        'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"><office:body>'.$content.'</office:body></office:document-content>',
    ], $name, storedFirst: true);
}

function fxOdt(array $blocks, string $name = 'list.odt'): UploadedFile
{
    $xml = '<office:text>';
    foreach ($blocks as $block) {
        if (is_string($block)) {
            $xml .= '<text:p>'.fxEsc($block).'</text:p>';
        } else {
            $xml .= '<table:table>'.implode('', array_map(fn ($row) => '<table:table-row>'.implode('', array_map(fn ($c) => '<table:table-cell><text:p>'.fxEsc($c).'</text:p></table:table-cell>', $row)).'</table:table-row>', $block)).'</table:table>';
        }
    }

    return fxOdf('text', $xml.'</office:text>', $name);
}

const FX_TABLE = [['Description', 'Qty', 'Unit'], ['LED Bulb 12W', '10', 'pcs'], ['THHN Wire 3.5mm', '2', 'roll']];
const FX_ROWS = [['LED Bulb 12W', 10, 'pcs'], ['THHN Wire 3.5mm', 2, 'roll']];

// ---- Word, PowerPoint and OpenDocument are read directly, and tables stay tables

it('reads a Word document\'s table row by row without mixing quantities, descriptions and units', function () {
    $json = plExtract(fxDocx(['Product list for the Tondo project', FX_TABLE]))->assertOk()->json();

    expect($json['status'])->toBe('ok')->and($json['method'])->toBe('docx')->and($json['ocr'])->toBeFalse()
        ->and(plRows($json))->toEqual(FX_ROWS);
});

it('reads a PowerPoint slide table and an OpenDocument text table the same way', function () {
    $pptx = plExtract(fxPptx([['Materials', FX_TABLE]]))->assertOk()->json();
    $odt = plExtract(fxOdt(['Materials needed', FX_TABLE]))->assertOk()->json();

    expect(plRows($pptx))->toEqual(FX_ROWS)->and($pptx['method'])->toBe('pptx')
        ->and(plRows($odt))->toEqual(FX_ROWS)->and($odt['method'])->toBe('odt');
});

it('reads an OpenDocument spreadsheet (ODS) as a spreadsheet', function () {
    $rows = '';
    foreach (FX_TABLE as $row) {
        $rows .= '<table:table-row>'.implode('', array_map(fn ($c) => '<table:table-cell office:value-type="string"><text:p>'.fxEsc($c).'</text:p></table:table-cell>', $row)).'</table:table-row>';
    }
    // The package files and namespaces a real spreadsheet program writes (PhpSpreadsheet's ODS reader needs them).
    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" office:version="1.2"';
    $ods = fxZip([
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'META-INF/manifest.xml' => '<?xml version="1.0"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"/>',
        'meta.xml' => '<?xml version="1.0"?><office:document-meta '.$ns.'/>',
        'settings.xml' => '<?xml version="1.0"?><office:document-settings '.$ns.'/>',
        'styles.xml' => '<?xml version="1.0"?><office:document-styles '.$ns.'/>',
        'content.xml' => '<?xml version="1.0"?><office:document-content '.$ns.'><office:body><office:spreadsheet><table:table table:name="Sheet1">'.$rows.'</table:table></office:spreadsheet></office:body></office:document-content>',
    ], 'list.ods', storedFirst: true);

    $json = plExtract($ods)->assertOk()->json();
    expect(plRows($json))->toEqual(FX_ROWS)->and($json['method'])->toBe('spreadsheet');
});

it('reads a Word document that is only text lines, keeping each line separate', function () {
    $json = plExtract(fxDocx(['10 pcs LED Bulb 12W', '2 roll THHN Wire 3.5mm']))->assertOk()->json();

    expect($json['status'])->toBe('ok')->and($json['method'])->toBe('text')
        ->and(array_column($json['items'], 'description'))->toEqual(['LED Bulb 12W', 'THHN Wire 3.5mm']);
});

// ---- Text formats

it('reads TXT, RTF, HTML (with a table) and SVG text without OCR', function () {
    $txt = plExtract(UploadedFile::fake()->createWithContent('list.txt', "10 pcs LED Bulb 12W\n2 roll THHN Wire 3.5mm\n"))->assertOk()->json();
    $rtf = plExtract(UploadedFile::fake()->createWithContent('list.rtf', '{\\rtf1\\ansi{\\fonttbl{\\f0 Arial;}}{\\*\\generator Word;}10 pcs LED Bulb 12W\\par 2 roll THHN Wire 3.5mm\\par}'))->assertOk()->json();
    $html = plExtract(UploadedFile::fake()->createWithContent('list.html', '<html><head><title>x</title><script>var a=1;</script></head><body><h1>Order</h1><table><tr><th>Description</th><th>Qty</th><th>Unit</th></tr><tr><td>LED Bulb 12W</td><td>10</td><td>pcs</td></tr><tr><td>THHN Wire 3.5mm</td><td>2</td><td>roll</td></tr></table></body></html>'))->assertOk()->json();
    $svg = plExtract(UploadedFile::fake()->createWithContent('list.svg', '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="10">10 pcs LED Bulb 12W</text><text x="0" y="30">2 roll THHN Wire 3.5mm</text></svg>'))->assertOk()->json();

    foreach ([$txt, $rtf, $svg] as $json) {
        expect(array_column($json['items'], 'description'))->toEqual(['LED Bulb 12W', 'THHN Wire 3.5mm'])->and($json['ocr'])->toBeFalse();
    }
    expect(plRows($html))->toEqual(FX_ROWS)->and($html['ocr'])->toBeFalse();
});

it('decodes RTF escapes, tables and skipped groups correctly', function () {
    $reader = new App\Services\MaterialList\DocumentReader;
    $path = tempnam(sys_get_temp_dir(), 'rtf');
    file_put_contents($path, '{\\rtf1\\ansi{\\fonttbl{\\f0 Arial;}}{\\colortbl;\\red0;}Caf\\\'e9 \\u8364? cable\\tab 10\\cell\\row \\{braces\\} \\par}');

    $read = $reader->read($path, 'rtf');
    expect($read['lines'][0])->toBe("Café € cable\t10")->and($read['lines'][1])->toBe('{braces}');
});

// ---- Pictures

it('converts PBM, PGM and PPM pictures to PNG for the OCR, keeping the pixels', function (string $name, string $bytes, int $w, int $h, int $expectedGray) {
    $file = UploadedFile::fake()->createWithContent($name, $bytes);
    $reader = new App\Services\MaterialList\DocumentReader;
    $path = $file->getRealPath();

    expect($reader->detect($path, pathinfo($name, PATHINFO_EXTENSION)))->toBe('image');
    $png = $reader->toPng($path);
    expect($png)->not->toBeNull()->and(is_file($png))->toBeTrue();
    $img = imagecreatefrompng($png);
    expect([imagesx($img), imagesy($img)])->toBe([$w, $h])
        ->and(imagecolorat($img, 0, 0) & 0xFF)->toBe($expectedGray);
    @unlink($png);
})->with([
    'P1 ASCII bitmap (1 = black)' => ['a.pbm', "P1\n# c\n2 1\n1 0\n", 2, 1, 0],
    'P4 binary bitmap' => ['b.pbm', "P4\n8 1\n".chr(0b10000000), 8, 1, 0],
    'P2 ASCII graymap' => ['c.pgm', "P2\n2 1\n255\n128 255\n", 2, 1, 128],
    'P5 binary graymap' => ['d.pgm', "P5\n2 1\n255\n".chr(64).chr(255), 2, 1, 64],
    'P3 ASCII pixmap' => ['e.ppm', "P3\n1 1\n255\n10 20 30\n", 1, 1, 30],
    'P6 binary pixmap' => ['f.ppm', "P6\n1 1\n255\n".chr(10).chr(20).chr(30), 1, 1, 30],
]);

it('hands a PGM picture to the OCR as a PNG and reads the words it returns', function () {
    $fake = plFakeOcr(['Description Qty Unit', 'LED Bulb 12W 10 pcs', 'THHN Wire 3.5mm 2 roll']);
    // A 4x3 gray picture; the fake OCR stands in for Tesseract.
    $json = plExtract(UploadedFile::fake()->createWithContent('scan.pgm', "P2\n4 3\n255\n".str_repeat("255 255 255 255\n", 3)))->assertOk()->json();

    expect($json['ocr'])->toBeTrue()->and($fake->calls)->toBeGreaterThan(0)
        ->and(plRows($json))->toEqual(FX_ROWS);
});

it('accepts GIF, WEBP and BMP pictures and BMP/WEBP/GIF reach the OCR, and says when a GIF has more than one frame', function () {
    $fake = plFakeOcr(['Description Qty Unit', 'LED Bulb 12W 10 pcs', 'THHN Wire 3.5mm 2 roll']);
    $img = imagecreatetruecolor(40, 20);
    imagefill($img, 0, 0, 0xFFFFFF);
    foreach (['gif' => fn () => imagegif($img), 'webp' => fn () => imagewebp($img), 'bmp' => fn () => imagebmp($img)] as $ext => $write) {
        ob_start();
        $write();
        $bytes = (string) ob_get_clean();
        $json = plExtract(UploadedFile::fake()->createWithContent("list.{$ext}", $bytes))->assertOk()->json();
        expect($json['ocr'])->toBeTrue("{$ext} was read through OCR")->and(plRows($json))->toEqual(FX_ROWS);
    }

    $two = "GIF89a\x01\x00\x01\x00\x00\x00\x00"."\x00\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C"."\x00\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C";
    $notes = (new App\Services\MaterialList\DocumentReader)->pictureNotes(tap(tempnam(sys_get_temp_dir(), 'gif'), fn ($p) => file_put_contents($p, $two)));
    expect($notes)->toHaveCount(1)->and($notes[0])->toContain('2 frames')->toContain('first frame');
});

it('validates TIFF by its signature and refuses HEIC when no converter is configured, with a clear message', function () {
    $reader = new App\Services\MaterialList\DocumentReader;
    $tiff = tap(tempnam(sys_get_temp_dir(), 'tif'), fn ($p) => file_put_contents($p, "II*\x00\x08\x00\x00\x00".str_repeat("\0", 64)));
    expect($reader->detect($tiff, 'tif'))->toBe('image')->and($reader->detect($tiff, 'tiff'))->toBe('image');

    config(['services.ocr.heif' => null]);
    plExtract(UploadedFile::fake()->createWithContent('photo.heic', "\x00\x00\x00\x18ftypheic".str_repeat("\0", 200)))
        ->assertStatus(422)->assertJson(['reason' => 'converter_unavailable'])
        ->assertJsonPath('message', 'HEIC photos cannot be read on this server. Please save the photo as JPG or PNG and upload that.');
});

it('refuses legacy .doc and .ppt with a clear message when LibreOffice is not configured', function (string $name) {
    config(['services.ocr.office' => null]);
    $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 600);

    plExtract(UploadedFile::fake()->createWithContent($name, $ole))
        ->assertStatus(422)->assertJson(['reason' => 'converter_unavailable', 'keep_file' => false]);
})->with(['old.doc', 'old.ppt']);

// ---- Pictures inside Word and PowerPoint documents are OCR'd, and marked for review

it('reads a picture pasted into a Word document with OCR and marks those rows as OCR', function () {
    $fake = plFakeOcr(['Description Qty Unit', 'LED Bulb 12W 10 pcs', 'THHN Wire 3.5mm 2 roll']);
    $img = imagecreatetruecolor(60, 40);
    imagefill($img, 0, 0, 0xFFFFFF);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();

    $json = plExtract(fxDocx(['Scanned list attached below'], ['word/media/image1.png' => $png.str_repeat("\0", 300)]))->assertOk()->json();

    expect($json['ocr'])->toBeTrue()->and($fake->calls)->toBe(1)
        ->and(plRows($json))->toEqual(FX_ROWS)
        ->and(implode(' ', $json['notes']))->toContain('pictures in this document')->toContain('check');
});

it('says so when pictures in a document cannot be read because OCR is not available, instead of ignoring them', function () {
    app()->instance(OcrEngine::class, new class extends OcrEngine
    {
        public function tesseractAvailable(): bool
        {
            return false;
        }
    });
    $img = imagecreatetruecolor(60, 40);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();

    $json = plExtract(fxDocx([FX_TABLE], ['word/media/image1.png' => $png.str_repeat("\0", 300)]))->assertOk()->json();

    expect(plRows($json))->toEqual(FX_ROWS)                                 // the typed table is still read
        ->and(implode(' ', $json['notes']))->toContain('NOT read');
});

// ---- Validation and security

it('validates each format by its content, not its extension', function (string $name, string $bytes) {
    plExtract(UploadedFile::fake()->createWithContent($name, $bytes))->assertStatus(422)->assertJson(['reason' => 'corrupted', 'keep_file' => false]);
})->with([
    'a PNG named .gif' => ['x.gif', "\x89PNG\r\n\x1A\n".str_repeat("\0", 100)],
    'text named .bmp' => ['x.bmp', 'hello, not a bitmap at all'],
    'a JPEG named .webp' => ['x.webp', "\xFF\xD8\xFF\xE0".str_repeat("\0", 100)],
    'text named .tif' => ['x.tif', 'not a tiff'],
    'text named .heic' => ['x.heic', 'not a heic photo'],
    'garbage named .docx' => ['x.docx', "PK\x03\x04garbage"],
    'a plain zip named .pptx' => ['x.pptx', "PK\x03\x04".str_repeat("\0", 60)],
    'a docx named .odt' => ['x.odt', "PK\x03\x04garbage"],
    'text named .rtf' => ['x.rtf', 'just words'],
    'binary named .txt' => ['x.txt', "\x00\x01\x02\x03binary\x00"],
    'text named .pgm' => ['x.pgm', 'P9 nonsense'],
    'text named .doc' => ['x.doc', 'plain text, not an OLE file'],
]);

it('refuses programs and server-side code whatever they are called', function (string $name, string $bytes) {
    plExtract(UploadedFile::fake()->createWithContent($name, $bytes))->assertStatus(422)->assertJson(['keep_file' => false]);
})->with([
    'a program named .docx' => ['x.docx', "MZ\x90\x00\x03\x00\x00\x00PE\0\0"],
    'a shell script named .txt' => ['x.txt', "#!/bin/sh\nrm -rf /\n"],
    'php named .html' => ['x.html', "<?php system(\$_GET['c']); ?>"],
    'php named .svg' => ['x.svg', "<?php echo 1;"],
    'an ELF binary named .rtf' => ['x.rtf', "\x7FELF\x02\x01\x01"],
]);

it('refuses a zip bomb, a zip with path tricks and an XML entity bomb', function () {
    $content = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
    $doc = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>';

    // Too many entries.
    $many = ['[Content_Types].xml' => $content, 'word/document.xml' => $doc];
    for ($i = 0; $i < App\Services\MaterialList\DocumentReader::MAX_ZIP_ENTRIES + 5; $i++) {
        $many["word/x{$i}.txt"] = 'a';
    }
    plExtract(fxZip($many, 'many.docx'))->assertStatus(422)->assertJson(['reason' => 'corrupted']);

    // An entry that climbs out of the folder.
    plExtract(fxZip(['[Content_Types].xml' => $content, 'word/document.xml' => $doc, '../../evil.php' => '<?php'], 'slip.docx'))->assertStatus(422)->assertJson(['reason' => 'corrupted']);

    // Declared entities ("billion laughs").
    $bomb = '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&b;</w:t></w:r></w:p></w:body></w:document>';
    plExtract(fxZip(['[Content_Types].xml' => $content, 'word/document.xml' => $bomb], 'bomb.docx'))->assertStatus(422)->assertJson(['reason' => 'corrupted']);
});

it('leaves no temporary files behind after reading documents, pictures and failures', function () {
    $tmp = storage_path('app/private/tmp');
    $before = is_dir($tmp) ? count(glob($tmp.'/*')) : 0;

    plFakeOcr(['Description Qty Unit', 'LED Bulb 12W 10 pcs']);
    plExtract(UploadedFile::fake()->createWithContent('a.pgm', "P2\n2 2\n255\n255 255\n255 255\n"));
    plExtract(UploadedFile::fake()->createWithContent('b.heic', "\x00\x00\x00\x18ftypheic".str_repeat("\0", 200)));
    plExtract(fxDocx([FX_TABLE]));
    plExtract(UploadedFile::fake()->createWithContent('c.docx', 'PK garbage'));

    expect(is_dir($tmp) ? count(glob($tmp.'/*')) : 0)->toBe($before);
});

it('keeps staff document imports on their original formats', function () {
    $reader = app(App\Services\MaterialList\FileReader::class);
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, 'plain text');

    // Only the public quotation request reads the wider set; the default reader (staff imports) still refuses it.
    expect(fn () => $reader->detectType($path, 'list.txt'))->toThrow(App\Services\MaterialList\ExtractionException::class)
        ->and($reader->forQuotationRequests()->detectType($path, 'list.txt'))->toBe('txt');
});

it('accepts the new formats when a quotation request is sent, and rejects ones that are not on the list', function () {
    test()->post('/quotation/product-list/extract', ['file' => UploadedFile::fake()->createWithContent('list.mp3', 'ID3')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJson(['reason' => 'invalid']);

    $this->get('/quotation')->assertInertia(fn ($page) => $page->where('productFileTypes', App\Services\MaterialList\FileReader::QUOTATION_EXTENSIONS));
});

// ---- Mixed PDFs: text pages from their text layer, scanned pages through OCR, in page order

/** Tesseract/pdftotext TSV for lines laid out on a fixed character grid (5 px per character, 30 px per line). */
function fxTsv(array $lines): string
{
    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n1\t1\t0\t0\t0\t0\t0\t0\t600\t300\t-1\t\n";
    foreach ($lines as $n => $line) {
        preg_match_all('/\S+/u', $line, $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as $i => [$word, $byte]) {
            $tsv .= implode("\t", [5, 1, 1, 1, $n + 1, $i + 1, $byte * 5, 20 + $n * 30, strlen($word) * 5, 12, 95, $word])."\n";
        }
    }

    return $tsv;
}

function fxRow(string $d, string $q, string $u): string
{
    return sprintf('%-30s%-10s%s', $d, $q, $u);
}

/** An OCR engine whose pages are all fake: text pages 1 and 3 from the "text layer", page 2 from "OCR". */
function fxMixedEngine(bool $canOcr): object
{
    $engine = new class($canOcr) extends OcrEngine
    {
        public array $ocrPages = [];

        public function __construct(private bool $canOcr) {}

        public function tesseractAvailable(): bool
        {
            return $this->canOcr;
        }

        public function pdfRendererAvailable(): bool
        {
            return $this->canOcr;
        }

        public function pdfTextAvailable(): bool
        {
            return true;
        }

        public function pdfTextTsvPage(string $pdfPath, int $page): ?string
        {
            return [
                1 => fxTsv([fxRow('Description', 'Qty', 'Unit'), fxRow('LED Bulb 12W', '10', 'pcs')]),
                3 => fxTsv([fxRow('THHN Wire 3.5mm', '2', 'roll')]),
            ][$page] ?? null;
        }

        public function pdfTextTsv(string $pdfPath): ?string
        {
            // All text pages at once (page 2 contributes nothing: it has no text layer).
            return fxTsv([fxRow('Description', 'Qty', 'Unit'), fxRow('LED Bulb 12W', '10', 'pcs'), fxRow('THHN Wire 3.5mm', '2', 'roll')]);
        }

        public function pdfPagesToTsv(string $pdfPath, array $pages): array
        {
            $this->ocrPages = $pages;

            return array_fill_keys($pages, fxTsv([fxRow('Circuit Breaker 20A', '5', 'pcs')]));
        }
    };
    app()->instance(OcrEngine::class, $engine);

    return $engine;
}

it('reads a PDF with text pages and a scanned page page by page, in order, and reports the scanned page', function () {
    $engine = fxMixedEngine(canOcr: true);
    $pdf = plTablePdf([
        [['Description', 'Qty', 'Unit'], ['LED Bulb 12W warm white', '10', 'pcs'], ['Extension cord 5m with surge protector', '4', 'pcs']],
        [],                                                         // page 2: no text layer (a scan)
        [['THHN Wire 3.5mm solid copper', '2', 'roll'], ['Junction box 4x4 galvanized', '12', 'pcs']],
    ]);

    $json = plExtract($pdf)->assertOk()->json();

    expect($engine->ocrPages)->toBe([2])                            // only the scanned page went through OCR
        ->and($json['method'])->toBe('pdf-ocr')->and($json['ocr'])->toBeTrue()
        ->and(array_column($json['items'], 'description'))->toBe(['LED Bulb 12W', 'Circuit Breaker 20A', 'THHN Wire 3.5mm'])   // page order kept
        ->and(implode(' ', $json['notes']))->toContain('Page 2')->toContain('OCR')->toContain('check');
});

it('still reads the text pages of a mixed PDF when OCR is not available, and says which pages were not read', function () {
    $engine = fxMixedEngine(canOcr: false);
    $pdf = plTablePdf([
        [['Description', 'Qty', 'Unit'], ['LED Bulb 12W warm white', '10', 'pcs'], ['Extension cord 5m with surge protector', '4', 'pcs']],
        [],
        [['THHN Wire 3.5mm solid copper', '2', 'roll'], ['Junction box 4x4 galvanized', '12', 'pcs']],
    ]);

    $json = plExtract($pdf)->assertOk()->json();

    expect($engine->ocrPages)->toBe([])                             // nothing was invented for the scanned page
        ->and(array_column($json['items'], 'description'))->toBe(['LED Bulb 12W', 'THHN Wire 3.5mm'])
        ->and(implode(' ', $json['notes']))->toContain('Page 2')->toContain('NOT read');
});
