<?php

use App\Models\ImportBatch;
use App\Services\Import\ColumnSchema;
use App\Services\Import\DocumentExtractor;
use App\Services\Import\RowMapper;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\HeaderMapper;
use App\Services\MaterialList\MaterialParser;
use App\Services\MaterialList\OcrEngine;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Column detection and table rebuilding with real files and the real tools: Excel and CSV
 * (PhpSpreadsheet), text PDFs (made with dompdf, read by Poppler's pdftotext), scanned PDFs (an
 * image only: rendered by pdftoppm, read by Tesseract), and PNG/JPEG pictures of a table
 * (Tesseract). Both readers are covered: the quotation material list (FileReader +
 * MaterialParser) and the staff inventory import (DocumentExtractor + RowMapper).
 * Tests that need a tool are skipped when it is not installed.
 */

/** Text above every table: a title and supplier details, none of which is a header or an item. */
const RF_ABOVE = ['Bill of Materials and Quantities', 'Supplier: ABC Trading', 'Date: 2026-10-01', 'Quotation No. 123'];

beforeEach(function () {
    $this->rfDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rf-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->rfDir);
});

afterEach(function () {
    File::deleteDirectory($this->rfDir);
});

/** True when one of the tools is missing (then the test is skipped). */
function rfMissing(string ...$tools): bool
{
    $ocr = app(OcrEngine::class);
    foreach ($tools as $tool) {
        $available = match ($tool) {
            'tesseract' => $ocr->tesseractAvailable(),
            'pdftotext' => $ocr->pdfTextAvailable(),
            'pdftoppm' => $ocr->pdfRendererAvailable(),
        };
        if (! $available) {
            return true;
        }
    }

    return false;
}

function rfPath(string $name): string
{
    return test()->rfDir.DIRECTORY_SEPARATOR.$name;
}

function rfFile(string $name, string $content): string
{
    file_put_contents(rfPath($name), $content);

    return rfPath($name);
}

/** @param  array<int, array<int, mixed>>  $rows */
function rfXlsx(string $name, array $rows): string
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
    (new Xlsx($book))->save(rfPath($name));
    $book->disconnectWorksheets();

    return rfPath($name);
}

function rfPdf(string $name, string $html): string
{
    $options = new Options;
    $options->setTempDir(test()->rfDir);
    $options->setFontCache(test()->rfDir);
    $options->setIsRemoteEnabled(false);
    $pdf = new Dompdf($options);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();
    file_put_contents(rfPath($name), $pdf->output());

    return rfPath($name);
}

/**
 * A text PDF: a title, a supplier block, then a bordered table.
 *
 * @param  array<int, array<int, string>>  $table  header row first
 * @param  array<int, int>  $widths  column widths in px (narrow columns wrap their text)
 */
function rfTextPdf(string $name, array $table, array $widths = []): string
{
    $cell = fn (string $tag, int $c, string $text) => "<{$tag}".(isset($widths[$c]) ? " style=\"width:{$widths[$c]}px\"" : '').'>'.e($text)."</{$tag}>";
    $rows = '';
    foreach ($table as $r => $cells) {
        $rows .= '<tr>'.implode('', array_map(fn ($c, $text) => $cell($r === 0 ? 'th' : 'td', $c, $text), array_keys($cells), $cells)).'</tr>';
    }

    return rfPdf($name, '<html><head><style>body{font-family:Helvetica;font-size:12px} table{border-collapse:collapse}'
        .' th,td{border:1px solid #000;padding:4px 6px;text-align:left;vertical-align:top}</style></head><body>'
        .'<h2>'.e(RF_ABOVE[0]).'</h2><p>'.implode('<br>', array_map('e', array_slice(RF_ABOVE, 1))).'</p>'
        .'<table>'.$rows.'</table></body></html>');
}

/**
 * A clean picture of a table, as a scan or photo would show it: black DejaVu Sans text (30 px)
 * on white with wide column gaps, the title and supplier details above it, and either ruled
 * cells ($grid) or a dark header bar with white text ($bar).
 *
 * @param  array<int, array<int, string>>  $table  header row first
 */
function rfRender(array $table, bool $grid = false, bool $bar = false): GdImage
{
    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    $size = 30;
    $pad = 30;
    $widths = [];
    foreach ($table as $cells) {
        foreach ($cells as $c => $text) {
            $box = imagettfbbox($size, 0, $font, $text === '' ? 'x' : $text);
            $widths[$c] = max($widths[$c] ?? 0, $box[2] - $box[0]);
        }
    }
    $x = [60];
    foreach ($widths as $c => $width) {
        $x[$c + 1] = $x[$c] + $width + 2 * $pad + 40;
    }
    $lineHeight = (int) ($size * 2.2);
    $img = imagecreatetruecolor(max(1200, end($x) + 60), 100 + (count(RF_ABOVE) + count($table)) * $lineHeight + 120);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);

    $y = 90;
    foreach (RF_ABOVE as $i => $text) {
        imagettftext($img, $i === 0 ? $size + 6 : $size, 0, 60, $y, $black, $font, $text);
        $y += $lineHeight;
    }
    $top = $y;
    if ($bar) {
        imagefilledrectangle($img, $x[0], $top, end($x), $top + $lineHeight, imagecolorallocate($img, 45, 45, 45));
    }
    foreach ($table as $r => $cells) {
        foreach ($cells as $c => $text) {
            imagettftext($img, $size, 0, $x[$c] + $pad, $top + $r * $lineHeight + (int) ($lineHeight * 0.65), $r === 0 && $bar ? $white : $black, $font, $text);
        }
    }
    if ($grid) {
        imagesetthickness($img, 3);
        for ($r = 0; $r <= count($table); $r++) {
            imageline($img, $x[0], $top + $r * $lineHeight, end($x), $top + $r * $lineHeight, $black);
        }
        foreach ($x as $edge) {
            imageline($img, $edge, $top, $edge, $top + count($table) * $lineHeight, $black);
        }
    }

    return $img;
}

function rfImage(string $name, GdImage $img): string
{
    str_ends_with($name, '.png') ? imagepng($img, rfPath($name)) : imagejpeg($img, rfPath($name), 90);

    return rfPath($name);
}

/** A scanned PDF: one A4 page holding only a picture, with no text layer. */
function rfScanPdf(string $name, GdImage $img): string
{
    ob_start();
    imagepng($img);
    $png = base64_encode((string) ob_get_clean());

    return rfPdf($name, '<html><body><img src="data:image/png;base64,'.$png.'" style="width:100%"></body></html>');
}

/** Read a material list the way QuotationController::importMaterials does. */
function rfMaterials(string $path, string $name): array
{
    $reader = app(FileReader::class);
    $type = $reader->detectType($path, $name);
    $table = in_array($type, ['pdf', 'image'], true)
        ? $reader->readTable($path, $type, fn (array $cells, bool $words = false) => HeaderMapper::isMaterialHeader($cells, $words))
        : null;
    $read = $table !== null && $table['aligned'] ? $table : $reader->read($path, $type);
    $parsed = isset($read['rows']) && $read['rows'] !== []
        ? MaterialParser::parseTable($read['rows'], null, null, $read['method'])
        : MaterialParser::parseText(MaterialParser::cleanLines($read['pages'] ?? []), $read['method']);

    return ['method' => $read['method'], 'aligned' => (bool) ($table['aligned'] ?? false), 'table' => $table] + $parsed;
}

/** [description, quantity] per item; a quantity is a float, or null when the file has none. */
function rfItems(array $parsed): array
{
    return array_map(fn ($item) => [$item['description'], $item['quantity'] === null ? null : (float) $item['quantity']], $parsed['items']);
}

/** The header row the parser chose, as it reads in the file. */
function rfHeader(array $parsed): array
{
    return $parsed['preview'][$parsed['header_index']] ?? [];
}

/** Read a file the way the staff inventory import does. */
function rfInventory(string $path, string $name): array
{
    $extracted = app(DocumentExtractor::class)->extract($path, $name, ImportBatch::INVENTORY);

    return ['method' => $extracted['method'], 'table' => $extracted['rows']] + RowMapper::map(ImportBatch::INVENTORY, $extracted['rows']);
}

/** The values of the inventory import lines, for the given fields. */
function rfLines(array $mapped, array $fields = ['sku', 'name', 'category', 'unit', 'price', 'stock']): array
{
    return array_map(fn ($row) => array_intersect_key($row['extracted'], array_flip($fields)), $mapped['rows']);
}

/** The ExtractionException a call throws: [reason, message]. */
function rfFailure(callable $read): array
{
    try {
        $read();
    } catch (ExtractionException $e) {
        return [$e->reason, $e->getMessage()];
    }

    return [null, null];
}

/** No title, supplier detail or header word became an item or an import line. */
function rfNoDocumentDetails(array $values): void
{
    foreach ($values as $value) {
        expect((string) $value)->not->toMatch('/Bill of Materials|Supplier|ABC Trading|Date|2026-10-01|Quotation|^123$/i');
    }
}

/** The material table used for the PDFs and pictures; the PVC conduit line has no quantity. */
function rfMaterialTable(array $header): array
{
    return [
        $header,
        ['1', 'Circuit Breaker 60A', 'Bolt-on 2P', '10', 'pcs'],
        ['2', 'THHN Wire 3.5mm', 'Stranded', '5', 'rolls'],
        ['3', 'PVC Conduit 20mm', 'Schedule 40', '', 'pcs'],
        ['4', 'LED Panel Light 600x600', '40W', '12', 'pcs'],
    ];
}

const RF_MATERIAL_ITEMS = [
    ['Circuit Breaker 60A', 10.0],
    ['THHN Wire 3.5mm', 5.0],
    ['PVC Conduit 20mm', null],
    ['LED Panel Light 600x600', 12.0],
];

/** The inventory table used for the PDFs and pictures; the LED panel line has no stock count. */
function rfInventoryTable(array $header): array
{
    return [
        $header,
        ['BRK-60A', 'Circuit Breaker 60A', 'Breakers', 'pcs', '850.00', '20'],
        ['THW-35', 'THHN Wire 3.5mm', 'Wires', 'roll', '2,400.00', '7'],
        ['LED-24', 'LED Panel Light 600x600', 'Lighting', 'pcs', '1,150.50', ''],
    ];
}

const RF_INVENTORY_LINES = [
    ['sku' => 'BRK-60A', 'name' => 'Circuit Breaker 60A', 'category' => 'Breakers', 'unit' => 'pcs', 'price' => '850.00', 'stock' => '20'],
    ['sku' => 'THW-35', 'name' => 'THHN Wire 3.5mm', 'category' => 'Wires', 'unit' => 'roll', 'price' => '2,400.00', 'stock' => '7'],
    ['sku' => 'LED-24', 'name' => 'LED Panel Light 600x600', 'category' => 'Lighting', 'unit' => 'pcs', 'price' => '1,150.50', 'stock' => null],
];

/** Checks shared by every material list read from a PDF or picture of rfMaterialTable(). */
function rfExpectMaterialTable(array $res, string $method, array $header, string $quantityColumn): void
{
    expect($res['method'])->toBe($method)
        ->and($res['aligned'])->toBeTrue()
        ->and($res['confident'])->toBeTrue()
        ->and($res['header_detected'])->toBeTrue()
        ->and($res['missing'])->toBe([])
        ->and(rfHeader($res))->toBe($header)
        ->and((array) $res['mapping'])->toMatchArray(['description' => 1, 'quantity' => 3, 'unit' => 4])
        ->and($res['columns'][3])->toBe($quantityColumn)
        ->and(rfItems($res))->toBe(RF_MATERIAL_ITEMS)
        ->and(array_column($res['items'], 'unit'))->toBe(['pcs', 'rolls', 'pcs', 'pcs']);
    // The line without a quantity is kept and flagged, never given a guessed number.
    expect($res['items'][2]['errors'])->toContain('Missing quantity.')
        ->and($res['items'][2]['quantity_raw'])->toBe('');
    rfNoDocumentDetails(array_column($res['items'], 'description'));
}

/** Checks shared by every inventory import of a PDF or picture of rfInventoryTable(). */
function rfExpectInventoryTable(array $res, string $method, string $nameColumn, string $stockColumn): void
{
    $header = collect($res['table'])->first(fn ($cells) => ColumnSchema::headerMap(ImportBatch::INVENTORY, $cells) !== null);

    expect($res['method'])->toBe($method)
        ->and($res['header_found'])->toBeTrue()
        ->and($header[1])->toBe($nameColumn)
        ->and($header[5])->toBe($stockColumn)
        ->and(rfLines($res))->toBe(RF_INVENTORY_LINES)
        // The title and supplier details are shown as unparsed lines, not imported.
        ->and($res['unparsed'])->toBe(RF_ABOVE);
    rfNoDocumentDetails(array_column(rfLines($res), 'name'));
}

// --- Header wordings --------------------------------------------------------------------------

it('recognises the common description and quantity headers in any case, spacing or punctuation', function () {
    $description = ['Description', 'Product Description', 'Product Name', 'Material Name', 'Item Description', 'Item Name',
        'Material', 'Product', 'ITEM_NAME', '  item   name ', 'Item-Name', 'PRODUCT DESCRIPTION.', 'productName'];
    $quantity = ['Quantity', 'Qty', 'QTY', 'QTY.', "Q'ty", 'Q’ty', 'Stock Quantity', 'STOCK_QUANTITY', 'Available Quantity',
        'Available Qty.', 'Current Stock', 'current_stock', 'On Hand', 'On-Hand', 'ON_HAND', 'Qty On-Hand', 'Quantity (pcs)'];

    foreach ($description as $h) {
        expect(HeaderMapper::fieldFor($h))->toBe('description', $h)
            ->and(ColumnSchema::fieldFor(ImportBatch::INVENTORY, $h))->toBe('name', $h);
    }
    foreach ($quantity as $h) {
        expect(HeaderMapper::fieldFor($h))->toBe('quantity', $h)
            ->and(ColumnSchema::fieldFor(ImportBatch::INVENTORY, $h))->toBe('stock', $h);
    }
});

it('never takes a title or supplier details for a header row', function () {
    foreach ([RF_ABOVE, [RF_ABOVE[0]], ['Supplier: ABC Trading', 'Date: 2026-10-01'], ['Quotation No. 123', 'Qty']] as $row) {
        expect(HeaderMapper::isHeaderRow($row))->toBeFalse()
            ->and(ColumnSchema::headerMap(ImportBatch::INVENTORY, $row))->toBeNull();
    }
    // Read word by word (OCR, PDF), a title holds two labels ("Materials", "Quantities") but is no header.
    expect(HeaderMapper::isHeaderRow(explode(' ', RF_ABOVE[0]), true))->toBeFalse()
        ->and(HeaderMapper::isMaterialHeader(explode(' ', RF_ABOVE[0]), true))->toBeFalse()
        ->and(ColumnSchema::headerMap(ImportBatch::INVENTORY, explode(' ', RF_ABOVE[0]), true))->toBeNull();
});

// --- Excel ------------------------------------------------------------------------------------

it('reads an Excel list with Description and Qty columns below a title and supplier details', function () {
    $path = rfXlsx('materials.xlsx', [
        [RF_ABOVE[0]],
        [RF_ABOVE[1]],
        [RF_ABOVE[2]],
        [RF_ABOVE[3]],
        [],
        ['Item', 'Description', 'Qty', 'Unit'],
        [1, 'Circuit Breaker 60A', 10, 'pcs'],
        [2, 'THHN Wire 3.5mm', 5.0, 'rolls'],
        [3, 'PVC Conduit 20mm', null, 'pcs'],
        [4, 'LED Panel Light 600x600', '12', 'pcs'],
        [5, 'Cable Tie 200mm', 'two', 'packs'],
    ]);

    $res = rfMaterials($path, 'materials.xlsx');

    expect($res['method'])->toBe('spreadsheet')
        ->and($res['confident'])->toBeTrue()
        ->and(rfHeader($res))->toBe(['Item', 'Description', 'Qty', 'Unit'])
        ->and((array) $res['mapping'])->toMatchArray(['description' => 1, 'quantity' => 2, 'unit' => 3])
        ->and(rfItems($res))->toBe([...RF_MATERIAL_ITEMS, ['Cable Tie 200mm', null]]);
    // Blank and unreadable quantities stay empty and block the line until staff fix them.
    expect($res['items'][2]['errors'])->toBe(['Missing quantity.'])
        ->and($res['items'][4]['errors'])->toBe(['Quantity "two" is not a valid number.']);
    rfNoDocumentDetails(array_column($res['items'], 'description'));
});

it('reads an Excel sheet with Product Name and Stock Quantity columns, for quotations and for the inventory import', function () {
    $path = rfXlsx('stock.xlsx', [
        ['Stock list - October 2026'],
        ['Supplier: ABC Trading', null, 'Date: 2026-10-01'],
        [],
        ['SKU', 'Product Name', 'Category', 'Unit', 'Price', 'Stock Quantity'],
        ['BRK-60A', 'Circuit Breaker 60A', 'Breakers', 'pcs', 850, 20],
        ['THW-35', 'THHN Wire 3.5mm', 'Wires', 'roll', 2400, 7],
        ['LED-24', 'LED Panel Light 600x600', 'Lighting', 'pcs', 1150.5, null],
    ]);

    $materials = rfMaterials($path, 'stock.xlsx');

    expect($materials['confident'])->toBeTrue()
        ->and((array) $materials['mapping'])->toMatchArray(['item_code' => 0, 'description' => 1, 'unit' => 3, 'unit_price' => 4, 'quantity' => 5])
        ->and(rfItems($materials))->toBe([['Circuit Breaker 60A', 20.0], ['THHN Wire 3.5mm', 7.0], ['LED Panel Light 600x600', null]])
        ->and($materials['items'][2]['errors'])->toBe(['Missing quantity.']);

    $inventory = rfInventory($path, 'stock.xlsx');

    expect($inventory['method'])->toBe('spreadsheet')
        ->and($inventory['header_found'])->toBeTrue()
        ->and(rfLines($inventory))->toBe([
            ['sku' => 'BRK-60A', 'name' => 'Circuit Breaker 60A', 'category' => 'Breakers', 'unit' => 'pcs', 'price' => '850', 'stock' => '20'],
            ['sku' => 'THW-35', 'name' => 'THHN Wire 3.5mm', 'category' => 'Wires', 'unit' => 'roll', 'price' => '2400', 'stock' => '7'],
            ['sku' => 'LED-24', 'name' => 'LED Panel Light 600x600', 'category' => 'Lighting', 'unit' => 'pcs', 'price' => '1150.5', 'stock' => null],
        ])
        ->and($inventory['unparsed'])->toBe(['Stock list - October 2026', 'Supplier: ABC Trading | Date: 2026-10-01']);
});

// --- CSV --------------------------------------------------------------------------------------

it('reads CSV files with other header wordings', function (string $csv, string $descriptionColumn, string $quantityColumn) {
    $path = rfFile('materials.csv', $csv);

    $res = rfMaterials($path, 'materials.csv');

    expect($res['method'])->toBe('csv')
        ->and($res['confident'])->toBeTrue()
        ->and($res['columns'][$res['mapping']->description])->toBe($descriptionColumn)
        ->and($res['columns'][$res['mapping']->quantity])->toBe($quantityColumn)
        ->and(rfItems($res))->toBe([['Circuit Breaker 60A', 10.0], ['THHN Wire 3.5mm', 5.0], ['PVC Conduit 20mm', null]]);
    rfNoDocumentDetails(array_column($res['items'], 'description'));

    // The inventory import finds the same columns (the quantity is shown there, never imported).
    $lines = rfLines(rfInventory($path, 'materials.csv'), ['name', 'stock']);
    expect($lines)->toBe([
        ['name' => 'Circuit Breaker 60A', 'stock' => '10'],
        ['name' => 'THHN Wire 3.5mm', 'stock' => '5'],
        ['name' => 'PVC Conduit 20mm', 'stock' => null],
    ]);
})->with([
    'ITEM_NAME and Q\'ty' => ["ITEM_NAME,Q'ty,UOM\nCircuit Breaker 60A,10,pcs\nTHHN Wire 3.5mm,5,rolls\nPVC Conduit 20mm,,pcs\n", 'ITEM_NAME', "Q'ty"],
    'Material Name and On-Hand, with a title' => ["Bill of Materials and Quantities\nSupplier: ABC Trading\n\nMaterial Name;On-Hand;Remarks\nCircuit Breaker 60A;10;\nTHHN Wire 3.5mm;5;rush\nPVC Conduit 20mm;;\n", 'Material Name', 'On-Hand'],
    'PRODUCT DESCRIPTION and Available Quantity' => ["No.,PRODUCT DESCRIPTION,Available Quantity\n1,Circuit Breaker 60A,10\n2,THHN Wire 3.5mm,5\n3,PVC Conduit 20mm,\n", 'PRODUCT DESCRIPTION', 'Available Quantity'],
    'product_name and current_stock, tab separated' => ["product_name\tcurrent_stock\tunit\nCircuit Breaker 60A\t10\tpcs\nTHHN Wire 3.5mm\t5\trolls\nPVC Conduit 20mm\t\tpcs\n", 'product_name', 'current_stock'],
    'Item Description. and QTY.' => ["\xEF\xBB\xBFItem Description.,QTY.\nCircuit Breaker 60A,10\nTHHN Wire 3.5mm,5\nPVC Conduit 20mm,\n", 'Item Description.', 'QTY.'],
]);

// --- Missing columns ------------------------------------------------------------------------

it('names the missing description column instead of guessing one', function () {
    $res = rfMaterials(rfFile('codes.csv', "Product Code,Qty,Unit\nBRK-60A,10,pcs\nTHW-35,5,rolls\n"), 'codes.csv');

    expect($res['confident'])->toBeFalse()
        ->and($res['header_detected'])->toBeTrue()
        ->and($res['missing'])->toBe(['description'])
        ->and($res['items'])->toBe([])
        ->and($res['warnings'][0])->toStartWith('No description column was found.')
        ->and($res['warnings'][0])->toContain('Columns found: "Product Code", "Qty", "Unit".')
        ->and($res['warnings'][0])->not->toContain('quantity');
});

it('names the missing quantity column instead of guessing one', function () {
    $path = rfXlsx('no-qty.xlsx', [
        [RF_ABOVE[0]],
        ['Description', 'Brand', 'Unit'],
        ['Circuit Breaker 60A', 'Schneider', 'pcs'],
        ['THHN Wire 3.5mm', 'Phelps Dodge', 'rolls'],
    ]);

    $res = rfMaterials($path, 'no-qty.xlsx');

    expect($res['confident'])->toBeFalse()
        ->and($res['header_detected'])->toBeTrue()
        ->and($res['header_index'])->toBe(1)
        ->and($res['missing'])->toBe(['quantity'])
        ->and($res['items'])->toBe([])
        ->and($res['warnings'][0])->toStartWith('No quantity column was found.')
        ->and($res['warnings'][0])->toContain('Columns found: "Description", "Brand", "Unit".')
        ->and($res['warnings'][0])->toContain('"Qty", "Quantity", "Stock Quantity" or "On Hand"');
});

it('names both missing columns when no header row is recognised', function () {
    $res = rfMaterials(rfFile('odd.csv', "Thing,How many\nBreaker,4\n"), 'odd.csv');

    expect($res['confident'])->toBeFalse()
        ->and($res['header_detected'])->toBeFalse()
        ->and($res['missing'])->toBe(['description', 'quantity'])
        ->and($res['warnings'][0])->toStartWith('No description or quantity column was found. No column headers were recognised.');
});

it('tells the inventory import which column is missing when there is no product name', function () {
    $res = rfInventory(rfFile('stock.csv', "SKU,Qty,Price\nBRK-60A,10,850\n"), 'stock.csv');

    expect($res['header_found'])->toBeFalse()
        ->and(ColumnSchema::describeMissingKey(ImportBatch::INVENTORY, $res['table'][0]))
        ->toBe('No product name column was found. Columns found: "SKU", "Qty", "Price". Choose the columns below, or add a column headed "Product Name", "Description", "Item Name" or "Material" to the file.');
});

// --- Text PDFs (Poppler pdftotext) ------------------------------------------------------------

it('rebuilds the table of a text PDF from Poppler word positions', function () {
    $header = ['Item', 'Item Description', 'Specification', 'Qty', 'Unit'];
    $path = rfTextPdf('materials.pdf', rfMaterialTable($header));

    $res = rfMaterials($path, 'materials.pdf');

    rfExpectMaterialTable($res, 'pdf-text', $header, 'Qty');
    expect(array_column($res['items'], 'specs'))->toBe(['Bolt-on 2P', 'Stranded', 'Schedule 40', '40W'])
        // "Item" lost the description to "Item Description": it is the line number column.
        ->and($res['mapping']->item_code)->toBe(0)
        // The title and supplier details sit above the header, outside the table.
        ->and(array_slice($res['table']['rows'], 0, 4))->toBe(array_map(fn ($line) => [$line], RF_ABOVE));
})->skip(fn () => rfMissing('pdftotext'), 'Poppler (pdftotext) is not installed.');

it('imports the inventory table of a text PDF', function () {
    $path = rfTextPdf('stock.pdf', rfInventoryTable(['SKU', 'Product Name', 'Category', 'Unit', 'Price', 'Stock Quantity']));

    rfExpectInventoryTable(rfInventory($path, 'stock.pdf'), 'pdf-text', 'Product Name', 'Stock Quantity');
})->skip(fn () => rfMissing('pdftotext'), 'Poppler (pdftotext) is not installed.');

it('reads a text PDF whose header and cells wrap onto two lines', function () {
    $path = rfTextPdf('wrapped.pdf', [
        ['No.', 'Product Description', 'Available Quantity', 'Unit'],
        ['1', 'Molded Case Circuit Breaker 3 Pole 100A Bolt-on Type', '4', 'pcs'],
        ['2', 'THHN Wire 3.5mm', '5', 'rolls'],
        ['3', 'LED Panel Light 600x600 40W Daylight with Driver', '12', 'pcs'],
    ], [30, 170, 60, 50]);

    $res = rfMaterials($path, 'wrapped.pdf');

    expect($res['method'])->toBe('pdf-text')
        ->and($res['aligned'])->toBeTrue()
        ->and(rfHeader($res))->toBe(['No.', 'Product Description', 'Available Quantity', 'Unit'])
        ->and(rfItems($res))->toBe([
            ['Molded Case Circuit Breaker 3 Pole 100A Bolt-on Type', 4.0],
            ['THHN Wire 3.5mm', 5.0],
            ['LED Panel Light 600x600 40W Daylight with Driver', 12.0],
        ]);
})->skip(fn () => rfMissing('pdftotext'), 'Poppler (pdftotext) is not installed.');

it('reads every row of a text PDF that runs over two pages with the header repeated', function () {
    $table = [['Item Name', 'QTY', 'Unit']];
    foreach (range(1, 70) as $n) {
        $table[] = ["Cable Tie {$n}00mm", (string) ($n * 2), 'pack'];
    }
    $path = rfPdf('long.pdf', '<html><head><style>body{font-family:Helvetica;font-size:12px} table{border-collapse:collapse;width:100%}'
        .' th,td{border:1px solid #000;padding:4px 6px;text-align:left}</style></head><body><h2>'.e(RF_ABOVE[0]).'</h2><table><thead><tr>'
        .'<th>Item Name</th><th>QTY</th><th>Unit</th></tr></thead><tbody>'
        .implode('', array_map(fn ($r) => "<tr><td>{$r[0]}</td><td>{$r[1]}</td><td>{$r[2]}</td></tr>", array_slice($table, 1)))
        .'</tbody></table></body></html>');

    $res = rfMaterials($path, 'long.pdf');

    expect(app(DocumentExtractor::class)->extract($path, 'long.pdf', ImportBatch::INVENTORY)['pages'])->toBe(2)
        ->and($res['method'])->toBe('pdf-text')
        ->and(rfItems($res))->toBe(array_map(fn ($r) => [$r[0], (float) $r[1]], array_slice($table, 1)));
})->skip(fn () => rfMissing('pdftotext'), 'Poppler (pdftotext) is not installed.');

it('never turns dates, phone or reference numbers of a text PDF into quantities', function () {
    $path = rfPdf('letter.pdf', '<html><body style="font-family:Helvetica;font-size:12px"><p>'.implode('<br>', [
        'Bill of Materials and Quantities', 'Supplier: ABC Trading', 'Date: 2026-10-01', 'Quotation No: 123', 'Tel: 0917-555-1234',
        'PO No: 4500-12', '10 pcs Circuit Breaker 60A', 'THHN Wire 3.5mm - 5 rolls', 'Please deliver to site office',
    ]).'</p></body></html>');

    $res = rfMaterials($path, 'letter.pdf');

    expect($res['method'])->toBe('pdf-text')
        ->and(rfItems($res))->toBe([['Circuit Breaker 60A', 10.0], ['THHN Wire 3.5mm', 5.0]])
        ->and($res['unparsed_lines'])->toContain('Date: 2026-10-01', 'Quotation No: 123', 'Tel: 0917-555-1234', 'PO No: 4500-12', 'Please deliver to site office');
});

// --- Scanned PDFs (Poppler pdftoppm + Tesseract) ----------------------------------------------

it('reads a scanned PDF with a ruled table through pdftoppm and Tesseract', function () {
    $header = ['No.', 'Material Name', 'Specification', 'Quantity', 'Unit'];
    $path = rfScanPdf('scan.pdf', rfRender(rfMaterialTable($header), grid: true));

    // One 300 DPI page needs more memory than PHP's usual 128 MB leaves: the reader makes room
    // for it instead of stopping with a fatal error.
    $limit = ini_get('memory_limit');
    ini_set('memory_limit', (int) ceil((memory_get_usage(true) + 64 * 1024 ** 2) / 1024 ** 2).'M');
    try {
        $res = rfMaterials($path, 'scan.pdf');
    } finally {
        ini_set('memory_limit', $limit);
    }

    rfExpectMaterialTable($res, 'pdf-ocr', $header, 'Quantity');
})->skip(fn () => rfMissing('pdftoppm', 'tesseract'), 'Poppler (pdftoppm) or Tesseract is not installed.');

it('imports the inventory table of a scanned PDF with a dark header bar', function () {
    $path = rfScanPdf('stock-scan.pdf', rfRender(rfInventoryTable(['SKU', 'Item Name', 'Category', 'Unit', 'Price', 'Current Stock']), bar: true));

    rfExpectInventoryTable(rfInventory($path, 'stock-scan.pdf'), 'pdf-ocr', 'Item Name', 'Current Stock');
})->skip(fn () => rfMissing('pdftoppm', 'tesseract'), 'Poppler (pdftoppm) or Tesseract is not installed.');

// --- Pictures (Tesseract) ---------------------------------------------------------------------

it('reads a PNG of a ruled material table', function () {
    $header = ['No.', 'Product Name', 'Brand', 'Stock Quantity', 'Unit'];
    $table = rfMaterialTable($header);
    foreach ([1 => 'Schneider', 2 => 'Phelps Dodge', 3 => 'Neltex', 4 => 'Philips'] as $r => $brand) {
        $table[$r][2] = $brand;
    }

    $res = rfMaterials(rfImage('table.png', rfRender($table, grid: true)), 'table.png');

    rfExpectMaterialTable($res, 'ocr', $header, 'Stock Quantity');
    expect(array_column($res['items'], 'brand'))->toBe(['Schneider', 'Phelps Dodge', 'Neltex', 'Philips']);
})->skip(fn () => rfMissing('tesseract'), 'Tesseract is not installed.');

it('reads a JPEG of a material table with a dark header bar', function () {
    $header = ['Item', 'Description', 'Specification', 'Available Quantity', 'Unit'];

    $res = rfMaterials(rfImage('table.jpg', rfRender(rfMaterialTable($header), bar: true)), 'table.jpg');

    rfExpectMaterialTable($res, 'ocr', $header, 'Available Quantity');
    // The light page margins beside the bar are not read as extra columns.
    expect($res['columns'])->toBe($header);
})->skip(fn () => rfMissing('tesseract'), 'Tesseract is not installed.');

it('imports the inventory table of a PNG and of a JPEG', function (string $name, bool $grid) {
    $path = rfImage($name, rfRender(rfInventoryTable(['SKU', 'Material Name', 'Category', 'Unit', 'Price', 'On Hand']), grid: $grid, bar: ! $grid));

    $res = rfInventory($path, $name);

    rfExpectInventoryTable($res, 'ocr', 'Material Name', 'On Hand');
    expect(collect($res['table'])->every(fn ($cells) => count($cells) <= 6))->toBeTrue(); // no extra columns from borders or margins
})->with([
    'ruled PNG' => ['stock.png', true],
    'JPEG with a header bar' => ['stock.jpg', false],
])->skip(fn () => rfMissing('tesseract'), 'Tesseract is not installed.');

it('reads a screenshot with small letters and a coloured header bar', function () {
    // Like a 1600 px wide screenshot of a stock list: 10 pt text, white header words on an olive bar.
    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
    $img = imagecreatetruecolor(1600, 260);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $black = imagecolorallocate($img, 20, 20, 20);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagettftext($img, 18, 0, 40, 50, $black, $bold, 'Stock List');
    imagettftext($img, 10, 0, 40, 85, imagecolorallocate($img, 110, 110, 110), $font, 'Supplier: ABC Trading   ·   Date: 2026-10-01');
    imagefilledrectangle($img, 40, 115, 1560, 147, imagecolorallocate($img, 74, 83, 32));
    $rows = [['SKU', 'Product Name', 'Category', 'Unit', 'Price', 'On Hand'], ['BRK-60A', 'Circuit Breaker 60A', 'Breakers', 'pcs', '850.00', '20'], ['THW-35', 'THHN Wire 3.5mm', 'Wires', 'roll', '2,400.00', '7']];
    foreach ($rows as $r => $cells) {
        foreach ($cells as $c => $text) {
            imagettftext($img, 10, 0, [70, 230, 600, 800, 950, 1150][$c], 137 + $r * 32, $r === 0 ? $white : $black, $r === 0 ? $bold : $font, $text);
        }
    }

    $res = rfInventory(rfImage('screenshot.png', $img), 'screenshot.png');

    // The header in the bar is found, and small letters are enlarged enough to read "60A" and "pcs".
    expect($res['header_found'])->toBeTrue()
        ->and(rfLines($res))->toBe([
            ['sku' => 'BRK-60A', 'name' => 'Circuit Breaker 60A', 'category' => 'Breakers', 'unit' => 'pcs', 'price' => '850.00', 'stock' => '20'],
            ['sku' => 'THW-35', 'name' => 'THHN Wire 3.5mm', 'category' => 'Wires', 'unit' => 'roll', 'price' => '2,400.00', 'stock' => '7'],
        ]);
})->skip(fn () => rfMissing('tesseract'), 'Tesseract is not installed.');

it('finds no materials and invents no lines in a picture without text', function () {
    $img = imagecreatetruecolor(1200, 800);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $path = rfImage('blank.png', $img);

    $res = rfMaterials($path, 'blank.png');
    $inventory = rfInventory($path, 'blank.png');

    expect($res['items'])->toBe([])
        ->and($res['confident'])->toBeFalse()
        ->and($inventory['rows'])->toBe([]);
})->skip(fn () => rfMissing('tesseract'), 'Tesseract is not installed.');

// --- Invalid files ----------------------------------------------------------------------------

it('rejects invalid and unreadable files with a clear reason', function (string $name, string $content, string $reason, string $message) {
    $path = rfFile($name, $content);

    foreach ([fn () => rfMaterials($path, $name), fn () => rfInventory($path, $name)] as $read) {
        [$gotReason, $gotMessage] = rfFailure($read);

        expect($gotReason)->toBe($reason)
            ->and($gotMessage)->toContain($message)
            ->and($gotMessage)->not->toMatch('/SQLSTATE|Exception|Stack trace|\.php/i');
    }
})->with([
    'garbage named .pdf' => ['list.pdf', 'this is not a pdf at all', 'corrupted', "doesn't look like a valid .pdf file"],
    'damaged PDF' => ['broken.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog garbage", 'corrupted', 'We could not open this PDF'],
    'fake .xlsx' => ['list.xlsx', "PK\x03\x04 not really a workbook", 'corrupted', "doesn't look like a valid .xlsx file"],
    'empty file' => ['empty.csv', '', 'empty', 'The file is empty.'],
    'PNG named .jpg' => ['photo.jpg', "\x89PNG\r\n\x1A\n".str_repeat("\0", 32), 'corrupted', "doesn't look like a valid .jpg file"],
    'binary named .csv' => ['data.csv', "\x00\x01\x02binary", 'corrupted', "doesn't look like a valid .csv file"],
    'unsupported type' => ['list.docx', 'PK', 'unsupported', 'This file type is not supported.'],
]);

it('rejects a real PNG saved with a .jpg name', function () {
    $img = imagecreatetruecolor(20, 20);
    $path = rfImage('real.png', $img);
    rename($path, rfPath('photo.jpg'));

    expect(rfFailure(fn () => rfMaterials(rfPath('photo.jpg'), 'photo.jpg')))
        ->toBe(['corrupted', "This file doesn't look like a valid .jpg file. It may be damaged, or saved in a different format."]);
});
