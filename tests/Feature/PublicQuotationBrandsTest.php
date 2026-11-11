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

function validQuotationPayload(array $overrides = []): array
{
    return array_merge([
        'company' => 'Acme Builders',
        'contactName' => 'Juan Dela Cruz',
        'email' => 'juan@acme.test',
        'phone' => '09171234567',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
    ], $overrides);
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
            ->missing('products'),
        );
});

it('saves selected brands as quotation lines and in the notes', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');
    brandProduct($this->team, 'Cable', 'Phelps Dodge');

    $this->post('/quotation', validQuotationPayload(['brands' => ['Schneider', 'Phelps Dodge']]))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->items->where('sku', 'BRAND')->pluck('product_name')->values()->all())->toBe(['Brand: Schneider', 'Brand: Phelps Dodge'])
        ->and($quotation->items->pluck('product_id')->filter()->all())->toBe([])
        ->and($quotation->notes)->toContain('Brands Requested')->toContain('Schneider, Phelps Dodge')
        ->and($quotation->client_name)->toBe('Acme Builders');
});

it('accepts brand names case-insensitively and stores the catalog spelling once', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload(['brands' => ['schneider', 'SCHNEIDER']]))
        ->assertSessionHasNoErrors();

    expect(Quotation::firstOrFail()->items->where('sku', 'BRAND')->pluck('product_name')->values()->all())->toBe(['Brand: Schneider']);
});

it('saves brands that are not listed in a separate line', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => ['Schneider'],
        'hasUnlistedBrands' => true,
        'unlistedBrands' => 'Omron, Legrand',
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->items->pluck('sku')->all())->toBe(['BRAND', 'BRAND-UNLISTED'])
        ->and($quotation->items->firstWhere('sku', 'BRAND-UNLISTED')->product_name)->toContain('Omron, Legrand')
        ->and($quotation->notes)->toContain('Brands Not Listed')->toContain('Omron, Legrand')
        ->and($quotation->request_details['brands'])->toBe([['brand' => 'Schneider', 'details' => '']])
        ->and($quotation->request_details['unlisted_brands'])->toBe('Omron, Legrand');
});

it('ignores the unlisted brand text when the option is not ticked', function () {
    $this->post('/quotation', validQuotationPayload(['hasUnlistedBrands' => false, 'unlistedBrands' => 'Omron']))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['unlisted_brands'])->toBeNull()
        ->and($quotation->items->where('sku', 'BRAND-UNLISTED'))->toHaveCount(0)
        ->and($quotation->notes)->not->toContain('Omron');
});

it('accepts a request that only names unlisted brands', function () {
    $this->post('/quotation', validQuotationPayload(['hasUnlistedBrands' => true, 'unlistedBrands' => 'Omron']))
        ->assertSessionHasNoErrors();

    expect(Quotation::count())->toBe(1);
});

it('keeps brands optional', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload())->assertSessionHasNoErrors();
    $this->post('/quotation', validQuotationPayload(['brands' => ['']]))->assertSessionHasNoErrors();

    expect(Quotation::count())->toBe(2)
        ->and(Quotation::first()->items->where('sku', 'BRAND'))->toHaveCount(0);
});

it('requires brand names when the unlisted option is ticked', function () {
    $this->post('/quotation', validQuotationPayload(['hasUnlistedBrands' => true, 'unlistedBrands' => '  ']))
        ->assertSessionHasErrors('unlistedBrands');

    expect(Quotation::count())->toBe(0);
});

it('rejects brands that are not in the catalog or belong to inactive products', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');
    brandProduct($this->team, 'Old', 'Retired', active: false);

    $this->post('/quotation', validQuotationPayload(['brands' => ['Made Up']]))->assertSessionHasErrors('brands');
    $this->post('/quotation', validQuotationPayload(['brands' => ['Schneider', 'Retired']]))->assertSessionHasErrors('brands');
    $this->post('/quotation', validQuotationPayload(['brands' => 'Schneider']))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

it('still validates the customer fields', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', ['brands' => ['Schneider']])
        ->assertSessionHasErrors(['company', 'contactName', 'email', 'phone']);
});

it('ignores removed quantity, notes and additional requirement fields', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => ['Schneider'],
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
        'brands' => [
            ['brand' => 'Schneider Electric', 'details' => '50 pcs 20A Miniature Circuit Breakers'],
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();
    expect($quotation->items->first()->product_name)->toContain('Brand: Schneider Electric - 50 pcs 20A Miniature Circuit Breakers')
        ->and($quotation->notes)->toContain('50 pcs 20A Miniature Circuit Breakers');
});

it('accepts "Others" with a product description that does not need to match inventory', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [
            ['brand' => 'Schneider', 'details' => '10 pcs 2-pole 30A'],
            ['brand' => 'Others', 'brand_name' => 'Philips', 'details' => 'Weatherproof floodlight 200W, 6 pcs'],
            ['brand' => 'Others', 'brand_name' => 'Panduit', 'details' => 'Cable tray 100 mm, 20 m'],
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    $others = $quotation->items->where('sku', 'OTHERS');
    expect($others)->toHaveCount(2)
        ->and($others->pluck('product_name')->all())->toBe(['Others (Philips): Weatherproof floodlight 200W, 6 pcs', 'Others (Panduit): Cable tray 100 mm, 20 m'])
        ->and($others->pluck('product_id')->filter())->toBeEmpty() // never matched to inventory
        ->and($quotation->items->firstWhere('sku', 'BRAND')->product_name)->toBe('Brand: Schneider - 10 pcs 2-pole 30A')
        ->and($quotation->notes)->toContain('Other Products (brand not listed)')->toContain('Weatherproof floodlight 200W')
        ->and($quotation->notes)->toContain("Brands Requested ---\nSchneider\n") // "Others" is not a brand
        ->and($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->valid_until)->toBeNull() // set when approved
        ->and((float) $quotation->tax_rate)->toBe(0.0);
});

it('requires a product description when the brand is "Others"', function () {
    $this->post('/quotation', validQuotationPayload([
        'brands' => [['brand' => 'Others', 'brand_name' => 'Omron', 'details' => '   ']],
    ]))->assertSessionHasErrors(['brands' => 'Please describe the product you need for each "Others" row.']);

    expect(Quotation::count())->toBe(0);
});

it('requires the brand name when the brand is "Others"', function () {
    $this->post('/quotation', validQuotationPayload([
        'brands' => [['brand' => 'Others', 'brand_name' => '  ', 'details' => 'Relay 24V', 'quantity' => '4']],
    ]))->assertSessionHasErrors(['brands' => 'Please enter the brand name for each "Others" row.']);

    expect(Quotation::count())->toBe(0);
});

it('saves the description and quantity of each brand row, and the brand name of Others rows', function () {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [
            ['brand' => 'Schneider', 'brand_name' => '', 'details' => 'Miniature circuit breaker 20A', 'quantity' => '50'],
            ['brand' => 'Others', 'brand_name' => 'Omron', 'details' => 'Relay 24V', 'quantity' => '2.5'],
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['brands'])->toBe([
        ['brand' => 'Schneider', 'details' => 'Miniature circuit breaker 20A', 'quantity' => 50],
        ['brand' => 'Others', 'brand_name' => 'Omron', 'details' => 'Relay 24V', 'quantity' => 2.5],
    ])
        // Lines hold whole numbers: a decimal quantity stays in the name, the line starts at 1.
        ->and($quotation->items->map(fn ($i) => [$i->sku, $i->product_name, $i->quantity])->all())->toBe([
            ['BRAND', 'Brand: Schneider - Miniature circuit breaker 20A', 50],
            ['OTHERS', 'Others (Omron): Relay 24V (Qty 2.5)', 1],
        ])
        ->and($quotation->notes)->toContain('• Schneider: Miniature circuit breaker 20A · Qty 50')
        ->and($quotation->notes)->toContain('• Omron: Relay 24V · Qty 2.5');
});

it('refuses a brand quantity that is not a number greater than zero', function (string $quantity) {
    brandProduct($this->team, 'Breaker', 'Schneider');

    $this->post('/quotation', validQuotationPayload([
        'brands' => [['brand' => 'Schneider', 'details' => 'Breaker', 'quantity' => $quantity]],
    ]))->assertSessionHasErrors(['brands' => 'Brand #1: please enter the quantity as a number greater than zero (for example 10 or 2.5).']);

    expect(Quotation::count())->toBe(0);
})->with(['ten', '0', '-3', '10 pcs', '1.2345', '2000000']);
