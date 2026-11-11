<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'pr-team']);

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
    [$this->staff, $this->staffCookies] = $actor('staff_procurement');
});

function purchaseRequestFor(Team $team, string $status, string $number = 'PR-T-1'): PurchaseRequest
{
    return PurchaseRequest::create([
        'team_id' => $team->id,
        'request_number' => $number,
        'quantity' => 5,
        'estimated_cost' => 100,
        'status' => $status,
        'requested_at' => now(),
    ]);
}

it('lets an admin delete a purchase request, refreshes the list and records the audit entry', function (string $status) {
    $request = purchaseRequestFor($this->team, $status);
    $other = purchaseRequestFor($this->team, PurchaseRequest::PENDING, 'PR-T-2');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->from('/pr-team/procurement?tab=requests')
        ->delete("/pr-team/procurement/{$request->id}")
        ->assertRedirect('/pr-team/procurement?tab=requests')
        ->assertSessionHas('success');

    $this->assertSoftDeleted('purchase_requests', ['id' => $request->id, 'deleted_by_id' => $this->admin->id]);
    // Only the selected request is removed.
    $this->assertDatabaseHas('purchase_requests', ['id' => $other->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'procurement.purchase_request_deleted',
        'user_id' => $this->admin->id,
    ]);
    $log = AuditLog::where('event', 'procurement.purchase_request_deleted')->sole();
    expect($log->metadata['purchase_request_id'])->toBe($request->id)
        ->and($log->created_at)->not->toBeNull();

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->get('/pr-team/procurement?tab=requests')
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)
            ->where('requests.data.0.id', $other->id)
        );
})->with([PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::REJECTED]);

it('stops staff from deleting purchase requests, including direct API requests', function (bool $json) {
    $request = purchaseRequestFor($this->team, PurchaseRequest::PENDING);
    $url = "/pr-team/procurement/{$request->id}";

    $as = $this->actingAs($this->staff)->withCookies($this->staffCookies)->withCredentials();
    ($json ? $as->deleteJson($url) : $as->delete($url))->assertForbidden();

    $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'deleted_at' => null]);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'procurement.purchase_request_deleted']);
})->with(['form request' => false, 'json api request' => true]);

it('hides the delete action from staff while they can still view requests', function () {
    purchaseRequestFor($this->team, PurchaseRequest::PENDING);

    $this->actingAs($this->staff)->withCookies($this->staffCookies)
        ->get('/pr-team/procurement?tab=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can_delete', false)
            ->where('requests.total', 1)
        );
});

it('keeps approved and converted requests', function (string $status) {
    $request = purchaseRequestFor($this->team, $status);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/pr-team/procurement/{$request->id}")
        ->assertSessionHas('error');

    $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'deleted_at' => null]);
})->with([PurchaseRequest::APPROVED, PurchaseRequest::CONVERTED]);

it('keeps a request that is linked to a purchase order, and leaves the order untouched', function () {
    $request = purchaseRequestFor($this->team, PurchaseRequest::REJECTED);
    $po = PurchaseOrder::create([
        'team_id' => $this->team->id, 'purchase_request_id' => $request->id, 'po_date' => '2026-11-01',
        'po_number' => 'PO-LINK-1', 'quantity' => 5, 'description' => 'Breaker', 'unit_cost' => 20, 'total_cost' => 100,
        'status' => PurchaseOrder::DRAFT,
    ]);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/pr-team/procurement/{$request->id}")
        ->assertSessionHas('error');

    $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'purchase_request_id' => $request->id, 'deleted_at' => null]);
});

it('does not let an admin delete another team\'s purchase request', function () {
    $otherTeam = Team::factory()->create(['slug' => 'other-team']);
    $request = purchaseRequestFor($otherTeam, PurchaseRequest::PENDING);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->delete("/pr-team/procurement/{$request->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'deleted_at' => null]);
});
