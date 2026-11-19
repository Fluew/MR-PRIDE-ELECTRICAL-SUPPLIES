<?php

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ProcurementActivity;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Who sees what on the Deliveries page, and who a delivery can be assigned to.
 *
 * A driver (Deliveries module only) sees just the customer deliveries assigned to them. Adding the
 * Deliveries module to Inventory or Procurement staff must not take away the inbound tab or the
 * full customer delivery list those modules already give them.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'dma-team']);
    $this->url = '/dma-team';
    [$this->admin, $this->adminCookies] = dmaActor($this->team, 'admin');

    // One confirmed order, so one customer delivery exists (not assigned to anyone).
    $product = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-30A', 'name' => 'Circuit Breaker 30A', 'price' => 100, 'cost_price' => 60, 'stock' => 20, 'reorder_level' => 2, 'status' => 'In Stock', 'is_active' => true, 'unit' => 'pcs']);
    $quotation = Quotation::create(['team_id' => $this->team->id, 'quotation_number' => 'Q-DMA-1', 'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(), 'subtotal' => 200, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 200, 'status' => QuotationStatus::Accepted]);
    $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 100, 'discount' => 0, 'subtotal' => 200]);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $this->admin, ['fulfillment_type' => 'delivery']);
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'GCash', 'amount' => 200, 'reference' => 'GC-DMA-1', 'payment_date' => now()->toDateString()], null, $this->admin);
    $payments->verify($payment, ['reference' => 'GC-DMA-1', 'amount' => 200, 'payment_date' => now()->toDateString()], $this->admin);
    $this->fulfillment = OrderFulfillment::sole();
});

/** A team member with a trusted device and, optionally, explicit module assignments. */
function dmaActor(Team $team, string $role, array $modules = [], array $attributes = []): array
{
    $user = User::factory()->create($attributes + ['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    if ($modules) {
        $user->syncModules($modules);
    }

    return [$user->fresh(), createTrustedDeviceFor($user)['cookies']];
}

function dmaDeliveries($t, User $user, array $cookies): array
{
    return $t->actingAs($user)->withCookies($cookies)->get("{$t->url}/deliveries?tab=inbound")->assertOk()->viewData('page')['props'];
}

it('keeps the inbound tab and the full delivery list for inventory or procurement staff who also have Deliveries', function (string $role, array $modules) {
    [$staff, $cookies] = dmaActor($this->team, $role, $modules);

    $props = dmaDeliveries($this, $staff, $cookies);

    expect($staff->isDeliveryDriverOnly())->toBeFalse()
        ->and($props['isDeliveryStaff'])->toBeFalse()
        ->and($props['activeTab'])->toBe('inbound')
        ->and($props['inboundDeliveries'])->not->toBeNull()
        ->and($props['outboundDeliveries']['data'])->toHaveCount(1) // not limited to deliveries assigned to them
        ->and($props['canPrepare'])->toBeTrue();

    // Recording an inbound delivery is open to them (the empty form fails validation, not authorization).
    $this->actingAs($staff)->withCookies($cookies)->post("{$this->url}/deliveries/inbound", [])->assertSessionHasErrors();
})->with([
    'inventory only' => ['staff_inventory', []],
    'procurement only' => ['staff_procurement', []],
    'inventory + deliveries' => ['staff_inventory', ['inventory', 'deliveries']],
    'deliveries + inventory' => ['staff_deliveries', ['deliveries', 'inventory']],
    'procurement + deliveries' => ['staff_procurement', ['procurement', 'deliveries']],
    'sales + procurement + deliveries' => ['staff_sales', ['sales', 'procurement', 'deliveries']],
]);

it('still limits a driver to the customer deliveries assigned to them, without inbound', function (string $role, array $modules) {
    [$driver, $cookies] = dmaActor($this->team, $role, $modules);

    $props = dmaDeliveries($this, $driver, $cookies);
    expect($driver->isDeliveryDriverOnly())->toBeTrue()
        ->and($props['isDeliveryStaff'])->toBeTrue()
        ->and($props['activeTab'])->toBe('outbound')
        ->and($props['inboundDeliveries'])->toBeNull()
        ->and($props['outboundDeliveries']['data'])->toHaveCount(0); // nothing assigned yet
    $this->actingAs($driver)->withCookies($cookies)->post("{$this->url}/deliveries/inbound", [])->assertForbidden();

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("{$this->url}/deliveries/fulfillments/{$this->fulfillment->id}/assign", ['staff_id' => $driver->id])->assertSessionHasNoErrors();
    expect(dmaDeliveries($this, $driver, $cookies)['outboundDeliveries']['data'])->toHaveCount(1);
})->with([
    'legacy deliveries role' => ['staff_deliveries', []],
    'deliveries module' => ['staff_deliveries', ['deliveries']],
    'sales + deliveries' => ['staff_sales', ['sales', 'deliveries']],
]);

it('does not change what inventory staff see when a delivery is assigned to them', function () {
    [$staff, $cookies] = dmaActor($this->team, 'staff_inventory');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("{$this->url}/deliveries/fulfillments/{$this->fulfillment->id}/assign", ['staff_id' => $staff->id])->assertSessionHasNoErrors();

    $props = dmaDeliveries($this, $staff, $cookies);
    expect($this->fulfillment->fresh()->handled_by_id)->toBe($staff->id)
        ->and($props['isDeliveryStaff'])->toBeFalse()
        ->and($props['inboundDeliveries'])->not->toBeNull();
});

it('assigns a delivery only to an active team member who can open the Deliveries module', function () {
    [$sales] = dmaActor($this->team, 'staff_sales');
    [$inactive] = dmaActor($this->team, 'staff_deliveries', [], ['status' => 'inactive']);
    $customer = User::factory()->create(['role' => 'user']);
    $outsider = User::factory()->create(['role' => 'staff_deliveries', 'password_changed_at' => now()]); // another team
    [$driver] = dmaActor($this->team, 'staff_deliveries');
    [$warehouse] = dmaActor($this->team, 'staff_inventory');
    $assign = fn (int $id) => $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("{$this->url}/deliveries/fulfillments/{$this->fulfillment->id}/assign", ['staff_id' => $id]);

    foreach ([$sales, $inactive, $customer, $outsider] as $user) {
        $assign($user->id)->assertSessionHasErrors('staff_id');
    }
    $assign(999999)->assertSessionHasErrors('staff_id');
    expect($this->fulfillment->fresh()->handled_by_id)->toBeNull();

    foreach ([$driver, $warehouse, $this->admin] as $user) {
        $assign($user->id)->assertSessionHasNoErrors();
        expect($this->fulfillment->fresh()->handled_by_id)->toBe($user->id);
    }

    // The administrator's list offers exactly the people who can be assigned.
    $list = collect($this->actingAs($this->admin)->withCookies($this->adminCookies)->get("{$this->url}/deliveries")->viewData('page')['props']['deliveryStaffList']);
    expect($list->pluck('id')->sort()->values()->all())->toBe(collect([$this->admin->id, $driver->id, $warehouse->id])->sort()->values()->all())
        ->and($list->firstWhere('id', $driver->id)['role_label'])->toBe('Staff (Deliveries)');
});

it('tells a requester about the review of their purchase request unless they work in Procurement', function (string $role, array $modules, bool $notified) {
    Notification::fake();
    [$requester] = dmaActor($this->team, $role, $modules);
    $product = Product::firstOrFail();
    $pr = PurchaseRequest::create(['team_id' => $this->team->id, 'request_number' => 'PR-DMA-1', 'product_id' => $product->id, 'requested_by_id' => $requester->id,
        'quantity' => 10, 'unit' => 'pcs', 'status' => PurchaseRequest::PENDING, 'justification' => 'Restock', 'requested_at' => now()]);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject', 'remarks' => 'Not needed'])->assertSessionHasNoErrors();

    $sent = Notification::sent($requester, ProcurementActivity::class)->filter(fn ($n) => str_contains(json_encode($n->toArray($requester)), 'Your purchase request was'));
    expect($sent->isNotEmpty())->toBe($notified);
})->with([
    'inventory staff' => ['staff_inventory', [], true],
    'procurement staff' => ['staff_procurement', [], false],
    'inventory first, procurement second' => ['staff_inventory', ['inventory', 'procurement'], false],
    'procurement first, inventory second' => ['staff_procurement', ['procurement', 'inventory'], false],
]);
