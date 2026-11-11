<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProductCost;
use App\Models\SupplierSkuMapping;
use App\Models\Team;
use App\Models\User;
use App\Services\Procurement\InboundReceiving;
use App\Services\Procurement\SupplierCosts;
use Carbon\Carbon;

/*
 * "Record Inbound P.O. Delivery": a purchase order with several products is received, inspected
 * and confirmed in one step; accepted units go into inventory, corrections move stock by the
 * difference, and only administrators can delete (cancel) a delivery.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-09 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'inbound-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->staff = User::factory()->create(['role' => 'staff_procurement', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->staff, ['role' => TeamRole::Member->value]);
    $this->adminCookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->staffCookies = createTrustedDeviceFor($this->staff)['cookies'];
    $this->url = "/{$this->team->slug}";

    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Surangel Supply', 'status' => 'Active']);
    $this->other = Supplier::create(['team_id' => $this->team->id, 'name' => 'Other Supply', 'status' => 'Active']);
    $this->breaker = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'Circuit Breaker 40A', 'category' => 'Breakers',
        'price' => 20, 'cost_price' => 9, 'stock' => 25, 'is_active' => true, 'unit' => 'pcs', 'supplier_id' => $this->supplier->id]);

    $line = fn (array $a) => PurchaseOrder::create($a + ['team_id' => $this->team->id, 'po_date' => '2026-11-02', 'po_number' => 'PO-7001',
        'supplier_id' => $this->supplier->id, 'status' => PurchaseOrder::SENT, 'unit_of_measure' => 'PCS']);
    // Line 1 is our breaker; line 2 is a product we do not stock yet (supplier code only).
    $this->poBreaker = $line(['quantity' => 20, 'item_sku' => 'SUR-BRK40', 'product_id' => $this->breaker->id, 'description' => 'Circuit Breaker 40A', 'unit_cost' => 12.5, 'total_cost' => 250]);
    $this->poCable = $line(['quantity' => 10, 'item_sku' => 'SUR-THHN12', 'description' => 'THHN Wire 12 AWG 150m', 'unit_cost' => 40, 'total_cost' => 400, 'unit_of_measure' => 'ROLL']);
});

afterEach(fn () => Carbon::setTestNow());

function inboundAs($t, string $who)
{
    return $t->actingAs($t->{$who})->withCookies($t->{$who.'Cookies'});
}

function inboundLines($t, array $breaker, array $cable): array
{
    return [
        ['purchase_order_id' => $t->poBreaker->id] + $breaker,
        ['purchase_order_id' => $t->poCable->id] + $cable,
    ];
}

function confirmDelivery($t, array $lines, string $who = 'staff', array $extra = [])
{
    return inboundAs($t, $who)->post("{$t->url}/deliveries/inbound", $extra + [
        'po_number' => 'PO-7001',
        'delivery_date' => '2026-11-09',
        'submission_key' => (string) Str::uuid(),
        'lines' => array_map(fn ($l) => $l + ['inspected' => true], $lines),
    ]);
}

test('the Record Inbound P.O. Delivery list shows open purchase orders with their products, from the database', function () {
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-01', 'po_number' => 'PO-CANCELLED', 'quantity' => 5, 'description' => 'X',
        'unit_cost' => 1, 'total_cost' => 5, 'supplier_id' => $this->supplier->id, 'status' => PurchaseOrder::CANCELLED]);
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-01', 'po_number' => 'PO-DONE', 'quantity' => 5, 'description' => 'Y',
        'unit_cost' => 1, 'total_cost' => 5, 'supplier_id' => $this->supplier->id, 'status' => PurchaseOrder::FULL]);

    $orders = app(InboundReceiving::class)->receivableOrders($this->team->id);

    expect($orders)->toHaveCount(1)
        ->and($orders[0])->toMatchArray(['po_number' => 'PO-7001', 'po_date' => '2026-11-02', 'supplier' => ['id' => $this->supplier->id, 'name' => 'Surangel Supply']])
        ->and($orders[0]['lines'])->toHaveCount(2)
        ->and($orders[0]['lines'][1])->toMatchArray(['quantity' => 10, 'item_sku' => 'SUR-THHN12', 'description' => 'THHN Wire 12 AWG 150m',
            'unit_cost' => 40.0, 'unit_of_measure' => 'ROLL', 'extended_cost' => 400.0, 'previously_received' => 0, 'remaining' => 10, 'product' => null]);

    inboundAs($this, 'staff')->get("{$this->url}/deliveries?tab=inbound")->assertOk()
        ->assertInertia(fn ($page) => $page->has('receivableOrders', 1)->where('receivableOrders.0.po_number', 'PO-7001'));
});

test('confirming adds accepted units to stock, creates the missing product with our own SKU, and generates the Delivery #', function () {
    $response = confirmDelivery($this, inboundLines($this,
        ['received_quantity' => 15, 'accepted_quantity' => 15, 'rejected_quantity' => 0],
        ['received_quantity' => 6, 'accepted_quantity' => 4, 'rejected_quantity' => 2, 'inspection_remarks' => 'Two rolls crushed', 'product_action' => 'create', 'category' => 'Wiring'],
    ));
    $response->assertSessionHasNoErrors();

    $header = InboundDelivery::where('purchase_order_number', 'PO-7001')->firstOrFail();
    expect($header->delivery_number)->toBe('DEL-110926-001')
        ->and($header->delivery_date->toDateString())->toBe('2026-11-09')
        ->and($header->confirmed_by_id)->toBe($this->staff->id)
        ->and($header->items)->toHaveCount(2);

    // Scenario B: existing product, 25 + 15 = 40, SKU unchanged, no duplicate.
    expect($this->breaker->fresh()->stock)->toBe(40)
        ->and($this->breaker->fresh()->sku)->toBe('BRK-000001')
        ->and($this->breaker->fresh()->price)->toEqual(20); // selling price untouched

    // Scenario A: new product with a generated internal SKU; the supplier SKU is kept separately.
    $cable = Product::where('name', 'THHN Wire 12 AWG 150m')->firstOrFail();
    expect($cable->sku)->toBe('WIR-000001')
        ->and($cable->stock)->toBe(4) // rejected units never reach stock
        ->and((float) $cable->cost_price)->toBe(40.0)
        ->and($cable->price)->toBeNull()
        ->and($cable->supplier_id)->toBe($this->supplier->id)
        ->and($cable->unit)->toBe('roll')
        ->and($cable->status)->toBe('Incomplete')
        ->and($this->poCable->fresh()->product_id)->toBe($cable->id)
        ->and(SupplierSkuMapping::productFor($this->team->id, $this->supplier->id, 'SUR-THHN12')?->id)->toBe($cable->id);

    expect(StockMovement::where('reference_number', 'DEL-110926-001')->where('type', 'stock_in')->pluck('quantity')->sort()->values()->all())->toBe([4, 15])
        ->and(SupplierProductCost::count())->toBe(2)
        ->and((float) SupplierProductCost::where('product_id', $cable->id)->value('unit_cost'))->toBe(40.0);

    $cableDelivery = Delivery::where('purchase_order_id', $this->poCable->id)->first();
    expect($cableDelivery->qualityStatus())->toBe('Partially Passed')
        ->and($cableDelivery->rejection_reason)->toBe('Two rolls crushed')
        ->and($this->poBreaker->fresh()->status)->toBe(PurchaseOrder::PARTIAL)
        ->and(AuditLog::where('event', 'inbound_delivery.confirmed')->where('subject_id', $header->id)->exists())->toBeTrue();

    // The view shows the purchase order information and the received products.
    inboundAs($this, 'staff')->withCredentials()->getJson("{$this->url}/deliveries/inbound/{$header->id}")
        ->assertOk()
        ->assertJsonPath('purchase_order_number', 'PO-7001')
        ->assertJsonPath('order_date', '2026-11-02')
        ->assertJsonPath('supplier', 'Surangel Supply')
        ->assertJsonPath('items.1.item_sku', 'SUR-THHN12')
        ->assertJsonPath('items.1.ordered_quantity', 10)
        ->assertJsonPath('items.1.received_quantity', 6)
        ->assertJsonPath('items.1.accepted_quantity', 4)
        ->assertJsonPath('items.1.rejected_quantity', 2)
        ->assertJsonPath('items.1.quality_status', 'Partially Passed')
        ->assertJsonPath('items.1.extended_cost', 400)
        ->assertJsonPath('can_delete', false);
});

test('products received on an inbound delivery appear in the Inventory module with their new stock', function () {
    confirmDelivery($this, inboundLines($this,
        ['received_quantity' => 15, 'accepted_quantity' => 15, 'rejected_quantity' => 0],
        ['received_quantity' => 6, 'accepted_quantity' => 6, 'rejected_quantity' => 0, 'product_action' => 'create', 'category' => 'Wiring'],
    ))->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'added to inventory'));
    $cable = Product::where('name', 'THHN Wire 12 AWG 150m')->firstOrFail();

    // The new product has no selling price yet (Incomplete, not sellable) but is real stock, so the
    // normal list, the counts, the Incomplete filter, the movement history and the export show it.
    $rows = fn ($page) => collect($page->toArray()['props']['products']['data'])->keyBy('id');
    inboundAs($this, 'admin')->get("{$this->url}/inventory")->assertOk()
        ->assertInertia(function ($page) use ($rows, $cable) {
            $list = $rows($page);
            expect($list->get($cable->id)['stock'] ?? null)->toBe(6)
                ->and($list->get($cable->id)['status'] ?? null)->toBe('Incomplete')
                ->and($list->get($this->breaker->id)['stock'] ?? null)->toBe(40);
            $page->where('summary.total', 2)
                ->where('summary.incomplete', 1)
                ->where('recent_movements', fn ($m) => collect($m)->where('reference_number', 'DEL-110926-001')->count() === 2);
        });
    inboundAs($this, 'admin')->get("{$this->url}/inventory?status=Incomplete")->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.id', $cable->id));
    inboundAs($this, 'admin')->get("{$this->url}/inventory?search=THHN")->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.stock', 6));
});

test('inconsistent or missing inspection information is refused and nothing is saved', function (array $breaker, array $cable, string $field) {
    confirmDelivery($this, inboundLines($this, $breaker, $cable))->assertSessionHasErrors($field);

    expect(InboundDelivery::where('purchase_order_number', 'PO-7001')->exists())->toBeFalse()
        ->and($this->breaker->fresh()->stock)->toBe(25)
        ->and(Product::count())->toBe(1);
})->with([
    'accepted + rejected ≠ received' => [['received_quantity' => 10, 'accepted_quantity' => 7, 'rejected_quantity' => 2], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], 'lines.0.accepted_quantity'],
    'more than is still owed' => [['received_quantity' => 21, 'accepted_quantity' => 21, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], 'lines.0.received_quantity'],
    'rejected without remarks' => [['received_quantity' => 5, 'accepted_quantity' => 3, 'rejected_quantity' => 2], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], 'lines.0.inspection_remarks'],
    'unmatched product without a choice' => [['received_quantity' => 5, 'accepted_quantity' => 5, 'rejected_quantity' => 0], ['received_quantity' => 2, 'accepted_quantity' => 2, 'rejected_quantity' => 0], 'lines.1.product_action'],
    'nothing received' => [['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], 'lines'],
    'negative accepted' => [['received_quantity' => 5, 'accepted_quantity' => -1, 'rejected_quantity' => 6], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0], 'lines.0.accepted_quantity'],
]);

test('the same confirmation cannot add stock twice', function () {
    $lines = inboundLines($this, ['received_quantity' => 5, 'accepted_quantity' => 5, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]);
    confirmDelivery($this, $lines, 'staff', ['submission_key' => 'same-key'])->assertSessionHasNoErrors();
    confirmDelivery($this, $lines, 'staff', ['submission_key' => 'same-key'])->assertSessionHasErrors('error');

    expect(InboundDelivery::where('purchase_order_number', 'PO-7001')->count())->toBe(1)
        ->and($this->breaker->fresh()->stock)->toBe(30);
});

test('an unmatched product can be linked to an existing product, and its supplier SKU is matched automatically next time', function () {
    $wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WIR-000009', 'name' => 'Wire 12 AWG (roll)', 'price' => 70, 'cost_price' => 38, 'stock' => 3, 'is_active' => true, 'unit' => 'roll']);
    confirmDelivery($this, inboundLines($this,
        ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0],
        ['received_quantity' => 4, 'accepted_quantity' => 4, 'rejected_quantity' => 0, 'product_action' => 'link', 'product_id' => $wire->id],
    ))->assertSessionHasNoErrors();

    expect($wire->fresh()->stock)->toBe(7)->and(Product::count())->toBe(2)
        ->and($wire->fresh()->supplier_id)->toBe($this->supplier->id)
        ->and($wire->fresh()->sku)->toBe('WIR-000009');

    // A new purchase order from the same supplier with the same code needs no choice.
    $next = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-05', 'po_number' => 'PO-7002', 'quantity' => 5, 'item_sku' => 'SUR-THHN12',
        'description' => 'THHN 12', 'unit_cost' => 41, 'total_cost' => 205, 'unit_of_measure' => 'ROLL', 'supplier_id' => $this->supplier->id, 'status' => PurchaseOrder::SENT]);
    $orders = collect(app(InboundReceiving::class)->receivableOrders($this->team->id))->keyBy('po_number');
    expect($orders['PO-7002']['lines'][0]['product'])->toMatchArray(['id' => $wire->id, 'via' => 'supplier_sku']);

    inboundAs($this, 'staff')->post("{$this->url}/deliveries/inbound", ['po_number' => 'PO-7002', 'delivery_date' => '2026-11-09',
        'lines' => [['purchase_order_id' => $next->id, 'received_quantity' => 5, 'accepted_quantity' => 5, 'rejected_quantity' => 0]]])->assertSessionHasErrors('lines.0.inspected');
    inboundAs($this, 'staff')->post("{$this->url}/deliveries/inbound", ['po_number' => 'PO-7002', 'delivery_date' => '2026-11-09',
        'lines' => [['purchase_order_id' => $next->id, 'received_quantity' => 5, 'accepted_quantity' => 5, 'rejected_quantity' => 0, 'inspected' => true]]])->assertSessionHasNoErrors();
    expect($wire->fresh()->stock)->toBe(12)->and($next->fresh()->product_id)->toBe($wire->id);
});

test('staff can correct a confirmed delivery: stock moves by the difference only and the change is recorded', function () {
    confirmDelivery($this, inboundLines($this, ['received_quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]));
    $header = InboundDelivery::where('purchase_order_number', 'PO-7001')->firstOrFail();
    $item = $header->items()->first();
    expect($this->breaker->fresh()->stock)->toBe(35);

    inboundAs($this, 'staff')->put("{$this->url}/deliveries/inbound/{$header->id}", [
        'reason' => 'Two breakers found cracked after unpacking',
        'lines' => [['delivery_id' => $item->id, 'received_quantity' => 10, 'accepted_quantity' => 8, 'rejected_quantity' => 2, 'inspection_remarks' => 'Cracked housing']],
    ])->assertSessionHasNoErrors();

    // 10 → 8 accepted: −2 units, not +8.
    expect($this->breaker->fresh()->stock)->toBe(33)
        ->and(StockMovement::where('movement_key', "inbound-delivery:{$item->id}:correction:1")->value('quantity'))->toBe(2)
        ->and($item->fresh()->qualityStatus())->toBe('Partially Passed');

    $log = AuditLog::where('event', 'inbound_delivery.corrected')->where('subject_id', $header->id)->firstOrFail();
    expect($log->user_id)->toBe($this->staff->id)
        ->and($log->metadata['reason'])->toBe('Two breakers found cracked after unpacking')
        ->and($log->metadata['changes']['lines'][0]['from']['accepted_quantity'])->toBe(10)
        ->and($log->metadata['changes']['lines'][0]['to']['accepted_quantity'])->toBe(8);
});

test('a correction that would make stock negative is refused', function () {
    confirmDelivery($this, inboundLines($this, ['received_quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]));
    $header = InboundDelivery::where('purchase_order_number', 'PO-7001')->firstOrFail();
    $item = $header->items()->first();
    $this->breaker->update(['stock' => 3]); // most of it was sold meanwhile

    inboundAs($this, 'staff')->put("{$this->url}/deliveries/inbound/{$header->id}", [
        'reason' => 'Recount',
        'lines' => [['delivery_id' => $item->id, 'received_quantity' => 10, 'accepted_quantity' => 2, 'rejected_quantity' => 8, 'inspection_remarks' => 'Wrong rating']],
    ])->assertSessionHasErrors('accepted_quantity');

    expect($this->breaker->fresh()->stock)->toBe(3)->and($item->fresh()->accepted_quantity)->toBe(10);
});

test('only an administrator can delete a delivery; it is kept as cancelled and its stock is reversed', function () {
    confirmDelivery($this, inboundLines($this, ['received_quantity' => 20, 'accepted_quantity' => 20, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]));
    $header = InboundDelivery::where('purchase_order_number', 'PO-7001')->firstOrFail();
    expect($this->poBreaker->fresh()->status)->toBe(PurchaseOrder::FULL)->and($this->breaker->fresh()->stock)->toBe(45);

    inboundAs($this, 'staff')->delete("{$this->url}/deliveries/inbound/{$header->id}", ['confirmation' => 'YES', 'reason' => 'Duplicate'])->assertForbidden();
    expect($header->fresh()->status)->toBe(InboundDelivery::CONFIRMED);

    inboundAs($this, 'admin')->delete("{$this->url}/deliveries/inbound/{$header->id}", ['confirmation' => 'YES', 'reason' => 'Recorded against the wrong order'])->assertSessionHasNoErrors();

    $header->refresh();
    expect($header->status)->toBe(InboundDelivery::CANCELLED)
        ->and($header->cancelled_by_id)->toBe($this->admin->id)
        ->and($this->breaker->fresh()->stock)->toBe(25)
        ->and($this->poBreaker->fresh()->status)->toBe(PurchaseOrder::SENT)
        ->and($header->items()->first()->status)->toBe('Cancelled');

    // A second delete does nothing more.
    inboundAs($this, 'admin')->delete("{$this->url}/deliveries/inbound/{$header->id}", ['confirmation' => 'YES', 'reason' => 'Again'])->assertSessionHasErrors('error');
    expect($this->breaker->fresh()->stock)->toBe(25);
});

test('a purchase order is not Received while rejected units are unresolved', function () {
    confirmDelivery($this, inboundLines($this, ['received_quantity' => 22, 'accepted_quantity' => 20, 'rejected_quantity' => 2, 'inspection_remarks' => 'Bent pins', 'over_receipt_reason' => 'Supplier sent spares'], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]), 'admin')
        ->assertSessionHasNoErrors();
    expect($this->poBreaker->fresh()->status)->toBe(PurchaseOrder::PARTIAL);

    $item = Delivery::where('purchase_order_id', $this->poBreaker->id)->first();
    inboundAs($this, 'admin')->post("{$this->url}/deliveries/{$item->id}/resolution", ['resolution_status' => 'returned'])->assertSessionHasNoErrors();
    expect($this->poBreaker->fresh()->status)->toBe(PurchaseOrder::FULL);
});

test('the quotation uses the selected supplier\'s latest cost, never another supplier\'s', function () {
    confirmDelivery($this, inboundLines($this, ['received_quantity' => 5, 'accepted_quantity' => 5, 'rejected_quantity' => 0], ['received_quantity' => 0, 'accepted_quantity' => 0, 'rejected_quantity' => 0]));
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-01', 'po_number' => 'PO-OLD', 'quantity' => 5, 'product_id' => $this->breaker->id,
        'description' => 'Circuit Breaker 40A', 'unit_cost' => 11, 'total_cost' => 55, 'supplier_id' => $this->other->id, 'status' => PurchaseOrder::CLOSED]);

    $costs = app(SupplierCosts::class)->latest($this->team->id);
    expect($costs->get("{$this->breaker->id}:{$this->supplier->id}"))->toMatchArray(['unit_cost' => 12.5, 'source' => 'delivery', 'reference' => 'DEL-110926-001'])
        ->and($costs->get("{$this->breaker->id}:{$this->other->id}"))->toMatchArray(['unit_cost' => 11.0, 'source' => 'purchase_order']);

    // Saved without a typed cost: the chosen supplier's cost; the selling price stays the customer's.
    inboundAs($this, 'admin')->post("{$this->url}/quotations", [
        'client_name' => 'Acme', 'contact_person' => 'Ana', 'email' => 'ana@example.com', 'status' => 'draft', 'tax_rate' => 0, 'discount' => 0,
        'items' => [['product_id' => $this->breaker->id, 'sku' => 'BRK-000001', 'product_name' => 'Circuit Breaker 40A', 'quantity' => 2, 'unit_price' => 20, 'supplier_id' => $this->other->id]],
    ])->assertSessionHasNoErrors();
    $item = Quotation::latest('id')->firstOrFail()->items()->first();
    expect((float) $item->unit_cost)->toBe(11.0)->and($item->supplier_id)->toBe($this->other->id)->and((float) $item->unit_price)->toBe(20.0);

    inboundAs($this, 'admin')->get("{$this->url}/quotations")->assertOk()
        ->assertInertia(fn ($page) => $page->has('supplierCosts')->has('suppliers', 2));
});
