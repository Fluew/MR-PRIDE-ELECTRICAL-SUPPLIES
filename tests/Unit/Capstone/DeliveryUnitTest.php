<?php

/*
 * 4.4.1 Unit Testing — Delivery Management (UT-73 to UT-79).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 */

use App\Enums\DeliveryStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Team;
use App\Services\Delivery\FulfillmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    Carbon::setTestNow('2026-11-09 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'del-cap']);
    [$this->admin, $this->adminCookies] = capActor('admin', $this->team);
    [$this->staff, $this->staffCookies] = capActor('staff_procurement', $this->team);
    [$this->warehouse, $this->warehouseCookies] = capActor('staff_inventory', $this->team);
    $this->supplier = capSupplier($this->team, 'Surangel Supply');
    $this->breaker = capProduct($this->team, ['sku' => 'BRK-000001', 'name' => 'Circuit Breaker 40A', 'stock' => 25, 'supplier_id' => $this->supplier->id]);
    // A sent purchase order for 20 breakers.
    $this->po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-02', 'po_number' => 'PO-7001', 'supplier_id' => $this->supplier->id,
        'status' => PurchaseOrder::SENT, 'unit_of_measure' => 'PCS', 'quantity' => 20, 'item_sku' => 'SUR-BRK40', 'product_id' => $this->breaker->id,
        'description' => 'Circuit Breaker 40A', 'unit_cost' => 12.5, 'total_cost' => 250]);
    $this->url = '/del-cap';
});

afterEach(fn () => Carbon::setTestNow());

/** Confirm an inbound delivery of the purchase order with the given quantities. */
function capInbound($t, array $line, array $extra = [])
{
    return $t->actingAs($t->staff)->withCookies($t->staffCookies)->post("{$t->url}/deliveries/inbound", $extra + [
        'po_number' => 'PO-7001', 'delivery_date' => '2026-11-09', 'submission_key' => (string) Str::uuid(),
        'lines' => [$line + ['purchase_order_id' => $t->po->id, 'inspected' => true]],
    ]);
}

it('UT-73 refuses inbound quantities that do not add up or exceed what is still owed', function () {
    capInbound($this, ['received_quantity' => 10, 'accepted_quantity' => 7, 'rejected_quantity' => 2])->assertSessionHasErrors('lines.0.accepted_quantity'); // 7 + 2 ≠ 10
    capInbound($this, ['received_quantity' => 21, 'accepted_quantity' => 21, 'rejected_quantity' => 0])->assertSessionHasErrors('lines.0.received_quantity'); // 21 of 20
    capInbound($this, ['received_quantity' => 5, 'accepted_quantity' => -1, 'rejected_quantity' => 6])->assertSessionHasErrors('lines.0.accepted_quantity');
    capInbound($this, ['received_quantity' => 5, 'accepted_quantity' => 3, 'rejected_quantity' => 2])->assertSessionHasErrors('lines.0.inspection_remarks');  // rejected, no reason
    capInbound($this, ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0])->assertSessionHasErrors('lines');                      // nothing received

    expect(InboundDelivery::count())->toBe(0)
        ->and($this->breaker->fresh()->stock)->toBe(25)
        ->and(StockMovement::count())->toBe(0);
});

it('UT-74 adds only the accepted inbound units to stock', function () {
    capInbound($this, ['received_quantity' => 20, 'accepted_quantity' => 17, 'rejected_quantity' => 3, 'inspection_remarks' => 'Three cracked'])
        ->assertSessionHasNoErrors();

    // 25 + 17 accepted = 42; the 3 rejected units never reach stock.
    $movement = StockMovement::sole();
    expect($this->breaker->fresh()->stock)->toBe(42)
        ->and($movement->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'stock_in', 'quantity' => 17, 'previous_stock' => 25, 'new_stock' => 42])
        ->and(Delivery::sole()->only(['quantity', 'accepted_quantity', 'rejected_quantity']))->toBe(['quantity' => 20, 'accepted_quantity' => 17, 'rejected_quantity' => 3]);
});

it('UT-75 validates the quantity and date of a quick delivery receipt', function () {
    $receive = fn (array $data) => $this->actingAs($this->staff)->withCookies($this->staffCookies)
        ->post("{$this->url}/deliveries/purchase-orders/{$this->po->id}/receive", $data + ['delivery_date' => '2026-11-09', 'quantity' => 10]);

    $receive(['quantity' => 0])->assertSessionHasErrors('quantity');
    $receive(['quantity' => -4])->assertSessionHasErrors('quantity');
    $receive(['quantity' => 2.5])->assertSessionHasErrors('quantity');
    $receive(['quantity' => 21])->assertSessionHasErrors('quantity');                       // one more than ordered
    $receive(['delivery_date' => '2026-11-10'])->assertSessionHasErrors('delivery_date');   // tomorrow
    expect(Delivery::count())->toBe(0);

    $receive(['quantity' => 20])->assertSessionHasNoErrors(); // exactly what is owed
    expect(Delivery::sole()->quantity)->toBe(20)
        ->and($this->breaker->fresh()->stock)->toBe(25); // receiving alone adds nothing: inspection comes first
});

it('UT-76 corrects a confirmed inbound delivery by the difference only, and never below zero', function () {
    capInbound($this, ['received_quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0])->assertSessionHasNoErrors();
    $header = InboundDelivery::sole();
    $item = $header->items()->first();
    $correct = fn (int $accepted, int $rejected) => $this->actingAs($this->staff)->withCookies($this->staffCookies)->put("{$this->url}/deliveries/inbound/{$header->id}", [
        'reason' => 'Recount after unpacking',
        'lines' => [['delivery_id' => $item->id, 'received_quantity' => 10, 'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'inspection_remarks' => 'Cracked housing']],
    ]);
    expect($this->breaker->fresh()->stock)->toBe(35);

    $correct(8, 2)->assertSessionHasNoErrors(); // 10 → 8 accepted: stock −2, not +8
    expect($this->breaker->fresh()->stock)->toBe(33);

    $this->breaker->update(['stock' => 3]); // most of it was sold meanwhile
    $correct(2, 8)->assertSessionHasErrors('accepted_quantity'); // would take out 6 of 3
    expect($this->breaker->fresh()->stock)->toBe(3);
});

it('UT-77 refuses an outbound release larger than the ordered quantity still to be released', function () {
    $sale = capConfirmed($this->team, $this->admin, [[$this->breaker, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);

    expect(fn () => $service->prepareRelease($sale->fulfillment, [$line->id => 6], $this->warehouse))->toThrow(WorkflowException::class, 'only 5 can still be released');
    expect(fn () => $service->prepareRelease($sale->fulfillment, [$line->id => 0], $this->warehouse))->toThrow(WorkflowException::class, 'Choose at least one item');
    expect(fn () => $service->prepareRelease($sale->fulfillment, [999999 => 1], $this->warehouse))->toThrow(WorkflowException::class, 'not part of this order');
    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)
        ->post("{$this->url}/deliveries/fulfillments/{$sale->fulfillment->id}/releases", ['quantities' => [$line->id => -1]])->assertSessionHasErrors("quantities.{$line->id}");

    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $this->warehouse); // exactly the ordered quantity
    expect($release->items->sole()->quantity)->toBe(5)
        ->and($this->breaker->fresh()->stock)->toBe(25); // preparing a release moves no stock
});

it('UT-78 refuses a received quantity outside the released quantity', function () {
    $sale = capConfirmed($this->team, $this->admin, [[$this->breaker, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $this->warehouse);
    $service->markReady($release, $this->warehouse);
    $service->dispatch($release, $this->admin);
    $item = $release->items->sole();
    $receive = fn (int $qty) => $service->recordReceipt($release->fresh(), [$line->id => $qty], 'Site Engr. Lito', null, null, $this->admin);

    expect(fn () => $receive(6))->toThrow(WorkflowException::class, 'received quantity must be between 0 and 5');
    expect(fn () => $receive(0))->toThrow(WorkflowException::class, 'Nothing was received');
    expect($this->breaker->fresh()->stock)->toBe(25);
});

it('UT-79 takes out of stock exactly the quantity the customer received, once', function () {
    $sale = capConfirmed($this->team, $this->admin, [[$this->breaker, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $this->warehouse);
    $service->markReady($release, $this->warehouse);
    $service->dispatch($release, $this->admin);
    expect($this->breaker->fresh()->stock)->toBe(25)->and($this->breaker->fresh()->availableStock())->toBe(20); // on the road: still in stock, held

    $status = $service->recordReceipt($release->fresh(), [$line->id => 3], 'Site Engr. Lito', null, null, $this->admin);

    // 25 − 3 received = 22; the 2 not received stay in stock, reserved for the order.
    $movement = StockMovement::where('delivery_release_id', $release->id)->sole();
    expect($status)->not->toBe(DeliveryStatus::Delivered)
        ->and($this->breaker->fresh()->stock)->toBe(22)
        ->and($movement->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'sale_release', 'quantity' => -3, 'previous_stock' => 25, 'new_stock' => 22])
        ->and($line->fresh()->delivered_quantity)->toBe(3)
        ->and($line->fresh()->remaining_quantity)->toBe(2)
        ->and(Product::find($this->breaker->id)->availableStock())->toBe(20);
});
