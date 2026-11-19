<?php

use App\Enums\QuotationStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

function brandProduct(Team $team, string $name, ?string $brand, bool $active = true): Product
{
    return Product::create([
        'team_id' => $team->id,
        'name' => $name,
        'sku' => 'SKU-'.strtoupper(substr(md5($name), 0, 6)),
        'category' => 'General',
        'price' => 10,
        'stock' => 5,
        'brand' => $brand,
        'is_active' => $active,
    ]);
}

/** A valid request: contact details with a chosen country code and a digits-only number. Products are added per test. */
function validQuotationPayload(array $overrides = []): array
{
    return array_merge([
        'company' => 'Acme Builders',
        'contactName' => 'Juan Dela Cruz',
        'email' => 'juan@acme.test',
        'phoneCountry' => '+63',
        'phone' => '9171234567',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
    ], $overrides);
}

/** One product row as the form sends it: a brand, the client's description and a whole-number quantity. */
function brandRow(string $brand, string $details = 'Circuit breaker 20A', string $quantity = '5', string $brandName = ''): array
{
    return ['brand' => $brand, 'brand_name' => $brandName, 'details' => $details, 'quantity' => $quantity];
}

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('lists each catalog brand once, without product names or SKUs', function () {
    Brand::truncate();
    brandProduct($this->team, 'Breaker A', 'Schneider');
    brandProduct($this->team, 'Breaker B', 'Schneider');
    brandProduct($this->team, 'Breaker C', ' schneider ');
    brandProduct($this->team, 'Cable', 'Phelps Dodge');
    brandProduct($this->team, 'No brand', null);
    brandProduct($this->team, 'Blank brand', '   ');
    brandProduct($this->team, 'Inactive', 'Hidden Brand', active: false);

    $this->get('/quotation')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotations/Create')
            ->where('brands', ['Phelps Dodge', 'Schneider'])
            ->has('phoneRules.countries')
            ->where('phoneRules.default', '+63')
            ->missing('products'),
        );
});

it('saves selected brands as quotation lines and in the notes', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');
    brandProduct($this->team, 'Cable', 'Phelps Dodge');

    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('Schneider', 'MCB 20A', '10'), brandRow('Phelps Dodge', 'THHN 3.5mm', '4')]]))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->items->where('sku', 'BRAND')->pluck('product_name')->values()->all())->toBe(['Brand: Schneider - MCB 20A', 'Brand: Phelps Dodge - THHN 3.5mm'])
        ->and($quotation->items->pluck('product_id')->filter()->all())->toBe([])
        ->and($quotation->notes)->toContain('Brands Requested')->toContain('Schneider, Phelps Dodge')
        ->and($quotation->client_name)->toBe('Acme Builders')
        ->and($quotation->phone)->toBe('+63 9171234567');
});

it('accepts brand names case-insensitively and stores the catalog spelling once', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('schneider'), brandRow('SCHNEIDER')]]))
        ->assertSessionHasNoErrors();

    expect(Quotation::firstOrFail()->items->where('sku', 'BRAND')->pluck('product_name')->values()->all())->toBe(['Brand: Schneider - Circuit breaker 20A']);
});

it('saves brands that are not listed in a separate line', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Schneider', 'MCB 20A', '3')],
        'hasUnlistedBrands' => true,
        'unlistedBrands' => 'Omron, Legrand',
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->items->pluck('sku')->all())->toBe(['BRAND', 'BRAND-UNLISTED'])
        ->and($quotation->items->firstWhere('sku', 'BRAND-UNLISTED')->product_name)->toContain('Omron, Legrand')
        ->and($quotation->notes)->toContain('Brands Not Listed')->toContain('Omron, Legrand')
        ->and($quotation->request_details['brands'])->toBe([['brand' => 'Schneider', 'details' => 'MCB 20A', 'quantity' => 3]])
        ->and($quotation->request_details['unlisted_brands'])->toBe('Omron, Legrand');
});

it('ignores the unlisted brand text when the option is not ticked', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('Schneider')], 'hasUnlistedBrands' => false, 'unlistedBrands' => 'Omron']))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['unlisted_brands'])->toBeNull()
        ->and($quotation->items->where('sku', 'BRAND-UNLISTED'))->toHaveCount(0)
        ->and($quotation->notes)->not->toContain('Omron');
});

it('no longer accepts a request that only names unlisted brands: a product with its description and quantity is needed', function () {
    $this->post('/quotation', validQuotationPayload(['hasUnlistedBrands' => true, 'unlistedBrands' => 'Omron']))
        ->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

it('requires a product (or a product list): a request with no product rows is refused', function () {
    $this->post('/quotation', validQuotationPayload())->assertSessionHasErrors(['brands' => 'Add at least one product (brand, description and quantity), or upload a product list.']);
    $this->post('/quotation', validQuotationPayload(['brands' => ['']]))->assertSessionHasErrors('brands');
    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('', '', '')]]))->assertSessionHasErrors('brands');   // an empty row is ignored

    expect(Quotation::count())->toBe(0);
});

it('requires brand names when the unlisted option is ticked', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('Schneider')], 'hasUnlistedBrands' => true, 'unlistedBrands' => '  ']))
        ->assertSessionHasErrors('unlistedBrands');

    expect(Quotation::count())->toBe(0);
});

it('rejects brands that are not in the catalog or belong to inactive products', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');
    brandProduct($this->team, 'Old', 'Retired', active: false);

    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('Made Up')]]))->assertSessionHasErrors('brands');
    $this->post('/quotation', validQuotationPayload(['brands' => [brandRow('Schneider'), brandRow('Retired', 'Other thing', '2')]]))->assertSessionHasErrors('brands');
    $this->post('/quotation', validQuotationPayload(['brands' => 'Schneider']))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

it('still validates the customer fields', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', ['brands' => [brandRow('Schneider')]])
        ->assertSessionHasErrors(['company', 'contactName', 'email', 'phone', 'phoneCountry']);
});

it('ignores removed quantity, notes and additional requirement fields', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Schneider', 'Breaker', '1')],
        'additionalRequirements' => 'Deliver by Friday',
        'items' => [['product_id' => 1, 'quantity' => 500, 'specifications' => 'x']],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->notes)->not->toContain('Deliver by Friday')
        ->and($quotation->items->where('sku', 'BRAND'))->toHaveCount(1)
        ->and($quotation->items->firstWhere('sku', 'BRAND')->quantity)->toBe(1)
        ->and($quotation->items->where('sku', 'CUSTOM'))->toHaveCount(0);
});

it('keeps existing quotations with product and custom items readable', function () {
    $product = brandProduct($this->team, 'Breaker', 'Schneider');
    $quotation = Quotation::create([
        'team_id' => $this->team->id, 'quotation_number' => 'Q-OLD-1', 'client_name' => 'Old Client',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->create([
        'product_id' => $product->id, 'sku' => $product->sku, 'product_name' => 'Breaker',
        'quantity' => 4, 'unit_price' => 10, 'discount' => 0, 'subtotal' => 40,
    ]);

    $fresh = Quotation::with('items')->find($quotation->id);
    expect($fresh->items->first()->quantity)->toBe(4)->and($fresh->items->first()->product_id)->toBe($product->id);
});

it('stores an optional brand on a product', function () {
    $product = brandProduct($this->team, 'Breaker', null);
    $product->update(['brand' => 'Eaton']);

    expect($product->fresh()->brand)->toBe('Eaton');
});

it('lists partner brands stored in the brands table', function () {
    Brand::truncate();
    Brand::create(['name' => 'DeWalt', 'category' => 'Hardware & Machinery', 'is_partner' => true, 'is_active' => true]);
    Brand::create(['name' => 'Himel', 'category' => 'Electrical Materials', 'is_partner' => true, 'is_active' => true]);

    $this->get('/quotation')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotations/Create')
            ->where('brands', ['DeWalt', 'Himel'])
        );
});

it('saves selected brands with specific quantity and requirements', function () {
    Brand::firstOrCreate(['name' => 'Schneider Electric'], ['is_active' => true]);

    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Schneider Electric', '20A Miniature Circuit Breakers', '50')],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->items->first()->product_name)->toContain('Brand: Schneider Electric - 20A Miniature Circuit Breakers')
        ->and($quotation->items->first()->quantity)->toBe(50)
        ->and($quotation->notes)->toContain('20A Miniature Circuit Breakers');
});

it('accepts "Others" with a product description that does not need to match inventory', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [
            brandRow('Schneider', '2-pole 30A', '10'),
            brandRow('Others', 'Weatherproof floodlight 200W', '6', 'Philips'),
            brandRow('Others', 'Cable tray 100 mm', '20', 'Panduit'),
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    $others = $quotation->items->where('sku', 'OTHERS');
    expect($others)->toHaveCount(2)
        ->and($others->pluck('product_name')->all())->toBe(['Others (Philips): Weatherproof floodlight 200W', 'Others (Panduit): Cable tray 100 mm'])
        ->and($others->pluck('product_id')->filter())->toBeEmpty() // never matched to inventory
        ->and($quotation->items->firstWhere('sku', 'BRAND')->product_name)->toBe('Brand: Schneider - 2-pole 30A')
        ->and($quotation->notes)->toContain('Other Products (brand not listed)')->toContain('Weatherproof floodlight 200W')
        ->and($quotation->notes)->toContain("Brands Requested ---\nSchneider\n") // "Others" is not a brand
        ->and($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->valid_until)->toBeNull() // set when approved
        ->and((float) $quotation->tax_rate)->toBe(0.0);
});

it('requires a product description when the brand is "Others"', function () {
    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Others', '   ', '3', 'Omron')],
    ]))->assertSessionHasErrors(['brands' => 'Brand #1: describe the product you need.']);

    expect(Quotation::count())->toBe(0);
});

it('requires the brand name when the brand is "Others"', function () {
    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Others', 'Relay 24V', '4', '  ')],
    ]))->assertSessionHasErrors(['brands' => 'Please enter the brand name for each "Others" row.']);

    expect(Quotation::count())->toBe(0);
});

it('saves the description and quantity of each brand row, and the brand name of Others rows', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [
            brandRow('Schneider', 'Miniature circuit breaker 20A', '50'),
            brandRow('Others', 'Relay 24V', '3', 'Omron'),
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['brands'])->toBe([
        ['brand' => 'Schneider', 'details' => 'Miniature circuit breaker 20A', 'quantity' => 50],
        ['brand' => 'Others', 'brand_name' => 'Omron', 'details' => 'Relay 24V', 'quantity' => 3],
    ])
        ->and($quotation->items->map(fn ($i) => [$i->sku, $i->product_name, $i->quantity])->all())->toBe([
            ['BRAND', 'Brand: Schneider - Miniature circuit breaker 20A', 50],
            ['OTHERS', 'Others (Omron): Relay 24V', 3],
        ])
        ->and($quotation->notes)->toContain('• Schneider: Miniature circuit breaker 20A · Qty 50')
        ->and($quotation->notes)->toContain('• Omron: Relay 24V · Qty 3');
});

it('refuses a brand quantity that is not a whole number greater than zero', function (string $quantity) {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [brandRow('Schneider', 'Breaker', $quantity)],
    ]))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
})->with(['ten', '0', '-3', '10 pcs', '2.5', '1,000', '1.2345', '2000000', '1e3', '+5', '０５']);
