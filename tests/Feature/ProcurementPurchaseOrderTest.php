<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/*
 * The Procurement module: Purchase Orders and Purchase Requests. Supplier Commercial Invoices were
 * removed from the module (2026-11-09); existing invoice records stay in the database untouched.
 */
beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'test-team']);
    $this->user = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->user)['cookies'];
});

function asProcurementAdmin($t)
{
    return $t->actingAs($t->user)->withCookies($t->cookies);
}

test('procurement index loads purchase orders and no longer sends commercial invoices', function () {
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-25', 'po_number' => '4000005472', 'quantity' => 100, 'item_sku' => '068SSCR2',
        'description' => '0.68mm Stainless Steel Conduit Rod 2m', 'unit_of_measure' => 'PCS', 'unit_cost' => 450.00, 'total_cost' => 45000.00, 'status' => 'Issued']);

    asProcurementAdmin($this)->get("/{$this->team->slug}/procurement")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Procurement/Index')
            ->where('activeTab', 'purchase_orders')
            ->missing('commercialInvoices')
            ->missing('metrics.totalCommercialInvoices')
            ->missing('statusOptions.commercial_invoices')
            ->has('purchaseOrders.data', 1)
            ->where('purchaseOrders.data.0.po_number', '4000005472')
            ->where('metrics.totalPurchaseOrders', 1)
        );
});

test('the commercial invoice endpoints are gone and existing invoice records are kept', function () {
    $ci = CommercialInvoice::create(['team_id' => $this->team->id, 'invoice_date' => '2026-10-25', 'invoice_number' => 'CI-035-0722', 'quantity' => 10,
        'description' => 'Historical invoice', 'unit_cost' => 100.00, 'total_cost' => 1000.00, 'status' => CommercialInvoice::VERIFIED]);
    $slug = $this->team->slug;
    $csv = UploadedFile::fake()->createWithContent('ci.csv', "DATE,COMMERCIAL INVOICE,QUANTITY,Description,UNIT COST\n10-25-2026,CI-9,1,X,1");

    asProcurementAdmin($this)->post("/{$slug}/procurement/commercial-invoices", ['invoice_number' => 'CI-NEW'])->assertStatus(404);
    asProcurementAdmin($this)->post("/{$slug}/procurement/commercial-invoices/import-csv", ['file' => $csv, 'execute' => true])->assertStatus(404);
    asProcurementAdmin($this)->delete("/{$slug}/procurement/commercial-invoices/{$ci->id}")->assertStatus(404);
    asProcurementAdmin($this)->post("/{$slug}/procurement/invoices", ['invoice_number' => 'CI-NEW'])->assertStatus(404);
    asProcurementAdmin($this)->post("/{$slug}/procurement/invoices/{$ci->id}/verify")->assertStatus(404);
    asProcurementAdmin($this)->get("/{$slug}/procurement/invoices/{$ci->id}/attachment")->assertStatus(404);

    // The old tab address opens Purchase Orders.
    asProcurementAdmin($this)->get("/{$slug}/procurement?tab=commercial_invoices")
        ->assertInertia(fn ($page) => $page->where('activeTab', 'purchase_orders'));

    expect(CommercialInvoice::count())->toBe(1)->and($ci->fresh()->trashed())->toBeFalse();
});

test('a purchase order cannot be created without an approved purchase request', function () {
    asProcurementAdmin($this)->post("/{$this->team->slug}/procurement/purchase-orders", [
        'po_date' => '2026-10-25', 'po_number' => '4000005472', 'quantity' => 50, 'description' => '0.68mm Stainless Steel Conduit Rod 2m', 'unit_cost' => 450.00,
    ])->assertNotFound();
    $this->assertDatabaseCount('purchase_orders', 0);
});

test('can import purchase orders csv with required headers', function () {
    $csvContent = implode("\n", [
        'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost',
        '10-25-2026,4000005472,100,068SSCR2,"0.68mm Stainless Steel Conduit Rod 2m",PCS,450.00',
        '10-25-2026,4000005473,40,BRK-40A,"Circuit Breaker 2P 40A Rail Mounted",BOX,320.00',
    ]);
    $file = UploadedFile::fake()->createWithContent('purchase_orders.csv', $csvContent);

    $preview = asProcurementAdmin($this)->post("/{$this->team->slug}/procurement/purchase-orders/import-csv", ['file' => $file, 'execute' => false], ['Accept' => 'application/json']);
    $preview->assertSuccessful();
    $previewData = $preview->json();
    expect($previewData['success'])->toBeTrue()
        ->and($previewData['summary']['valid_count'])->toBe(2)
        ->and($previewData['valid_rows'][0]['po_number'])->toBe('4000005472')
        ->and($previewData['valid_rows'][0]['quantity'])->toBe(100)
        ->and($previewData['valid_rows'][0]['item_sku'])->toBe('068SSCR2')
        ->and($previewData['valid_rows'][0]['unit_of_measure'])->toBe('PCS')
        ->and($previewData['valid_rows'][0]['unit_cost'])->toEqual(450.0)
        ->and($previewData['valid_rows'][0]['total_cost'])->toEqual(45000.0);

    asProcurementAdmin($this)->post("/{$this->team->slug}/procurement/purchase-orders/import-csv", ['file' => $file, 'execute' => true], ['Accept' => 'application/json'])
        ->assertSuccessful();
    $this->assertDatabaseHas('purchase_orders', ['team_id' => $this->team->id, 'po_number' => '4000005472', 'quantity' => 100, 'item_sku' => '068SSCR2',
        'unit_of_measure' => 'PCS', 'unit_cost' => 450.00, 'total_cost' => 45000.00]);
});

test('can delete a purchase order', function () {
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-25', 'po_number' => '4000005472', 'quantity' => 10,
        'description' => 'Test PO', 'unit_cost' => 100.00, 'total_cost' => 1000.00]);

    asProcurementAdmin($this)->delete("/{$this->team->slug}/procurement/purchase-orders/{$po->id}")->assertSessionHasNoErrors();
    $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
});

test('can filter purchase orders by unit of measure and supplier', function () {
    $supplierA = Supplier::create(['team_id' => $this->team->id, 'name' => 'Supplier Alpha', 'status' => 'Active']);
    $supplierB = Supplier::create(['team_id' => $this->team->id, 'name' => 'Supplier Beta', 'status' => 'Active']);
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-25', 'po_number' => '4000001001', 'quantity' => 20, 'description' => 'Cable Box',
        'unit_of_measure' => 'BOX', 'unit_cost' => 500, 'total_cost' => 10000, 'status' => 'Issued', 'supplier_id' => $supplierA->id]);
    PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-25', 'po_number' => '4000001002', 'quantity' => 100, 'description' => 'Screws',
        'unit_of_measure' => 'PCS', 'unit_cost' => 2, 'total_cost' => 200, 'status' => 'Received', 'supplier_id' => $supplierB->id]);

    asProcurementAdmin($this)->get("/{$this->team->slug}/procurement?tab=purchase_orders&unit_of_measure=BOX")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('purchaseOrders.total', 1)->where('purchaseOrders.data.0.po_number', '4000001001'));

    asProcurementAdmin($this)->get("/{$this->team->slug}/procurement?supplier_id={$supplierB->id}")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('purchaseOrders.total', 1)->where('purchaseOrders.data.0.po_number', '4000001002'));
});

// ============================================================ read-only reference + admin correction

/** A supplier purchase order with two products (two purchase_orders rows sharing the PO number). */
function twoLinePo(Team $team): array
{
    $supplier = Supplier::create(['team_id' => $team->id, 'name' => 'Surangel and Sons', 'status' => 'Active']);
    $line = fn (array $a) => PurchaseOrder::create($a + ['team_id' => $team->id, 'po_date' => '2026-11-02', 'po_number' => '4000005769', 'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::SENT, 'unit_of_measure' => 'PC']);

    return [
        $line(['quantity' => 2, 'item_sku' => '3P50A', 'description' => 'MCB 3P, 50A IC60N SCHNEIDER', 'unit_cost' => 62, 'total_cost' => 124]),
        $line(['quantity' => 3, 'item_sku' => '3P32A', 'description' => 'MCB 3P, 32A IC60N SCHNEIDER', 'unit_cost' => 29.5, 'total_cost' => 88.5]),
    ];
}

test('the Open view lists every line of the purchase order with the nine fields, read-only', function () {
    [$first, $second] = twoLinePo($this->team);

    asProcurementAdmin($this)->withCredentials()->getJson("/{$this->team->slug}/procurement/purchase-orders/{$second->id}/workspace")
        ->assertOk()
        ->assertJsonPath('purchase_order.id', $second->id)
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0', ['id' => $first->id, 'po_date' => '2026-11-02', 'po_number' => '4000005769', 'supplier' => 'Surangel and Sons', 'quantity' => 2,
            'item_sku' => '3P50A', 'description' => 'MCB 3P, 50A IC60N SCHNEIDER', 'unit_cost' => 62, 'unit_of_measure' => 'PC', 'extended_cost' => 124, 'product_id' => null])
        ->assertJsonPath('lines.1.extended_cost', 88.5);
});

test('the old "Product & date" edit is gone; staff cannot correct a purchase order', function () {
    [$po] = twoLinePo($this->team);
    asProcurementAdmin($this)->patch("/{$this->team->slug}/procurement/purchase-orders/{$po->id}/schedule", ['promised_delivery_date' => '2026-11-20'])->assertNotFound();

    $staff = User::factory()->create(['role' => 'staff_procurement', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $this->actingAs($staff)->withCookies(createTrustedDeviceFor($staff)['cookies'])
        ->patch("/{$this->team->slug}/procurement/purchase-orders/{$po->id}/correction", ['po_date' => '2026-11-01', 'reason' => 'typo'])->assertForbidden();

    expect($po->fresh()->po_date->toDateString())->toBe('2026-11-02');
});

test('an administrator corrects the order date of the whole PO, with a reason, audited; quantities and prices stay', function () {
    [$first, $second] = twoLinePo($this->team);
    $url = "/{$this->team->slug}/procurement/purchase-orders/{$first->id}/correction";

    asProcurementAdmin($this)->patch($url, ['po_date' => '2026-11-01'])->assertSessionHasErrors(['reason' => 'Give the reason for the correction.']);
    asProcurementAdmin($this)->patch($url, ['po_date' => '2099-01-01', 'reason' => 'x'])->assertSessionHasErrors('po_date');
    asProcurementAdmin($this)->patch($url, ['po_date' => '2026-11-01', 'reason' => 'Typed from the wrong page of the supplier document.'])->assertSessionHasNoErrors();

    expect($first->fresh()->po_date->toDateString())->toBe('2026-11-01')
        ->and($second->fresh()->po_date->toDateString())->toBe('2026-11-01') // one document date for every line
        ->and($first->fresh()->quantity)->toBe(2)->and((float) $first->fresh()->unit_cost)->toBe(62.0)
        ->and($first->fresh()->description)->toBe('MCB 3P, 50A IC60N SCHNEIDER');
    $log = AuditLog::where('event', 'purchase_order.corrected')->sole();
    expect($log->description)->toContain('Typed from the wrong page')
        ->and($log->metadata['changes']['po_date'])->toBe(['from' => '2026-11-02', 'to' => '2026-11-01', 'lines' => 2]);

    asProcurementAdmin($this)->patch($url, ['po_date' => '2026-11-01', 'reason' => 'again'])->assertSessionHasErrors(['error' => 'Nothing was changed.']);
});

test('a correction cannot move the order date past a delivery, or relink a product whose units are in stock', function () {
    [$po] = twoLinePo($this->team);
    $product = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'MCB 50A', 'price' => 80, 'stock' => 0, 'is_active' => true, 'unit' => 'pcs']);
    $other = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000002', 'name' => 'MCB 63A', 'price' => 90, 'stock' => 0, 'is_active' => true, 'unit' => 'pcs']);
    $url = "/{$this->team->slug}/procurement/purchase-orders/{$po->id}/correction";

    // Linking an existing product (nothing is created) is allowed before anything is in stock.
    $products = Product::count();
    asProcurementAdmin($this)->patch($url, ['product_id' => $product->id, 'reason' => 'Matched to our catalog item.'])->assertSessionHasNoErrors();
    expect($po->fresh()->product_id)->toBe($product->id)->and(Product::count())->toBe($products);

    Delivery::create(['team_id' => $this->team->id, 'purchase_order_id' => $po->id, 'delivery_date' => '2026-11-05', 'delivery_number' => 'DEL-110526-001',
        'quantity' => 2, 'accepted_quantity' => 2, 'rejected_quantity' => 0, 'inspection_result' => 'accepted', 'posted_quantity' => 2, 'posted_at' => now(),
        'status' => 'Delivered', 'unit' => 'PC', 'description' => 'MCB']);

    asProcurementAdmin($this)->patch($url, ['po_date' => '2026-11-06', 'reason' => 'x'])->assertSessionHasErrors('po_date');
    asProcurementAdmin($this)->patch($url, ['product_id' => $other->id, 'reason' => 'x'])->assertSessionHasErrors('product_id');
    expect($po->fresh()->product_id)->toBe($product->id)->and($po->fresh()->po_date->toDateString())->toBe('2026-11-02');
});

test('reviewing a draft order no longer changes its order date', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-02', 'po_number' => '4000009001', 'quantity' => 5, 'description' => 'Wire',
        'unit_cost' => 0, 'total_cost' => 0, 'status' => PurchaseOrder::DRAFT, 'unit_of_measure' => 'ROLL']);

    asProcurementAdmin($this)->patch("/{$this->team->slug}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $supplier->id, 'quantity' => 5, 'unit_cost' => 40, 'po_date' => '2026-10-01'])
        ->assertSessionHasNoErrors();

    expect($po->fresh()->po_date->toDateString())->toBe('2026-11-02')->and((float) $po->fresh()->unit_cost)->toBe(40.0);
});
