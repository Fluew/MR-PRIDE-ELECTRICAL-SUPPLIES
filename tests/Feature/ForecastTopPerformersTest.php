<?php

use App\Enums\ForecastPeriod;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\Forecast\TopPerformers;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Capstone/helpers.php';

beforeEach(function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-11-17 10:00:00'); // a Tuesday
    $this->team = Team::factory()->create();
    $this->top = app(TopPerformers::class);
});

afterEach(fn () => Carbon::setTestNow());

function topSale(Team $team, Product $p, string $date, int $qty, float $price, int $cancelled = 0, string $status = 'completed'): void
{
    $sale = Sale::create(['team_id' => $team->id, 'order_number' => 'TOP-'.uniqid(), 'client_name' => 'Top test', 'sale_date' => $date, 'status' => $status]);
    $sale->items()->create(['product_id' => $p->id, 'sku' => $p->sku, 'product_name' => $p->name, 'quantity' => $qty, 'cancelled_quantity' => $cancelled, 'unit_price' => $price, 'subtotal' => $qty * $price]);
}

it('ranks the top 5 products by units sold in the selected period, with suppliers, unit cost and net sales', function () {
    $main = Supplier::create(['team_id' => $this->team->id, 'name' => 'Main Supply', 'status' => 'Active']);
    $products = collect(range(1, 6))->map(fn ($i) => capProduct($this->team, ['name' => "Product {$i}", 'cost_price' => $i * 1.5, 'supplier_id' => $i === 1 ? $main->id : null], '2020-01-01'));

    // November (current month, to date): units 1..6 → product 6 first, product 1 left out.
    foreach ($products as $i => $p) {
        topSale($this->team, $p, '2026-11-0'.($i + 2), ($i + 1) * 10, 2.0);
    }
    // Product 1 also: a cancelled part and an unconfirmed order do not count; October sales are another period.
    topSale($this->team, $products[0], '2026-11-10', 100, 2.0, 95);
    topSale($this->team, $products[0], '2026-11-11', 500, 2.0, 0, 'cancelled');
    topSale($this->team, $products[0], '2026-10-15', 900, 2.0);

    $month = $this->top->for($this->team->id, ForecastPeriod::Monthly);
    expect($month['label'])->toBe('Nov 2026')->and($month['from'])->toBe('2026-11-01')->and($month['to'])->toBe('2026-11-17')
        ->and(array_column($month['products'], 'name'))->toBe(['Product 6', 'Product 5', 'Product 4', 'Product 3', 'Product 2'])
        ->and($month['products'][0])->toMatchArray(['rank' => 1, 'units_sold' => 60, 'total_sales' => 120.0, 'unit_cost' => 9.0]);

    // October: product 1 alone, with its supplier.
    $october = $this->top->for($this->team->id, ForecastPeriod::Monthly, 1);
    expect($october['label'])->toBe('Oct 2026')->and($october['to'])->toBe('2026-10-31')->and($october['is_current'])->toBeFalse()
        ->and($october['products'])->toHaveCount(1)
        ->and($october['products'][0])->toMatchArray(['name' => 'Product 1', 'units_sold' => 900, 'total_sales' => 1800.0, 'suppliers' => ['Main Supply']]);

    // Annual 2026: product 1 = 10 + (100 − 95) + 900 = 915 units; sales for the kept units only.
    $year = $this->top->for($this->team->id, ForecastPeriod::Annual);
    expect($year['products'][0])->toMatchArray(['name' => 'Product 1', 'units_sold' => 915, 'total_sales' => 1830.0]);

    // This week (Mon 16 Nov – today): nothing sold yet; last week holds the 9th–10th sales.
    $week = $this->top->for($this->team->id, ForecastPeriod::Weekly);
    expect($week['from'])->toBe('2026-11-16')->and($week['products'])->toBe([]);
    expect($this->top->for($this->team->id, ForecastPeriod::Weekly, 1)['products'][0]['name'])->toBe('Product 1');
});

it('scores suppliers only from the records dated in the selected period', function () {
    $p = capProduct($this->team, [], '2020-01-01');
    $s = Supplier::create(['team_id' => $this->team->id, 'name' => 'Alpha', 'status' => 'Active']);
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-01', 'promised_delivery_date' => '2026-10-10', 'po_number' => 'PO-1', 'quantity' => 10, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => 5, 'total_cost' => 50, 'supplier_id' => $s->id]);
    Delivery::create(['team_id' => $this->team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => 'PO-1', 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s->id, 'status' => 'Delivered',
        'inspection_result' => 'partially_accepted', 'inspected_on' => '2026-10-09', 'delivery_date' => '2026-10-08', 'delivery_number' => 'D-1', 'quantity' => 10, 'accepted_quantity' => 9, 'rejected_quantity' => 1]);

    $october = collect($this->top->for($this->team->id, ForecastPeriod::Monthly, 1)['suppliers'])->firstWhere('id', $s->id);
    expect($october)->not->toBeNull()
        ->and($october['quality'])->toEqual(90.0)
        ->and($october['delivery'])->not->toBeNull()
        ->and($october['price'])->toBeNull()     // no other supplier to compare with: insufficient, not 0
        ->and($october['overall'])->toBeNull();

    // November has no records for this supplier: it is not listed at all.
    expect(collect($this->top->for($this->team->id, ForecastPeriod::Monthly)['suppliers'])->firstWhere('id', $s->id))->toBeNull();
});

it('serves the panel on the forecast page and reloads only the panel for a new period', function () {
    [$user, $cookies] = capActor('admin', $this->team);
    $p = capProduct($this->team, ['name' => 'Wire'], '2020-01-01');
    topSale($this->team, $p, '2025-06-01', 40, 3.0);

    $this->actingAs($user)->withCookies($cookies)->get('/'.$this->team->slug.'/forecast')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('topPerformers.period', 'monthly')->where('topPerformers.offset', 0)->has('periodPlanning'));

    $this->actingAs($user)->withCookies($cookies)
        ->get('/'.$this->team->slug.'/forecast?top_period=annual&top_offset=1', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Forecast/Index', 'X-Inertia-Partial-Data' => 'topPerformers',
        ])
        ->assertOk()
        ->assertJsonPath('props.topPerformers.label', '2025')
        ->assertJsonPath('props.topPerformers.products.0.name', 'Wire')
        ->assertJsonPath('props.topPerformers.products.0.units_sold', 40)
        ->assertJsonMissingPath('props.periodPlanning'); // the SMA forecast is not recalculated

    $this->actingAs($user)->withCookies($cookies)->get('/'.$this->team->slug.'/forecast?top_period=daily')->assertSessionHasErrors('top_period');
});
