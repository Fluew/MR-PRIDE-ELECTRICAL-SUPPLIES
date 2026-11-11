<?php

use App\Enums\TeamRole;
use App\Models\OrderFulfillment;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'del-team']);

    $actor = function (string $role): array {
        $user = User::factory()->create([
            'role' => $role,
            'current_team_id' => $this->team->id,
            'password_changed_at' => now(),
        ]);
        $this->team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

        return [$user, createTrustedDeviceFor($user)['cookies']];
    };

    [$this->admin, $this->adminCookies] = $actor('admin');
    [$this->staff, $this->staffCookies] = $actor('staff_deliveries');

    $this->sale = Sale::create([
        'team_id' => $this->team->id,
        'invoice_number' => 'INV-DEL-900',
        'client_name' => 'Solar Solutions Ph',
        'sale_date' => now(),
        'subtotal' => 1000,
        'discount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1000,
        'status' => 'completed',
        'processed_by_id' => $this->admin->id,
    ]);
});

function customerDelivery(Sale $sale, string $status, ?int $handledBy = null): OrderFulfillment
{
    // One delivery record per Sales Order: a second delivery needs its own order.
    if (OrderFulfillment::withTrashed()->where('sale_id', $sale->id)->exists()) {
        $sale = tap($sale->replicate(['order_number'])->fill(['invoice_number' => 'INV-DEL-'.uniqid()]))->save();
    }

    return OrderFulfillment::create([
        'team_id' => $sale->team_id,
        'sale_id' => $sale->id,
        'fulfillment_number' => 'FUL-'.uniqid(),
        'fulfillment_type' => 'delivery',
        'recipient_name' => 'Engr. Manuel Roxas',
        'contact_number' => '09175554433',
        'delivery_address' => 'Calamba, Laguna',
        'status' => $status,
        'handled_by_id' => $handledBy,
    ]);
}

it('lets an admin move a finished customer delivery to the recycle bin with an audit entry', function () {
    $delivery = customerDelivery($this->sale, 'delivered');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/del-team/deliveries/fulfillments/{$delivery->id}", ['confirmation' => 'YES'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertSoftDeleted('order_fulfillments', ['id' => $delivery->id, 'deleted_by_id' => $this->admin->id]);
    // The sales order is kept.
    $this->assertDatabaseHas('sales', ['id' => $this->sale->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'delivery.customer_delivery_deleted',
        'user_id' => $this->admin->id,
    ]);

    // It appears in the Recycle Bin and can be restored there.
    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->get('/del-team/recycle-bin?type=customer_delivery')
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)
            ->where('records.data.0.type', 'customer_delivery')
            ->where('records.data.0.title', $delivery->fulfillment_number)
            ->where('records.data.0.deleted_by.email', $this->admin->email)
        );

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("/del-team/recycle-bin/customer_delivery/{$delivery->id}/restore")
        ->assertSessionHas('success');

    $this->assertDatabaseHas('order_fulfillments', ['id' => $delivery->id, 'deleted_at' => null]);
});

it('forbids staff from deleting customer deliveries through a direct request', function (bool $json) {
    $delivery = customerDelivery($this->sale, 'delivered', $this->staff->id);
    $url = "/del-team/deliveries/fulfillments/{$delivery->id}";

    $request = $this->actingAs($this->staff)->withCookies($this->staffCookies)->withCredentials();
    ($json ? $request->deleteJson($url, ['confirmation' => 'YES']) : $request->delete($url, ['confirmation' => 'YES']))
        ->assertForbidden();

    $this->assertDatabaseHas('order_fulfillments', ['id' => $delivery->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('audit_logs', ['event' => 'auth.unauthorized_delete', 'user_id' => $this->staff->id]);
})->with(['form request' => false, 'json api request' => true]);

it('keeps deliveries that are still in progress', function (string $status) {
    $delivery = customerDelivery($this->sale, $status);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/del-team/deliveries/fulfillments/{$delivery->id}", ['confirmation' => 'YES'])
        ->assertSessionHas('error');

    $this->assertDatabaseHas('order_fulfillments', ['id' => $delivery->id, 'deleted_at' => null]);
})->with(['pending', 'ready_for_delivery', 'in_transit']);

it('rejects a wrong confirmation word', function () {
    $delivery = customerDelivery($this->sale, 'cancelled');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/del-team/deliveries/fulfillments/{$delivery->id}", ['confirmation' => 'no'])
        ->assertSessionHasErrors('confirmation');

    $this->assertDatabaseHas('order_fulfillments', ['id' => $delivery->id, 'deleted_at' => null]);
});

it('tells the page which deliveries an admin can delete, and never offers delete to staff', function () {
    $finished = customerDelivery($this->sale, 'delivered', $this->staff->id);
    $active = customerDelivery($this->sale, 'in_transit', $this->staff->id);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->get('/del-team/deliveries')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can_delete', true)
            ->where('outboundDeliveries.data', fn ($rows) => collect($rows)->firstWhere('id', $finished->id)['delete_blocked_reason'] === null
                && collect($rows)->firstWhere('id', $active->id)['delete_blocked_reason'] !== null)
        );

    $this->actingAs($this->staff)->withCookies($this->staffCookies)->get('/del-team/deliveries')
        ->assertInertia(fn (Assert $page) => $page->where('auth.can_delete', false));
});
