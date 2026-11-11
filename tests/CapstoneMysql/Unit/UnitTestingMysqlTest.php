<?php

/*
 * Section 4.4.1 Unit Testing — 25 test cases on MySQL/MariaDB (UT-01 to UT-25).
 *
 * Database: the dedicated local testing database `mr_pride_testing` only (see phpunit.mysql.xml and
 * tests/Capstone/mysql_helpers.php). Run with:
 *
 *     php vendor/bin/pest -c phpunit.mysql.xml --testsuite=Unit
 *
 * Test-only file: it exercises the existing application and changes nothing in it.
 */

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Http\Middleware\EnsureRole;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\PaymentService;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Capstone\MysqlTestCase;

require_once __DIR__.'/../../Capstone/mysql_helpers.php';

uses(MysqlTestCase::class); // RefreshDatabase, after the connection is confirmed to be mr_pride_testing

beforeEach(function () {
    capAssertMysqlTestingDatabase();
    Http::preventStrayRequests(); // no real call to Resend, Frankfurter or any other service
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'ut-team']);
    $this->url = '/ut-team';
    $this->as = fn (User $user, array $cookies) => $this->actingAs($user)->withCookies($cookies);
});

afterEach(fn () => Carbon::setTestNow());

// =========================================================================================
// 1. Authentication and Access Administration
// =========================================================================================

it('UT-01 signs in only with a registered email and its correct password', function () {
    $user = User::factory()->create(['role' => 'admin', 'password_changed_at' => now()]);
    $cookies = createTrustedDeviceFor($user)['cookies'];
    $login = fn (array $data) => $this->withCookies($cookies)->post(route('login.store'), $data);

    $login(['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    $login(['email' => 'nobody@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $login(['email' => '', 'password' => ''])->assertSessionHasErrors(['email', 'password']);
    $this->assertGuest();

    $response = $login(['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    expect($response->headers->get('Location'))->toContain('/dashboard');
});

it('UT-02 limits each role to its own module and admits only a registered, approved device', function () {
    $user = fn (string $role) => User::factory()->create(['role' => $role, 'password_changed_at' => now()]);

    expect(EnsureRole::allows($user('admin'), ['staff_sales']))->toBeTrue()
        ->and(EnsureRole::allows($user('staff_sales'), ['admin', 'staff_sales']))->toBeTrue()
        ->and(EnsureRole::allows($user('staff_sales'), ['admin', 'staff_inventory']))->toBeFalse()
        ->and(EnsureRole::allows($user('staff_reports'), ['admin']))->toBeFalse()
        ->and(EnsureRole::allows($user('user'), ['admin', 'staff_sales']))->toBeFalse();

    $owner = $user('staff_sales');
    $device = createTrustedDeviceFor($owner);
    $request = fn (array $cookies) => Request::create('/login', 'GET', [], $cookies);
    expect(TrustedDevice::findActiveByCredential($request($device['cookies']))?->id)->toBe($device['device']->id)
        ->and(TrustedDevice::findActiveByCredential($request(['mrpride_device_uuid' => $device['device']->device_uuid, 'mrpride_device_credential' => 'forged'])))->toBeNull()
        ->and($device['device']->isAuthorizedForUser($owner))->toBeTrue()
        ->and($device['device']->isAuthorizedForUser($user('staff_sales')))->toBeFalse();

    // No registered device: the login request itself is blocked.
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertNotFound();
    $this->assertGuest();

    $device['device']->update(['status' => 'revoked']);
    expect(TrustedDevice::findActiveByCredential($request($device['cookies'])))->toBeNull();
});

// =========================================================================================
// 2. Quotation Management
// =========================================================================================

it('UT-03 calculates the subtotal, discount, tax and grand total of a quotation', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $post = fn (array $extra, array $line) => ($this->as)($staff, $cookies)->post("{$this->url}/quotations", $extra + [
        'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com', 'status' => 'draft',
        'items' => [$line + ['sku' => 'BRK-30A', 'product_name' => 'Circuit Breaker 30A']],
    ]);

    // 4 × 250.00 = 1,000.00; less 100.00 = 900.00; 12% tax = 108.00; total 1,008.00.
    $post(['discount' => 100, 'tax_rate' => 12], ['quantity' => 4, 'unit_price' => 250])->assertSessionHasNoErrors();
    // 3 × 33.33 = 99.99; no discount, no tax.
    $post([], ['quantity' => 3, 'unit_price' => 33.33])->assertSessionHasNoErrors();

    [$first, $second] = Quotation::orderBy('id')->get()->all();
    expect([(float) $first->subtotal, (float) $first->discount, (float) $first->tax_amount, (float) $first->grand_total])->toBe([1000.0, 100.0, 108.0, 1008.0])
        ->and([(float) $second->subtotal, (float) $second->tax_amount, (float) $second->grand_total])->toBe([99.99, 0.0, 99.99])
        ->and((float) $second->items->sole()->subtotal)->toBe(99.99)
        ->and($first->status)->toBe(QuotationStatus::Draft);
});

it('UT-04 refuses invalid quotation quantities, prices and incomplete forms', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $form = fn (array $line = [], array $extra = []) => $extra + ['client_name' => 'Metro Builders Inc.', 'status' => 'draft',
        'items' => [$line + ['sku' => 'BRK-30A', 'product_name' => 'Circuit Breaker 30A', 'quantity' => 2, 'unit_price' => 250]]];
    $post = fn (array $data) => ($this->as)($staff, $cookies)->post("{$this->url}/quotations", $data);

    foreach ([0, -1, 1.5, 'abc', ''] as $quantity) {
        $post($form(['quantity' => $quantity]))->assertSessionHasErrors('items.0.quantity');
    }
    $post($form(['unit_price' => -1]))->assertSessionHasErrors('items.0.unit_price');
    $post($form([], ['client_name' => '']))->assertSessionHasErrors('client_name');
    $post($form([], ['tax_rate' => 101]))->assertSessionHasErrors('tax_rate');
    $post(['client_name' => 'Metro Builders Inc.', 'status' => 'draft', 'items' => []])->assertSessionHasErrors('items');
    $this->post('/quotation', [])->assertSessionHasErrors(['company', 'contactName', 'email', 'phone']); // public request form
    expect(Quotation::count())->toBe(0);

    $post($form(['quantity' => 1, 'unit_price' => 0]))->assertSessionHasNoErrors(); // smallest quantity, zero price
    expect(Quotation::sole()->items->sole()->quantity)->toBe(1);
});

it('UT-05 allows only the defined quotation status changes', function () {
    [$staff, $cookies] = capActor('staff_quotations', $this->team);
    $quotation = capQuotation($this->team, QuotationStatus::Draft, [[capProduct($this->team), 1]]);
    $patch = fn (string $status) => ($this->as)($staff, $cookies)->patch("{$this->url}/quotations/{$quotation->id}/status", ['status' => $status]);

    expect(QuotationStatus::Draft->canMoveTo(QuotationStatus::Sent))->toBeTrue()
        ->and(QuotationStatus::Draft->canMoveTo(QuotationStatus::Accepted))->toBeFalse()
        ->and(QuotationStatus::Sent->canMoveTo(QuotationStatus::Accepted))->toBeTrue()
        ->and(QuotationStatus::Accepted->allowedNext())->toBe([])
        ->and(QuotationStatus::Accepted->canConvertToSale())->toBeTrue()
        ->and(QuotationStatus::Sent->canConvertToSale())->toBeFalse();

    foreach (['accepted', 'sent', 'not-a-status', ''] as $status) {
        $patch($status)->assertSessionHasErrors('status'); // not a status staff may set by hand
    }
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);

    $patch('rejected')->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
});

// =========================================================================================
// 3. Inventory Management
// =========================================================================================

it('UT-06 validates a new product and sets its stock status from the reorder level', function () {
    [$staff, $cookies] = capActor('staff_inventory', $this->team);
    $product = fn (array $o = []) => $o + ['name' => 'Circuit Breaker 60A', 'category' => 'Breakers', 'unit' => 'pcs', 'price' => 150, 'cost_price' => 90, 'stock' => 20, 'reorder_level' => 10];
    $post = fn (array $data) => ($this->as)($staff, $cookies)->post("{$this->url}/inventory/products", $data);

    $post([])->assertSessionHasErrors(['name', 'category', 'price', 'stock']);
    $post($product(['price' => -1]))->assertSessionHasErrors('price');
    $post($product(['stock' => -1]))->assertSessionHasErrors('stock');
    $post($product(['stock' => 1.5]))->assertSessionHasErrors('stock');
    expect(Product::count())->toBe(0);

    foreach ([0 => 'Out of Stock', 10 => 'Low Stock', 11 => 'In Stock'] as $stock => $status) {
        $post($product(['name' => "Breaker {$stock}", 'stock' => $stock]))->assertSessionHasNoErrors();
        expect(Product::where('name', "Breaker {$stock}")->value('status'))->toBe($status);
    }
    expect(Product::orderBy('id')->pluck('sku')->all())->toBe(['BRK-000001', 'BRK-000002', 'BRK-000003'])
        ->and(StockMovement::where('type', 'stock_in')->pluck('new_stock')->all())->toBe([10, 11]); // opening movements
});

it('UT-07 adds a valid stock-in quantity to the stock on hand and records the movement', function () {
    [$staff, $cookies] = capActor('staff_inventory', $this->team);
    $product = capProduct($this->team, ['stock' => 10]);
    $stockIn = fn (mixed $qty) => ($this->as)($staff, $cookies)->post("{$this->url}/inventory/stock-in", ['product_id' => $product->id, 'quantity' => $qty, 'reference_number' => 'DR-1001']);

    foreach ([0, -5, 2.5, 'ten', ''] as $invalid) {
        $stockIn($invalid)->assertSessionHasErrors('quantity');
    }
    expect($product->fresh()->stock)->toBe(10)->and(StockMovement::count())->toBe(0);

    $stockIn(5)->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(15)
        ->and(StockMovement::sole()->only(['type', 'quantity', 'previous_stock', 'new_stock', 'reference_number', 'product_id']))
        ->toBe(['type' => 'stock_in', 'quantity' => 5, 'previous_stock' => 10, 'new_stock' => 15, 'reference_number' => 'DR-1001', 'product_id' => $product->id]);
});

it('UT-08 refuses a stock-out above the available stock and never lets stock go negative', function () {
    [$staff, $cookies] = capActor('staff_inventory', $this->team);
    [$admin] = capActor('admin', $this->team);
    $product = capProduct($this->team, ['stock' => 10, 'reorder_level' => 2]);
    $stockOut = fn (mixed $qty) => ($this->as)($staff, $cookies)->post("{$this->url}/inventory/stock-out", ['product_id' => $product->id, 'quantity' => $qty, 'reason' => 'Damaged']);

    $stockOut(11)->assertSessionHasErrors('quantity'); // one more than is in stock
    $stockOut(0)->assertSessionHasErrors('quantity');
    $stockOut(-3)->assertSessionHasErrors('quantity');

    capConfirmed($this->team, $admin, [[$product, 4]]); // a confirmed order reserves 4: 6 available
    expect($product->fresh()->availableStock())->toBe(6);
    $stockOut(7)->assertSessionHasErrors('quantity');
    expect(capSessionError('quantity'))->toContain('Available quantity: 6')
        ->and($product->fresh()->stock)->toBe(10);

    $stockOut(6)->assertSessionHasNoErrors(); // exactly what is available
    expect($product->fresh()->stock)->toBe(4)
        ->and($product->fresh()->availableStock())->toBe(0)
        ->and(StockMovement::where('type', 'stock_out')->sole()->only(['quantity', 'previous_stock', 'new_stock']))->toBe(['quantity' => 6, 'previous_stock' => 10, 'new_stock' => 4]);
});

it('UT-09 recommends a reorder from the reorder level and the forecast shortfall', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $withHistory = function (array $attributes): Product {
        $product = capProduct($this->team, $attributes);
        foreach (['2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) { // forecast 50 a month, 150 for 3 months
            capDemand($this->team, $product, $date, $qty);
        }

        return $product;
    };
    $low = $withHistory(['stock' => 5, 'reorder_level' => 10, 'reorder_quantity' => 100]);
    $covered = $withHistory(['stock' => 500, 'reorder_level' => 10, 'reorder_quantity' => 100]);
    $atLevel = capProduct($this->team, ['stock' => 10, 'reorder_level' => 10, 'reorder_quantity' => 30]);
    $aboveLevel = capProduct($this->team, ['stock' => 11, 'reorder_level' => 10, 'reorder_quantity' => 30]);

    $result = app(ProductPrioritizer::class)->forTeam($this->team->id);

    expect($result[$low->id]['reorder'])->toMatchArray(['needed' => true, 'forecast' => 150, 'shortfall' => 145, 'suggested_quantity' => 145]) // max(100, 150 − 5)
        ->and($result[$covered->id]['reorder'])->toMatchArray(['needed' => false, 'suggested_quantity' => 0])
        ->and($result[$atLevel->id]['reorder'])->toMatchArray(['needed' => true, 'forecast' => null, 'suggested_quantity' => 30]) // at the level, no history
        ->and($result[$aboveLevel->id]['reorder'])->toMatchArray(['needed' => false, 'suggested_quantity' => 0]);
});

// =========================================================================================
// 4. Procurement
// =========================================================================================

it('UT-10 validates purchase request quantities and refuses a duplicate open request', function () {
    [$staff, $cookies] = capActor('staff_procurement', $this->team);
    $product = capProduct($this->team, ['stock' => 5]);
    $post = fn (array $line) => ($this->as)($staff, $cookies)->post("{$this->url}/procurement/requests", ['lines' => [$line + ['product_id' => $product->id, 'justification' => 'Below reorder level.']]]);

    foreach ([0, -3, 2.5, '', 1000001] as $invalid) {
        $post(['quantity' => $invalid])->assertSessionHasErrors('lines.0.quantity');
    }
    $post(['quantity' => 10, 'justification' => ''])->assertSessionHasErrors('lines.0.justification');
    $post(['quantity' => 6, 'product_id' => null, 'unit' => 'bucket', 'product_description' => 'THHN 14 AWG'])->assertSessionHasErrors('lines.0.unit'); // not a listed unit
    expect(PurchaseRequest::count())->toBe(0);

    $post(['quantity' => 1])->assertSessionHasNoErrors(); // smallest quantity
    $post(['quantity' => 50])->assertSessionHasErrors();  // the product already has an open request
    expect(PurchaseRequest::sole()->only(['quantity', 'status', 'unit']))->toBe(['quantity' => 1, 'status' => PurchaseRequest::PENDING, 'unit' => 'pcs'])
        ->and($product->fresh()->stock)->toBe(5); // a request never changes stock
});

it('UT-11 validates a purchase order and calculates its extended cost as quantity times unit cost', function () {
    [$admin, $adminCookies] = capActor('admin', $this->team);
    [$staff, $cookies] = capActor('staff_procurement', $this->team);
    $supplier = capSupplier($this->team);
    $product = capProduct($this->team, ['stock' => 5, 'supplier_id' => $supplier->id]);

    // Approval of a request creates the draft purchase order.
    ($this->as)($staff, $cookies)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $product->id, 'quantity' => 40, 'justification' => 'Restock.']]]);
    $request = PurchaseRequest::sole();
    ($this->as)($admin, $adminCookies)->post("{$this->url}/procurement/requests/{$request->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    ($this->as)($admin, $adminCookies)->post("{$this->url}/procurement/requests/{$request->id}/review", ['decision' => 'approve'])->assertSessionHasErrors(); // not twice
    $po = PurchaseOrder::sole();
    $patch = fn (array $data) => ($this->as)($staff, $cookies)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $data + ['supplier_id' => $supplier->id, 'quantity' => 40, 'unit_cost' => 12.5]);

    $patch(['supplier_id' => 999999])->assertSessionHasErrors('supplier_id');
    $patch(['quantity' => 0])->assertSessionHasErrors('quantity');
    $patch(['unit_cost' => 0])->assertSessionHasErrors('unit_cost');
    $patch(['unit_cost' => -1])->assertSessionHasErrors('unit_cost');
    $patch([])->assertSessionHasNoErrors();

    // 40 × 12.50 = 500.00
    $po->refresh();
    expect($po->only(['purchase_request_id', 'product_id', 'supplier_id', 'quantity', 'status']))
        ->toBe(['purchase_request_id' => $request->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'quantity' => 40, 'status' => PurchaseOrder::DRAFT])
        ->and((float) $po->unit_cost)->toBe(12.5)
        ->and((float) $po->total_cost)->toBe(500.0);
});

it('UT-12 maps an imported purchase order file to the purchase order fields and refuses invalid quantities', function () {
    [$admin, $cookies] = capActor('admin', $this->team);
    $import = fn (array $rows, bool $execute = false) => ($this->as)($admin, $cookies)->post("{$this->url}/procurement/purchase-orders/import-csv", [
        'file' => UploadedFile::fake()->createWithContent('purchase_orders.csv', implode("\n", ['Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost', ...$rows])),
        'execute' => $execute,
    ], ['Accept' => 'application/json']);

    // A valid row: every column reaches its field; 100 × 450.00 = 45,000.00.
    $import(['10-25-2026,4000005472,100,068SSCR2,"Stainless Steel Conduit Rod 2m",PCS,450.00'], execute: true)->assertSuccessful();
    $po = PurchaseOrder::sole();
    expect($po->only(['po_number', 'quantity', 'item_sku', 'description', 'unit_of_measure']))
        ->toBe(['po_number' => '4000005472', 'quantity' => 100, 'item_sku' => '068SSCR2', 'description' => 'Stainless Steel Conduit Rod 2m', 'unit_of_measure' => 'PCS'])
        ->and($po->po_date->toDateString())->toBe('2026-10-25')
        ->and((float) $po->total_cost)->toBe(45000.0);

    // Zero, negative and decimal quantities are not whole quantities of at least 1.
    $json = $import(['10-25-2026,PO-ZERO,0,A0,"Zero quantity",PCS,10', '10-25-2026,PO-NEG,-5,A1,"Negative quantity",PCS,10', '10-25-2026,PO-DEC,2.5,A2,"Decimal quantity",PCS,10'])->json();
    expect($json['summary'])->toMatchArray(['valid_count' => 0, 'invalid_count' => 3]);
});

// =========================================================================================
// 5. Supplier Evaluation  (Delivery 40% = on-time 60% + fulfillment 40%; Price 35%; Quality 25%)
// =========================================================================================

it('UT-13 calculates delivery timeliness from on-time deliveries and quantity fulfillment', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $product = capProduct($this->team);
    $alpha = capSupplier($this->team, 'Alpha Electrical');
    $beta = capSupplier($this->team, 'Beta Trading');
    $gamma = capSupplier($this->team, 'Gamma Supply');
    // Alpha: promised Sep 10; delivered Sep 8 (on time) and Sep 15 (late); 97 of 100 accepted.
    capPurchaseOrderWithDeliveries($this->team, $alpha, $product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 100], [['2026-09-08', 60, 57, 3], ['2026-09-15', 40, 40, 0]]);
    // Boundary: Beta delivers on the promised date, Gamma one day after it.
    capPurchaseOrderWithDeliveries($this->team, $beta, $product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 10], [['2026-09-10', 10, 10, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $gamma, $product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 10], [['2026-09-11', 10, 10, 0]]);

    $result = app(SupplierEvaluator::class)->forTeam($this->team->id);
    $delivery = fn ($supplier) => collect($result[$supplier->id]['criteria'])->firstWhere('key', 'delivery');

    // (50.00 × 0.60) + (97.00 × 0.40) = 68.80; × 0.40 = 27.52
    expect($delivery($alpha)['inputs'])->toMatchArray(['completed_deliveries' => 2, 'on_time_deliveries' => 1, 'ordered_quantity' => 100, 'accepted_quantity' => 97])
        ->and($delivery($alpha)['score'])->toEqual(68.8)
        ->and($delivery($alpha)['weighted'])->toEqual(27.52)
        ->and($delivery($beta)['inputs']['on_time_rate'])->toEqual(100.0)
        ->and($delivery($gamma)['inputs']['on_time_rate'])->toEqual(0.0)
        ->and($delivery($gamma)['score'])->toEqual(40.0);
});

it('UT-14 calculates price competitiveness against the lowest purchase cost of the same product', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $product = capProduct($this->team);
    $other = capProduct($this->team);
    $alpha = capSupplier($this->team, 'Alpha Electrical');
    $beta = capSupplier($this->team, 'Beta Trading');
    $solo = capSupplier($this->team, 'Solo Supply');
    capPurchaseOrderWithDeliveries($this->team, $alpha, $product, ['unit_cost' => 1100, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $beta, $product, ['unit_cost' => 1000, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $solo, $other, ['unit_cost' => 5, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);

    $result = app(SupplierEvaluator::class)->forTeam($this->team->id);
    $price = fn ($supplier) => collect($result[$supplier->id]['criteria'])->firstWhere('key', 'price');

    // Alpha: (1,000 / 1,100) × 100 = 90.91; × 0.35 = 31.82. Beta is the cheapest: 100.00.
    expect($price($alpha)['score'])->toEqual(90.91)
        ->and($price($alpha)['weighted'])->toEqual(31.82)
        ->and($price($beta)['score'])->toEqual(100.0)
        // Nobody else delivered Solo's product: no comparison, so no price score and no final score.
        ->and($price($solo)['score'])->toBeNull()
        ->and($result[$solo->id]['score'])->toBeNull()
        ->and($result[$solo->id]['rank'])->toBeNull();
});

it('UT-15 calculates product quality and the final weighted supplier score, class and rank', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $product = capProduct($this->team);
    $alpha = capSupplier($this->team, 'Alpha Electrical');
    $beta = capSupplier($this->team, 'Beta Trading');
    $none = capSupplier($this->team, 'No Records Supply');
    capPurchaseOrderWithDeliveries($this->team, $alpha, $product, ['unit_cost' => 1100, 'promised_delivery_date' => '2026-09-10', 'quantity' => 100], [['2026-09-08', 60, 57, 3], ['2026-09-15', 40, 40, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $beta, $product, ['unit_cost' => 1000, 'promised_delivery_date' => '2026-09-25', 'quantity' => 50], [['2026-09-20', 50, 50, 0]]);

    $evaluator = app(SupplierEvaluator::class);
    $result = $evaluator->forTeam($this->team->id);
    $quality = collect($result[$alpha->id]['criteria'])->firstWhere('key', 'quality');

    // Quality: (97 / 100) × 100 = 97.00; × 0.25 = 24.25.
    // Final: 27.52 + 31.82 + 24.25 = 83.59.
    expect($quality['inputs'])->toMatchArray(['accepted' => 97, 'rejected' => 3])
        ->and($quality['score'])->toEqual(97.0)
        ->and($quality['weighted'])->toEqual(24.25)
        ->and($result[$alpha->id]['final_formula'])->toBe('27.52 + 31.82 + 24.25 = 83.59')
        ->and($result[$alpha->id]['score'])->toEqual(83.59)
        ->and($result[$alpha->id]['classification'])->toBe('Very Good')
        ->and($result[$alpha->id]['rank'])->toBe(2)
        ->and($result[$beta->id]['score'])->toEqual(100.0)
        ->and($result[$beta->id]['classification'])->toBe('Excellent')
        ->and($result[$beta->id]['rank'])->toBe(1)
        ->and($result[$none->id]['score'])->toBeNull() // no records: no score, not zero
        ->and($evaluator->classify(90))->toBe('Excellent')
        ->and($evaluator->classify(89.99))->toBe('Very Good')
        ->and($evaluator->classify(59.99))->toBe('Needs Improvement');
});

// =========================================================================================
// 6. Sales and Payment Recording  (orders here: 2 × 100.00 + 12% tax = 224.00)
// =========================================================================================

it('UT-16 creates a sales order only from an accepted quotation, once, with the same totals', function () {
    [$sales, $cookies] = capActor('staff_sales', $this->team);
    $product = capProduct($this->team, ['stock' => 50, 'price' => 100]);
    $post = fn (array $data) => ($this->as)($sales, $cookies)->post("{$this->url}/sales", $data);

    $post([])->assertSessionHasErrors('quotation_id');
    $post(['quotation_id' => 999999])->assertSessionHasErrors('quotation_id');
    foreach ([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Rejected] as $status) {
        $post(['quotation_id' => capQuotation($this->team, $status, [[$product, 2]])->id])->assertSessionHasErrors('error');
    }
    expect(Sale::count())->toBe(0);

    $accepted = capQuotation($this->team, QuotationStatus::Accepted, [[$product, 2]]);
    $post(['quotation_id' => $accepted->id, 'fulfillment_type' => 'delivery'])->assertSessionHasNoErrors();
    $post(['quotation_id' => $accepted->id])->assertSessionHasErrors(['error' => 'This quotation was already converted to a Sales Order.']);

    $sale = Sale::with('items')->sole();
    expect($sale->quotation_id)->toBe($accepted->id)
        ->and($accepted->fresh()->converted_to_sale_id)->toBe($sale->id)
        ->and((float) $sale->grand_total)->toBe(224.0)
        ->and((float) $sale->outstanding_balance)->toBe(224.0)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and($sale->items->sole()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(50); // creating an order moves no stock
});

it('UT-17 validates the amount, method and date of a payment', function () {
    [$sales, $cookies] = capActor('staff_sales', $this->team);
    $sale = capOrder($this->team, $sales, [[capProduct($this->team, ['price' => 100]), 2]]);
    $pay = fn (array $data) => ($this->as)($sales, $cookies)->post("{$this->url}/sales/{$sale->id}/payments", $data + ['method' => 'GCash', 'amount' => 100, 'reference' => 'GC-1001', 'payment_date' => now()->toDateString()]);

    foreach ([0, -50, 'abc', '', 0.001] as $amount) {
        $pay(['amount' => $amount])->assertSessionHasErrors('amount');
    }
    $pay(['method' => 'Cash'])->assertSessionHasErrors('method');
    $pay(['payment_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('payment_date'); // tomorrow
    $pay(['proof' => UploadedFile::fake()->create('proof.exe', 10)])->assertSessionHasErrors('proof');
    expect(Payment::count())->toBe(0);

    $pay(['amount' => 0.01])->assertSessionHasNoErrors(); // the smallest amount
    $payment = Payment::sole();
    expect((float) $payment->submitted_amount)->toBe(0.01)
        ->and($payment->status)->toBe(PaymentRecordStatus::Submitted)
        ->and((float) $sale->fresh()->amount_paid)->toBe(0.0); // recorded, not counted until verified
});

it('UT-18 calculates the remaining balance and refuses over-payments and duplicate transactions', function () {
    [$admin] = capActor('admin', $this->team);
    [$sales] = capActor('staff_sales', $this->team);
    $product = capProduct($this->team, ['stock' => 50, 'price' => 100]);
    $sale = capOrder($this->team, $admin, [[$product, 2]]);   // 224.00
    $other = capOrder($this->team, $admin, [[$product, 2]]);
    $payments = app(PaymentService::class);
    $record = fn (Sale $s, float $amount, string $ref) => $payments->record($s, ['method' => 'Bank Transfer', 'amount' => $amount, 'reference' => $ref, 'payment_date' => now()->toDateString()], null, $sales);
    $verify = fn (Payment $p, float $amount, string $ref) => $payments->verify($p->fresh(), ['reference' => $ref, 'amount' => $amount, 'payment_date' => now()->toDateString()], $sales);

    $first = $verify($record($sale, 100, 'BDO-1'), 100, 'BDO-1');
    expect((float) $first->balance_after)->toBe(124.0)
        ->and((float) $sale->fresh()->outstanding_balance)->toBe(124.0)
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);

    $second = $record($sale, 124, 'BDO-2');
    expect(fn () => $verify($second, 124.01, 'BDO-2'))->toThrow(WorkflowException::class, 'more than the remaining balance'); // one centavo over
    expect(fn () => $verify($second, 124, 'bdo 1'))->toThrow(WorkflowException::class, 'was already verified for another payment'); // same bank reference
    expect(fn () => $verify($record($other, 100, 'BDO-1'), 100, 'BDO-1'))->toThrow(WorkflowException::class, 'was already verified for another payment');
    expect(PaymentReceipt::count())->toBe(1)->and((float) $sale->fresh()->amount_paid)->toBe(100.0);

    $receipt = $verify($second, 124, 'BDO-2'); // exactly the balance
    expect((float) $receipt->previously_paid)->toBe(100.0)
        ->and((float) $receipt->balance_after)->toBe(0.0)
        ->and((float) $sale->fresh()->outstanding_balance)->toBe(0.0)
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
});

// =========================================================================================
// 7. Delivery Management
// =========================================================================================

it('UT-19 validates inbound delivery quantities and adds only the accepted units to stock', function () {
    Carbon::setTestNow('2026-11-09 10:00:00');
    [$staff, $cookies] = capActor('staff_procurement', $this->team);
    $supplier = capSupplier($this->team, 'Surangel Supply');
    $breaker = capProduct($this->team, ['stock' => 25, 'supplier_id' => $supplier->id]);
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-11-02', 'po_number' => 'PO-7001', 'supplier_id' => $supplier->id, 'status' => PurchaseOrder::SENT,
        'unit_of_measure' => 'PCS', 'quantity' => 20, 'product_id' => $breaker->id, 'description' => $breaker->name, 'unit_cost' => 12.5, 'total_cost' => 250]);
    $confirm = fn (array $line) => ($this->as)($staff, $cookies)->post("{$this->url}/deliveries/inbound", ['po_number' => 'PO-7001', 'delivery_date' => '2026-11-09',
        'submission_key' => (string) Str::uuid(), 'lines' => [$line + ['purchase_order_id' => $po->id, 'inspected' => true]]]);

    $confirm(['received_quantity' => 10, 'accepted_quantity' => 7, 'rejected_quantity' => 2])->assertSessionHasErrors('lines.0.accepted_quantity');  // 7 + 2 ≠ 10
    $confirm(['received_quantity' => 21, 'accepted_quantity' => 21, 'rejected_quantity' => 0])->assertSessionHasErrors('lines.0.received_quantity'); // 21 of 20 ordered
    $confirm(['received_quantity' => 5, 'accepted_quantity' => -1, 'rejected_quantity' => 6])->assertSessionHasErrors('lines.0.accepted_quantity');
    $confirm(['received_quantity' => 5, 'accepted_quantity' => 3, 'rejected_quantity' => 2])->assertSessionHasErrors('lines.0.inspection_remarks');  // rejected, no reason
    expect(InboundDelivery::count())->toBe(0)->and($breaker->fresh()->stock)->toBe(25)->and(StockMovement::count())->toBe(0);

    $confirm(['received_quantity' => 20, 'accepted_quantity' => 17, 'rejected_quantity' => 3, 'inspection_remarks' => 'Three cracked'])->assertSessionHasNoErrors();

    // 25 + 17 accepted = 42; the 3 rejected units never reach stock.
    expect($breaker->fresh()->stock)->toBe(42)
        ->and(StockMovement::sole()->only(['type', 'quantity', 'previous_stock', 'new_stock']))->toBe(['type' => 'stock_in', 'quantity' => 17, 'previous_stock' => 25, 'new_stock' => 42])
        ->and(Delivery::sole()->only(['purchase_order_id', 'quantity', 'accepted_quantity', 'rejected_quantity']))->toBe(['purchase_order_id' => $po->id, 'quantity' => 20, 'accepted_quantity' => 17, 'rejected_quantity' => 3]);
});

it('UT-20 refuses outbound release and received quantities outside what was ordered and released', function () {
    [$admin] = capActor('admin', $this->team);
    [$warehouse, $cookies] = capActor('staff_inventory', $this->team);
    $product = capProduct($this->team, ['stock' => 25, 'price' => 100]);
    $sale = capConfirmed($this->team, $admin, [[$product, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);

    expect(fn () => $service->prepareRelease($sale->fulfillment, [$line->id => 6], $warehouse))->toThrow(WorkflowException::class, 'only 5 can still be released');
    expect(fn () => $service->prepareRelease($sale->fulfillment, [$line->id => 0], $warehouse))->toThrow(WorkflowException::class, 'Choose at least one item');
    ($this->as)($warehouse, $cookies)->post("{$this->url}/deliveries/fulfillments/{$sale->fulfillment->id}/releases", ['quantities' => [$line->id => -1]])->assertSessionHasErrors("quantities.{$line->id}");

    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $warehouse); // exactly the ordered quantity
    expect(fn () => $service->dispatch($release, $admin))->toThrow(WorkflowException::class, 'Mark the release ready'); // steps cannot be skipped
    $service->markReady($release, $warehouse);
    $service->dispatch($release, $admin);
    expect(fn () => $service->recordReceipt($release->fresh(), [$line->id => 6], 'Site Engr. Lito', null, null, $admin))->toThrow(WorkflowException::class, 'received quantity must be between 0 and 5');
    expect(fn () => $service->recordReceipt($release->fresh(), [$line->id => 0], 'Site Engr. Lito', null, null, $admin))->toThrow(WorkflowException::class, 'Nothing was received');

    expect($product->fresh()->stock)->toBe(25)->and(StockMovement::count())->toBe(0); // nothing left stock
});

it('UT-21 takes out of stock exactly the quantity the customer received, once', function () {
    [$admin] = capActor('admin', $this->team);
    [$warehouse] = capActor('staff_inventory', $this->team);
    $product = capProduct($this->team, ['stock' => 25, 'price' => 100]);
    $sale = capConfirmed($this->team, $admin, [[$product, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $warehouse);
    $service->markReady($release, $warehouse);
    $service->dispatch($release, $admin);
    expect($product->fresh()->stock)->toBe(25)->and($product->fresh()->availableStock())->toBe(20); // on the road: in stock, reserved

    $service->recordReceipt($release->fresh(), [$line->id => 3], 'Site Engr. Lito', null, null, $admin);
    app(StockLedger::class)->deductDelivered($release->fresh(), $admin); // repeating it changes nothing

    // 25 − 3 received = 22; the 2 not received stay in stock, reserved for the order.
    expect($product->fresh()->stock)->toBe(22)
        ->and(StockMovement::where('delivery_release_id', $release->id)->sole()->only(['type', 'quantity', 'previous_stock', 'new_stock', 'sale_id']))
        ->toBe(['type' => 'sale_release', 'quantity' => -3, 'previous_stock' => 25, 'new_stock' => 22, 'sale_id' => $sale->id])
        ->and($line->fresh()->only(['delivered_quantity', 'deducted_quantity', 'reserved_quantity']))->toBe(['delivered_quantity' => 3, 'deducted_quantity' => 3, 'reserved_quantity' => 2])
        ->and($product->fresh()->availableStock())->toBe(20);
});

// =========================================================================================
// 8. Demand Forecasting  (Simple Moving Average; clock fixed at 15 October 2026)
// =========================================================================================

it('UT-22 forecasts a month as the rounded average of the previous three months, counting empty months as zero', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $forecaster = app(SmaForecaster::class);
    $series = function (array $monthly, string $to = '2026-10-15') use ($forecaster): array {
        $product = capProduct($this->team);
        foreach (array_combine(['2026-07-10', '2026-08-10', '2026-09-10'], $monthly) as $date => $qty) {
            if ($qty > 0) {
                capDemand($this->team, $product, $date, $qty);
            }
        }

        return $forecaster->series($this->team->id, Carbon::parse('2026-07-01'), Carbon::parse($to), 3, 'All', $product->id);
    };

    $main = $series([10, 20, 30]);
    expect($main['historical'])->toBe([10, 20, 30, 0])
        ->and($main['forecast'])->toBe([null, null, null, 20])                 // (10 + 20 + 30) / 3
        ->and($main['steps'][0])->toMatchArray(['month' => 'Oct 2026', 'sum' => 60, 'window' => 3, 'forecast' => 20])
        ->and($series([10, 10, 11])['forecast'][3])->toBe(10)                  // 10.33
        ->and($series([1, 2, 2])['forecast'][3])->toBe(2)                      // 1.67
        ->and($series([30, 0, 0], '2026-11-30')['forecast'])->toBe([null, null, null, 10, 0]) // zero-demand months
        ->and($series([12, 12, 12], '2026-09-30')['forecast'])->toBe([null, null, null]);     // exactly 3 months: no forecast yet
});

it('UT-23 gives no forecast without history, accepts only the 3, 6 and 12 month periods, and uses confirmed demand', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $forecaster = app(SmaForecaster::class);
    $empty = capProduct($this->team);
    $product = capProduct($this->team);
    foreach (['2026-04-05' => 20, '2026-05-05' => 20, '2026-06-05' => 20, '2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
        capDemand($this->team, $product, $date, $qty);
    }
    $order = function (int $quantity, string $status) use ($product): void {
        $sale = Sale::create(['team_id' => $this->team->id, 'order_number' => 'SO-UT-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'sale_date' => '2026-09-12',
            'subtotal' => $quantity * 100, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => $quantity * 100, 'status' => $status]);
        $sale->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => 100, 'discount' => 0, 'subtotal' => $quantity * 100]);
    };
    $order(75, 'confirmed'); // September: stock movements 60, confirmed orders 75
    $order(40, 'pending');   // not counted
    $order(40, 'cancelled'); // not counted

    $result = $forecaster->forProducts($this->team->id, [$empty->id, $product->id], 3);
    $six = $forecaster->forProducts($this->team->id, [$product->id], 6)[$product->id];

    expect($result[$empty->id])->toMatchArray(['available' => false, 'forecast' => null, 'period_forecast' => null, 'reason' => 'No sales or outgoing stock records exist for this product.'])
        // September demand is the larger of 60 and 75: (40 + 50 + 75) / 3 = 55 a month, 165 for the period.
        ->and($result[$product->id])->toMatchArray(['available' => true, 'forecast' => 55, 'period_forecast' => 165])
        // Six months: (20 + 20 + 20 + 40 + 50 + 75) / 6 = 37.5 → 38 a month; 225 for the period.
        ->and($six)->toMatchArray(['available' => true, 'forecast' => 38, 'period_forecast' => 225])
        ->and(array_map(fn ($value) => SmaForecaster::window($value), [3, 6, 12, '6', 5, 0, -3, null, 'abc']))->toBe([3, 6, 12, 6, 3, 3, 3, 3, 3]);
});

// =========================================================================================
// 9. Reporting and Analytics  (clock fixed at 15 October 2026)
// =========================================================================================

it('UT-24 filters report figures by date range and totals revenue and inventory value correctly', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    [$reporter, $cookies] = capActor('staff_reports', $this->team);
    $sale = fn (string $date, float $total, string $status = 'completed') => Sale::create(['team_id' => $this->team->id, 'order_number' => 'SO-RPT-'.uniqid(), 'client_name' => 'Metro Builders Inc.',
        'sale_date' => $date, 'subtotal' => $total, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => $total, 'status' => $status]);
    $sale('2026-09-30 12:00:00', 100);
    $sale('2026-10-01 00:00:00', 200, 'confirmed');  // first day of the range
    $sale('2026-10-10 23:59:00', 400, 'processing'); // last day of the range
    $sale('2026-10-11 08:00:00', 800);
    $sale('2026-10-05 09:00:00', 1600, 'pending');   // not a sale yet
    $sale('2026-10-06 09:00:00', 3200, 'cancelled'); // never counted
    capProduct($this->team, ['category' => 'Breakers', 'stock' => 10, 'cost_price' => 60, 'reorder_level' => 2]); // 600.00, in stock
    capProduct($this->team, ['category' => 'Breakers', 'stock' => 2, 'cost_price' => 25, 'reorder_level' => 5]);   //  50.00, low
    capProduct($this->team, ['category' => 'Wiring', 'stock' => 0, 'cost_price' => 10, 'reorder_level' => 5]);     //   0.00, out
    $otherTeam = Team::factory()->create();
    Sale::create(['team_id' => $otherTeam->id, 'order_number' => 'SO-OTHER', 'client_name' => 'Another Company', 'sale_date' => '2026-10-05', 'subtotal' => 9999, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => 9999, 'status' => 'completed']);
    $report = fn (string $query = '') => ($this->as)($reporter, $cookies)->get("{$this->url}/reports".($query ? "?{$query}" : ''))->assertOk()->viewData('page')['props'];

    $ranged = $report('date_preset=custom&from_date=2026-10-01&to_date=2026-10-10');
    $all = $report();

    expect($ranged['dateFilter'])->toBe(['preset' => 'custom', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-10'])
        ->and($ranged['metrics']['salesCount'])->toBe(2)
        ->and((float) $ranged['metrics']['totalRevenue'])->toBe(600.0)   // 200 + 400, both end dates included
        ->and($all['metrics']['salesCount'])->toBe(4)
        ->and((float) $all['metrics']['totalRevenue'])->toBe(1500.0)     // pending, cancelled and the other team left out
        ->and((float) $report('date_preset=this_month')['metrics']['totalRevenue'])->toBe(1400.0)
        ->and($report('date_preset=next_century')['dateFilter']['preset'])->toBe('all_time')
        ->and((float) $all['metrics']['totalValuation'])->toBe(650.0)    // Σ stock × cost
        ->and($all['inventoryAnalytics']['statusDistribution'])->toBe(['in_stock' => 1, 'low_stock' => 1, 'out_of_stock' => 1]);
});

it('UT-25 handles an unreadable date in the report date range without a server error', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    [$reporter, $cookies] = capActor('staff_reports', $this->team);

    $response = ($this->as)($reporter, $cookies)->get("{$this->url}/reports?date_preset=custom&from_date=not-a-date&to_date=2026-10-10");

    expect($response->status())->toBeLessThan(500);
});
