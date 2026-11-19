<?php

/*
 * Inbound Delivery — editable Supplier Name (IS-01 to IS-07).
 *
 * The supplier of an inbound delivery defaults to the purchase order's supplier and can be changed
 * to another registered, active supplier before the delivery is confirmed. The purchase order
 * keeps its own supplier, a confirmed delivery keeps the supplier it was recorded under, and the
 * Supplier Evaluation counts the delivery for the supplier that delivered.
 *
 * Test-only file. Every step goes through the application's own routes and services; the database
 * is the dedicated MariaDB testing database `mr_pride_testing` (run with: php vendor/bin/pest -c phpunit.mysql.xml).
 */

use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductCost;
use App\Models\Team;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Capstone\MysqlTestCase;

require_once __DIR__.'/../../Capstone/mysql_helpers.php';

uses(MysqlTestCase::class); // RefreshDatabase, after the connection is confirmed to be mr_pride_testing

beforeEach(function () {
    capAssertMysqlTestingDatabase();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'inbound-supplier-cap']);
    [$this->buyer, $this->buyerCookies] = capActor('staff_procurement', $this->team);
    $this->ordered = capSupplier($this->team, 'Volt Supply');     // the supplier on the purchase order
    $this->delivering = capSupplier($this->team, 'Ampere Trading'); // another registered supplier
    $this->product = capProduct($this->team, ['sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'stock' => 5, 'supplier_id' => $this->ordered->id]);
    $this->url = '/inbound-supplier-cap';

    $this->order = fn (string $number, float $cost = 10) => PurchaseOrder::create([
        'team_id' => $this->team->id, 'po_date' => '2026-10-01', 'po_number' => $number, 'quantity' => 100, 'product_id' => $this->product->id,
        'item_sku' => 'VLT-CB40', 'description' => 'Circuit Breaker 40A', 'unit_cost' => $cost, 'total_cost' => $cost * 100, 'unit_of_measure' => 'PCS',
        'supplier_id' => $this->ordered->id, 'status' => PurchaseOrder::SENT, 'promised_delivery_date' => '2026-10-20',
    ]);
    $this->confirm = fn (PurchaseOrder $po, array $extra = [], int $accepted = 90, int $rejected = 10) => $this->actingAs($this->buyer)->withCookies($this->buyerCookies)
        ->post("{$this->url}/deliveries/inbound", $extra + [
            'po_number' => $po->po_number, 'delivery_date' => '2026-10-15', 'submission_key' => (string) Str::uuid(),
            'lines' => [['purchase_order_id' => $po->id, 'inspected' => true, 'received_quantity' => $accepted + $rejected, 'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected, 'inspection_remarks' => $rejected ? 'Cracked casings' : null]],
        ]);
    $this->criteria = fn (Supplier $s) => collect(app(SupplierEvaluator::class)->forTeam($this->team->id)[$s->id]['criteria'])->keyBy('key');
});

afterEach(fn () => Carbon::setTestNow());

it('IS-01 lists the registered active suppliers for the Supplier Name dropdown', function () {
    Supplier::create(['team_id' => $this->team->id, 'name' => 'Dormant Supply', 'status' => 'Inactive']);
    ($this->order)('PO-9001');

    $this->actingAs($this->buyer)->withCookies($this->buyerCookies)->get("{$this->url}/deliveries?tab=inbound")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('suppliers', fn ($list) => collect($list)->pluck('name')->all() === ['Ampere Trading', 'Volt Supply'])
            ->where('receivableOrders.0.supplier.id', $this->ordered->id)); // pre-selected from the purchase order
});

it('IS-02 records the delivery under the purchase order supplier when the supplier is not changed', function () {
    $po = ($this->order)('PO-9001');

    ($this->confirm)($po)->assertSessionHasNoErrors();                                        // field left out
    ($this->confirm)(($this->order)('PO-9002'), ['supplier_id' => $this->ordered->id])->assertSessionHasNoErrors(); // same supplier sent

    expect(InboundDelivery::pluck('supplier_id')->all())->toBe([$this->ordered->id, $this->ordered->id])
        ->and(Delivery::pluck('supplier_id')->unique()->all())->toBe([$this->ordered->id])
        ->and(Supplier::count())->toBe(2);
});

it('IS-03 saves the selected supplier on the delivery and leaves the purchase order unchanged', function () {
    $po = ($this->order)('PO-9001');

    ($this->confirm)($po, ['supplier_id' => $this->delivering->id])->assertSessionHasNoErrors();

    $header = InboundDelivery::sole();
    expect($header->supplier_id)->toBe($this->delivering->id)
        ->and(Delivery::sole()->supplier_id)->toBe($this->delivering->id)
        ->and($po->fresh()->supplier_id)->toBe($this->ordered->id)          // purchase order keeps its supplier
        ->and($po->fresh()->quantity)->toBe(100)
        ->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL)           // status rule unchanged: 90 of 100 accepted
        ->and($this->product->fresh()->stock)->toBe(95)                     // stock rule unchanged: 5 + 90 accepted
        ->and(SupplierProductCost::sole()->supplier_id)->toBe($this->delivering->id)
        ->and(Supplier::count())->toBe(2)                                   // no duplicate supplier created
        ->and(AuditLog::where('event', 'inbound_delivery.confirmed')->sole()->metadata)
        ->toMatchArray(['supplier_id' => $this->delivering->id, 'purchase_order_supplier_id' => $this->ordered->id]);

    $this->actingAs($this->buyer)->withCookies($this->buyerCookies)->withCredentials()->getJson("{$this->url}/deliveries/inbound/{$header->id}")->assertOk()
        ->assertJson(['supplier' => 'Ampere Trading', 'supplier_id' => $this->delivering->id]);
});

it('IS-04 refuses a supplier that is not a registered active supplier of the team', function () {
    $po = ($this->order)('PO-9001');
    $inactive = Supplier::create(['team_id' => $this->team->id, 'name' => 'Dormant Supply', 'status' => 'Inactive']);
    $foreign = capSupplier(Team::factory()->create(), 'Other Company Supplier');
    $archived = capSupplier($this->team, 'Archived Supply');
    $archived->delete();

    foreach ([999999, $foreign->id, $archived->id, $inactive->id, 'abc'] as $bad) {
        ($this->confirm)($po, ['supplier_id' => $bad])->assertSessionHasErrors('supplier_id');
    }

    expect(InboundDelivery::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and($this->product->fresh()->stock)->toBe(5);
});

it('IS-05 counts delivery and quality for the supplier that delivered, not the purchase order supplier', function () {
    ($this->confirm)(($this->order)('PO-9001'), ['supplier_id' => $this->delivering->id])->assertSessionHasNoErrors();

    $delivered = ($this->criteria)($this->delivering);
    $ordered = ($this->criteria)($this->ordered);

    expect($delivered['delivery']['inputs'])->toMatchArray(['completed_deliveries' => 1, 'on_time_deliveries' => 1, 'ordered_quantity' => 100, 'accepted_quantity' => 90])
        ->and($delivered['delivery']['score'])->toEqual(96.0)   // (100 × 0.60) + (90 × 0.40)
        ->and($delivered['quality']['inputs'])->toMatchArray(['inspections' => 1, 'accepted' => 90, 'rejected' => 10])
        ->and($delivered['quality']['score'])->toEqual(90.0)
        ->and($ordered['delivery']['score'])->toBeNull()        // nothing is counted twice
        ->and($ordered['quality']['score'])->toBeNull();
});

it('IS-06 compares prices under the supplier that delivered, consistent with the other criteria', function () {
    // Volt Supply delivers its own order at 10.00; Ampere Trading delivers a Volt Supply order at 12.50.
    ($this->confirm)(($this->order)('PO-9001', 10), [], 100, 0)->assertSessionHasNoErrors();
    ($this->confirm)(($this->order)('PO-9002', 12.5), ['supplier_id' => $this->delivering->id], 100, 0)->assertSessionHasNoErrors();

    $evaluator = app(SupplierEvaluator::class);
    $ordered = ($this->criteria)($this->ordered);
    $delivered = ($this->criteria)($this->delivering);

    expect($ordered['price']['score'])->toEqual(100.0)
        ->and($ordered['price']['inputs']['comparisons'][0]['own_price'])->toEqual(10.0)
        ->and($delivered['price']['score'])->toEqual(80.0)      // (10.00 / 12.50) × 100
        ->and($delivered['price']['inputs']['comparisons'][0]['own_price'])->toEqual(12.5)
        // Every criterion of each supplier is based on that supplier's own single delivery.
        ->and($ordered['quality']['inputs']['inspections'])->toBe(1)
        ->and($delivered['quality']['inputs']['inspections'])->toBe(1)
        ->and($evaluator->forSupplier($this->ordered)['complete'])->toBeTrue()
        ->and($evaluator->forSupplier($this->delivering)['complete'])->toBeTrue()
        ->and($evaluator->forSupplier($this->delivering)['score'])->toEqual(93.0); // 100×0.40 + 80×0.35 + 100×0.25 = 40 + 28 + 25
});

it('IS-07 keeps the supplier of a confirmed delivery when the delivery is corrected', function () {
    ($this->confirm)(($this->order)('PO-9001'), ['supplier_id' => $this->delivering->id])->assertSessionHasNoErrors();
    $header = InboundDelivery::sole();
    $item = Delivery::sole();

    $this->actingAs($this->buyer)->withCookies($this->buyerCookies)->put("{$this->url}/deliveries/inbound/{$header->id}", [
        'reason' => 'One more unit found damaged', 'delivery_date' => '2026-10-15', 'supplier_id' => $this->ordered->id,
        'lines' => [['delivery_id' => $item->id, 'received_quantity' => 100, 'accepted_quantity' => 89, 'rejected_quantity' => 11, 'inspection_remarks' => 'Cracked casings']],
    ])->assertSessionHasNoErrors();

    expect($header->fresh()->supplier_id)->toBe($this->delivering->id)   // the supplier sent with the correction is ignored
        ->and($item->fresh()->supplier_id)->toBe($this->delivering->id)
        ->and((int) $item->fresh()->accepted_quantity)->toBe(89)          // the correction itself still works
        ->and($this->product->fresh()->stock)->toBe(94);
});
