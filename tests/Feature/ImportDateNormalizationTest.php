<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\OcrEngine;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Dates read from imported files (CSV, Excel, text PDF, scanned PDF, PNG, JPG) are validated and
 * saved as YYYY-MM-DD through the real import endpoints. Ambiguous dates are never guessed. OCR
 * tests are skipped when Tesseract or Poppler is not installed.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'date-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'ABC Electrical Trading', 'status' => 'Active']);
    // Quote lines are matched to these products by their exact codes.
    Product::create(['team_id' => $this->team->id, 'sku' => 'WR-35', 'name' => 'THHN Wire 3.5mm', 'price' => 1300, 'cost_price' => 950, 'stock' => 0, 'is_active' => true, 'unit' => 'roll']);
    Product::create(['team_id' => $this->team->id, 'sku' => 'CB-60', 'name' => 'Circuit Breaker 60A', 'price' => 900, 'cost_price' => 700, 'stock' => 0, 'is_active' => true, 'unit' => 'pcs']);
    $this->dnDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dn-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->dnDir);
});

afterEach(function () {
    File::deleteDirectory($this->dnDir);
});

function dnAs($t)
{
    return $t->actingAs($t->admin)->withCookies($t->cookies)->withCredentials();
}

/** The raw value stored in a date column: exactly YYYY-MM-DD on MySQL DATE columns; SQLite keeps Eloquent's time part. */
function dnStored(string $table, string $column, int $id): string
{
    $raw = (string) DB::table($table)->where('id', $id)->value($column);

    return DB::connection()->getDriverName() === 'mysql' ? $raw : substr($raw, 0, 10);
}

function dnCsv(string $name, string $csv): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $csv);
}

function dnMissing(string ...$tools): bool
{
    $ocr = app(OcrEngine::class);
    foreach ($tools as $tool) {
        if (! match ($tool) {
            'tesseract' => $ocr->tesseractAvailable(),
            'pdftotext' => $ocr->pdfTextAvailable(),
            'pdftoppm' => $ocr->pdfRendererAvailable(),
        }) {
            return true;
        }
    }

    return false;
}

/** Upload a supplier quotation document without a quotation date (it is read from the document). */
function dnUploadQuote($t, UploadedFile $file, array $extra = []): ImportBatch
{
    $id = dnAs($t)->post('/date-team/imports/supplier_quotation', ['file' => $file, 'supplier_id' => $t->supplier->id, 'currency' => 'PHP'] + $extra)
        ->assertCreated()->json('id');

    return ImportBatch::findOrFail($id);
}

function dnQuotePayload($t, ImportBatch $batch): array
{
    return dnAs($t)->getJson("/date-team/imports/batches/{$batch->id}")->assertOk()->json('batch');
}

/** Lines of a supplier quotation as a scan or photo shows them: header text, then the item table. */
function dnRender(string $dateLine, float $tilt = 0.0): GdImage
{
    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    $img = imagecreatetruecolor(1700, 1000);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    $lines = ['ABC Electrical Trading', $dateLine, 'Quotation No. 7781'];
    foreach ($lines as $i => $text) {
        imagettftext($img, 30, 0, 60, 90 + $i * 66, $black, $font, $text);
    }
    $x = [60, 760, 1000, 1330];
    foreach ([['Description', 'Qty', 'Unit Price', 'Amount'], ['THHN Wire 3.5mm', '10', '1250.00', '12500.00'], ['Circuit Breaker 60A', '4', '850.00', '3400.00']] as $r => $cells) {
        foreach ($cells as $c => $text) {
            imagettftext($img, 30, 0, $x[$c], 420 + $r * 70, $black, $font, $text);
        }
    }

    return $tilt == 0.0 ? $img : imagerotate($img, $tilt, $white);
}

function dnPdf(string $path, string $html): void
{
    $options = new Options;
    $options->setTempDir(dirname($path));
    $options->setFontCache(dirname($path));
    $pdf = new Dompdf($options);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();
    file_put_contents($path, $pdf->output());
}

// ---------------------------------------------------------------------------------------------
// CSV imports with a MONTH-DAY-YEAR template: mixed formats in one file
// ---------------------------------------------------------------------------------------------

it('reads mixed date formats in one quotation CSV, rejects invalid dates and saves YYYY-MM-DD', function () {
    $csv = implode("\n", [
        'DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT',
        '12-25-2025,Q-A,1,Item A,10,10',     // MM-DD-YYYY
        '25-12-2025,Q-B,1,Item B,10,10',     // DD-MM-YYYY (day above 12)
        '2026-11-03,Q-C,1,Item C,10,10',     // YYYY-MM-DD
        '05-06-2026,Q-D,1,Item D,10,10',     // ambiguous: the template says MONTH-DAY-YEAR
        '02-29-2028,Q-E,1,Item E,10,10',     // leap day
        '02-29-2026,Q-F,1,Item F,10,10',     // not a leap year
        '31-02-2026,Q-G,1,Item G,10,10',     // no 31 February
        '13-13-2026,Q-H,1,Item H,10,10',     // no month 13
        '05-06-26,Q-I,1,Item I,10,10',       // two-digit year
    ]);

    $preview = dnAs($this)->post('/date-team/quotations/import-csv', ['file' => dnCsv('history.csv', $csv), 'execute' => false], ['Accept' => 'application/json'])
        ->assertOk()->json();

    $valid = collect($preview['valid_rows'])->keyBy('quote_number');
    expect($valid->map(fn ($r) => $r['date'])->all())->toBe(['Q-A' => '2025-12-25', 'Q-B' => '2025-12-25', 'Q-C' => '2026-11-03', 'Q-D' => '2026-05-06', 'Q-E' => '2028-02-29'])
        ->and($valid['Q-B']['original_date'])->toBe('25-12-2025');

    $errors = collect($preview['invalid_rows'])->mapWithKeys(fn ($r) => [$r['row_number'] => implode(' ', $r['errors'])]);
    expect($errors[7])->toContain('"02-29-2026" is not a real calendar date: February 2026 has 28 days')
        ->and($errors[8])->toContain('"31-02-2026" is not a real calendar date')
        ->and($errors[9])->toContain('no valid month')
        ->and($errors[10])->toContain('four-digit year');

    dnAs($this)->post('/date-team/quotations/import-csv', ['file' => dnCsv('history.csv', $csv), 'execute' => true, 'mode' => 'append'], ['Accept' => 'application/json']);

    $saved = Quotation::where('team_id', $this->team->id)->get()->mapWithKeys(fn ($q) => [$q->quotation_number => dnStored('quotations', 'quotation_date', $q->id)]);
    expect($saved->sortKeys()->all())->toBe(['Q-A' => '2025-12-25', 'Q-B' => '2025-12-25', 'Q-C' => '2026-11-03', 'Q-D' => '2026-05-06', 'Q-E' => '2028-02-29']);
});

it('normalizes purchase order CSV dates', function () {
    $po = implode("\n", [
        'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost',
        '25-12-2025,PO-1,10,SKU-1,Item 1,PCS,5',
        '2026-01-31,PO-2,10,SKU-2,Item 2,PCS,5',
        '02-30-2026,PO-3,10,SKU-3,Item 3,PCS,5',
    ]);
    dnAs($this)->post('/date-team/procurement/purchase-orders/import-csv', ['file' => dnCsv('po.csv', $po), 'execute' => true], ['Accept' => 'application/json']);

    $pos = PurchaseOrder::where('team_id', $this->team->id)->get()->mapWithKeys(fn ($p) => [$p->po_number => dnStored('purchase_orders', 'po_date', $p->id)]);

    expect($pos->sortKeys()->all())->toBe(['PO-1' => '2025-12-25', 'PO-2' => '2026-01-31']);
});

it('rejects an impossible delivery date instead of saving it, and saves valid ones as YYYY-MM-DD', function () {
    $bad = implode("\n", [
        'Date,Delivery Number,Purchase Order Number,Quantity,Unit,Description',
        '13-40-2026,DEL-BAD,,5,PCS,Item 1',
    ]);
    // Before this change, 13-40-2026 was turned into "2026-13-40" and passed to the database.
    $preview = dnAs($this)->post('/date-team/deliveries/import-csv', ['file' => dnCsv('del.csv', $bad), 'execute' => false], ['Accept' => 'application/json'])->json();
    expect(json_encode($preview))->toContain('13-40-2026')->toContain('no valid month');
    dnAs($this)->post('/date-team/deliveries/import-csv', ['file' => dnCsv('del.csv', $bad), 'execute' => true], ['Accept' => 'application/json']);
    expect(Delivery::where('delivery_number', 'DEL-BAD')->exists())->toBeFalse();

    $good = implode("\n", [
        'Date,Delivery Number,Purchase Order Number,Quantity,Unit,Description',
        '25-12-2025,DEL-A,,5,PCS,Item 1',
        '2026-10-25,DEL-B,,5,PCS,Item 2',
    ]);
    dnAs($this)->post('/date-team/deliveries/import-csv', ['file' => dnCsv('del.csv', $good), 'execute' => true], ['Accept' => 'application/json']);

    $saved = Delivery::where('team_id', $this->team->id)->get()->mapWithKeys(fn ($d) => [$d->delivery_number => dnStored('deliveries', 'delivery_date', $d->id)]);
    expect($saved->sortKeys()->all())->toBe(['DEL-A' => '2025-12-25', 'DEL-B' => '2026-10-25']);
});

// ---------------------------------------------------------------------------------------------
// Supplier quotation import: the quotation date read from the document
// ---------------------------------------------------------------------------------------------

it('reads the quotation and validity dates from a CSV quotation and saves them as YYYY-MM-DD', function () {
    $csv = "ABC Electrical Trading\nQuotation Date: 12-25-2025\nValid Until: 25-01-2026\nCode,Description,Unit,Qty,Unit Price,Amount\nWR-35,THHN Wire 3.5mm,roll,10,1250,12500\n";
    $batch = dnUploadQuote($this, dnCsv('quote.csv', $csv));

    expect($batch->meta['quoted_on'])->toBe('2025-12-25')
        ->and($batch->meta['valid_until'])->toBe('2026-01-25')
        ->and($batch->meta['date_sources'])->toBe(['quoted_on' => 'document', 'valid_until' => 'document'])
        ->and($batch->meta['document_dates']['quoted_on'])->toMatchArray(['original' => '12-25-2025', 'format' => 'MM-DD-YYYY', 'label' => 'Quotation Date'])
        ->and($batch->meta['document_dates']['valid_until'])->toMatchArray(['original' => '25-01-2026', 'format' => 'DD-MM-YYYY']);
    expect(dnQuotePayload($this, $batch)['date_issues'])->toBe([]);

    dnAs($this)->postJson("/date-team/imports/batches/{$batch->id}/confirm")->assertOk();

    $quote = SupplierQuote::sole();
    expect(dnStored('supplier_quotes', 'quoted_on', $quote->id))->toBe('2025-12-25')
        ->and(dnStored('supplier_quotes', 'valid_until', $quote->id))->toBe('2026-01-25');
    $audit = AuditLog::where('event', 'import.completed')->sole();
    expect($audit->metadata['dates']['document_original'])->toBe(['quoted_on' => '12-25-2025', 'valid_until' => '25-01-2026']);
});

it('flags an ambiguous document date, blocks the import, and saves the date staff confirm', function () {
    $csv = "Quotation Date: 05-06-2026\nCode,Description,Unit,Qty,Unit Price\nCB-60,Circuit Breaker 60A,pcs,4,850\n";
    $batch = dnUploadQuote($this, dnCsv('quote.csv', $csv));

    expect($batch->meta['quoted_on'])->toBeNull()
        ->and($batch->meta['document_dates']['quoted_on']['original'])->toBe('05-06-2026');
    $issues = dnQuotePayload($this, $batch)['date_issues'];
    expect($issues[0])->toMatchArray(['level' => 'error', 'field' => 'quoted_on', 'original' => '05-06-2026'])
        ->and($issues[0]['message'])->toContain('Date could not be interpreted reliably: 05-06-2026. Please verify the intended date format.');

    dnAs($this)->postJson("/date-team/imports/batches/{$batch->id}/confirm")->assertStatus(422)
        ->assertJsonPath('message', $issues[0]['message']);
    expect(SupplierQuote::count())->toBe(0);

    // Corrections are validated on the server: real calendar dates in YYYY-MM-DD only.
    dnAs($this)->patchJson("/date-team/imports/batches/{$batch->id}/dates", ['quoted_on' => '2026-02-30'])->assertStatus(422);
    dnAs($this)->patchJson("/date-team/imports/batches/{$batch->id}/dates", ['quoted_on' => '05-06-2026'])->assertStatus(422);
    dnAs($this)->patchJson("/date-team/imports/batches/{$batch->id}/dates", ['quoted_on' => '2026-05-06', 'valid_until' => '2026-05-01'])->assertStatus(422);

    $payload = dnAs($this)->patchJson("/date-team/imports/batches/{$batch->id}/dates", ['quoted_on' => '2026-05-06'])->assertOk()->json('batch');
    expect($payload['meta']['quoted_on'])->toBe('2026-05-06')
        ->and($payload['meta']['date_sources']['quoted_on'])->toBe('edited')
        ->and($payload['meta']['document_dates']['quoted_on']['original'])->toBe('05-06-2026')
        ->and(collect($payload['date_issues'])->where('level', 'error'))->toBeEmpty();
    expect(AuditLog::where('event', 'import.dates_corrected')->sole()->metadata)
        ->toMatchArray(['after' => ['quoted_on' => '2026-05-06', 'valid_until' => null], 'document_original' => ['quoted_on' => '05-06-2026', 'valid_until' => null]]);

    dnAs($this)->postJson("/date-team/imports/batches/{$batch->id}/confirm")->assertOk();
    expect(dnStored('supplier_quotes', 'quoted_on', SupplierQuote::sole()->id))->toBe('2026-05-06');
});

it('flags an invalid document date and never replaces a date staff entered', function () {
    $invalid = dnUploadQuote($this, dnCsv('a.csv', "Date: 31-02-2026\nDescription,Qty,Unit Price\nItem,1,5\n"));
    $issues = dnQuotePayload($this, $invalid)['date_issues'];
    expect($invalid->meta['quoted_on'])->toBeNull()
        ->and($issues[0]['level'])->toBe('error')
        ->and($issues[0]['message'])->toContain('"31-02-2026" is not a real calendar date: February 2026 has 28 days');

    $entered = dnUploadQuote($this, dnCsv('b.csv', "Date: 2026-10-01\nDescription,Qty,Unit Price\nItem,1,5\n"), ['quoted_on' => '2026-10-05']);
    $issues = dnQuotePayload($this, $entered)['date_issues'];
    expect($entered->meta['quoted_on'])->toBe('2026-10-05')
        ->and($entered->meta['date_sources']['quoted_on'])->toBe('entered')
        ->and($issues[0]['level'])->toBe('warning')
        ->and($issues[0]['message'])->toContain('reads 2026-10-01 ("2026-10-01"), but 2026-10-05 is set');

    // A date that cannot be found at all must be entered before importing.
    $none = dnUploadQuote($this, dnCsv('c.csv', "Description,Qty,Unit Price\nItem,1,5\n"));
    expect(dnQuotePayload($this, $none)['date_issues'][0]['message'])->toContain('No quotation date was found');
});

it('validates dates typed at upload on the server', function () {
    dnAs($this)->postJson('/date-team/imports/supplier_quotation', ['file' => dnCsv('q.csv', "Description,Qty\nItem,1\n"), 'supplier_id' => $this->supplier->id, 'quoted_on' => '2026-02-30'])
        ->assertStatus(422)->assertJsonPath('message', 'The quotation date must be a valid date (YYYY-MM-DD).');
    dnAs($this)->postJson('/date-team/imports/supplier_quotation', ['file' => dnCsv('q.csv', "Description,Qty\nItem,1\n"), 'supplier_id' => $this->supplier->id, 'quoted_on' => '11/03/2026'])
        ->assertStatus(422);
});

it('reads real Excel date cells and text dates as YYYY-MM-DD', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([
        ['ABC Electrical Trading', ''],
        ['Quotation Date:', null],
        ['Valid until:', '31/01/2026'],
        ['Description', 'Qty', 'Unit Price'],
        ['THHN Wire 3.5mm', 46329, 1250],   // 46329 in a plain number cell stays a quantity
    ]);
    $sheet->setCellValue('B2', ExcelDate::PHPToExcel(new DateTime('2025-12-25')));
    $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
    $path = $this->dnDir.DIRECTORY_SEPARATOR.'quote.xlsx';
    (new Xlsx($book))->save($path);

    $batch = dnUploadQuote($this, new UploadedFile($path, 'quote.xlsx', null, null, true));

    expect($batch->meta['quoted_on'])->toBe('2025-12-25')
        ->and($batch->meta['valid_until'])->toBe('2026-01-31')
        ->and($batch->rows()->sole()->values['quantity'])->toEqual(46329);
});

it('reads the quotation date from a text-based PDF', function () {
    if (dnMissing('pdftotext')) {
        $this->markTestSkipped('Poppler pdftotext is not installed.');
    }
    $path = $this->dnDir.DIRECTORY_SEPARATOR.'quote.pdf';
    dnPdf($path, '<html><body style="font-family:Helvetica;font-size:12px"><h2>ABC Electrical Trading</h2><p>Quotation Date: 25/12/2025</p>'
        .'<table border="1" cellpadding="4" style="border-collapse:collapse"><tr><th>Description</th><th>Qty</th><th>Unit Price</th></tr>'
        .'<tr><td>THHN Wire 3.5mm</td><td>10</td><td>1250.00</td></tr></table></body></html>');

    $batch = dnUploadQuote($this, new UploadedFile($path, 'quote.pdf', 'application/pdf', null, true));

    expect($batch->extraction_method)->toBe('pdf-text')
        ->and($batch->meta['quoted_on'])->toBe('2025-12-25')
        ->and($batch->meta['document_dates']['quoted_on']['original'])->toBe('25/12/2025');
});

it('reads the quotation date from a scanned PDF with Poppler and Tesseract', function () {
    if (dnMissing('tesseract', 'pdftoppm')) {
        $this->markTestSkipped('Tesseract or Poppler pdftoppm is not installed.');
    }
    ob_start();
    imagepng(dnRender('Quotation Date: 25-12-2025'));
    $png = base64_encode((string) ob_get_clean());
    $path = $this->dnDir.DIRECTORY_SEPARATOR.'scan.pdf';
    dnPdf($path, '<html><body><img src="data:image/png;base64,'.$png.'" style="width:100%"></body></html>');

    $batch = dnUploadQuote($this, new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true));

    expect($batch->extraction_method)->toBe('pdf-ocr')
        ->and($batch->meta['quoted_on'])->toBe('2025-12-25');
});

it('reads the quotation date from PNG and JPG photos, including a slightly tilted one', function (string $name, string $dateLine, float $tilt, string $iso) {
    if (dnMissing('tesseract')) {
        $this->markTestSkipped('Tesseract is not installed.');
    }
    $img = dnRender($dateLine, $tilt);
    $path = $this->dnDir.DIRECTORY_SEPARATOR.$name;
    str_ends_with($name, '.png') ? imagepng($img, $path) : imagejpeg($img, $path, 92);

    $batch = dnUploadQuote($this, new UploadedFile($path, $name, null, null, true));

    expect($batch->extraction_method)->toBe('ocr')
        ->and($batch->meta['quoted_on'])->toBe($iso)
        ->and($batch->rows()->count())->toBe(2);
})->with([
    'PNG, DD-MM-YYYY' => ['quote.png', 'Quotation Date: 25-12-2025', 0.0, '2025-12-25'],
    'JPG, MM-DD-YYYY' => ['quote.jpg', 'Quotation Date: 12-25-2025', 0.0, '2025-12-25'],
    'JPG tilted 2°, YYYY-MM-DD' => ['tilted.jpg', 'Quotation Date: 2025-12-25', 2.0, '2025-12-25'],
]);

it('flags an ambiguous date read by OCR instead of guessing it', function () {
    if (dnMissing('tesseract')) {
        $this->markTestSkipped('Tesseract is not installed.');
    }
    $path = $this->dnDir.DIRECTORY_SEPARATOR.'ambiguous.png';
    imagepng(dnRender('Quotation Date: 05-06-2026'), $path);

    $batch = dnUploadQuote($this, new UploadedFile($path, 'ambiguous.png', null, null, true));

    expect($batch->meta['quoted_on'])->toBeNull()
        ->and($batch->meta['document_dates']['quoted_on']['status'])->toBe('ambiguous')
        ->and(dnQuotePayload($this, $batch)['date_issues'][0]['message'])->toContain('Date could not be interpreted reliably: 05-06-2026.');
});
