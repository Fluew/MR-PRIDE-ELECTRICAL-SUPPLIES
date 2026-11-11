<?php

use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * AHP Product Prioritization, SMA forecasting and transaction-based Supplier Evaluation:
 * calculated from records only, missing data reported rather than invented.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'eval-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
});

afterEach(fn () => Carbon::setTestNow());

function evalProduct(Team $team, string $sku, int $stock, string $created = '2025-01-01'): Product
{
    $p = Product::create(['team_id' => $team->id, 'sku' => $sku, 'name' => "Product {$sku}", 'price' => 10, 'cost_price' => 5, 'stock' => $stock, 'is_active' => true, 'unit' => 'pcs']);
    $p->forceFill(['created_at' => $created])->saveQuietly();

    return $p;
}

function sold(Team $team, Product $p, string $date, int $qty): void
{
    StockMovement::create(['team_id' => $team->id, 'product_id' => $p->id, 'type' => 'sale', 'quantity' => -$qty, 'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "SALE-{$p->sku}-{$date}"]);
}

function evalSupplier(Team $team, string $name): Supplier
{
    return Supplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'Active']);
}

test('SMA forecast is the plain average of the previous months, with no trend factor or invented history', function () {
    $p = evalProduct($this->team, 'SMA-1', 50);
    sold($this->team, $p, '2026-07-10', 10);
    sold($this->team, $p, '2026-08-10', 20);
    sold($this->team, $p, '2026-09-10', 30);

    $series = app(SmaForecaster::class)->series($this->team->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-15'), 3, 'All', $p->id);
    expect($series['historical'])->toBe([10, 20, 30, 0])
        ->and($series['forecast'])->toBe([null, null, null, 20])
        ->and($series['sourceRecordCount'])->toBe(3);

    $empty = evalProduct($this->team, 'SMA-EMPTY', 5);
    $none = app(SmaForecaster::class)->series($this->team->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-10-15'), 3, 'All', $empty->id);
    expect($none['hasData'])->toBeFalse()
        ->and(array_sum($none['historical']))->toBe(0);
});

test('AHP product priority uses the fixed weights and real records', function () {
    $a = evalProduct($this->team, 'A', 20);
    $b = evalProduct($this->team, 'B', 80);
    foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
        sold($this->team, $a, $d, 30);
        sold($this->team, $b, $d, 10);
    }
    $s1 = evalSupplier($this->team, 'S1');
    $s2 = evalSupplier($this->team, 'S2');
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $a->id, 'supplier_id' => $s1->id, 'is_available' => true, 'verified_at' => now()]);
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $a->id, 'supplier_id' => $s2->id, 'is_available' => false, 'verified_at' => now()]);
    // Unverified: must not count.
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $b->id, 'supplier_id' => $s1->id, 'is_available' => true]);

    $r = app(ProductPrioritizer::class)->forTeam($this->team->id);
    $scores = fn ($id) => collect($r[$id]['criteria'])->mapWithKeys(fn ($c) => [$c['key'] => $c['score']])->all();

    // A: demand 30/30, stock (1 − 20/80), movement 90/90, availability (1 − 1/2).
    expect($scores($a->id))->toEqual(['demand' => 100.0, 'stock' => 75.0, 'movement' => 100.0, 'supplier_availability' => 50.0])
        ->and($r[$a->id]['score'])->toEqual(87.5)
        ->and($r[$a->id]['classification'])->toBe('Very High Priority')
        ->and($r[$a->id]['scale']['score'])->toEqual(4.5)
        ->and($r[$a->id]['scale']['range'])->toBe('4.21–5.00')
        ->and($r[$a->id]['scale']['action'])->toBe('Reorder Immediately')
        ->and($r[$a->id]['final_formula'])->toBe('40.00 + 22.50 + 20.00 + 5.00 = 87.50');

    // B has no verified supplier record: incomplete, never classified, availability not treated as 0.
    expect($r[$b->id]['complete'])->toBeFalse()
        ->and($r[$b->id]['score'])->toBeNull()
        ->and($r[$b->id]['classification'])->toBeNull()
        ->and($scores($b->id)['supplier_availability'])->toBeNull()
        ->and($scores($b->id)['demand'])->toEqual(33.33)
        ->and($r[$b->id]['partial_score'])->toEqual(round(33.33 * 0.4, 2) + 0.0 + round(33.33 * 0.2, 2));
});

test('a product without sales records has no demand or movement score instead of zero', function () {
    $p = evalProduct($this->team, 'NEW', 10);
    $r = app(ProductPrioritizer::class)->forTeam($this->team->id)[$p->id];
    $demand = collect($r['criteria'])->firstWhere('key', 'demand');

    expect($demand['available'])->toBeFalse()
        ->and($demand['score'])->toBeNull()
        ->and($demand['reason'])->toContain('No sales')
        ->and($r['classification'])->toBeNull();
});

test('supplier evaluation is calculated from deliveries, inspections and purchase order costs, and ranked', function () {
    $p = evalProduct($this->team, 'P', 0);
    $s1 = evalSupplier($this->team, 'Alpha');
    $s2 = evalSupplier($this->team, 'Beta');
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-09-01', 'promised_delivery_date' => '2026-09-10', 'po_number' => 'PO-1', 'quantity' => 100, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => 1100, 'total_cost' => 110000, 'supplier_id' => $s1->id]);
    $base = ['team_id' => $this->team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => 'PO-1', 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s1->id, 'status' => 'Delivered', 'inspection_result' => 'partially_accepted', 'inspected_on' => '2026-09-16'];
    Delivery::create($base + ['delivery_date' => '2026-09-08', 'delivery_number' => 'D-1', 'quantity' => 60, 'accepted_quantity' => 57, 'rejected_quantity' => 3, 'rejection_reason' => 'Dented']);
    Delivery::create($base + ['delivery_date' => '2026-09-15', 'delivery_number' => 'D-2', 'quantity' => 40, 'accepted_quantity' => 40, 'rejected_quantity' => 0]);
    // The same delivery recorded twice must not inflate the score.
    Delivery::create($base + ['delivery_date' => '2026-09-15', 'delivery_number' => 'D-2', 'quantity' => 40, 'accepted_quantity' => 40, 'rejected_quantity' => 0]);

    // Price: purchase order unit cost of accepted deliveries. Alpha pays 1100 (PO-1), Beta 1000.
    // Beta's order has no promised date, so Beta has no delivery performance score.
    $poB = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-09-01', 'po_number' => 'PO-B', 'quantity' => 50, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => 1000, 'total_cost' => 50000, 'supplier_id' => $s2->id]);
    Delivery::create(['team_id' => $this->team->id, 'purchase_order_id' => $poB->id, 'purchase_order_number' => 'PO-B', 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s2->id, 'status' => 'Delivered',
        'inspection_result' => 'accepted', 'inspected_on' => '2026-09-20', 'delivery_date' => '2026-09-20', 'delivery_number' => 'D-B1', 'quantity' => 50, 'accepted_quantity' => 50, 'rejected_quantity' => 0]);
    // Not counted: a cancelled delivery and one older than the 12-month period, both cheaper.
    foreach ([['PO-A-CXL', 400, '2026-10-01', 'Cancelled'], ['PO-A-OLD', 500, '2025-06-01', 'Delivered']] as [$no, $cost, $date, $status]) {
        $old = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => $date, 'po_number' => $no, 'quantity' => 10, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => $cost, 'total_cost' => 10 * $cost, 'supplier_id' => $s1->id]);
        Delivery::create(['team_id' => $this->team->id, 'purchase_order_id' => $old->id, 'purchase_order_number' => $no, 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s1->id, 'status' => $status,
            'inspection_result' => 'accepted', 'inspected_on' => $date, 'delivery_date' => $date, 'delivery_number' => "D-{$no}", 'quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0]);
    }
    // Quotes no longer affect the price score.
    SupplierQuote::create(['team_id' => $this->team->id, 'product_id' => $p->id, 'supplier_id' => $s1->id, 'currency' => 'USD', 'unit' => 'pcs', 'tax_inclusive' => false, 'quoted_on' => '2026-09-01', 'unit_price' => 10, 'verified_at' => now()]);

    $all = app(SupplierEvaluator::class)->forTeam($this->team->id);
    $e = $all[$s1->id];
    $c = collect($e['criteria'])->keyBy('key');

    // On-time 1/2 = 50, fulfillment 97/100 = 97 → 50×0.6 + 97×0.4 = 68.80.
    expect($c['delivery']['inputs']['completed_deliveries'])->toBe(2)
        ->and($c['delivery']['score'])->toEqual(68.8)
        ->and($c['price']['score'])->toEqual(90.91)
        ->and($c['price']['inputs']['comparisons'][0]['own_price'])->toEqual(1100.0)
        ->and($c['price']['inputs']['comparisons'][0]['lowest_price'])->toEqual(1000.0)
        ->and($c['quality']['score'])->toEqual(97.0)
        // 27.52 + 31.82 + 24.25
        ->and($e['score'])->toEqual(83.59)
        ->and($e['classification'])->toBe('Very Good')
        ->and($e['rank'])->toBe(1)
        ->and($e['ranked_of'])->toBe(1);

    // Beta has no delivery performance score (no promised date), so it is neither classified nor ranked.
    expect($all[$s2->id]['complete'])->toBeFalse()
        ->and($all[$s2->id]['classification'])->toBeNull()
        ->and($all[$s2->id]['rank'])->toBeNull()
        ->and(collect($all[$s2->id]['criteria'])->firstWhere('key', 'price')['score'])->toEqual(100.0)
        ->and(collect($all[$s2->id]['criteria'])->firstWhere('key', 'delivery')['available'])->toBeFalse();
});

test('the manual supplier scoring endpoint no longer exists', function () {
    $device = createTrustedDeviceFor($this->admin);
    $this->actingAs($this->admin)->withCookies($device['cookies'])
        ->post("/{$this->team->slug}/suppliers/ahp", ['criteria_weights' => ['price' => 1, 'quality' => 1, 'delivery' => 1]])
        ->assertStatus(405);
});

test('inspection derives the result from quantities and must account for every unit received', function () {
    $device = createTrustedDeviceFor($this->admin);
    $s = evalSupplier($this->team, 'Gamma');
    $d = Delivery::create(['team_id' => $this->team->id, 'delivery_date' => '2026-10-10', 'delivery_number' => 'D-9', 'quantity' => 10, 'unit' => 'pcs', 'description' => 'X', 'supplier_id' => $s->id, 'status' => 'Delivered']);
    $url = "/{$this->team->slug}/deliveries/{$d->id}/inspection";
    $as = fn () => $this->actingAs($this->admin)->withCookies($device['cookies']);

    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 1, 'inspected_on' => '2026-10-11', 'rejection_category' => 'damaged', 'rejection_reason' => 'Cracked'])->assertSessionHasErrors('accepted_quantity');
    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 2, 'inspected_on' => '2026-10-11'])->assertSessionHasErrors('rejection_reason');
    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 2, 'inspected_on' => '2026-10-11', 'rejection_category' => 'damaged', 'rejection_reason' => 'Cracked casing'])->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->inspection_result)->toBe('partially_accepted')
        ->and($d->accepted_quantity)->toBe(8)
        ->and($d->inspected_by_id)->toBe($this->admin->id);
});

test('quotes need an administrator to verify them', function () {
    $staff = User::factory()->create(['role' => 'staff_suppliers', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $device = createTrustedDeviceFor($staff);
    $p = evalProduct($this->team, 'Q', 1);
    $s = evalSupplier($this->team, 'Delta');

    $this->actingAs($staff)->withCookies($device['cookies'])
        ->post("/{$this->team->slug}/suppliers/{$s->id}/quotes", ['product_id' => $p->id, 'unit_price' => 12.5, 'currency' => 'USD', 'unit' => 'PCS', 'tax_inclusive' => false, 'quoted_on' => '2026-10-01'])
        ->assertSessionHasNoErrors();
    $quote = SupplierQuote::firstOrFail();
    expect($quote->unit)->toBe('pcs')->and($quote->verified_at)->toBeNull();

    $this->actingAs($staff)->withCookies($device['cookies'])->post("/{$this->team->slug}/supplier-quotes/{$quote->id}/verify")->assertForbidden();

    $adminDevice = createTrustedDeviceFor($this->admin);
    $this->actingAs($this->admin)->withCookies($adminDevice['cookies'])->post("/{$this->team->slug}/supplier-quotes/{$quote->id}/verify")->assertSessionHasNoErrors();
    expect($quote->fresh()->verified_by_id)->toBe($this->admin->id);
});

test('reports and supplier pages receive the backend evaluations', function () {
    $device = createTrustedDeviceFor($this->admin);
    evalProduct($this->team, 'PG', 3);
    evalSupplier($this->team, 'Epsilon');

    $this->actingAs($this->admin)->withCookies($device['cookies'])->get("/{$this->team->slug}/reports?view=priority")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('productPriority.items', 1)->where('productPriority.items.0.complete', false));

    $this->actingAs($this->admin)->withCookies($device['cookies'])->get("/{$this->team->slug}/suppliers")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 1)->where('suppliers.data.0.evaluation.complete', false)->where('weights.delivery', 0.4));
});

test('reorder recommendation comes from the reorder level and the SMA forecast', function () {
    $a = evalProduct($this->team, 'RA', 20);
    $a->forceFill(['reorder_level' => 5, 'reorder_quantity' => 15])->saveQuietly();
    foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
        sold($this->team, $a, $d, 30);
    }
    $b = evalProduct($this->team, 'RB', 100);
    $b->forceFill(['reorder_level' => 5, 'reorder_quantity' => 15])->saveQuietly();

    $r = app(ProductPrioritizer::class)->forTeam($this->team->id);

    // A, default period 3 Months: 30/month × 3 = 90 needed, available 20, shortfall 70, so max(15, 70) = 70.
    expect($r[$a->id]['forecast'])->toMatchArray(['months' => 3, 'period' => '3 Months', 'monthly' => 30, 'period_total' => 90])
        ->and($r[$a->id]['reorder']['needed'])->toBeTrue()
        ->and($r[$a->id]['reorder']['shortfall'])->toBe(70)
        ->and($r[$a->id]['reorder']['suggested_quantity'])->toBe(70)
        ->and($r[$a->id]['reorder']['basis'])->toContain('for the next 3 Months');

    // 1 Year: SMA-12 = 90 / 12 = 7.5 a month, × 12 months = 90 for the year.
    $year = app(ProductPrioritizer::class)->forTeam($this->team->id, null, 12)[$a->id];
    expect($year['forecast'])->toMatchArray(['months' => 12, 'period' => '1 Year', 'monthly' => 8, 'period_total' => 90])
        ->and(collect($year['criteria'])->firstWhere('key', 'demand')['source'])->toContain('SMA-12');
    // B: no sales history and stock above the reorder level: no reorder, no invented forecast.
    expect($r[$b->id]['reorder']['needed'])->toBeFalse()
        ->and($r[$b->id]['reorder']['forecast'])->toBeNull();
});

test('staff choose the forecasting period on the Forecast and Reports pages, and review each product', function () {
    $staff = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $device = createTrustedDeviceFor($staff);
    $p = evalProduct($this->team, 'FC', 20);
    foreach (['2026-04-05' => 6, '2026-05-05' => 6, '2026-06-05' => 6, '2026-07-05' => 30, '2026-08-05' => 30, '2026-09-05' => 30] as $d => $q) {
        sold($this->team, $p, $d, $q);
    }
    $as = fn () => $this->actingAs($staff)->withCookies($device['cookies']);

    // 6 Months: (6+6+6+30+30+30) / 6 = 18 a month → 108 for the next 6 months.
    $as()->get("/{$this->team->slug}/forecast?sma_window=6")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('smaWindow', 6)->where('forecastPeriods.6', '6 Months')
            ->where('productForecasts.0.product.sku', 'FC')
            ->where('productForecasts.0.forecast.monthly', 18)
            ->where('productForecasts.0.forecast.period_total', 108)
            ->where('productForecasts.0.reorder.needed', true));

    // The Reports AHP Product Prioritization view uses the same period; an unknown period falls back to 3 Months.
    $as()->get("/{$this->team->slug}/reports?view=priority&forecast_months=12")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('productPriority.forecastMonths', 12)->where('productPriority.smaWindow', 12)
            ->where('productPriority.forecastPeriods.12', '1 Year')->where('productPriority.items.0.forecast.period', '1 Year'));
    $as()->get("/{$this->team->slug}/reports?view=priority&forecast_months=5")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('productPriority.forecastMonths', 3)->where('productPriority.items.0.forecast.period_total', 90));
});

test('suppliers are ranked by AHP score on the Suppliers page and in the purchase order draft, without being chosen automatically', function () {
    $device = createTrustedDeviceFor($this->admin);
    $p = evalProduct($this->team, 'RK', 0);
    $make = function (string $name, string $promised, string $delivered, int $accepted, float $price) use ($p) {
        $s = evalSupplier($this->team, $name);
        $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-09-01', 'promised_delivery_date' => $promised, 'po_number' => "PO-{$name}", 'quantity' => 10, 'product_id' => $p->id, 'description' => 'RK', 'unit_cost' => $price, 'total_cost' => 10 * $price, 'supplier_id' => $s->id, 'status' => PurchaseOrder::FULL]);
        Delivery::create(['team_id' => $this->team->id, 'purchase_order_id' => $po->id, 'delivery_date' => $delivered, 'delivery_number' => "D-{$name}", 'quantity' => 10, 'unit' => 'pcs', 'description' => 'RK',
            'supplier_id' => $s->id, 'status' => 'Delivered', 'inspection_result' => 'accepted', 'inspected_on' => $delivered, 'accepted_quantity' => $accepted, 'rejected_quantity' => 10 - $accepted]);

        return $s;
    };
    $slow = $make('Slow', '2026-09-05', '2026-09-20', 8, 9);   // late, 8/10 accepted, cheapest
    $fast = $make('Fast', '2026-09-10', '2026-09-08', 10, 10); // on time, all accepted
    evalSupplier($this->team, 'New'); // no records: not ranked

    $all = app(SupplierEvaluator::class)->forTeam($this->team->id);
    expect($all[$fast->id]['rank'])->toBe(1)->and($all[$slow->id]['rank'])->toBe(2)->and($all[$fast->id]['ranked_of'])->toBe(2);

    $this->actingAs($this->admin)->withCookies($device['cookies'])->get("/{$this->team->slug}/suppliers")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('suppliers.data.0.name', 'Fast')->where('suppliers.data.0.evaluation.rank', 1)
            ->where('suppliers.data.1.evaluation.rank', 2)->where('suppliers.data.2.evaluation.rank', null));

    // A draft PO lists suppliers best rank first, with no supplier picked by the ranking.
    $draft = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-15', 'po_number' => 'PO-DRAFT', 'quantity' => 5, 'product_id' => $p->id, 'description' => 'RK', 'unit_cost' => 0, 'total_cost' => 0, 'status' => PurchaseOrder::DRAFT]);
    // JSON test requests only send cookies (the trusted-device cookie) with credentials.
    $json = $this->actingAs($this->admin)->withCookies($device['cookies'])->withCredentials()
        ->getJson("/{$this->team->slug}/procurement/purchase-orders/{$draft->id}/workspace")->assertOk()->json();
    expect(array_column($json['suppliers'], 'name'))->toBe(['Fast', 'Slow', 'New'])
        ->and($json['suppliers'][0]['rank'])->toBe(1)
        ->and($json['purchase_order']['supplier_id'])->toBeNull();
});
