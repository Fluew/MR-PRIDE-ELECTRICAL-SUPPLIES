<?php

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Team;
use App\Models\User;
use App\Services\Documents\DocumentImage;
use App\Services\Documents\DocumentPdf;
use App\Services\Documents\QuotationDocument;
use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser;

/*
 * The official MR-PRIDE quotation: one template for the PDF download, the PDF sent to the client,
 * the PNG/JPG export and printing. Data comes from the stored quotation, client and products.
 */

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'quote-team']);
    $member = function (string $role, string $name): array {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
        $this->team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

        return [$user, createTrustedDeviceFor($user)['cookies']];
    };
    [$this->staff, $this->staffCookies] = $member('staff_quotations', 'Maria Reyes');
    [$this->inventory, $this->inventoryCookies] = $member('staff_inventory', 'Ivan Store');

    $wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WR-35', 'name' => 'THHN Wire 3.5mm²', 'unit' => 'roll', 'price' => 100, 'cost_price' => 70, 'stock' => 10, 'is_active' => true]);
    $breaker = Product::create(['team_id' => $this->team->id, 'sku' => 'CB-60', 'name' => 'Circuit Breaker 60A', 'unit' => 'pcs', 'price' => 50, 'cost_price' => 30, 'stock' => 10, 'is_active' => true]);
    $client = Client::create(['team_id' => $this->team->id, 'company_name' => 'Acme Builders Corp.', 'contact_person' => 'Juan Dela Cruz', 'address' => '123 Rizal Ave., Manila', 'phone' => '+63 917 123 4567']);

    // Stored the way the app saves quotations: line discount is per unit; VAT on (subtotal - discount).
    // Lines: 10 × 100 (1 off per unit) = 990; 4 × 50 = 200 → subtotal 1,190; overall discount 40;
    // VAT 12% of 1,150 = 138 → grand total 1,288.
    $this->quotation = Quotation::create([
        'team_id' => $this->team->id, 'quotation_number' => 'Q110326-WR35', 'client_id' => $client->id,
        'client_name' => 'Acme Builders Corp.', 'contact_person' => 'Juan Dela Cruz', 'address' => '123 Rizal Ave., Manila', 'phone' => '+63 917 123 4567',
        'quotation_date' => '2026-11-03', 'valid_until' => '2026-11-18',
        'subtotal' => 1190, 'discount' => 40, 'tax_rate' => 12, 'tax_amount' => 138, 'grand_total' => 1288,
        'status' => 'draft', 'prepared_by_id' => $this->staff->id,
    ]);
    QuotationItem::create(['quotation_id' => $this->quotation->id, 'product_id' => $wire->id, 'sku' => 'WR-35', 'product_name' => 'THHN Wire 3.5mm²', 'quantity' => 10, 'unit_price' => 100, 'discount' => 1, 'subtotal' => 990]);
    QuotationItem::create(['quotation_id' => $this->quotation->id, 'product_id' => $breaker->id, 'sku' => 'CB-60', 'product_name' => 'Circuit Breaker 60A', 'quantity' => 4, 'unit_price' => 50, 'discount' => 0, 'subtotal' => 200]);
});

function quoteText(string $pdf): string
{
    return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText()) ?? '';
}

it('builds the document from the stored quotation, its client and products, matching the stored totals', function () {
    $doc = QuotationDocument::data($this->quotation);

    expect($doc['client'])->toBe(['name' => 'Juan Dela Cruz', 'company' => 'Acme Builders Corp.', 'address' => '123 Rizal Ave., Manila', 'phone' => '+63 917 123 4567'])
        ->and($doc['number'])->toBe('Q110326-WR35')
        ->and($doc['date'])->toBe('November 3, 2026')
        ->and($doc['rows'])->toBe([
            ['no' => 1, 'description' => 'THHN Wire 3.5mm²', 'quantity' => 10, 'unit' => 'roll', 'unit_price' => 100.0, 'amount' => 1000.0],
            ['no' => 2, 'description' => 'Circuit Breaker 60A', 'quantity' => 4, 'unit' => 'pcs', 'unit_price' => 50.0, 'amount' => 200.0],
        ])
        ->and($doc['sub_total'])->toBe(1200.0)      // Σ quantity × unit price
        ->and($doc['discount'])->toBe(50.0)         // 10 × 1 line discount + 40 overall
        ->and($doc['tax'])->toBe(138.0)
        ->and($doc['total'])->toBe(1288.0)          // the stored grand total
        ->and($doc['sub_total'] - $doc['discount'] + $doc['tax'])->toBe($doc['total'])
        ->and($doc['valid_days'])->toBe(15)
        ->and($doc['prepared_by'])->toBe('Maria Reyes');
});

it('prints the official header, client, items, summary, exact terms, bank details and signatures', function () {
    $text = quoteText(app(DocumentPdf::class)->quotation($this->quotation));

    foreach ([
        'MR-PRIDE Electrical Supplies', '610 Esguerra St. Tondo Manila Philippines 1012',
        'Contact Number: +63 9397366907 / +63 9083602754', 'Email: sales.rcjlent@gmail.com',
        'Name: Juan Dela Cruz', 'Company Name: Acme Builders Corp.', 'Address: 123 Rizal Ave., Manila', 'Contact Number: +63 917 123 4567',
        'Date: November 3, 2026', 'Quotation No.: Q110326-WR35',
        'We are pleased to submit to you the quotation for the following items:',
        'ITEM NO', 'DESCRIPTION', 'QTY', 'UNIT', 'UNIT PRICE', 'AMOUNT',
        'THHN Wire 3.5mm²', 'roll', 'USD 100.00', 'USD 1,000.00', 'Circuit Breaker 60A', 'USD 200.00',
        'Sub Total USD 1,200.00', 'Less Discount USD 50.00', 'VAT (12%) USD 138.00', 'TOTAL AMOUNT USD 1,288.00',
        '100% ADVANCED PAYMENT (PROCESSING P.O UPON RECEIVED PAYMENT)',
        'THIS QUOTATION IS VALID 15 DAYS',
        'DELIVERY LEAD TIME ON STOCK SUBJECT TO PRIOR SALE',
        'PRICES QUOTED HEREIN ARE SUBJECT TO CHANGE WITHOUT PRIOR NOTICE',
        'CANCELLATION CHARGES: A CHARGE WILL BE MADE ON ALL CANCELLED ORDER, TOGETHER WITH THE CHARGE FOR ALL WORK CARRIED OUT TO THE DATE OF WRITTEN CANCELLATION WILL BE CHARGES 25% OF PURCHASE ORDER',
        'PAYMENT TO:', 'BANK NAME: CHINA BANKING CORPORATION', 'ACCOUNT NAME: MR-PRIDE ELECTRICAL SUPPLIES', 'ACCOUNT NO.: 100952000262',
        'ADDRESS: 947 APL-YSL BLDG. ILAYA ST. TONDO MANILA PHILIPPINES - ILAYA BRANCH', 'SWIFT CODE: CHBKPHMM',
        'PREPARED BY:', 'Maria Reyes', 'CONFORME:', // section labels are styled in capitals
        'Quotation No. Q110326-WR35', 'Page 1 of',
    ] as $expected) {
        expect($text)->toContain($expected);
    }
    // No internal figures on the client document.
    expect($text)->not->toContain('USD 70.00')->not->toContain('USD 30.00');
});

it('leaves Less Discount blank and shows no VAT row when the quotation has neither', function () {
    $this->quotation->items()->update(['discount' => 0]);
    $this->quotation->update(['subtotal' => 1200, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 1200]);

    $text = quoteText(app(DocumentPdf::class)->quotation($this->quotation->fresh()));

    expect($text)->toContain('Less Discount TOTAL AMOUNT USD 1,200.00')->not->toContain('VAT');
});

it('continues long quotations onto more pages with the column headings repeated', function () {
    $product = Product::where('sku', 'WR-35')->first();
    foreach (range(3, 60) as $i) {
        QuotationItem::create(['quotation_id' => $this->quotation->id, 'product_id' => $product->id, 'sku' => 'WR-35', 'product_name' => "Item line {$i}", 'quantity' => 1, 'unit_price' => 1, 'discount' => 0, 'subtotal' => 1]);
    }

    $pdf = (new Parser)->parseContent(app(DocumentPdf::class)->quotation($this->quotation->fresh()));
    $pages = $pdf->getPages();

    expect(count($pages))->toBeGreaterThan(1);
    foreach ($pages as $n => $page) {
        $text = $page->getText();
        if (str_contains($text, 'Item line')) {
            expect($text)->toContain('UNIT PRICE'); // headings on every page that has items
        }
        expect($text)->toContain('Page '.($n + 1).' of '.count($pages));
    }
    expect(quoteText(app(DocumentPdf::class)->quotation($this->quotation->fresh())))->toContain('Item line 60');
});

it('serves the PDF inline for preview and printing, and as a download', function () {
    $base = "/quote-team/quotations/{$this->quotation->id}/pdf";
    $as = $this->actingAs($this->staff)->withCookies($this->staffCookies);

    $as->get($base)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="Quotation-Q110326-WR35.pdf"');
    $as->get("{$base}?download=1")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Quotation-Q110326-WR35.pdf"');
});

it('exports the same document as PNG and JPG images', function (string $format, string $mime) {
    if (! app(DocumentImage::class)->available()) {
        $this->markTestSkipped('Poppler pdftoppm is not installed.');
    }

    $res = $this->actingAs($this->staff)->withCookies($this->staffCookies)
        ->get("/quote-team/quotations/{$this->quotation->id}/image?format={$format}")
        ->assertOk()->assertHeader('Content-Type', $mime)
        ->assertHeader('Content-Disposition', "attachment; filename=\"Quotation-Q110326-WR35.{$format}\"");

    $size = getimagesizefromstring($res->getContent());
    expect($size['mime'])->toBe($mime)
        ->and($size[0])->toBeBetween(1240, 1241); // A4 at 150 DPI
})->with([['png', 'image/png'], ['jpg', 'image/jpeg']]);

it('makes new quotations valid for 3 days and prints that validity', function () {
    expect(config('company.quotation_validity_days'))->toBe(3);

    // The staff form gets the same default.
    $this->actingAs($this->staff)->withCookies($this->staffCookies)->get('/quote-team/quotations')
        ->assertOk()->assertInertia(fn ($page) => $page->where('quotationValidityDays', 3));

    // Imported quotations: valid until the quotation date + 3 days.
    $csv = "DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n10-25-2026,Q102526-WR35,5,\"THHN Wire 3.5mm²\",100.00,500.00";
    $this->actingAs($this->staff)->withCookies($this->staffCookies)
        ->post('/quote-team/quotations/import-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $csv), 'execute' => true, 'mode' => 'append'], ['Accept' => 'application/json']);
    $imported = Quotation::where('quotation_number', 'Q102526-WR35')->firstOrFail();

    expect($imported->valid_until->toDateString())->toBe('2026-10-28')
        ->and(quoteText(app(DocumentPdf::class)->quotation($imported)))->toContain('THIS QUOTATION IS VALID 3 DAYS');
});

it('keeps the quotation document to staff with access to quotations', function () {
    $this->actingAs($this->inventory)->withCookies($this->inventoryCookies)
        ->get("/quote-team/quotations/{$this->quotation->id}/pdf")->assertForbidden();
    $this->actingAs($this->inventory)->withCookies($this->inventoryCookies)
        ->get("/quote-team/quotations/{$this->quotation->id}/image?format=png")->assertForbidden();
});
