<?php

use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-12 10:00:00');

    $this->owner = User::factory()->create(['role' => 'super_admin']);
    $this->team = Team::create([
        'name' => 'MRP ElectroDesk Team',
        'slug' => 'mrp-electrodesk',
        'owner_id' => $this->owner->id,
    ]);
    $this->user = User::factory()->create([
        'role' => 'staff_forecast',
        'current_team_id' => $this->team->id,
        'password_changed_at' => now(),
    ]);
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $this->device = createTrustedDeviceFor($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

function createForecastProduct(Team $team, array $overrides = []): Product
{
    static $seq = 1;
    $n = $seq++;

    return Product::forceCreate(array_merge([
        'team_id' => $team->id,
        'sku' => sprintf('ELC-%04d', $n),
        'name' => "Electrical Item {$n}",
        'description' => "Industrial specification for item {$n}",
        'category' => 'Breakers',
        'stock' => 25,
        'min_stock' => 10,
        'reorder_level' => 15,
        'reorder_quantity' => 30,
        'price' => 250.00,
        'unit' => 'pcs',
        'status' => 'In Stock',
        'is_active' => true,
        'created_at' => Carbon::parse('2020-01-01 08:00:00'),
        'updated_at' => Carbon::parse('2020-01-01 08:00:00'),
    ], $overrides));
}

/**
 * Record an eligible sale order with line items and optional linked stock movements.
 *
 * @param  list<array{product: Product, quantity: int, cancelled_quantity?: int, with_movement?: bool}>  $items
 */
function recordSaleTransaction(
    Team $team,
    string $saleDate,
    array $items,
    SalesOrderStatus $status = SalesOrderStatus::Completed,
    ?string $orderNumber = null,
): Sale {
    static $orderSeq = 1;
    $num = $orderNumber ?? sprintf('SO-%05d', $orderSeq++);

    $sale = Sale::create([
        'team_id' => $team->id,
        'order_number' => $num,
        'client_name' => 'Test Customer',
        'sale_date' => $saleDate,
        'subtotal' => 1000,
        'tax' => 0,
        'total' => 1000,
        'status' => $status,
    ]);

    foreach ($items as $item) {
        $product = $item['product'];
        $qty = (int) $item['quantity'];
        $cancelled = (int) ($item['cancelled_quantity'] ?? 0);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => $qty,
            'cancelled_quantity' => $cancelled,
            'unit_price' => 100,
            'subtotal' => max(0, $qty - $cancelled) * 100,
        ]);

        if ($item['with_movement'] ?? true) {
            StockMovement::create([
                'team_id' => $team->id,
                'product_id' => $product->id,
                'sale_id' => $sale->id,
                'type' => 'sale_release',
                'quantity' => max(0, $qty - $cancelled),
                'previous_stock' => 100,
                'new_stock' => 100 - max(0, $qty - $cancelled),
                'movement_date' => $saleDate,
                'reference_number' => $num,
            ]);
        }
    }

    return $sale;
}

it('uses previous completed calendar months for SMA 3, SMA 6, and SMA 12 while excluding the incomplete current month', function () {
    $productA = createForecastProduct($this->team, ['sku' => 'BRK-101', 'name' => '20A Circuit Breaker', 'description' => 'Miniature 20A 2-pole breaker']);
    $productB = createForecastProduct($this->team, ['sku' => 'WIR-202', 'name' => 'THHN 3.5mm Wire', 'description' => 'Stranded copper building wire']);
    $productC = createForecastProduct($this->team, ['sku' => 'LGT-303', 'name' => 'LED Panel 18W', 'description' => 'Daylight recessed panel']);

    // Seed 12 completed calendar months (Nov 2025 – Oct 2026) when today is Nov 12, 2026:
    // Nov 2025 - Apr 2026 (first 6 months): 1 transaction/month, 1 product (A), 12 units/month
    foreach (['2025-11-10', '2025-12-10', '2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10'] as $date) {
        recordSaleTransaction($this->team, $date, [
            ['product' => $productA, 'quantity' => 12],
        ]);
    }

    // May 2026 - Jul 2026 (months 7-9): 2 transactions/month, 2 distinct products (A & B), 24 units/month
    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $date) {
        recordSaleTransaction($this->team, $date, [
            ['product' => $productA, 'quantity' => 14],
        ]);
        recordSaleTransaction($this->team, $date, [
            ['product' => $productB, 'quantity' => 10],
        ]);
    }

    // Aug 2026 - Oct 2026 (last 3 completed months):
    // Aug 2026: 3 transactions, 3 distinct products (A, B, C), 30 units (A:15, B:10, C:5)
    recordSaleTransaction($this->team, '2026-08-05', [['product' => $productA, 'quantity' => 15], ['product' => $productB, 'quantity' => 10]]);
    recordSaleTransaction($this->team, '2026-08-15', [['product' => $productC, 'quantity' => 3]]);
    recordSaleTransaction($this->team, '2026-08-22', [['product' => $productC, 'quantity' => 2]]);

    // Sep 2026: 4 transactions, 2 distinct products (A, B), 36 units (A:20, B:16)
    recordSaleTransaction($this->team, '2026-09-04', [['product' => $productA, 'quantity' => 10]]);
    recordSaleTransaction($this->team, '2026-09-11', [['product' => $productA, 'quantity' => 10]]);
    recordSaleTransaction($this->team, '2026-09-18', [['product' => $productB, 'quantity' => 8]]);
    recordSaleTransaction($this->team, '2026-09-25', [['product' => $productB, 'quantity' => 8]]);

    // Oct 2026: 3 transactions, 3 distinct products (A, B, C), 40 units (A:25, B:9, C:6)
    recordSaleTransaction($this->team, '2026-10-03', [['product' => $productA, 'quantity' => 25]]);
    recordSaleTransaction($this->team, '2026-10-12', [['product' => $productB, 'quantity' => 9]]);
    recordSaleTransaction($this->team, '2026-10-20', [['product' => $productC, 'quantity' => 6]]);

    // Incomplete current month (Nov 2026) — MUST be excluded from completed-month calculations!
    recordSaleTransaction($this->team, '2026-11-05', [
        ['product' => $productA, 'quantity' => 999],
        ['product' => $productB, 'quantity' => 999],
    ]);

    $forecaster = app(SmaForecaster::class);
    $analytics3 = $forecaster->analytics($this->team->id, Carbon::parse('2025-11-01'), Carbon::today(), 3);

    // Target month is Nov 2026; SMA 3 uses Aug–Oct 2026; SMA 6 uses May–Oct 2026; SMA 12 uses Nov 2025–Oct 2026
    expect($analytics3['targetMonth'])->toBe('Nov 2026')
        ->and($analytics3['lastCompletedMonth'])->toBe('Oct 2026')
        ->and($analytics3['summariesByWindow'][3]['range_label'])->toBe('Aug 2026 – Oct 2026')
        ->and($analytics3['summariesByWindow'][6]['range_label'])->toBe('May 2026 – Oct 2026')
        ->and($analytics3['summariesByWindow'][12]['range_label'])->toBe('Nov 2025 – Oct 2026');

    // Verify SMA 3 (Aug–Oct 2026):
    // Transactions: Aug(3) + Sep(4) + Oct(3) = 10 / 3 = 3.33 -> rounded 3
    // Products Involved: Aug(3) + Sep(2) + Oct(3) = 8 / 3 = 2.67 -> rounded 3 (capped <= 3 eligible products)
    // Units Sold: Aug(30) + Sep(36) + Oct(40) = 106 / 3 = 35.33 -> rounded 35
    $sma3 = $analytics3['summariesByWindow'][3];
    expect($sma3['sales_transactions'])->toMatchArray([
        'available' => true,
        'sum' => 10,
        'decimal' => 3.33,
        'rounded' => 3,
    ])->and($sma3['products_involved'])->toMatchArray([
        'available' => true,
        'sum' => 8,
        'decimal' => 2.67,
        'rounded' => 3,
    ])->and($sma3['units_sold'])->toMatchArray([
        'available' => true,
        'sum' => 106,
        'decimal' => 35.33,
        'rounded' => 35,
    ]);

    // Verify SMA 6 (May–Oct 2026):
    // Transactions: May(2)+Jun(2)+Jul(2) + Aug(3)+Sep(4)+Oct(3) = 16 / 6 = 2.67 -> rounded 3
    // Products Involved: May(2)+Jun(2)+Jul(2) + Aug(3)+Sep(2)+Oct(3) = 14 / 6 = 2.33 -> rounded 2
    // Units Sold: May(24)+Jun(24)+Jul(24) + Aug(30)+Sep(36)+Oct(40) = 178 / 6 = 29.67 -> rounded 30
    $sma6 = $analytics3['summariesByWindow'][6];
    expect($sma6['sales_transactions'])->toMatchArray([
        'available' => true,
        'sum' => 16,
        'decimal' => 2.67,
        'rounded' => 3,
    ])->and($sma6['products_involved'])->toMatchArray([
        'available' => true,
        'sum' => 14,
        'decimal' => 2.33,
        'rounded' => 2,
    ])->and($sma6['units_sold'])->toMatchArray([
        'available' => true,
        'sum' => 178,
        'decimal' => 29.67,
        'rounded' => 30,
    ]);

    // Verify SMA 12 (Nov 2025–Oct 2026):
    // Transactions: 6*1 + 16 = 22 / 12 = 1.83 -> rounded 2
    // Products Involved: 6*1 + 14 = 20 / 12 = 1.67 -> rounded 2
    // Units Sold: 6*12 + 178 = 250 / 12 = 20.83 -> rounded 21
    $sma12 = $analytics3['summariesByWindow'][12];
    expect($sma12['sales_transactions'])->toMatchArray([
        'available' => true,
        'sum' => 22,
        'decimal' => 1.83,
        'rounded' => 2,
    ])->and($sma12['products_involved'])->toMatchArray([
        'available' => true,
        'sum' => 20,
        'decimal' => 1.67,
        'rounded' => 2,
    ])->and($sma12['units_sold'])->toMatchArray([
        'available' => true,
        'sum' => 250,
        'decimal' => 20.83,
        'rounded' => 21,
    ]);

    // Verify Product-Level SMA 3 / 6 / 12 and consistency with aggregate units sold
    $perProduct = $forecaster->forProducts($this->team->id, [$productA->id, $productB->id, $productC->id], 3);
    // Product A in Aug-Oct: 15 + 20 + 25 = 60 -> SMA3 = 20.00 (Increasing)
    // Product B in Aug-Oct: 10 + 16 + 9 = 35 -> SMA3 = 11.67 (rounded 12)
    // Product C in Aug-Oct: 5 + 0 + 6 = 11 -> SMA3 = 3.67 (rounded 4)
    expect($perProduct[$productA->id])->toMatchArray([
        'available' => true,
        'historical_units' => 60,
        'decimal_forecast' => 20.0,
        'forecast' => 20,
        'sma_3' => 20.0,
        'sma_6' => 17.0,   // (14*3 + 60) / 6 = 102 / 6 = 17.0
        'sma_12' => 14.5,  // (12*6 + 102) / 12 = 174 / 12 = 14.5
        'trend' => 'Increasing',
    ])->and($perProduct[$productB->id])->toMatchArray([
        'available' => true,
        'historical_units' => 35,
        'decimal_forecast' => 11.67,
        'forecast' => 12,
        'sma_3' => 11.67,
        'sma_6' => 10.83,  // (10*3 + 35) / 6 = 65 / 6 = 10.83
        'sma_12' => 5.42,  // 65 / 12 = 5.42
    ])->and($perProduct[$productC->id])->toMatchArray([
        'available' => true,
        'historical_units' => 11,
        'decimal_forecast' => 3.67,
        'forecast' => 4,
        'sma_3' => 3.67,
        'sma_6' => 1.83,   // 11 / 6 = 1.83
        'sma_12' => 0.92,  // 11 / 12 = 0.92
    ]);

    // Sum of product-level historical units in window === aggregate window units sold (60 + 35 + 11 = 106)
    $productHistoricalSum = array_sum(array_column($perProduct, 'historical_units'));
    expect($productHistoricalSum)->toBe($sma3['units_sold']['sum']);
});

it('counts each eligible sales transaction once, excludes invalid/cancelled sales, and prevents double-counting with stock movements', function () {
    $p1 = createForecastProduct($this->team, ['sku' => 'P-01']);
    $p2 = createForecastProduct($this->team, ['sku' => 'P-02']);

    // Establish opening inventory so history covers Aug-Oct 2026
    foreach ([$p1, $p2] as $p) {
        StockMovement::create([
            'team_id' => $this->team->id,
            'product_id' => $p->id,
            'type' => 'stock_in',
            'quantity' => 100,
            'previous_stock' => 0,
            'new_stock' => 100,
            'movement_date' => '2026-01-05',
        ]);
    }

    // Aug 2026: 1 multi-item Completed order (10 of P1 + 20 of P2, with 5 cancelled on P2 -> net 10 + 15 = 25 units)
    recordSaleTransaction($this->team, '2026-08-10', [
        ['product' => $p1, 'quantity' => 10, 'with_movement' => true],
        ['product' => $p2, 'quantity' => 20, 'cancelled_quantity' => 5, 'with_movement' => true],
    ], SalesOrderStatus::Completed);

    // Invalid/excluded transactions in Aug 2026:
    // - Pending order (not counted in sales)
    recordSaleTransaction($this->team, '2026-08-12', [
        ['product' => $p1, 'quantity' => 50, 'with_movement' => false],
    ], SalesOrderStatus::Pending);
    // - Cancelled order
    recordSaleTransaction($this->team, '2026-08-14', [
        ['product' => $p1, 'quantity' => 50, 'with_movement' => false],
    ], SalesOrderStatus::Cancelled);
    // - Confirmed order where 100% of item quantity was cancelled
    recordSaleTransaction($this->team, '2026-08-16', [
        ['product' => $p1, 'quantity' => 15, 'cancelled_quantity' => 15, 'with_movement' => false],
    ], SalesOrderStatus::Confirmed);
    // - Soft-deleted Completed order
    $deletedSale = recordSaleTransaction($this->team, '2026-08-18', [
        ['product' => $p1, 'quantity' => 40, 'with_movement' => false],
    ], SalesOrderStatus::Completed);
    $deletedSale->delete();

    // Sep 2026: 1 Confirmed order (5 of P1) + 1 Processing order (10 of P1) -> 2 transactions, 1 product involved, 15 units
    recordSaleTransaction($this->team, '2026-09-08', [
        ['product' => $p1, 'quantity' => 5, 'with_movement' => true],
    ], SalesOrderStatus::Confirmed);
    recordSaleTransaction($this->team, '2026-09-19', [
        ['product' => $p1, 'quantity' => 10, 'with_movement' => true],
    ], SalesOrderStatus::Processing);

    // Oct 2026: Zero sales month (0 transactions, 0 products involved, 0 units)

    $analytics = app(SmaForecaster::class)->analytics($this->team->id, Carbon::parse('2026-08-01'), Carbon::today(), 3);
    $sma3 = $analytics['summariesByWindow'][3];

    // Transactions: Aug(1) + Sep(2) + Oct(0) = 3 / 3 = 1.00
    expect($sma3['sales_transactions']['inputs'])->toBe([
        ['month' => 'Aug 2026', 'ym' => '2026-08', 'value' => 1],
        ['month' => 'Sep 2026', 'ym' => '2026-09', 'value' => 2],
        ['month' => 'Oct 2026', 'ym' => '2026-10', 'value' => 0],
    ])->and($sma3['sales_transactions']['decimal'])->toBe(1.0)
        ->and($sma3['sales_transactions']['rounded'])->toBe(1);

    // Products Involved: Aug(2) + Sep(1) + Oct(0) = 3 / 3 = 1.00
    expect($sma3['products_involved']['inputs'])->toBe([
        ['month' => 'Aug 2026', 'ym' => '2026-08', 'value' => 2],
        ['month' => 'Sep 2026', 'ym' => '2026-09', 'value' => 1],
        ['month' => 'Oct 2026', 'ym' => '2026-10', 'value' => 0],
    ])->and($sma3['products_involved']['decimal'])->toBe(1.0)
        ->and($sma3['products_involved']['rounded'])->toBe(1);

    // Units Sold: Aug(25) + Sep(15) + Oct(0) = 40 / 3 = 13.33 -> rounded 13
    expect($sma3['units_sold']['inputs'])->toBe([
        ['month' => 'Aug 2026', 'ym' => '2026-08', 'value' => 25],
        ['month' => 'Sep 2026', 'ym' => '2026-09', 'value' => 15],
        ['month' => 'Oct 2026', 'ym' => '2026-10', 'value' => 0],
    ])->and($sma3['units_sold']['decimal'])->toBe(13.33)
        ->and($sma3['units_sold']['rounded'])->toBe(13);
});

it('distinguishes genuine zero demand from insufficient historical data and preserves zero-demand products', function () {
    // Product with full history since Jan 2026, but 0 sales in Aug-Oct 2026 -> genuine Zero Demand (0)
    $zeroDemandProduct = createForecastProduct($this->team, [
        'sku' => 'ZRO-001',
        'name' => 'Legacy Standby Breaker',
        'created_at' => Carbon::parse('2026-01-01'),
    ]);
    StockMovement::create([
        'team_id' => $this->team->id,
        'product_id' => $zeroDemandProduct->id,
        'type' => 'stock_in',
        'quantity' => 50,
        'previous_stock' => 0,
        'new_stock' => 50,
        'movement_date' => '2026-01-10',
    ]);

    // Product created in Sep 2026 with first sale in Sep 2026 -> Insufficient History for SMA 3 (needs Aug-Oct 2026)
    $newProduct = createForecastProduct($this->team, [
        'sku' => 'NEW-002',
        'name' => 'Newly Introduced Contactor',
        'created_at' => Carbon::parse('2026-09-10'),
    ]);
    recordSaleTransaction($this->team, '2026-09-15', [
        ['product' => $newProduct, 'quantity' => 12],
    ]);

    // Product with no records ever -> Insufficient History
    $neverMovedProduct = createForecastProduct($this->team, [
        'sku' => 'NVR-003',
        'name' => 'Unstocked Specialty Meter',
        'created_at' => Carbon::parse('2026-01-01'),
    ]);

    $forecasts = app(SmaForecaster::class)->forProducts(
        $this->team->id,
        [$zeroDemandProduct->id, $newProduct->id, $neverMovedProduct->id],
        3
    );

    // Zero-demand product: available = true, forecast = 0, trend = 'Zero Demand'
    expect($forecasts[$zeroDemandProduct->id])->toMatchArray([
        'available' => true,
        'forecast' => 0,
        'decimal_forecast' => 0.0,
        'historical_units' => 0,
        'trend' => 'Zero Demand',
        'reason' => null,
    ]);

    // New product (< 3 full months): available = false, forecast = null, trend = 'Insufficient Data'
    expect($forecasts[$newProduct->id]['available'])->toBeFalse()
        ->and($forecasts[$newProduct->id]['forecast'])->toBeNull()
        ->and($forecasts[$newProduct->id]['decimal_forecast'])->toBeNull()
        ->and($forecasts[$newProduct->id]['trend'])->toBe('Insufficient Data')
        ->and($forecasts[$newProduct->id]['reason'])->toContain('fewer than 3 full months');

    // Never moved product: available = false, forecast = null, trend = 'Insufficient Data'
    expect($forecasts[$neverMovedProduct->id]['available'])->toBeFalse()
        ->and($forecasts[$neverMovedProduct->id]['forecast'])->toBeNull()
        ->and($forecasts[$neverMovedProduct->id]['trend'])->toBe('Insufficient Data');
});

it('keeps the SMA calculation window intact across all historical graph date filter presets', function () {
    $product = createForecastProduct($this->team, ['sku' => 'HIST-01', 'name' => 'Historical Conduit']);

    // Record sales from Jan 2020 and across Nov 2025 – Oct 2026
    recordSaleTransaction($this->team, '2020-01-15', [['product' => $product, 'quantity' => 20]]);

    $monthlyQuantities = [
        '2025-11-10' => 10,
        '2025-12-10' => 12,
        '2026-01-10' => 14,
        '2026-02-10' => 16,
        '2026-03-10' => 18,
        '2026-04-10' => 20,
        '2026-05-10' => 22,
        '2026-06-10' => 24,
        '2026-07-10' => 26,
        '2026-08-10' => 28,
        '2026-09-10' => 30,
        '2026-10-10' => 32,
    ];
    foreach ($monthlyQuantities as $date => $qty) {
        recordSaleTransaction($this->team, $date, [['product' => $product, 'quantity' => $qty]]);
    }

    // Even when filtering the historical graph to "last_3_months" with SMA 12 selected,
    // smaWindow remains 12, the next-month forecast uses the full 12 completed months (252 / 12 = 21),
    // and the displayed historical months (Aug, Sep, Oct 2026) still have their 12-month SMA trend values!
    foreach (['last_3_months', 'last_6_months', 'last_12_months', '2020_present', 'custom'] as $preset) {
        $query = http_build_query([
            'date_preset' => $preset,
            'sma_window' => 12,
            'from_date' => '2026-08-01',
            'to_date' => '2026-10-31',
        ]);

        $this->actingAs($this->user)
            ->withCookies($this->device['cookies'])
            ->get("/{$this->team->slug}/forecast?{$query}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('datePreset', $preset)
                ->where('smaWindow', 12)
                ->where('summaryCards.predictedUnitsSold.rounded', 21)
                ->where('summaryCards.predictedUnitsSold.decimal', 21)
                ->where('summaryCards.productsWithForecastedDemand.count', 1)
                ->where('smaAnalytics.metricSeries.units_sold.next_month_forecast.rounded', 21)
                ->where('smaAnalytics.metricSeries.units_sold.sma_rounded.0', fn ($val) => $preset === '2020_present' ? $val === null : $val !== null)
            );
    }
});

it('calculates rolling historical backtesting accuracy (MAE and WAPE) and handles zero actual demand safely', function () {
    $product = createForecastProduct($this->team, [
        'sku' => 'ACC-01',
        'created_at' => Carbon::parse('2026-05-01 08:00:00'),
    ]);

    // 6 completed months (May 2026 – Oct 2026) with a zero-sales month in Aug 2026:
    // May: 10, Jun: 20, Jul: 30 -> Aug SMA-3 forecast = (10+20+30)/3 = 20, Actual Aug = 0 (error = 20)
    // Jun: 20, Jul: 30, Aug: 0  -> Sep SMA-3 forecast = (20+30+0)/3 = 16.67 (17), Actual Sep = 25 (error = 8)
    // Jul: 30, Aug: 0,  Sep: 25 -> Oct SMA-3 forecast = (30+0+25)/3 = 18.33 (18), Actual Oct = 15 (error = 3)
    recordSaleTransaction($this->team, '2026-05-10', [['product' => $product, 'quantity' => 10]]);
    recordSaleTransaction($this->team, '2026-06-10', [['product' => $product, 'quantity' => 20]]);
    recordSaleTransaction($this->team, '2026-07-10', [['product' => $product, 'quantity' => 30]]);
    // Aug 2026 has 0 sales
    recordSaleTransaction($this->team, '2026-09-10', [['product' => $product, 'quantity' => 25]]);
    recordSaleTransaction($this->team, '2026-10-10', [['product' => $product, 'quantity' => 15]]);

    $analytics = app(SmaForecaster::class)->analytics($this->team->id, Carbon::parse('2026-05-01'), Carbon::today(), 3);
    $unitsBacktest = $analytics['backtest']['units_sold'];

    // Backtested months: Aug 2026 (error 20), Sep 2026 (error 8), Oct 2026 (error 3)
    // Total error = 31 over 3 months -> MAE = 31 / 3 = 10.33
    // Total actual across backtested months = 0 + 25 + 15 = 40 -> WAPE = (31 / 40) * 100 = 77.5%
    // Zero-actual months = 1 (Aug 2026); MAPE months = 2 (Sep & Oct)
    expect($unitsBacktest['months'])->toBe(3)
        ->and($unitsBacktest['zero_actual_months'])->toBe(1)
        ->and($unitsBacktest['mape_months'])->toBe(2)
        ->and($unitsBacktest['mae'])->toBe(10.33)
        ->and($unitsBacktest['wape'])->toBe(77.5)
        ->and($unitsBacktest['total_actual'])->toBe(40)
        ->and($unitsBacktest['total_abs_error'])->toBe(31);

    // When all backtested months have 0 actual demand, WAPE and MAPE must be null (never divide by zero or invent accuracy)
    $zeroProduct = createForecastProduct($this->team, ['sku' => 'ACC-ZERO']);
    StockMovement::create([
        'team_id' => $this->team->id,
        'product_id' => $zeroProduct->id,
        'type' => 'stock_in',
        'quantity' => 20,
        'previous_stock' => 0,
        'new_stock' => 20,
        'movement_date' => '2026-01-10',
    ]);

    $zeroAnalytics = app(SmaForecaster::class)->analytics(
        $this->team->id,
        Carbon::parse('2026-05-01'),
        Carbon::today(),
        3,
        'All',
        $zeroProduct->id
    );
    $zeroBacktest = $zeroAnalytics['backtest']['units_sold'];

    expect($zeroBacktest['months'])->toBe(6)
        ->and($zeroBacktest['mae'])->toBe(0.0)
        ->and($zeroBacktest['wape'])->toBeNull()
        ->and($zeroBacktest['mape'])->toBeNull();
});

it('integrates Sales Records -> SMA Demand Forecast -> AHP Product Prioritization -> Reorder Recommendation', function () {
    $highDemandProduct = createForecastProduct($this->team, [
        'sku' => 'AHP-HIGH',
        'name' => 'High Demand Breaker',
        'description' => 'Main distribution breaker',
        'stock' => 15,
        'reorder_level' => 10,
        'reorder_quantity' => 25,
    ]);
    $lowDemandProduct = createForecastProduct($this->team, [
        'sku' => 'AHP-LOW',
        'name' => 'Low Demand Switch',
        'description' => 'Auxiliary toggle switch',
        'stock' => 80,
        'reorder_level' => 10,
        'reorder_quantity' => 20,
    ]);

    $supplier = Supplier::create([
        'team_id' => $this->team->id,
        'name' => 'ElectroCore Supply',
        'status' => 'Active',
    ]);
    foreach ([$highDemandProduct, $lowDemandProduct] as $p) {
        ProductSupplier::create([
            'team_id' => $this->team->id,
            'product_id' => $p->id,
            'supplier_id' => $supplier->id,
            'is_available' => true,
            'verified_at' => '2026-10-01',
        ]);
    }

    // Record sales in Aug, Sep, Oct 2026:
    // High demand product: 30, 45, 60 -> SMA-3 = 45/month, 3-month period total = 135
    // Low demand product: 0, 0, 0 -> SMA-3 = 0/month
    recordSaleTransaction($this->team, '2026-08-10', [['product' => $highDemandProduct, 'quantity' => 30]]);
    recordSaleTransaction($this->team, '2026-09-10', [['product' => $highDemandProduct, 'quantity' => 45]]);
    recordSaleTransaction($this->team, '2026-10-10', [['product' => $highDemandProduct, 'quantity' => 60]]);

    StockMovement::create([
        'team_id' => $this->team->id,
        'product_id' => $lowDemandProduct->id,
        'type' => 'stock_in',
        'quantity' => 80,
        'previous_stock' => 0,
        'new_stock' => 80,
        'movement_date' => '2026-01-10',
    ]);

    $priorities = app(ProductPrioritizer::class)->forTeam($this->team->id, Carbon::today(), 3);

    $highPriority = $priorities[$highDemandProduct->id];
    $lowPriority = $priorities[$lowDemandProduct->id];

    // High demand product: SMA-3 monthly = 45, period_total = 135, available stock = 15 -> shortfall = 120 -> suggested = max(25, 120) = 120
    expect($highPriority['complete'])->toBeTrue()
        ->and($highPriority['forecast'])->toMatchArray([
            'available' => true,
            'months' => 3,
            'monthly' => 45,
            'decimal_monthly' => 45.0,
            'period_total' => 135,
            'historical_units' => 135,
            'trend' => 'Increasing',
        ])
        ->and($highPriority['reorder']['needed'])->toBeTrue()
        ->and($highPriority['reorder']['shortfall'])->toBe(120)
        ->and($highPriority['reorder']['suggested_quantity'])->toBe(120)
        ->and($highPriority['score'])->toBeGreaterThan($lowPriority['score']);

    // Low demand product: SMA-3 monthly = 0, available stock = 80 > reorder_level 10 -> no reorder needed
    expect($lowPriority['complete'])->toBeTrue()
        ->and($lowPriority['forecast'])->toMatchArray([
            'available' => true,
            'months' => 3,
            'monthly' => 0,
            'decimal_monthly' => 0.0,
            'period_total' => 0,
            'historical_units' => 0,
            'trend' => 'Zero Demand',
        ])
        ->and($lowPriority['reorder']['needed'])->toBeFalse()
        ->and($lowPriority['reorder']['suggested_quantity'])->toBe(0);

    // Forecast controller exposes both products with separate counts for Predicted Products Involved vs Products with Forecasted Demand
    $this->actingAs($this->user)
        ->withCookies($this->device['cookies'])
        ->get("/{$this->team->slug}/forecast?sma_window=3")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summaryCards.predictedSalesTransactions.rounded', 1)
            ->where('summaryCards.predictedProductsInvolved.rounded', 1)
            ->where('summaryCards.predictedUnitsSold.rounded', 45)
            ->where('summaryCards.productsWithForecastedDemand.count', 1)
            ->where('summaryCards.productsWithForecastedDemand.zero_demand_count', 1)
            ->has('productForecasts', 2)
            ->where('productForecasts.0.product.sku', 'AHP-HIGH')
            ->where('productForecasts.0.product.description', 'Main distribution breaker')
            ->where('productForecasts.0.forecast.trend', 'Increasing')
            ->where('productForecasts.0.reorder.suggested_quantity', 120)
        );
});
