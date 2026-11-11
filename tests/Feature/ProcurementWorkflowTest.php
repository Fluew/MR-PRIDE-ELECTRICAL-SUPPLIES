<?php

use App\Enums\TeamRole;
use App\Events\Procurement\AHPPrioritizationRecalculated;
use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\PurchaseOrderCreated;
use App\Events\Procurement\PurchaseOrderSent;
use App\Events\Procurement\PurchaseRequestApproved;
use App\Events\Procurement\PurchaseRequestRejected;
use App\Events\Procurement\PurchaseRequestSubmitted;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Events\Procurement\SupplierEvaluationUpdated;
use App\Jobs\RecalculateEvaluations;
use App\Models\Delivery;
use App\Models\EvaluationSnapshot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The full Inventory-to-Procurement cycle through the HTTP endpoints, with the automatic steps:
 * approval drafts the PO, inspection posts the accepted units to stock, and evaluations are recalculated.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'proc-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->staff = User::factory()->create(['role' => 'staff_procurement', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->staff, ['role' => TeamRole::Member->value]);
    $this->adminCookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->staffCookies = createTrustedDeviceFor($this->staff)['cookies'];

    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $this->product = Product::create(['team_id' => $this->team->id, 'sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 20, 'cost_price' => 10,
        'stock' => 5, 'reorder_level' => 10, 'reorder_quantity' => 100, 'is_active' => true, 'unit' => 'pcs', 'supplier_id' => $this->supplier->id]);
    $this->product->forceFill(['created_at' => '2025-01-01'])->saveQuietly();
    foreach (['2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
        StockMovement::create(['team_id' => $this->team->id, 'product_id' => $this->product->id, 'type' => 'sale', 'quantity' => -$qty, 'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "S-{$date}"]);
    }
    $this->url = "/{$this->team->slug}";
});

afterEach(fn () => Carbon::setTestNow());

function asStaff($t)
{
    return $t->actingAs($t->staff)->withCookies($t->staffCookies);
}
function asAdmin($t)
{
    return $t->actingAs($t->admin)->withCookies($t->adminCookies);
}

function approvedRequest($t, int $qty = 100): PurchaseRequest
{
    asStaff($t)->post("{$t->url}/procurement/requests", ['lines' => [['product_id' => $t->product->id, 'quantity' => $qty, 'justification' => 'Below reorder level.']]]);
    $pr = PurchaseRequest::latest('id')->first();
    asAdmin($t)->post("{$t->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve']);

    return $pr->fresh();
}

test('the complete cycle advances automatically after each human step', function () {
    Event::fake([
        PurchaseRequestSubmitted::class, PurchaseRequestApproved::class, PurchaseOrderCreated::class, PurchaseOrderSent::class,
        InboundDeliveryRecorded::class, QualityInspectionCompleted::class, InventoryUpdated::class,
    ]);
    $url = $this->url;

    // 1. AHP recommends replenishment (5 in stock, forecast 50/month: 150 over the default 3-month period).
    $priority = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id];
    expect($priority['reorder']['needed'])->toBeTrue()->and($priority['reorder']['suggested_quantity'])->toBe(145)
        ->and(PurchaseRequest::count())->toBe(0); // recommendations never create requests by themselves

    // 2. Staff submit the request: Pending Approval.
    asStaff($this)->post("{$url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 100, 'justification' => 'Below reorder level; forecast 50/month.']], 'forecast_months' => 3])
        ->assertSessionHasNoErrors();
    $pr = PurchaseRequest::sole();
    expect($pr->status)->toBe(PurchaseRequest::PENDING)->and($pr->recommended_quantity)->toBe(145)->and($pr->forecast_quantity)->toBe(150);
    Event::assertDispatched(PurchaseRequestSubmitted::class);
    asStaff($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertForbidden();

    // 3–4. Admin approves: the draft PO is created at once, pre-filled from records, once.
    SupplierQuote::create(['team_id' => $this->team->id, 'supplier_id' => $this->supplier->id, 'product_id' => $this->product->id, 'reference' => 'Q-1', 'unit_price' => 9.5, 'unit' => 'pcs', 'quoted_on' => '2026-10-01', 'verified_at' => now()]);
    // Approved on Oct 1: the draft order is dated that day (the order date is not edited later).
    Carbon::setTestNow('2026-10-01 09:00:00');
    asAdmin($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve', 'remarks' => 'OK'])->assertSessionHasNoErrors();
    Carbon::setTestNow('2026-10-15 10:00:00');
    asAdmin($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasErrors();
    $po = PurchaseOrder::sole();
    expect($pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED)->and($pr->fresh()->reviewed_by_id)->toBe($this->admin->id)
        ->and($po->purchase_request_id)->toBe($pr->id)->and($po->status)->toBe(PurchaseOrder::DRAFT)
        ->and($po->supplier_id)->toBe($this->supplier->id)->and((float) $po->unit_cost)->toBe(9.5)->and($po->quantity)->toBe(100);
    Event::assertDispatched(PurchaseRequestApproved::class);
    Event::assertDispatched(PurchaseOrderCreated::class, fn ($e) => $e->automatic && $e->purchaseOrder->is($po));

    // 5. Staff confirm the terms and send. Goods cannot be received on a draft.
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-08', 'quantity' => 10])->assertSessionHasErrors();
    asStaff($this)->patch("{$url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9.5, 'payment_terms' => '30 days', 'promised_delivery_date' => '2026-10-20'])
        ->assertSessionHasNoErrors();
    asStaff($this)->post("{$url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::SENT)->and($po->fresh()->payment_terms)->toBe('30 days');
    Event::assertDispatched(PurchaseOrderSent::class);

    // 6. Partial delivery: an inspection task opens; the PO is not "delivered".
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-08', 'quantity' => 60, 'supplier_document_number' => 'DR-7781'])
        ->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    expect($delivery->inspectionStatus())->toBe('Pending Inspection')->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL)->and($this->product->fresh()->stock)->toBe(5);
    Event::assertDispatched(InboundDeliveryRecorded::class, fn ($e) => $e->openBefore === 100);
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-09', 'quantity' => 41])->assertSessionHasErrors('quantity');

    // 7. Inspection: 4 damaged + 2 defective are rejected and held; the 54 accepted go into stock at once.
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 3, 'inspected_on' => '2026-10-09', 'rejection_reason' => 'Burnt contacts'])
        ->assertSessionHasErrors('damaged_quantity');
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 2, 'inspected_on' => '2026-10-09', 'rejection_reason' => 'Burnt contacts, cracked casings'])
        ->assertSessionHasNoErrors();
    $delivery->refresh();
    expect($delivery->damaged_quantity)->toBe(4)->and($delivery->defective_quantity)->toBe(2)->and($delivery->rejection_category)->toBe('damaged')
        ->and($delivery->inspectionStatus())->toBe('Pending Resolution')
        ->and($this->product->fresh()->stock)->toBe(59)
        ->and($delivery->posted_quantity)->toBe(54)
        ->and(StockMovement::where('movement_key', "inbound-delivery:{$delivery->id}")->value('quantity'))->toBe(54);
    Event::assertDispatched(QualityInspectionCompleted::class);
    Event::assertDispatched(InventoryUpdated::class, fn ($e) => $e->movement?->quantity === 54);

    // No second posting: a manual post is refused, and a staff re-inspection with the same numbers moves nothing.
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/post-inventory")->assertSessionHasErrors();
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 2, 'inspected_on' => '2026-10-09', 'rejection_reason' => 'Burnt contacts, cracked casings'])->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(59);

    // Stock was posted once, at inspection.
    expect(StockMovement::where('movement_key', 'like', "inbound-delivery:{$delivery->id}%")->count())->toBe(1);
    Event::assertDispatchedTimes(InventoryUpdated::class, 1);

    // 10. AHP reflects the new stock; demand still comes from sales, not the purchase.
    $after = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id];
    expect(collect($after['criteria'])->firstWhere('key', 'stock')['inputs']['available'])->toBe(59)
        ->and($after['forecast']['monthly'])->toBe(50)->and($after['reorder']['forecast'])->toBe(150)
        // 59 in stock does not cover the 150 forecast for the next 3 months: still advised (91 short → 100).
        ->and($after['reorder']['needed'])->toBeTrue()->and($after['reorder']['suggested_quantity'])->toBe(100);

    // Second delivery completes the order (46 still owed: 40 open + 6 rejected).
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-14', 'quantity' => 46])->assertSessionHasNoErrors();
    $second = Delivery::latest('id')->first();
    asStaff($this)->post("{$url}/deliveries/{$second->id}/inspection", ['accepted_quantity' => 46, 'rejected_quantity' => 0, 'inspected_on' => '2026-10-14']);
    // Not Received while the 6 rejected units of the first delivery are unresolved.
    expect($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL);
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/resolution", ['resolution_status' => 'returned'])->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::FULL);

    // 11. Supplier Evaluation reads the same delivery and inspection records.
    $quality = collect(app(SupplierEvaluator::class)->forTeam($this->team->id)[$this->supplier->id]['criteria'])->firstWhere('key', 'quality');
    expect($quality['score'])->toEqual(round(100 / 106 * 100, 2));
});

test('a catalog product request takes only the quantity; the unit is the product\'s and no description is kept', function () {
    $this->product->update(['description' => 'Original catalog text']);
    $line = fn (array $l) => ['lines' => [$l + ['product_id' => $this->product->id, 'justification' => 'Needed for the Ortiz project.']]];

    // The page has the system units for "Product Not Listed", and each product's own unit.
    asStaff($this)->get("{$this->url}/procurement")->assertOk()
        ->assertInertia(fn ($page) => $page->where('unitOptions', fn ($u) => collect($u)->contains('box') && collect($u)->contains('roll')
            && collect($u)->filter(fn ($x) => $x === 'pcs')->count() === 1)
            ->where('products.0.unit', 'pcs'));

    // Zero, negatives, decimals and empty quantities are refused; nothing is saved.
    foreach ([0, -3, 2.5, ''] as $bad) {
        asStaff($this)->post("{$this->url}/procurement/requests", $line(['quantity' => $bad]))->assertSessionHasErrors('lines.0.quantity');
    }
    expect(PurchaseRequest::count())->toBe(0);

    // A unit or description sent with a catalog product is ignored: the product's own details stand.
    asStaff($this)->post("{$this->url}/procurement/requests", $line(['quantity' => 12, 'product_description' => 'something else', 'unit' => 'box']))
        ->assertSessionHasNoErrors();

    $pr = PurchaseRequest::sole();
    expect($pr->product_id)->toBe($this->product->id)
        ->and($pr->product_description)->toBeNull()
        ->and($pr->quantity)->toBe(12)
        ->and($pr->unit)->toBe('pcs')
        ->and($pr->isUnlisted())->toBeFalse()
        ->and($this->product->fresh()->description)->toBe('Original catalog text')
        ->and($this->product->fresh()->stock)->toBe(5);
});

test('"Product Not Listed": requested by description and unit, ordered, then added to inventory only when received', function () {
    Event::fake([InboundDeliveryRecorded::class]);
    $products = Product::count();
    $request = fn (array $l) => asStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [$l + ['product_id' => null, 'quantity' => 6, 'justification' => 'Needed for the Ortiz project.']]]);

    // Description and unit are required; the unit must be one of the system's units.
    $request(['unit' => 'roll'])->assertSessionHasErrors(['lines.0.product_description' => 'Describe the product you need to purchase.']);
    $request(['product_description' => 'THHN 14 AWG'])->assertSessionHasErrors(['lines.0.unit' => 'Select the unit.']);
    $request(['product_description' => 'THHN 14 AWG', 'unit' => 'bucket'])->assertSessionHasErrors(['lines.0.unit' => 'Select one of the listed units.']);
    expect(PurchaseRequest::count())->toBe(0);

    $request(['product_description' => '  THHN 14 AWG stranded wire, black, 150 m  ', 'unit' => 'roll'])->assertSessionHasNoErrors();
    $pr = PurchaseRequest::sole();
    expect($pr->product_id)->toBeNull()
        ->and($pr->isUnlisted())->toBeTrue()
        ->and($pr->product_description)->toBe('THHN 14 AWG stranded wire, black, 150 m')
        ->and($pr->unit)->toBe('roll')
        ->and($pr->quantity)->toBe(6)
        ->and($pr->status)->toBe(PurchaseRequest::PENDING)
        ->and($pr->itemName())->toBe('Not listed: THHN 14 AWG stranded wire, black, 150 m')
        ->and(Product::count())->toBe($products); // nothing is created in inventory

    // The request list shows it by its description.
    asStaff($this)->get("{$this->url}/procurement?tab=requests")->assertOk()
        ->assertInertia(fn ($page) => $page->where('requests.data.0.product', null)->where('requests.data.0.product_description', 'THHN 14 AWG stranded wire, black, 150 m')->where('requests.data.0.unit', 'roll'));

    // Approval drafts the purchase order from the description and unit, without a product.
    asAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    $po = PurchaseOrder::sole();
    expect($po->product_id)->toBeNull()->and($po->item_sku)->toBeNull()
        ->and($po->description)->toBe('THHN 14 AWG stranded wire, black, 150 m')
        ->and($po->unit_of_measure)->toBe('ROLL')->and($po->quantity)->toBe(6)
        ->and((float) $po->unit_cost)->toBe(0.0)->and($po->status)->toBe(PurchaseOrder::DRAFT)
        ->and($pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED);

    // Supplier and agreed price are confirmed, then it can be sent like any other order.
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasErrors('supplier_id');
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 6, 'unit_cost' => 41.5])->assertSessionHasNoErrors();
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::SENT);

    // The quick receive in the order workspace is refused: the product must be chosen first.
    asStaff($this)->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 6])
        ->assertSessionHasErrors('purchase_order_id');
    expect(Delivery::count())->toBe(0);

    // Record Inbound P.O. Delivery offers it without a product; staff create it (or link one) on receipt.
    asStaff($this)->get("{$this->url}/deliveries?tab=inbound")->assertOk()
        ->assertInertia(fn ($page) => $page->where('receivableOrders.0.lines.0.product', null)->where('receivableOrders.0.lines.0.description', 'THHN 14 AWG stranded wire, black, 150 m'));
    asStaff($this)->post("{$this->url}/deliveries/inbound", ['po_number' => $po->po_number, 'delivery_date' => '2026-10-15', 'submission_key' => 'unlisted-1', 'lines' => [
        ['purchase_order_id' => $po->id, 'received_quantity' => 6, 'accepted_quantity' => 6, 'rejected_quantity' => 0, 'inspected' => true, 'product_action' => 'create', 'category' => 'Wiring'],
    ]])->assertSessionHasNoErrors();

    $created = Product::where('name', 'THHN 14 AWG stranded wire, black, 150 m')->sole();
    expect($created->stock)->toBe(6)->and($created->unit)->toBe('roll')->and((float) $created->cost_price)->toBe(41.5)
        ->and($po->fresh()->product_id)->toBe($created->id)
        ->and($po->fresh()->status)->toBe(PurchaseOrder::FULL)
        // The request stays identifiable as a "Product Not Listed" request.
        ->and($pr->fresh()->isUnlisted())->toBeTrue();
});

test('an AHP reorder request also takes the product unit', function () {
    asStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 100, 'justification' => 'Below reorder level.']]])
        ->assertSessionHasNoErrors();
    expect(PurchaseRequest::sole())->unit->toBe('pcs')->product_description->toBeNull();
});

test('a product without a unit in the Product Catalog cannot be requested; no unit is assumed', function () {
    $noUnit = Product::create(['team_id' => $this->team->id, 'sku' => 'GEN-9', 'name' => 'Cable Gland', 'price' => 2, 'cost_price' => 1, 'stock' => 0, 'is_active' => true, 'unit' => null]);

    asStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $noUnit->id, 'quantity' => 5, 'product_description' => 'PG9 gland', 'unit' => 'pcs', 'justification' => 'Out of stock.']]])
        ->assertSessionHasErrors(['lines.0.product_id' => 'Cable Gland has no unit of measure in the Product Catalog. Add its unit in Inventory before requesting it.']);
    expect(PurchaseRequest::count())->toBe(0)
        ->and($noUnit->fresh()->unit)->toBeNull();
});

test('workflow events queue an evaluation refresh that snapshots only changed results', function () {
    Queue::fake();
    $pr = approvedRequest($this);
    $po = PurchaseOrder::sole();
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9]);
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send");
    asStaff($this)->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 10]);
    Queue::assertPushed(RecalculateEvaluations::class, fn ($j) => $j->trigger === 'inbound_delivery.recorded' && $j->teamId === $this->team->id);

    Event::fake([AHPPrioritizationRecalculated::class, SupplierEvaluationUpdated::class]);
    $job = new RecalculateEvaluations($this->team->id, 'inventory.updated', 'D-1');
    $job->handle(app(ProductPrioritizer::class), app(SupplierEvaluator::class));
    $first = EvaluationSnapshot::count();
    expect($first)->toBeGreaterThan(0)
        ->and(EvaluationSnapshot::where('kind', 'product_priority')->where('subject_id', $this->product->id)->value('trigger'))->toBe('inventory.updated')
        // Incomplete supplier evaluation stays incomplete: no invented score.
        ->and(EvaluationSnapshot::where('kind', 'supplier_evaluation')->value('score'))->toBeNull();
    Event::assertDispatched(AHPPrioritizationRecalculated::class);
    Event::assertDispatched(SupplierEvaluationUpdated::class);

    $job->handle(app(ProductPrioritizer::class), app(SupplierEvaluator::class));
    expect(EvaluationSnapshot::count())->toBe($first); // nothing changed → no new snapshot
    expect($job->tries)->toBe(3);
});

test('a rejected request stops: no purchase order is created', function () {
    Event::fake([PurchaseRequestRejected::class, PurchaseOrderCreated::class]);
    asStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 20, 'justification' => 'Restock']]]);
    $pr = PurchaseRequest::sole();

    asAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject'])->assertSessionHasErrors('remarks');
    asAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject', 'remarks' => 'Stock arriving from transfer'])->assertSessionHasNoErrors();
    expect(PurchaseOrder::count())->toBe(0)->and($pr->fresh()->status)->toBe(PurchaseRequest::REJECTED);
    Event::assertDispatched(PurchaseRequestRejected::class);
    Event::assertNotDispatched(PurchaseOrderCreated::class);
});

test('a draft without a known price cannot be sent, and quantity changes need a reason', function () {
    $this->product->update(['supplier_id' => null]);
    approvedRequest($this, 50);
    $po = PurchaseOrder::sole();
    expect($po->supplier_id)->toBeNull()->and((float) $po->unit_cost)->toBe(0.0)->and($po->notes)->toContain('Agreed price to be entered');

    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasErrors('supplier_id');
    $draft = ['supplier_id' => $this->supplier->id, 'unit_cost' => 9];
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40])->assertSessionHasErrors('quantity_adjustment_reason');
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 60, 'quantity_adjustment_reason' => 'MOQ'])->assertSessionHasErrors('quantity');
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40, 'quantity_adjustment_reason' => 'Budget cap'])->assertSessionHasNoErrors();
    expect($po->fresh()->quantity_adjustment_reason)->toBe('Budget cap');
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40, 'quantity_adjustment_reason' => 'x'])->assertSessionHasErrors();
});

/** A sent PO with one received delivery of $qty units, not inspected yet. */
function receivedDelivery($t, int $qty = 10): Delivery
{
    approvedRequest($t, $qty);
    $po = PurchaseOrder::sole();
    asStaff($t)->patch("{$t->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $t->supplier->id, 'quantity' => $qty, 'unit_cost' => 9]);
    asStaff($t)->post("{$t->url}/procurement/purchase-orders/{$po->id}/send");
    asStaff($t)->post("{$t->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => $qty]);

    return Delivery::sole();
}

test('an inspection correction by an admin moves stock by the difference only', function () {
    $d = receivedDelivery($this, 10);
    asStaff($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 10, 'rejected_quantity' => 0, 'inspected_on' => '2026-10-15'])->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(15);

    // 3 turn out to be cracked: the admin corrects the inspection; 3 come back out of stock.
    asAdmin($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 7, 'rejected_quantity' => 3, 'damaged_quantity' => 3, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'Cracked casing'])
        ->assertSessionHasNoErrors();
    $correction = StockMovement::where('movement_key', "inbound-delivery:{$d->id}:correction:1")->sole();
    expect($this->product->fresh()->stock)->toBe(12)->and($d->fresh()->posted_quantity)->toBe(7)
        ->and($correction->previous_stock)->toBe(15)->and($correction->new_stock)->toBe(12);

    // Saving the same figures again changes nothing.
    asAdmin($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 7, 'rejected_quantity' => 3, 'damaged_quantity' => 3, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'Cracked casing'])
        ->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(12)->and(StockMovement::where('movement_key', 'like', "inbound-delivery:{$d->id}%")->count())->toBe(2);

    // A correction can never take out units that are no longer in stock.
    $this->product->update(['stock' => 2]);
    asAdmin($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 0, 'rejected_quantity' => 10, 'damaged_quantity' => 10, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'All cracked'])
        ->assertSessionHasErrors('accepted_quantity');
    expect($this->product->fresh()->stock)->toBe(2)->and($d->fresh()->posted_quantity)->toBe(7);
});

test('a fully rejected delivery adds nothing to stock', function () {
    $d = receivedDelivery($this, 10);
    $po = PurchaseOrder::sole();
    asStaff($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 0, 'rejected_quantity' => 10, 'defective_quantity' => 10, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'Wrong model']);
    expect($d->fresh()->rejection_category)->toBe('defective')->and($this->product->fresh()->stock)->toBe(5)->and(StockMovement::where('type', 'stock_in')->count())->toBe(0);
    expect($this->product->fresh()->stock)->toBe(5)->and($d->fresh()->posted_at)->toBeNull()->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL);
});

test('inventory staff can save a draft purchase request and submit their own draft', function () {
    $invStaff = User::factory()->create(['role' => 'staff_inventory', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($invStaff, ['role' => TeamRole::Member->value]);
    $invCookies = createTrustedDeviceFor($invStaff)['cookies'];

    $this->actingAs($invStaff)->withCookies($invCookies)
        ->post("{$this->url}/procurement/requests", [
            'draft' => true,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 10, 'justification' => 'Draft restock request']],
        ])
        ->assertSessionHasNoErrors();

    $pr = PurchaseRequest::sole();
    expect($pr->status)->toBe(PurchaseRequest::DRAFT);

    $this->actingAs($invStaff)->withCookies($invCookies)
        ->post("{$this->url}/procurement/requests/{$pr->id}/submit")
        ->assertSessionHasNoErrors();

    expect($pr->fresh()->status)->toBe(PurchaseRequest::PENDING);
});
