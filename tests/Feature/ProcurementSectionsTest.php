<?php

use App\Enums\TeamRole;
use App\Models\CommercialInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Procurement module has two sections, each listing its own records: Purchase Orders and
 * Purchase Requests. (Supplier Commercial Invoices were removed on 2026-11-09; their records stay.)
 */

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'proc-team']);
    $this->otherTeam = Team::factory()->create(['slug' => 'other-proc']);

    $member = function (string $role): array {
        $user = User::factory()->create(['role' => $role, 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
        $this->team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

        return [$user, createTrustedDeviceFor($user)['cookies']];
    };
    [$this->admin, $this->adminCookies] = $member('admin');
    [$this->staff, $this->staffCookies] = $member('staff_procurement');

    $this->acme = Supplier::create(['team_id' => $this->team->id, 'name' => 'Acme Electrical', 'status' => 'Active']);
    $this->volt = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);

    $ci = fn (Team $team, string $number, ?Supplier $supplier, string $status = CommercialInvoice::PENDING) => CommercialInvoice::create([
        'team_id' => $team->id, 'supplier_id' => $supplier?->id, 'invoice_date' => '2026-10-25', 'invoice_number' => $number,
        'quantity' => 5, 'description' => "Invoice {$number}", 'unit_cost' => 10, 'total_cost' => 50, 'status' => $status,
    ]);
    $po = fn (Team $team, string $number, ?Supplier $supplier, string $status = PurchaseOrder::DRAFT) => PurchaseOrder::create([
        'team_id' => $team->id, 'supplier_id' => $supplier?->id, 'po_date' => '2026-10-25', 'po_number' => $number,
        'quantity' => 5, 'description' => "Order {$number}", 'unit_cost' => 10, 'total_cost' => 50, 'status' => $status,
    ]);
    $pr = fn (Team $team, string $number, ?Supplier $supplier, string $status = PurchaseRequest::PENDING) => PurchaseRequest::create([
        'team_id' => $team->id, 'supplier_id' => $supplier?->id, 'request_number' => $number,
        'quantity' => 5, 'estimated_cost' => 50, 'status' => $status, 'requested_at' => now(),
    ]);

    $ci($this->team, 'CI-ACME', $this->acme, CommercialInvoice::VERIFIED);
    $ci($this->team, 'CI-VOLT', $this->volt);
    $po($this->team, 'PO-ACME', $this->acme);
    $po($this->team, 'PO-VOLT', $this->volt, PurchaseOrder::SENT);
    $pr($this->team, 'PR-ACME', $this->acme);
    $pr($this->team, 'PR-VOLT', $this->volt, PurchaseRequest::DRAFT);
    // Another team's records never appear.
    $ci($this->otherTeam, 'CI-OTHER', null);
    $po($this->otherTeam, 'PO-OTHER', null);
    $pr($this->otherTeam, 'PR-OTHER', null);
});

function procurementPage($t, string $query = '', bool $staff = false)
{
    return $t->actingAs($staff ? $t->staff : $t->admin)->withCookies($staff ? $t->staffCookies : $t->adminCookies)
        ->get("/proc-team/procurement{$query}")->assertOk();
}

it('lists each section\'s own records with their supplier, for this team only', function () {
    procurementPage($this)->assertInertia(fn (Assert $page) => $page
        ->component('Procurement/Index')
        ->where('activeTab', 'purchase_orders')
        ->missing('commercialInvoices')
        ->where('purchaseOrders.total', 2)
        ->where('requests.total', 2)
        ->where('purchaseOrders.data', fn ($rows) => collect($rows)->pluck('supplier.name')->sort()->values()->all() === ['Acme Electrical', 'Volt Supply'])
        ->where('requests.data', fn ($rows) => collect($rows)->pluck('request_number')->sort()->values()->all() === ['PR-ACME', 'PR-VOLT'])
        ->missing('metrics.totalCommercialInvoices')
        ->where('metrics.totalPurchaseOrders', 2)
        ->where('metrics.totalRequests', 2)
        ->missing('statusOptions.commercial_invoices')
        ->where('statusOptions.purchase_orders', fn ($s) => collect($s)->all() === PurchaseOrder::STATUSES)
        ->where('statusOptions.requests', fn ($s) => collect($s)->all() === PurchaseRequest::STATUSES)
    );
});

it('opens each section by its tab and falls back to purchase orders for anything else (including the removed invoices tab)', function (string $tab, string $expected) {
    procurementPage($this, "?tab={$tab}")->assertInertia(fn (Assert $page) => $page->where('activeTab', $expected)->where('filters.tab', $expected));
})->with([
    ['commercial_invoices', 'purchase_orders'],
    ['purchase_orders', 'purchase_orders'],
    ['requests', 'requests'],
    ['deliveries', 'purchase_orders'],
    ['', 'purchase_orders'],
]);

it('filters each section by its own statuses, supplier and search', function () {
    procurementPage($this, '?tab=purchase_orders&status='.urlencode(PurchaseOrder::SENT))->assertInertia(fn (Assert $page) => $page
        ->where('purchaseOrders.total', 1)
        ->where('purchaseOrders.data.0.po_number', 'PO-VOLT')
    );

    procurementPage($this, "?tab=purchase_orders&supplier_id={$this->volt->id}")->assertInertia(fn (Assert $page) => $page
        ->where('purchaseOrders.total', 1)
        ->where('purchaseOrders.data.0.supplier.name', 'Volt Supply')
    );

    procurementPage($this, '?tab=requests&search=ACME')->assertInertia(fn (Assert $page) => $page
        ->where('requests.total', 1)
        ->where('requests.data.0.request_number', 'PR-ACME')
    );
});

it('shows both sections to procurement staff, with admin-only actions still admin-only', function () {
    procurementPage($this, '?tab=requests', true)->assertInertia(fn (Assert $page) => $page
        ->where('isAdmin', false)
        ->where('auth.can_delete', false)
        ->where('purchaseOrders.total', 2)
        ->where('requests.total', 2)
    );
});
