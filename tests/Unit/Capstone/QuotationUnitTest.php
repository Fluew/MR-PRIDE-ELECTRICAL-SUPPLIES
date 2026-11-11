<?php

/*
 * 4.4.1 Unit Testing — Quotation Management (UT-15 to UT-28).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 */

use App\Enums\QuotationStatus;
use App\Models\Brand;
use App\Models\Quotation;
use App\Models\Team;
use App\Services\Import\ValueParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
});

function capRequest(array $overrides = []): array
{
    return array_merge([
        'company' => 'Acme Builders', 'contactName' => 'Juan Dela Cruz', 'email' => 'juan@acme.test', 'phone' => '09171234567',
        'projectType' => 'Industrial Facility', 'timeline' => '1-3 months',
    ], $overrides);
}

function capPng(string $name = 'list.png', int $width = 300): UploadedFile
{
    $img = imagecreatetruecolor($width, 200);
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

/** A staff quotation form with one line. */
function capStaffQuotation(array $line = [], array $overrides = []): array
{
    return $overrides + [
        'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com', 'status' => 'draft',
        'items' => [$line + ['sku' => 'BRK-30A', 'product_name' => 'Circuit Breaker 30A', 'quantity' => 2, 'unit_price' => 250]],
    ];
}

it('UT-15 requires company, contact name, email and phone on a quotation request', function () {
    $this->post('/quotation', [])->assertSessionHasErrors(['company', 'contactName', 'email', 'phone']);

    expect(Quotation::count())->toBe(0);
});

it('UT-16 rejects a badly formed email address and an unlisted project type', function () {
    $this->post('/quotation', capRequest(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    $this->post('/quotation', capRequest(['projectType' => 'Spaceport']))->assertSessionHasErrors('projectType');

    expect(Quotation::count())->toBe(0);
});

it('UT-17 saves a complete quotation request as a Draft', function () {
    $this->post('/quotation', capRequest())->assertSessionHasNoErrors();

    $quotation = Quotation::sole();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->client_name)->toBe('Acme Builders')
        ->and($quotation->email)->toBe('juan@acme.test')
        ->and((float) $quotation->grand_total)->toBe(0.0);
});

it('UT-18 refuses zero, negative, decimal and non-numeric line quantities', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $post = fn (mixed $quantity) => $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations', capStaffQuotation(['quantity' => $quantity]));

    foreach ([0, -1, 1.5, 'abc', ''] as $invalid) {
        $post($invalid)->assertSessionHasErrors('items.0.quantity');
    }
    expect(Quotation::count())->toBe(0);

    $post(1)->assertSessionHasNoErrors(); // the smallest valid quantity
    expect(Quotation::sole()->items->sole()->quantity)->toBe(1);
});

it('UT-19 calculates subtotal, discount, tax and grand total of a quotation', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);

    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations', capStaffQuotation(['quantity' => 4, 'unit_price' => 250], ['discount' => 100, 'tax_rate' => 12]))
        ->assertSessionHasNoErrors();

    // 4 × 250 = 1,000.00; less 100.00 = 900.00; 12% tax = 108.00; total = 1,008.00.
    $quotation = Quotation::sole();
    expect((float) $quotation->subtotal)->toBe(1000.0)
        ->and((float) $quotation->discount)->toBe(100.0)
        ->and((float) $quotation->tax_amount)->toBe(108.0)
        ->and((float) $quotation->grand_total)->toBe(1008.0);
});

it('UT-20 requires at least one line, a customer name and a non-negative price', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $post = fn (array $data) => $this->actingAs($staff)->withCookies($cookies)->post('/mr-pride/quotations', $data);

    $post(['client_name' => 'Metro Builders Inc.', 'status' => 'draft', 'items' => []])->assertSessionHasErrors('items');
    $post(capStaffQuotation([], ['client_name' => '']))->assertSessionHasErrors('client_name');
    $post(capStaffQuotation(['unit_price' => -1]))->assertSessionHasErrors('items.0.unit_price');
    $post(capStaffQuotation([], ['tax_rate' => 101]))->assertSessionHasErrors('tax_rate');

    expect(Quotation::count())->toBe(0);
});

it('UT-21 accepts an existing brand whatever its letter case and stores the catalog spelling', function () {
    Brand::firstOrCreate(['name' => 'Schneider Electric'], ['is_active' => true]);

    $this->post('/quotation', capRequest(['brands' => [['brand' => 'schneider electric', 'details' => '20A breakers']]]))
        ->assertSessionHasNoErrors();

    expect(Quotation::sole()->request_details['brands'])->toBe([['brand' => 'Schneider Electric', 'details' => '20A breakers']]);
});

it('UT-22 rejects a brand that is not in the catalog', function () {
    Brand::firstOrCreate(['name' => 'Schneider Electric'], ['is_active' => true]);

    $this->post('/quotation', capRequest(['brands' => ['Made Up Brand']]))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

it('UT-23 needs the brand names when "brand not listed" is ticked, and then saves them', function () {
    $this->post('/quotation', capRequest(['hasUnlistedBrands' => true, 'unlistedBrands' => '   ']))->assertSessionHasErrors('unlistedBrands');
    expect(Quotation::count())->toBe(0);

    $this->post('/quotation', capRequest(['hasUnlistedBrands' => true, 'unlistedBrands' => 'Omron, Legrand']))->assertSessionHasNoErrors();
    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['unlisted_brands'])->toBe('Omron, Legrand')
        ->and($quotation->items->pluck('sku')->all())->toBe(['BRAND-UNLISTED']);
});

it('UT-24 allows only the defined quotation status transitions', function () {
    expect(QuotationStatus::Draft->canMoveTo(QuotationStatus::Sent))->toBeTrue()
        ->and(QuotationStatus::Draft->canMoveTo(QuotationStatus::Accepted))->toBeFalse()   // must be sent first
        ->and(QuotationStatus::Sent->canMoveTo(QuotationStatus::Accepted))->toBeTrue()
        ->and(QuotationStatus::Sent->canMoveTo(QuotationStatus::Draft))->toBeTrue()        // revise
        ->and(QuotationStatus::Accepted->allowedNext())->toBe([])                           // final
        ->and(QuotationStatus::Rejected->canMoveTo(QuotationStatus::Draft))->toBeTrue()
        ->and(QuotationStatus::Expired->canMoveTo(QuotationStatus::Sent))->toBeFalse()
        ->and(QuotationStatus::Draft->isEditable())->toBeTrue()
        ->and(QuotationStatus::Sent->isEditable())->toBeFalse()
        ->and(QuotationStatus::Accepted->canConvertToSale())->toBeTrue()
        ->and(QuotationStatus::Sent->canConvertToSale())->toBeFalse()
        ->and(QuotationStatus::tryFrom('pending'))->toBeNull();
});

it('UT-25 refuses a status that staff may not set by hand', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $quotation = capQuotation($this->team, QuotationStatus::Draft, [[capProduct($this->team), 1]]);
    $patch = fn (string $status) => $this->actingAs($staff)->withCookies($cookies)->patch("/mr-pride/quotations/{$quotation->id}/status", ['status' => $status]);

    $patch('accepted')->assertSessionHasErrors('status');
    $patch('sent')->assertSessionHasErrors('status');
    $patch('not-a-status')->assertSessionHasErrors('status');
    $patch('')->assertSessionHasErrors('status');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});

it('UT-26 restricts uploaded product-list files by type, size and number', function () {
    $post = fn (array $files) => $this->post('/quotation', capRequest(['productFiles' => $files]));

    $post([UploadedFile::fake()->createWithContent('setup.exe', 'MZ...')])->assertSessionHasErrors('productFiles.0');
    $post([UploadedFile::fake()->createWithContent('list.pdf', '<?php echo 1;')])->assertSessionHasErrors('productFiles.0'); // a script renamed to .pdf
    $post([UploadedFile::fake()->create('big.pdf', 25 * 1024 + 1, 'application/pdf')])->assertSessionHasErrors('productFiles.0'); // 1 KB over 25 MB
    $post(array_map(fn ($i) => capPng("p{$i}.png", 300 + $i), range(1, 6)))->assertSessionHasErrors('productFiles');           // 6 files: one too many

    expect(Quotation::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('quotations/product-lists'))->toBe([]);
});

it('UT-27 accepts a supported product-list file and stores it with the request', function () {
    fakeImageOcr();

    $this->post('/quotation', capRequest(['productFiles' => [capPng('site-list.png')]]))->assertSessionHasNoErrors();

    $file = Quotation::with('files')->sole()->files->sole();
    expect($file->original_name)->toBe('site-list.png');
    Storage::disk('local')->assertExists($file->path);
});

it('UT-28 extracts description, quantity and unit from an OCR-read list (OCR engine mocked)', function () {
    fakeImageOcr(); // returns: "1. Circuit breaker 2P 30A - 20 pcs", "2. THHN wire 3.5mm - 100 meters", "3. PVC conduit 20mm - 50 pieces"

    $json = $this->post('/quotation/product-list/extract', ['file' => capPng('site-list.png')], ['Accept' => 'application/json'])
        ->assertOk()->json();

    expect($json['items'])->toHaveCount(3)
        ->and($json['items'][0]['description'])->toContain('Circuit breaker 2P 30A')
        ->and((float) $json['items'][0]['quantity'])->toBe(20.0)
        ->and((float) $json['items'][1]['quantity'])->toBe(100.0)
        ->and((float) $json['items'][2]['quantity'])->toBe(50.0);
});

it('UT-29 reports OCR as unavailable instead of inventing rows', function () {
    fakeImageOcr(available: false);

    $response = $this->post('/quotation/product-list/extract', ['file' => capPng('site-list.png')], ['Accept' => 'application/json']);

    expect($response->json('items') ?? [])->toBe([])
        ->and((string) $response->json('message'))->toContain("can't read scanned documents or pictures automatically");
});

it('UT-30 validates extracted number fields and corrects OCR look-alike characters, reporting the correction', function () {
    expect(ValueParser::number('₱1,250.00'))->toMatchArray(['value' => 1250.0, 'problem' => null])
        ->and(ValueParser::number('1.250,50')['value'])->toBe(1250.5)
        ->and(ValueParser::number('(15.00)')['value'])->toBe(-15.0)
        ->and(ValueParser::number('75 /pc')['value'])->toBe(75.0)
        ->and(ValueParser::number('')['value'])->toBeNull()
        ->and(ValueParser::number('abc'))->toMatchArray(['value' => null, 'problem' => '"abc" is not a readable number'])
        // OCR reading: letters inside a number are corrected and the original is reported.
        ->and(ValueParser::number('l5', ocr: true))->toMatchArray(['value' => 15.0, 'corrected_from' => 'l5'])
        ->and(ValueParser::number('1O', ocr: true))->toMatchArray(['value' => 10.0, 'corrected_from' => '1O'])
        // Not an OCR reading: the same text is refused, never guessed.
        ->and(ValueParser::number('l5')['value'])->toBeNull()
        ->and(ValueParser::quantityAndUnit('10 pcs'))->toMatchArray(['value' => 10.0, 'unit' => 'pcs'])
        ->and(ValueParser::quantityAndUnit('25 Rolls'))->toMatchArray(['value' => 25.0, 'unit' => 'roll'])
        ->and(ValueParser::unit('bucket'))->toBeNull()
        ->and(ValueParser::date('45000')['value'])->toBe('2023-03-15')   // Excel serial date
        ->and(ValueParser::date('2026-02-31')['value'])->not->toBe('2026-02-31');
});
