<?php

/*
 * 4.4.1 Unit Testing — Inventory Management (UT-31 to UT-43).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 */

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Team;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Inventory\SkuGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'inv-team']);
    [$this->staff, $this->cookies] = capActor('staff_inventory', $this->team);
    $this->url = '/inv-team/inventory';
});

afterEach(fn () => Carbon::setTestNow());

function capInv($t)
{
    return $t->actingAs($t->staff)->withCookies($t->cookies);
}

function capNewProduct(array $overrides = []): array
{
    return $overrides + ['name' => 'Circuit Breaker 60A', 'category' => 'Breakers', 'unit' => 'pcs', 'price' => 150, 'cost_price' => 90, 'stock' => 20, 'reorder_level' => 10, 'reorder_quantity' => 50];
}

it('UT-31 requires the name, category, price and stock of a product', function () {
    capInv($this)->post("{$this->url}/products", [])->assertSessionHasErrors(['name', 'category', 'price', 'stock']);

    expect(Product::count())->toBe(0);
});

it('UT-32 refuses negative prices and negative or decimal stock values', function () {
    capInv($this)->post("{$this->url}/products", capNewProduct(['price' => -1]))->assertSessionHasErrors('price');
    capInv($this)->post("{$this->url}/products", capNewProduct(['cost_price' => -0.01]))->assertSessionHasErrors('cost_price');
    capInv($this)->post("{$this->url}/products", capNewProduct(['stock' => -1]))->assertSessionHasErrors('stock');
    capInv($this)->post("{$this->url}/products", capNewProduct(['stock' => 1.5]))->assertSessionHasErrors('stock');
    capInv($this)->post("{$this->url}/products", capNewProduct(['price' => 'abc']))->assertSessionHasErrors('price');
    capInv($this)->post("{$this->url}/products", capNewProduct(['reorder_level' => -5]))->assertSessionHasErrors('reorder_level');

    expect(Product::count())->toBe(0);
});

it('UT-33 saves a valid product with a generated SKU and an opening stock movement', function () {
    capInv($this)->post("{$this->url}/products", capNewProduct())->assertSessionHasNoErrors();

    $product = Product::sole();
    $movement = StockMovement::sole();
    expect($product->sku)->toBe('BRK-000001')
        ->and($product->stock)->toBe(20)
        ->and($product->status)->toBe('In Stock')
        ->and($movement->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'stock_in', 'quantity' => 20, 'previous_stock' => 0, 'new_stock' => 20]);
});

it('UT-34 sets the stock status at the boundaries of the reorder level', function () {
    foreach ([0 => 'Out of Stock', 1 => 'Low Stock', 10 => 'Low Stock', 11 => 'In Stock'] as $stock => $status) {
        capInv($this)->post("{$this->url}/products", capNewProduct(['name' => "Breaker stock {$stock}", 'stock' => $stock, 'reorder_level' => 10]))->assertSessionHasNoErrors();
        expect(Product::where('name', "Breaker stock {$stock}")->value('status'))->toBe($status);
    }
});

it('UT-35 generates category-based SKUs in sequence, never reusing a number', function () {
    expect(SkuGenerator::prefix('Breakers'))->toBe('BRK')
        ->and(SkuGenerator::prefix('  wiring '))->toBe('WIR')
        ->and(SkuGenerator::prefix('Conduits'))->toBe('CND')
        ->and(SkuGenerator::prefix('Panel Boards'))->toBe('PNL')
        ->and(SkuGenerator::prefix(''))->toBe('GEN')
        ->and(SkuGenerator::prefix(null))->toBe('GEN');

    $generator = app(SkuGenerator::class);
    expect($generator->peek($this->team->id, 'Breakers'))->toBe('BRK-000001')
        ->and($generator->next($this->team->id, 'Breakers'))->toBe('BRK-000001')
        ->and($generator->next($this->team->id, 'Breakers'))->toBe('BRK-000002')
        ->and($generator->next($this->team->id, 'Lighting'))->toBe('LGT-000001');

    capProduct($this->team, ['sku' => 'BRK-000120']); // e.g. an imported product
    expect($generator->next($this->team->id, 'Breakers'))->toBe('BRK-000121');
});

it('UT-36 validates a stock-in quantity and adds it to the stock on hand', function () {
    $product = capProduct($this->team, ['stock' => 10]);
    $stockIn = fn (mixed $qty) => capInv($this)->post("{$this->url}/stock-in", ['product_id' => $product->id, 'quantity' => $qty]);

    foreach ([0, -5, 2.5, 'ten', ''] as $invalid) {
        $stockIn($invalid)->assertSessionHasErrors('quantity');
    }
    capInv($this)->post("{$this->url}/stock-in", ['product_id' => 999999, 'quantity' => 5])->assertSessionHasErrors('product_id');
    expect($product->fresh()->stock)->toBe(10)->and(StockMovement::count())->toBe(0);

    $stockIn(5)->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(15)
        ->and(StockMovement::sole()->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'stock_in', 'quantity' => 5, 'previous_stock' => 10, 'new_stock' => 15]);
});

it('UT-37 refuses a stock-out larger than the available stock and never lets stock go negative', function () {
    $product = capProduct($this->team, ['stock' => 10, 'reorder_level' => 2]);
    $stockOut = fn (mixed $qty, ?string $reason = 'Damaged') => capInv($this)->post("{$this->url}/stock-out", ['product_id' => $product->id, 'quantity' => $qty, 'reason' => $reason]);

    $stockOut(11)->assertSessionHasErrors('quantity'); // one more than is available
    expect(capSessionError('quantity'))->toContain('Available quantity: 10');
    $stockOut(0)->assertSessionHasErrors('quantity');
    $stockOut(-3)->assertSessionHasErrors('quantity');
    $stockOut(5, null)->assertSessionHasErrors('reason');
    expect($product->fresh()->stock)->toBe(10);

    $stockOut(10)->assertSessionHasNoErrors(); // exactly what is available
    expect($product->fresh()->stock)->toBe(0)->and($product->fresh()->status)->toBe('Out of Stock');
});

it('UT-38 calculates available stock as stock on hand minus reserved units, never below zero', function () {
    [$admin] = capActor('admin', $this->team);
    $product = capProduct($this->team, ['stock' => 10]);
    expect($product->availableStock())->toBe(10);

    capConfirmed($this->team, $admin, [[$product, 4]]); // a confirmed order reserves 4
    expect($product->fresh()->reservedQuantity())->toBe(4)
        ->and($product->fresh()->availableStock())->toBe(6)
        ->and($product->fresh()->stock)->toBe(10);

    $product->update(['stock' => 3]); // fewer on hand than reserved
    expect($product->fresh()->availableStock())->toBe(0);
});

it('UT-39 validates a stock adjustment and records the difference', function () {
    $product = capProduct($this->team, ['stock' => 10, 'reorder_level' => 2]);
    // The Inventory page always sends the remarks field, empty when nothing was typed.
    $adjust = fn (array $data) => capInv($this)->post("{$this->url}/adjust", $data + ['product_id' => $product->id, 'reason' => 'Physical Count Correction', 'remarks' => '']);

    $adjust(['physical_count' => -1])->assertSessionHasErrors('physical_count');
    $adjust(['physical_count' => 7.5])->assertSessionHasErrors('physical_count');
    $adjust(['physical_count' => 7, 'reason' => 'Because'])->assertSessionHasErrors('reason');
    expect($product->fresh()->stock)->toBe(10);

    $adjust(['physical_count' => 7])->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(7)
        ->and(StockMovement::sole()->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'adjustment', 'quantity' => 3, 'previous_stock' => 10, 'new_stock' => 7]);

    $adjust(['physical_count' => 0])->assertSessionHasNoErrors(); // zero is a valid count
    expect($product->fresh()->stock)->toBe(0)->and($product->fresh()->status)->toBe('Out of Stock');
});

it('UT-99 records a stock adjustment when the optional remarks field is left out of the request', function () {
    $product = capProduct($this->team, ['stock' => 10, 'reorder_level' => 2]);

    // "remarks" is validated as optional (nullable), so a request without it should be saved.
    $response = capInv($this)->post("{$this->url}/adjust", ['product_id' => $product->id, 'physical_count' => 7, 'reason' => 'Physical Count Correction']);

    expect($response->status())->toBeLessThan(500)
        ->and($product->fresh()->stock)->toBe(7);
});

it('UT-40 classifies low stock against the reorder level, with 10 as the default threshold', function () {
    $status = function (int $stock, int $reorderLevel): string {
        $product = capProduct($this->team, ['stock' => $stock, 'reorder_level' => $reorderLevel, 'min_stock' => 0]);
        $product->updateCalculatedStatus();

        return $product->fresh()->status;
    };

    expect($status(0, 5))->toBe('Out of Stock')
        ->and($status(5, 5))->toBe('Low Stock')   // at the reorder level
        ->and($status(6, 5))->toBe('In Stock')    // one above it
        ->and($status(10, 0))->toBe('Low Stock')  // no reorder level set: threshold 10
        ->and($status(11, 0))->toBe('In Stock');
});

it('UT-41 reports the stock status of a requested quantity', function () {
    $product = capProduct($this->team, ['stock' => 20, 'reorder_level' => 5]);

    expect($product->stockStatusFor(1))->toBe('Available')
        ->and($product->stockStatusFor(14))->toBe('Available')          // 6 left: above the level
        ->and($product->stockStatusFor(15))->toBe('Low Stock')          // 5 left: at the level
        ->and($product->stockStatusFor(20))->toBe('Low Stock')          // all of it
        ->and($product->stockStatusFor(21))->toBe('Insufficient Stock') // one too many
        ->and($product->stockStatusFor(1, 0))->toBe('Not Available');
});

it('UT-42 recommends a reorder from the reorder level and the forecast shortfall', function () {
    // Forecast 50 a month (40, 50, 60 over the last three full months): 150 for the 3-month period.
    $history = function (Product $p): Product {
        foreach (['2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
            capDemand($this->team, $p, $date, $qty);
        }

        return $p;
    };
    $low = $history(capProduct($this->team, ['stock' => 5, 'reorder_level' => 10, 'reorder_quantity' => 100]));
    $covered = $history(capProduct($this->team, ['stock' => 500, 'reorder_level' => 10, 'reorder_quantity' => 100]));
    $atLevelNoHistory = capProduct($this->team, ['stock' => 10, 'reorder_level' => 10, 'reorder_quantity' => 30]);
    $aboveLevelNoHistory = capProduct($this->team, ['stock' => 11, 'reorder_level' => 10, 'reorder_quantity' => 30]);

    $result = app(ProductPrioritizer::class)->forTeam($this->team->id);

    expect($result[$low->id]['reorder'])->toMatchArray(['needed' => true, 'forecast' => 150, 'shortfall' => 145, 'suggested_quantity' => 145]) // max(100, 145)
        ->and($result[$covered->id]['reorder'])->toMatchArray(['needed' => false, 'suggested_quantity' => 0, 'shortfall' => 0])
        ->and($result[$atLevelNoHistory->id]['reorder'])->toMatchArray(['needed' => true, 'forecast' => null, 'suggested_quantity' => 30]) // at the level
        ->and($result[$aboveLevelNoHistory->id]['reorder'])->toMatchArray(['needed' => false, 'suggested_quantity' => 0]);
});

it('UT-43 converts the weighted priority score to the 1-5 scale and its class at the band boundaries', function () {
    $prioritizer = app(ProductPrioritizer::class);

    expect(ProductPrioritizer::toScale(0))->toBe(1.0)
        ->and(ProductPrioritizer::toScale(100))->toBe(5.0)
        ->and(ProductPrioritizer::toScale(87.5))->toBe(4.5)
        ->and(ProductPrioritizer::toScale(150))->toBe(5.0)   // clamped
        ->and(ProductPrioritizer::toScale(-10))->toBe(1.0)   // clamped
        ->and($prioritizer->classify(80))->toBe('High Priority')          // 4.20
        ->and($prioritizer->classify(80.25))->toBe('Very High Priority')  // 4.21
        ->and($prioritizer->classify(20))->toBe('Very Low Priority')      // 1.80
        ->and($prioritizer->classify(20.25))->toBe('Low Priority')        // 1.81
        ->and($prioritizer->classify(50))->toBe('Moderate Priority');     // 3.00
});
