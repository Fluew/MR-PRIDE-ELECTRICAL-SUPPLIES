<?php

use App\Enums\TeamRole;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\OcrEngine;
use App\Services\Procurement\PurchaseOrderReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Purchase order documents (PDF, PNG, JPG) read into the nine Purchase Order fields, shown for
 * checking, and saved only when confirmed. The test document recreates the layout of the
 * Surangel & Sons Construction purchase order: company heading, label boxes with values below
 * them (VENDOR CODE, ORDER DATE, PURCHASE ORDER NO., ...), VENDOR and SHIP TO boxes, the product
 * table, a TOTAL line, special instructions and a page number.
 *
 * Tests marked "real OCR" run Tesseract / Poppler and are skipped when they are not installed.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'po-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
});

const PO_ROWS = [
    ['2', '3P50A', 'MCB 3P, 50A IC60N SCHNEIDER', '62.00', 'PC', '124.00'],
    ['3', '3P32A', 'MCB 3P, 32A IC60N SCHNEIDER', '29.50', 'PC', '88.50'],
    ['1', '3P20A', 'MCB 3P, 20A IC60N SCHNEIDER', '28.29', 'PC', '28.29'],
    ['1', '2P32A', 'MCB 2P, 32A IC60N SCHNEIDER', '31.00', 'PC', '31.00'],
    ['1', '3P225A', 'MCB 3P, 225A EZC250F SCHNEIDER', '379.00', 'PC', '379.00'],
    ['1', 'NEMA1', 'PANEL BOARD NEMA 1', '2,200.00', 'PC', '2,200.00'],
];

/** The expected extraction for the reference document: [QUANTITY, ITEM/SKU, Description, UNIT COST, U/M, EXTENDED COST]. */
const PO_EXPECTED = [
    [2, '3P50A', 'MCB 3P, 50A IC60N SCHNEIDER', 62.0, 'PC', 124.0],
    [3, '3P32A', 'MCB 3P, 32A IC60N SCHNEIDER', 29.5, 'PC', 88.5],
    [1, '3P20A', 'MCB 3P, 20A IC60N SCHNEIDER', 28.29, 'PC', 28.29],
    [1, '2P32A', 'MCB 2P, 32A IC60N SCHNEIDER', 31.0, 'PC', 31.0],
    [1, '3P225A', 'MCB 3P, 225A EZC250F SCHNEIDER', 379.0, 'PC', 379.0],
    [1, 'NEMA1', 'PANEL BOARD NEMA 1', 2200.0, 'PC', 2200.0],
];

/**
 * The reference layout as [text, x, y (baseline), size] on a 2480 × 3300 px page, one list per
 * page. $pages splits the product rows over pages (the table header is repeated on each).
 */
function poLayout(array $rows = PO_ROWS, array $pages = [6], string $poNumber = '4000005769'): array
{
    $layout = [];
    $page = [
        ['SURANGEL & SONS CONSTRUCTION', 120, 160, 48], ['PURCHASE ORDER', 1650, 160, 40],
        ['Koror, Republic of Palau 96940   Tel: (680) 488-2621', 120, 230, 24], ['TIN: 000-123-456-000', 120, 280, 24],
    ];
    foreach ([['VENDOR CODE', 'V00123', 120], ['ASSIGNED CUSTOMER NUMBER', 'C-4471', 560], ['ORDER DATE', '1/13/23', 1180], ['PURCHASE ORDER NO.', $poNumber, 1560], ['PAGE', '1', 2150]] as [$l, $v, $x]) {
        $page[] = [$l, $x, 380, 20];
        $page[] = [$v, $x, 430, 26];
    }
    array_push($page, ['VENDOR:', 120, 540, 22], ['MR-PRIDE ELECTRICAL SUPPLIES', 120, 590, 26], ['#610 Esguerra St., Tondo, Manila', 120, 640, 26],
        ['SHIP TO:', 1300, 540, 22], ['SURANGEL & SONS CONSTRUCTION', 1300, 590, 26], ['Warehouse 2, Malakal, Koror', 1300, 640, 26]);
    foreach ([['STATUS', 'OPEN', 120], ['BACK REFERENCE', 'N/A', 480], ['FREIGHT POLICY', 'PREPAID', 900], ['SHIP VIA', 'TRUCK', 1350], ['PAYMENT TERMS', 'NET 30', 1750]] as [$l, $v, $x]) {
        $page[] = [$l, $x, 760, 20];
        $page[] = [$v, $x, 810, 24];
    }
    foreach ([['STORE NUMBER', '01', 120], ['BUYER', 'J. SANTOS', 450], ['ORDER TYPE', 'STOCK', 800], ['DATE DUE', '2/13/23', 1120], ['ALTERNATE PO NUMBER', '0004512', 1420], ['QUOTATION NUMBER', 'Q-2023-118', 1900]] as [$l, $v, $x]) {
        $page[] = [$l, $x, 920, 20];
        $page[] = [$v, $x, 970, 24];
    }

    $xs = [120, 400, 750, 1650, 1950, 2150];
    $tableHeader = fn (int $y) => array_map(fn ($h, $c) => [$h, $xs[$c], $y, 22], ['QUANTITY', 'ITEM/SKU NUMBER', 'DESCRIPTION', 'UNIT COST', 'U/M', 'EXTENDED COST'], array_keys($xs));
    $rowCells = function (array $cells, int $y) use ($xs) {
        $out = [];
        foreach ($cells as $c => $v) {
            $x = in_array($c, [3, 5], true) ? $xs[$c] + 200 - strlen($v) * 16 : $xs[$c]; // amounts right-aligned
            $out[] = [$v, $x, $y, 24];
        }

        return $out;
    };

    $start = 0;
    foreach ($pages as $p => $count) {
        $items = $p === 0 ? $page : [['SURANGEL & SONS CONSTRUCTION', 120, 160, 30]];
        $y = $p === 0 ? 1100 : 400;
        array_push($items, ...$tableHeader($y));
        foreach (array_slice($rows, $start, $count) as $r => $cells) {
            array_push($items, ...$rowCells($cells, $y + 90 + $r * 70));
        }
        $start += $count;
        if ($p === count($pages) - 1) {
            $y = $y + 90 + $count * 70 + 30;
            $items[] = ['TOTAL', 1950, $y, 24];
            $items[] = ['2,850.79', 2350 - 8 * 16, $y, 24];
            $items[] = ['SPECIAL INSTRUCTIONS: Deliver to the site warehouse. Call the buyer before delivery.', 120, $y + 200, 22];
        }
        $items[] = ['Page '.($p + 1).' of '.count($pages), 1150, 3150, 20];
        $layout[] = $items;
    }

    return $layout;
}

/** Draw one page of the layout. Returns the file path. */
function poDraw(array $items, string $type = 'png'): string
{
    $img = imagecreatetruecolor(2480, 3300);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $ink = imagecolorallocate($img, 15, 15, 15);
    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    foreach ($items as [$text, $x, $y, $size]) {
        imagettftext($img, $size, 0, $x, $y, $ink, $font, $text);
    }
    imagefilledrectangle($img, 100, 1040, 2380, 1044, $ink);
    $path = tempnam(sys_get_temp_dir(), 'po').'.'.$type;
    $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 92);

    return $path;
}

/** A PDF of the layout: with a real text layer, or scanned (each page only a picture). */
function poPdf(array $layout, bool $scanned = false): UploadedFile
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
    $kids = [];
    $n = 4;
    foreach ($layout as $items) {
        if ($scanned) {
            $jpeg = file_get_contents(poDraw($items, 'jpg'));
            $content = 'q 612 0 0 814 0 0 cm /Im1 Do Q';
            $objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 814] /Contents '.($n + 1).' 0 R /Resources << /XObject << /Im1 '.($n + 2).' 0 R >> >> >>';
            $objects[$n + 2] = '<< /Type /XObject /Subtype /Image /Width 2480 /Height 3300 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream";
        } else {
            $content = '';
            foreach ($items as [$text, $x, $y, $size]) {
                $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_convert_encoding($text, 'Windows-1252', 'UTF-8'));
                $content .= sprintf("BT /F1 %.1F Tf 1 0 0 1 %.1F %.1F Tm (%s) Tj ET\n", $size * 0.3, $x * 0.2468, 814 - $y * 0.2468, $text);
            }
            $objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 814] /Contents '.($n + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
        }
        $objects[$n + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        $kids[] = "{$n} 0 R";
        $n += 3;
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[$i] = strlen($pdf);
        $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $max = max(array_keys($objects));
    $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
    }
    $pdf .= 'trailer << /Size '.($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return UploadedFile::fake()->createWithContent($scanned ? 'scanned-po.pdf' : 'po.pdf', $pdf);
}

function poNeedsOcr(bool $pdf = false): void
{
    $ocr = new OcrEngine;
    if (! $ocr->tesseractAvailable() || ($pdf && (! $ocr->pdfRendererAvailable() || ! $ocr->pdfTextAvailable()))) {
        test()->markTestSkipped('Tesseract/Poppler are not installed.');
    }
}

function poRead(UploadedFile $file)
{
    return test()->actingAs(test()->admin)->withCookies(test()->cookies)
        ->post('/po-team/procurement/purchase-orders/read-document', ['file' => $file], ['Accept' => 'application/json']);
}

/** [QUANTITY, ITEM/SKU, Description, UNIT COST, U/M, EXTENDED COST (calculated)] per row. */
function poRows(array $json): array
{
    return array_map(fn ($r) => [$r['quantity'], $r['item_sku'], $r['description'], $r['unit_cost'], $r['unit_of_measure'], $r['calculated_cost']], $json['rows']);
}

/** Fake OCR: lines of text as Tesseract TSV (5 px per character, 30 px per line); "~word" is read with low confidence. */
function poFakeOcr(array $lines): void
{
    app()->instance(OcrEngine::class, new class($lines) extends OcrEngine
    {
        public function __construct(private array $lines) {}

        public function tesseractAvailable(): bool
        {
            return true;
        }

        public function imageToTsv(string $imagePath): string
        {
            $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
            foreach ($this->lines as $n => $line) {
                preg_match_all('/\S+/u', $line, $words, PREG_OFFSET_CAPTURE);
                foreach ($words[0] as $i => [$word, $byte]) {
                    $conf = str_starts_with($word, '~') ? 30 : 95;
                    $word = ltrim($word, '~');
                    $x = mb_strlen(substr($line, 0, $byte)) * 5;
                    $tsv .= implode("\t", [5, 1, 1, 1, $n + 1, $i + 1, $x, 20 + $n * 30, mb_strlen($word) * 5, 12, $conf, $word])."\n";
                }
            }

            return $tsv;
        }
    });
}

// ============================================================ reading the reference document

it('reads the reference purchase order from a picture (real OCR)', function () {
    poNeedsOcr();
    $json = poRead(new UploadedFile(poDraw(poLayout()[0]), 'surangel-po.png', null, null, true))->assertOk()->json();

    expect($json['header']['supplier']['name'])->toBe('SURANGEL & SONS CONSTRUCTION')
        ->and($json['header']['po_number']['value'])->toBe('4000005769')
        ->and($json['header']['po_date']['value'])->toBe('2023-01-13')
        ->and($json['header']['po_date']['original'])->toBe('1/13/23')
        ->and(poRows($json))->toEqual(PO_EXPECTED)
        ->and($json['document_total'])->toEqual(2850.79)
        ->and($json['calculated_total'])->toEqual(2850.79)
        ->and($json['ocr'])->toBeTrue();
});

it('reads the reference purchase order from a scanned PDF (real OCR)', function () {
    poNeedsOcr(pdf: true);
    $json = poRead(poPdf(poLayout(), scanned: true))->assertOk()->json();

    expect($json['method'])->toBe('pdf-ocr')
        ->and($json['header']['supplier']['name'])->toBe('SURANGEL & SONS CONSTRUCTION')
        ->and($json['header']['po_number']['value'])->toBe('4000005769')
        ->and($json['header']['po_date']['value'])->toBe('2023-01-13')
        ->and(poRows($json))->toEqual(PO_EXPECTED);
});

it('reads the reference purchase order from a text PDF, without OCR', function () {
    poNeedsOcr(pdf: true);
    $json = poRead(poPdf(poLayout()))->assertOk()->json();

    expect($json['method'])->toBe('pdf-text')
        ->and($json['ocr'])->toBeFalse()
        ->and($json['header']['supplier']['name'])->toBe('SURANGEL & SONS CONSTRUCTION')
        ->and($json['header']['po_number']['value'])->toBe('4000005769')
        ->and($json['header']['po_date']['value'])->toBe('2023-01-13')
        ->and(poRows($json))->toEqual(PO_EXPECTED)
        ->and(collect($json['rows'])->pluck('issues')->filter()->all())->toBe([]);
});

it('reads products over several pages, with the table header repeated (text PDF)', function () {
    poNeedsOcr(pdf: true);
    $json = poRead(poPdf(poLayout(PO_ROWS, [4, 2])))->assertOk()->json();

    expect(poRows($json))->toEqual(PO_EXPECTED);
});

it('extracts only the nine fields: never the vendor, ship-to, buyer, alternate PO, due date or other boxes', function () {
    poNeedsOcr(pdf: true);
    $json = poRead(poPdf(poLayout()))->assertOk()->json();

    expect(array_keys($json['header']))->toBe(['po_number', 'po_date', 'supplier'])
        ->and(array_keys($json['rows'][0]))->toBe(['quantity', 'item_sku', 'description', 'unit_cost', 'unit_of_measure', 'document_extended_cost', 'calculated_cost', 'issues'])
        ->and($json['header']['supplier']['name'])->not->toContain('MR-PRIDE') // the VENDOR box is the recipient
        ->and($json['header']['po_number']['value'])->not->toBe('0004512')  // ALTERNATE PO NUMBER
        ->and($json['header']['po_date']['value'])->not->toBe('2023-02-13'); // DATE DUE
    $everything = json_encode($json);
    foreach (['V00123', 'C-4471', 'J. SANTOS', 'PREPAID', 'TRUCK', 'NET 30', 'Q-2023-118', 'Esguerra', 'Malakal', '000-123-456', 'SPECIAL INSTRUCTIONS', 'Page 1'] as $ignored) {
        expect($everything)->not->toContain($ignored);
    }
});

// ============================================================ values, calculations and flags

it('flags an EXTENDED COST that differs from QUANTITY × UNIT COST instead of replacing it silently', function () {
    poNeedsOcr(pdf: true);
    $rows = PO_ROWS;
    $rows[1][5] = '85.50'; // 3 × 29.50 is 88.50
    $json = poRead(poPdf(poLayout($rows)))->assertOk()->json();

    expect($json['rows'][1]['document_extended_cost'])->toEqual(85.5)
        ->and($json['rows'][1]['calculated_cost'])->toEqual(88.5)
        ->and($json['rows'][1]['issues']['extended_cost'])->toBe('The document shows 85.50, but QTY ORDERED × UNIT COST = 88.50. Check the quantity and unit cost.');
});

it('keeps leading zeros, reads thousands separators and flags what it could not read', function () {
    poFakeOcr([
        'ACME ELECTRICAL TRADING CORPORATION',
        'ORDER DATE      PURCHASE ORDER NO.',
        '03/05/2026      0004000057',
        '',
        'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION                 UNIT COST   U/M   EXTENDED COST',
        '1,000     00123            THHN WIRE 3.5MM             1.50        M     1,500.00',
        '1O        00456            JUNCTION BOX 4X4            ~12.5O      PC    125.00',
        'ten       00789            PVC ELBOW 20MM              8.00              80.00',
        '2         00789            PVC ELBOW 20MM              8,00        PC    16.00',
    ]);

    $json = poRead(UploadedFile::fake()->image('po.png', 600, 800))->assertOk()->json();
    $rows = $json['rows'];

    expect($json['header']['po_number']['value'])->toBe('0004000057')
        ->and($json['header']['po_date']['value'])->toBe('2026-03-05')
        ->and($json['header']['po_date']['issue'])->toContain('month/day/year')
        ->and($json['header']['supplier']['name'])->toBe('ACME ELECTRICAL TRADING CORPORATION')
        ->and(array_column($rows, 'item_sku'))->toBe(['00123', '00456', '00789', '00789'])
        ->and($rows[0])->toMatchArray(['quantity' => 1000, 'unit_cost' => 1.5, 'calculated_cost' => 1500, 'issues' => []])
        ->and($rows[1]['quantity'])->toBe(10)->and($rows[1]['issues']['quantity'])->toContain('Read as "1O"')
        ->and($rows[1]['unit_cost'])->toEqual(12.5)->and($rows[1]['issues']['unit_cost'])->toContain('Check it')
        ->and($rows[2]['quantity'])->toBeNull()->and($rows[2]['issues']['quantity'])->toBe('"ten" is not a valid quantity.')
        ->and($rows[2]['issues']['unit_of_measure'])->toContain('U/M was not found')
        ->and($rows[3]['unit_cost'])->toBeNull()->and($rows[3]['issues']['unit_cost'])->toContain('decimal comma or a thousands separator');
});

it('matches the supplier to an existing supplier, and leaves an unclear one for the user', function () {
    Supplier::create(['team_id' => $this->team->id, 'name' => 'Surangel and Sons Construction', 'status' => 'Active']);
    poFakeOcr([
        'SURANGEL & SONS CONSTRUCTION',
        'ORDER DATE   PURCHASE ORDER NO.',
        '1/13/23      4000005769',
        'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION        UNIT COST  U/M  EXTENDED COST',
        '2         3P50A            MCB 3P 50A         62.00      PC   124.00',
    ]);
    $matched = poRead(UploadedFile::fake()->image('a.png', 600, 800))->assertOk()->json('header.supplier');
    expect($matched['supplier_id'])->toBe(Supplier::sole()->id)->and($matched['issue'])->toBeNull();

    poFakeOcr([
        'ORDER DATE   PURCHASE ORDER NO.',
        '1/13/23      4000005770',
        'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION        UNIT COST  U/M  EXTENDED COST',
        '2         3P50A            MCB 3P 50A         62.00      PC   124.00',
    ]);
    $unclear = poRead(UploadedFile::fake()->image('b.png', 600, 800))->assertOk()->json('header.supplier');
    expect($unclear)->toMatchArray(['name' => null, 'supplier_id' => null, 'issue' => 'The supplier could not be identified. Choose it from the list.']);
});

it('corrects an OCR look-alike in a description only when the document spells the word elsewhere', function () {
    poFakeOcr([
        'ORDER DATE   PURCHASE ORDER NO.',
        '1/13/23      4000005771',
        'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION                   UNIT COST  U/M  EXTENDED COST',
        '2         3P50A            MCB 3P 50A SCHNEIDER          62.00      PC   124.00',
        '3         3P32A            MCB 3P 32A SCHNElDER          29.50      PC   88.50',
        '1         3P20A            MCB 3P 20A SCHNEIDER          28.29      PC   28.29',
    ]);
    $rows = poRead(UploadedFile::fake()->image('c.png', 600, 800))->assertOk()->json('rows');

    expect($rows[1]['description'])->toBe('MCB 3P 32A SCHNEIDER')
        ->and($rows[1]['issues']['description'])->toBe('Corrected "SCHNElDER" → "SCHNEIDER" as written elsewhere in the document. Check it.');
});

it('leaves out a product row read twice in a row', function () {
    poFakeOcr([
        'ORDER DATE   PURCHASE ORDER NO.',
        '1/13/23      4000005772',
        'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION        UNIT COST  U/M  EXTENDED COST',
        '2         3P50A            MCB 3P 50A         62.00      PC   124.00',
        '2         3P50A            MCB 3P 50A         62.00      PC   124.00',
        '1         NEMA1            PANEL BOARD        2200.00    PC   2200.00',
    ]);
    $json = poRead(UploadedFile::fake()->image('d.png', 600, 800))->assertOk()->json();

    expect(array_column($json['rows'], 'item_sku'))->toBe(['3P50A', 'NEMA1'])
        ->and($json['notes'])->toContain('1 repeated product row(s) were left out.');
});

it('reads table headers and amounts written in other common ways', function (string $header, string $field) {
    expect(PurchaseOrderReader::column($header)[0] ?? null)->toBe($field);
})->with([
    ['QUANTITY', 'quantity'], ['QTY', 'quantity'], ['ITEM/SKU NUMBER', 'item_sku'], ['ITEM NUMBER', 'item_sku'], ['SKU', 'item_sku'],
    ['DESCRIPTION', 'description'], ['UNIT COST', 'unit_cost'], ['UNIT PRICE', 'unit_cost'], ['U/M', 'unit_of_measure'], ['UOM', 'unit_of_measure'],
    ['EXTENDED COST', 'extended_cost'], ['EXT. COST', 'extended_cost'], ['AMOUNT', 'extended_cost'], ['UNIT COST (PHP)', 'unit_cost'],
]);

// ============================================================ files and errors

it('accepts only PDF, PNG and JPG documents up to 25 MB, with clear messages', function (Closure $file, string $message) {
    poRead($file())->assertStatus(422)->assertJson(['success' => false, 'message' => $message]);
})->with([
    'Word file' => [fn () => UploadedFile::fake()->createWithContent('po.docx', 'PK'), 'Purchase order documents must be PDF, PNG or JPG files.'],
    'over 25 MB' => [fn () => UploadedFile::fake()->create('po.pdf', 25 * 1024 + 1, 'application/pdf'), 'The document is larger than 25 MB. Please upload a smaller file.'],
    'damaged PDF' => [fn () => UploadedFile::fake()->createWithContent('po.pdf', "%PDF-1.4\ngarbage"), 'We could not open this PDF. It may be damaged. Please re-save it, or upload an Excel/CSV file.'],
    'not really a picture' => [fn () => UploadedFile::fake()->createWithContent('po.png', 'hello'), "This file doesn't look like a valid .png file. It may be damaged, or saved in a different format."],
]);

it('reports a document with no product table instead of inventing rows', function () {
    poFakeOcr(['SURANGEL & SONS CONSTRUCTION', 'ORDER DATE   PURCHASE ORDER NO.', '1/13/23      4000005773', 'Thank you for your business.']);
    $json = poRead(UploadedFile::fake()->image('e.png', 600, 800))->assertOk()->json();

    expect($json['rows'])->toBe([])
        ->and($json['notes'][0])->toContain('No product table with QTY ORDERED, Description and UNIT COST columns was found');
});

it('saves nothing when a document is read', function () {
    poFakeOcr(['ORDER DATE   PURCHASE ORDER NO.', '1/13/23      4000005774', 'QUANTITY  ITEM/SKU NUMBER  DESCRIPTION   UNIT COST  U/M  EXTENDED COST', '2   3P50A   MCB   62.00   PC   124.00']);
    poRead(UploadedFile::fake()->image('f.png', 600, 800))->assertOk();

    expect(PurchaseOrder::count())->toBe(0)->and(Supplier::count())->toBe(0);
});

// ============================================================ saving after confirmation

function poSave(array $overrides = [])
{
    return test()->actingAs(test()->admin)->withCookies(test()->cookies)->post('/po-team/procurement/purchase-orders/import-document', array_merge([
        'po_number' => '4000005769',
        'po_date' => '2023-01-13',
        'new_supplier_name' => 'SURANGEL & SONS CONSTRUCTION',
        'file_name' => 'surangel-po.png',
        'rows' => array_map(fn ($r) => ['quantity' => $r[0], 'item_sku' => $r[1], 'description' => $r[2], 'unit_cost' => $r[3], 'unit_of_measure' => $r[4]], PO_EXPECTED),
    ], $overrides));
}

it('saves the confirmed purchase order: one line per product, EXTENDED COST calculated, supplier added', function () {
    poSave()->assertSessionHasNoErrors()->assertSessionHas('success', 'Purchase order 4000005769 imported with 6 product(s), total 2,850.79.');

    $lines = PurchaseOrder::orderBy('id')->get();
    $supplier = Supplier::sole();
    expect($supplier->name)->toBe('SURANGEL & SONS CONSTRUCTION')
        ->and($lines)->toHaveCount(6)
        ->and($lines->pluck('po_number')->unique()->all())->toBe(['4000005769'])
        ->and($lines->pluck('supplier_id')->unique()->all())->toBe([$supplier->id])
        ->and($lines->first()->po_date->toDateString())->toBe('2023-01-13')
        ->and($lines->map(fn ($l) => [$l->quantity, $l->item_sku, $l->description, (float) $l->unit_cost, $l->unit_of_measure, (float) $l->total_cost])->all())->toBe(PO_EXPECTED)
        ->and($lines->pluck('status')->unique()->all())->toBe([PurchaseOrder::SENT]); // Pending: received with "Record Inbound P.O. Delivery"
});

it('uses an existing supplier and refuses to import the same purchase order twice', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Surangel & Sons Construction', 'status' => 'Active']);
    poSave(['supplier_id' => $supplier->id, 'new_supplier_name' => null])->assertSessionHasNoErrors();
    poSave(['supplier_id' => $supplier->id, 'new_supplier_name' => null])->assertSessionHasErrors(['po_number' => 'Purchase order 4000005769 already exists. It was not imported again.']);

    expect(PurchaseOrder::count())->toBe(6)->and(Supplier::count())->toBe(1);
});

it('refuses missing or invalid values', function (array $overrides, string $key, string $message) {
    poSave($overrides)->assertSessionHasErrors([$key => $message]);
    expect(PurchaseOrder::count())->toBe(0);
})->with([
    'no PO number' => [['po_number' => ''], 'po_number', 'Enter the PURCHASE ORDER NO.'],
    'no date' => [['po_date' => ''], 'po_date', 'Enter the ORDER DATE.'],
    'no products' => [['rows' => []], 'rows', 'Add at least one product.'],
    'decimal quantity' => [['rows' => [['quantity' => '1.5', 'item_sku' => 'X', 'description' => 'MCB', 'unit_cost' => '10', 'unit_of_measure' => 'PC']]], 'rows.0.quantity', 'Product 1: QTY ORDERED must be a whole number.'],
    'missing description' => [['rows' => [['quantity' => 1, 'item_sku' => 'X', 'description' => '', 'unit_cost' => '10', 'unit_of_measure' => 'PC']]], 'rows.0.description', 'Product 1: Description is missing.'],
    'missing U/M' => [['rows' => [['quantity' => 1, 'item_sku' => 'X', 'description' => 'MCB', 'unit_cost' => '10', 'unit_of_measure' => '']]], 'rows.0.unit_of_measure', 'Product 1: U/M is missing.'],
    'three decimals' => [['rows' => [['quantity' => 1, 'item_sku' => 'X', 'description' => 'MCB', 'unit_cost' => '10.123', 'unit_of_measure' => 'PC']]], 'rows.0.unit_cost', 'Product 1: UNIT COST can have at most 2 decimals.'],
]);

// ============================================================ CSV with the nine fields

it('imports a CSV with the nine fields, checking SUPPLIER and EXTENDED COST', function () {
    Supplier::create(['team_id' => $this->team->id, 'name' => 'Surangel & Sons Construction', 'status' => 'Active']);
    $csv = "ORDER DATE,PURCHASE ORDER NO.,QUANTITY,SUPPLIER,ITEM/SKU Number,Description,UNIT COST,U/M,EXTENDED COST\n"
        ."01-13-2023,4000005769,2,Surangel & Sons Construction,3P50A,\"MCB 3P, 50A IC60N SCHNEIDER\",62.00,PC,124.00\n"
        ."01-13-2023,4000005769,3,surangel & sons construction,3P32A,\"MCB 3P, 32A IC60N SCHNEIDER\",29.50,PC,85.50\n"
        ."01-13-2023,4000005769,1,Unknown Supplier Inc,3P20A,\"MCB 3P, 20A IC60N SCHNEIDER\",28.29,PC,28.29\n";
    $file = fn () => UploadedFile::fake()->createWithContent('po.csv', $csv);

    $preview = test()->actingAs($this->admin)->withCookies($this->cookies)
        ->post('/po-team/procurement/purchase-orders/import-csv', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();

    expect($preview['summary']['valid_count'])->toBe(1)
        ->and($preview['invalid_rows'][0]['errors'])->toBe(['EXTENDED COST 85.50 does not equal QTY ORDERED × UNIT COST (88.50).'])
        ->and($preview['invalid_rows'][1]['errors'][0])->toContain('SUPPLIER "Unknown Supplier Inc" is not in the supplier list');

    test()->actingAs($this->admin)->withCookies($this->cookies)
        ->post('/po-team/procurement/purchase-orders/import-csv', ['file' => $file(), 'execute' => true])->assertSessionHasNoErrors();
    expect(PurchaseOrder::sole()->supplier_id)->toBe(Supplier::sole()->id);
});

it('reads the QTY ORDERED column header in a CSV (QUANTITY still works)', function () {
    $csv = "ORDER DATE,PURCHASE ORDER NO.,QTY ORDERED,ITEM/SKU Number,Description,UNIT COST,U/M\n"
        ."01-13-2023,4000005770,4,3P50A,\"MCB 3P, 50A IC60N SCHNEIDER\",62.00,PC\n";

    $preview = test()->actingAs($this->admin)->withCookies($this->cookies)
        ->post('/po-team/procurement/purchase-orders/import-csv', ['file' => UploadedFile::fake()->createWithContent('po.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()->json();

    expect($preview['summary']['valid_count'])->toBe(1)
        ->and($preview['valid_rows'][0]['quantity'])->toBe(4);
});

it('never shows technical details when reading fails unexpectedly', function () {
    app()->instance(PurchaseOrderReader::class, new class extends PurchaseOrderReader
    {
        public function __construct() {}

        public function read(string $path, string $originalName, int $teamId): array
        {
            throw new RuntimeException('SQLSTATE[HY000] secret details in /var/www/app.php:12');
        }
    });

    $response = poRead(UploadedFile::fake()->image('po.png', 600, 800))->assertStatus(500);

    expect($response->json())->toBe(['success' => false, 'message' => 'The document could not be read. Please check the file and try again.'])
        ->and($response->getContent())->not->toContain('SQLSTATE');
});
