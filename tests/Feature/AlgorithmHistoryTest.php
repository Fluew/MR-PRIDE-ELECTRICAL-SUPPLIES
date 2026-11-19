<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Team;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Database\Seeders\AlgorithmHistorySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Capstone/fixtures.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 23:59:59'));
});

test('historical fixtures reconcile and are repeatable without changing real records or sending notifications', function () {
    $real = Team::factory()->create();
    $product = capProduct($real, ['stock' => 42]);
    $before = $product->fresh()->getAttributes();
    Mail::fake();
    Notification::fake();
    Bus::fake();
    Http::preventStrayRequests();
    $this->seed(AlgorithmHistorySeeder::class);
    $team = Team::where('slug', AlgorithmHistorySeeder::SLUG)->firstOrFail();
    $tables = ['teams', 'brands', 'products', 'suppliers', 'clients', 'quotations', 'quotation_items', 'sales', 'sale_items',
        'purchase_requests', 'purchase_orders', 'inbound_deliveries', 'deliveries', 'supplier_product_costs', 'product_supplier',
        'stock_movements', 'payments', 'payment_receipts', 'sales_invoices', 'order_fulfillments', 'delivery_releases', 'delivery_release_items', 'evaluation_snapshots'];
    $counts = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $this->seed(AlgorithmHistorySeeder::class);
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all())->toBe($counts)
        ->and($product->fresh()->getAttributes())->toBe($before)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    foreach (Product::where('team_id', $team->id)->get() as $p) {
        $balance = 0;
        foreach (StockMovement::where('product_id', $p->id)->orderBy('movement_date')->orderBy('id')->get() as $move) {
            expect($move->previous_stock)->toBe($balance);
            $balance += $move->quantity;
            expect($move->new_stock)->toBe($balance)->and($balance)->toBeGreaterThanOrEqual(0);
        }
        expect($balance)->toBe($p->stock);
    }
    expect(DB::table('sales')->where('team_id', $team->id)->whereColumn('sale_date', '>', 'completed_at')->count())->toBe(0)
        ->and(DB::table('deliveries')->where('team_id', $team->id)->whereRaw('accepted_quantity + rejected_quantity <> quantity')->count())->toBe(0)
        ->and(DB::table('sales')->where('team_id', $team->id)->max('completed_at'))->toBe('2026-11-12 10:00:00');
    $products = Product::where('team_id', $team->id)->orderBy('sku')->get();
    foreach ([3 => [60, 100, 30, 40, 12, 0], 6 => [60, 99, 32, 53, 12, 0], 12 => [60, 96, 35, 73, 9, 0]] as $window => $expected) {
        $result = app(SmaForecaster::class)->forProducts($team->id, $products->modelKeys(), $window);
        expect(array_column($result, 'available'))->toBe(array_fill(0, 6, true));
        expect(array_column($result, 'forecast'))->toBe($expected);
    }
    $priority = app(ProductPrioritizer::class)->forTeam($team->id);
    expect($priority[$products[0]->id]['reorder']['suggested_quantity'])->toBe(175)
        ->and($priority[$products[2]->id]['reorder']['suggested_quantity'])->toBe(0)
        ->and($priority[$products[5]->id]['forecast']['monthly'])->toBe(0);
    $suppliers = app(SupplierEvaluator::class)->forTeam($team->id);
    expect(array_column($suppliers, 'complete'))->toBe([true, true, true]);
    $ranked = collect($suppliers)->sortBy('rank')->values();
    expect($ranked[0]['supplier']['name'])->toContain('Quezon')
        ->and($ranked[0]['score'])->toBeGreaterThan($ranked[1]['score'])
        ->and($ranked[1]['score'])->toBeGreaterThan($ranked[2]['score']);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    Bus::assertNothingDispatched();
});

test('linked releases across months are counted once and different products are summed', function () {
    $team = Team::factory()->create();
    $a = capProduct($team);
    $b = capProduct($team);
    $sale = Sale::create(['team_id' => $team->id, 'order_number' => 'TEST-LINK', 'client_name' => 'Test', 'sale_date' => '2026-08-15', 'status' => 'completed']);
    $sale->items()->create(['product_id' => $a->id, 'sku' => $a->sku, 'product_name' => $a->name, 'quantity' => 30, 'cancelled_quantity' => 5, 'unit_price' => 1]);
    $move = capDemand($team, $a, '2026-09-10', 25, 'sale_release');
    $move->update(['sale_id' => $sale->id]);
    capDemand($team, $b, '2026-08-10', 40);
    $series = app(SmaForecaster::class)->series($team->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'), 3);
    expect($series['historical'])->toBe([65, 0]);
});

test('backtesting uses complete actual months and does not fabricate percentages for zero demand', function () {
    $team = Team::factory()->create();
    $p = capProduct($team);
    foreach ([6 => 10, 7 => 20, 8 => 30, 9 => 40] as $month => $qty) {
        capDemand($team, $p, "2026-{$month}-10", $qty);
    }
    $series = app(SmaForecaster::class)->series($team->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-11-12'), 3);
    // September prediction 20 vs 40: error 20, 50%. October prediction 30 vs 0: error 30, MAPE excluded.
    expect($series['accuracy'])->toBe(['months' => 2, 'mae' => 25.0, 'mape' => 50.0, 'mape_months' => 1]);
    $empty = capProduct($team);
    $none = app(SmaForecaster::class)->series($team->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-11-12'), 3, 'All', $empty->id);
    expect(array_filter($none['forecast'], fn ($v) => $v !== null))->toBe([])->and($none['accuracy']['mape'])->toBeNull();
});

test('date bounds exclude later records and insufficient history is distinct from known zero demand', function () {
    $team = Team::factory()->create();
    $p = capProduct($team, [], '2026-10-01');
    capDemand($team, $p, '2026-10-10', 12);
    capDemand($team, $p, '2026-11-20', 100);
    $forecaster = app(SmaForecaster::class);
    expect($forecaster->forProducts($team->id, [$p->id], 3)[$p->id]['available'])->toBeFalse();
    expect($forecaster->series($team->id, Carbon::parse('2026-10-11'), Carbon::parse('2026-11-12'), 3)['historical'])->toBe([0, 0]);
});

test('fixture seeding refuses production before any write', function () {
    app()->instance('env', 'production');
    expect(fn () => (new AlgorithmHistorySeeder)->run())->toThrow(RuntimeException::class, 'Refused');
    expect(Team::where('slug', AlgorithmHistorySeeder::SLUG)->exists())->toBeFalse();
});

test('supplier evaluation excludes pending deliveries cancelled headers and invalid inspections', function () {
    $team = Team::factory()->create();
    $p = capProduct($team);
    $supplier = capSupplier($team);
    $po = capPurchaseOrderWithDeliveries($team, $supplier, $p, ['promised_delivery_date' => '2026-09-10', 'quantity' => 100], [['2026-09-08', 100, 100, 0]]);
    $delivery = $po->deliveries()->first();
    $evaluate = fn () => app(SupplierEvaluator::class)->forTeam($team->id)[$supplier->id];
    expect(collect($evaluate()['criteria'])->firstWhere('key', 'quality')['score'])->toBe(100.0);
    $delivery->update(['status' => 'Pending']);
    expect(collect($evaluate()['criteria'])->firstWhere('key', 'quality')['available'])->toBeFalse();
    $delivery->update(['status' => 'Delivered']);
    $delivery->inboundDelivery->update(['status' => 'cancelled']);
    expect(collect($evaluate()['criteria'])->firstWhere('key', 'quality')['available'])->toBeFalse();
    $delivery->inboundDelivery->update(['status' => 'confirmed']);
    $delivery->update(['accepted_quantity' => 101]);
    expect(collect($evaluate()['criteria'])->firstWhere('key', 'quality')['available'])->toBeFalse();
    $delivery->update(['accepted_quantity' => 100, 'inspected_on' => '2026-12-01']);
    expect(collect($evaluate()['criteria'])->firstWhere('key', 'quality')['available'])->toBeFalse();
});

test('reorder advice refreshes with stock and demand and never suggests negative purchases', function () {
    $team = Team::factory()->create();
    $p = capProduct($team, ['stock' => 5, 'reorder_level' => 10, 'reorder_quantity' => 20]);
    foreach ([8, 9, 10] as $month) {
        capDemand($team, $p, "2026-{$month}-10", 60);
    }
    $read = fn () => app(ProductPrioritizer::class)->forTeam($team->id)[$p->id]['reorder'];
    expect($read()['suggested_quantity'])->toBe(175);
    $p->update(['stock' => 200]);
    expect($read()['needed'])->toBeFalse()->and($read()['suggested_quantity'])->toBe(0);
    capDemand($team, $p, '2026-10-20', 100);
    expect($read()['suggested_quantity'])->toBe(80);
    $p->update(['stock' => 300, 'reorder_quantity' => -20]);
    expect($read()['suggested_quantity'])->toBe(0);
});
