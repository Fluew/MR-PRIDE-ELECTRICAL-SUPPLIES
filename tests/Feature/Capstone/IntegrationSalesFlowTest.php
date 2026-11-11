<?php

/*
 * 4.4.2 Integration Testing — customer-side workflows (IT-01 to IT-14):
 *   1. Authentication → Role-Based Dashboard
 *   2. Quotation Request → Quotation Management
 *   3. Quotation Acceptance → Sales Order Creation
 *   4. Sales Order → Payment Recording
 *   9. Sales Order → Outbound Delivery → Inventory Update
 *  10. Sales Records → Demand Forecasting
 *  11. Transactions → Reporting and Analytics
 *  12. Quotation Management → Email Integration
 *
 * Test-only file. Every step goes through the application's own routes and services; the
 * database is the in-memory SQLite test database and no external service is contacted.
 */

use App\Enums\DeliveryStatus;
use App\Enums\EmailStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\DeliveryRelease;
use App\Models\NotificationLog;
use App\Models\OrderFulfillment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Team;
use App\Services\Delivery\FulfillmentService;
use App\Services\ResendService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Capstone/helpers.php';

beforeEach(function () {
    capAssertIsolatedDatabase();
    Http::preventStrayRequests(); // any real call to Resend, Frankfurter or another service fails the test
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
    [$this->admin, $this->adminCookies] = capActor('admin', $this->team);
    [$this->quoter, $this->quoterCookies] = capActor('staff_quotations', $this->team);
    [$this->sales, $this->salesCookies] = capActor('staff_sales', $this->team);
    [$this->warehouse, $this->warehouseCookies] = capActor('staff_inventory', $this->team);
    [$this->driver, $this->driverCookies] = capActor('staff_deliveries', $this->team);
    $this->url = '/mr-pride';
});

function capAs($t, string $who)
{
    return $t->actingAs($t->{$who})->withCookies($t->{$who.'Cookies'});
}

function capEmails(string $type)
{
    return NotificationLog::where('type', $type)->get();
}

/** Prepare, ready, dispatch and deliver everything on a confirmed order, through the HTTP routes. */
function capDeliverAll($t, Sale $sale): DeliveryRelease
{
    $fulfillment = $sale->fulfillment;
    $quantities = $sale->items->mapWithKeys(fn ($line) => [$line->id => $line->quantity])->all();

    capAs($t, 'warehouse')->post("{$t->url}/deliveries/fulfillments/{$fulfillment->id}/releases", ['quantities' => $quantities])->assertSessionHasNoErrors();
    $release = DeliveryRelease::where('sale_id', $sale->id)->latest('id')->firstOrFail();
    capAs($t, 'warehouse')->post("{$t->url}/deliveries/releases/{$release->id}/ready")->assertSessionHasNoErrors();
    capAs($t, 'admin')->post("{$t->url}/deliveries/fulfillments/{$fulfillment->id}/assign", ['staff_id' => $t->driver->id])->assertSessionHasNoErrors();
    capAs($t, 'driver')->post("{$t->url}/deliveries/fulfillments/{$fulfillment->id}/start")->assertSessionHasNoErrors();
    capAs($t, 'driver')->post("{$t->url}/deliveries/fulfillments/{$fulfillment->id}/deliver", [
        'proof_of_delivery' => UploadedFile::fake()->image('dr.jpg', 400, 400), 'recipient_name' => 'Site Engr. Lito',
    ])->assertSessionHasNoErrors();

    return $release->fresh();
}

// ------------------------------------------------------- 1. Authentication → Role-Based Dashboard

it('IT-01 signs an administrator in and opens the administrator dashboard', function () {
    $response = $this->withCookies($this->adminCookies)->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($this->admin);
    $response->assertRedirect();
    // (The account factory also gives each test user a personal workspace; sign-in lands there.)
    expect($response->headers->get('Location'))->toMatch('#/[a-z0-9-]+/dashboard$#');

    capAs($this, 'admin')->get("{$this->url}/dashboard")->assertOk()->assertInertia(fn ($page) => $page->where('auth.user.id', $this->admin->id));
    // The administrator can open every module.
    foreach (['inventory', 'quotations', 'sales', 'procurement', 'deliveries', 'suppliers', 'forecast', 'reports', 'users'] as $module) {
        capAs($this, 'admin')->get("{$this->url}/{$module}")->assertOk();
    }
});

it('IT-02 signs a staff member in to the assigned module only', function () {
    $response = $this->withCookies($this->salesCookies)->post(route('login.store'), ['email' => $this->sales->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($this->sales);
    expect($response->headers->get('Location'))->toMatch('#/[a-z0-9-]+/sales$#'); // the assigned module, not a dashboard

    capAs($this, 'sales')->get("{$this->url}/sales")->assertOk();
    capAs($this, 'sales')->get("{$this->url}/dashboard")->assertRedirect("{$this->url}/sales"); // no administrator dashboard for staff
    foreach (['inventory', 'quotations', 'procurement', 'reports', 'users'] as $module) {
        capAs($this, 'sales')->get("{$this->url}/{$module}")->assertForbidden();
    }
});

it('IT-03 closes the workspace after sign-out and to a signed-in account on another device', function () {
    capAs($this, 'sales')->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
    $this->withCookies($this->salesCookies)->get("{$this->url}/sales")->assertRedirect(route('login'));

    // The sales account on the administrator's device: signed out, not let in.
    $this->actingAs($this->sales)->withCookies($this->adminCookies)->get("{$this->url}/sales")->assertRedirect(route('login'));
    $this->assertGuest();
});

// ---------------------------------------------------- 2. Quotation Request → Quotation Management

it('IT-04 passes a public quotation request to Quotation Management, where staff price it and an administrator approves it', function () {
    $product = capProduct($this->team, ['price' => 250, 'cost_price' => 150, 'stock' => 50]);

    $this->post('/quotation', ['company' => 'Acme Builders', 'contactName' => 'Juan Dela Cruz', 'email' => 'juan@acme.test', 'phone' => '09171234567',
        'projectType' => 'Industrial Facility', 'timeline' => '1-3 months', 'hasUnlistedBrands' => true, 'unlistedBrands' => 'Omron'])->assertSessionHasNoErrors();
    auth()->logout();
    $quotation = Quotation::sole();

    // The request is listed for quotation staff with what the customer sent.
    capAs($this, 'quoter')->get("{$this->url}/quotations")->assertOk()->assertInertia(fn ($page) => $page
        ->where('quotations.data.0.id', $quotation->id)
        ->where('quotations.data.0.client_name', 'Acme Builders')
        ->where('quotations.data.0.status', 'draft'));
    expect($quotation->request_details)->toMatchArray(['source' => 'public_form', 'project_type' => 'Industrial Facility', 'unlisted_brands' => 'Omron']);

    // Staff price it, submit it; only an administrator can approve.
    capAs($this, 'quoter')->put("{$this->url}/quotations/{$quotation->id}", ['client_name' => 'Acme Builders', 'email' => 'juan@acme.test', 'status' => 'draft',
        'items' => [['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 4, 'unit_price' => 250]]])->assertSessionHasNoErrors();
    capAs($this, 'quoter')->patch("{$this->url}/quotations/{$quotation->id}/status", ['status' => 'submit_for_approval'])->assertSessionHasNoErrors();
    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/approve")->assertForbidden();
    capAs($this, 'admin')->post("{$this->url}/quotations/{$quotation->id}/approve")->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->is_approved)->toBeTrue()
        ->and($quotation->approved_by_id)->toBe($this->admin->id)
        ->and((float) $quotation->grand_total)->toBe(1000.0)
        ->and($quotation->items->sole()->product_id)->toBe($product->id)
        ->and((float) $quotation->items->sole()->unit_cost)->toBe(150.0) // the supplier cost is carried from Inventory
        ->and($product->fresh()->stock)->toBe(50);                        // a quotation never moves stock
});

// ------------------------------------------------- 3. Quotation Acceptance → Sales Order Creation

it('IT-05 turns an accepted quotation into one sales order with the same customer, lines and totals', function () {
    $product = capProduct($this->team, ['price' => 100, 'stock' => 20]);
    $quotation = capQuotation($this->team, QuotationStatus::Sent, [[$product, 3]]);

    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/decision", ['decision' => 'accepted', 'source' => 'email'])->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
    capAs($this, 'sales')->post("{$this->url}/sales", ['quotation_id' => $quotation->id, 'fulfillment_type' => 'delivery'])->assertSessionHasNoErrors();

    $sale = Sale::with('items')->sole();
    $quotation->refresh();
    expect($sale->quotation_id)->toBe($quotation->id)
        ->and($quotation->converted_to_sale_id)->toBe($sale->id)       // linked in both directions
        ->and($sale->client_name)->toBe($quotation->client_name)
        ->and($sale->email)->toBe($quotation->email)
        ->and((float) $sale->subtotal)->toBe((float) $quotation->subtotal)
        ->and((float) $sale->tax_amount)->toBe((float) $quotation->tax_amount)
        ->and((float) $sale->grand_total)->toBe(336.0)
        ->and($sale->items->sole()->only(['product_id', 'quantity']))->toBe(['product_id' => $product->id, 'quantity' => 3])
        ->and($sale->items->sole()->quotation_item_id)->toBe($quotation->items->sole()->id)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($product->fresh()->stock)->toBe(20)
        ->and(StockMovement::count())->toBe(0);

    // The accepted quotation is no longer offered for a new order, and cannot be converted again.
    capAs($this, 'sales')->get("{$this->url}/sales")->assertOk()->assertInertia(fn ($page) => $page->has('quotations', 0)->has('sales.data', 1));
    capAs($this, 'sales')->post("{$this->url}/sales", ['quotation_id' => $quotation->id])->assertSessionHasErrors('error');
    expect(Sale::count())->toBe(1);
});

it('IT-06 creates no sales order for a quotation the customer rejected', function () {
    $quotation = capQuotation($this->team, QuotationStatus::Sent, [[capProduct($this->team), 1]]);

    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/decision", ['decision' => 'rejected', 'source' => 'phone', 'remarks' => 'Budget cut'])->assertSessionHasNoErrors();
    capAs($this, 'sales')->post("{$this->url}/sales", ['quotation_id' => $quotation->id])->assertSessionHasErrors('error');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected)
        ->and($quotation->fresh()->converted_to_sale_id)->toBeNull()
        ->and(Sale::count())->toBe(0);
});

// ----------------------------------------------------------- 4. Sales Order → Payment Recording

it('IT-07 records and verifies a payment, issuing the receipt and invoice and confirming the order', function () {
    $product = capProduct($this->team, ['price' => 100, 'stock' => 10]);
    $sale = capOrder($this->team, $this->sales, [[$product, 2]]); // 224.00

    capAs($this, 'sales')->post("{$this->url}/sales/{$sale->id}/payments", ['method' => 'GCash', 'amount' => 224, 'reference' => 'GC-778899',
        'payment_date' => now()->toDateString(), 'proof' => UploadedFile::fake()->image('gcash.png', 300, 600)])->assertSessionHasNoErrors();
    $payment = Payment::sole();
    expect($payment->status)->toBe(PaymentRecordStatus::Submitted)
        ->and((float) $sale->fresh()->amount_paid)->toBe(0.0)        // recorded, not yet counted
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Pending);

    capAs($this, 'sales')->post("{$this->url}/sales/{$sale->id}/payments/{$payment->id}/verify", ['reference' => 'GC-778899', 'amount' => 224, 'payment_date' => now()->toDateString()])
        ->assertSessionHasNoErrors();

    $sale->refresh();
    $receipt = PaymentReceipt::sole();
    $invoice = SalesInvoice::sole();
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Verified)
        ->and($receipt->only(['sale_id', 'payment_id']))->toBe(['sale_id' => $sale->id, 'payment_id' => $payment->id])
        ->and((float) $receipt->amount_received)->toBe(224.0)
        ->and((float) $receipt->balance_after)->toBe(0.0)
        ->and((float) $sale->amount_paid)->toBe(224.0)
        ->and((float) $sale->outstanding_balance)->toBe(0.0)
        ->and($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($invoice->sale_id)->toBe($sale->id)
        ->and($sale->invoice_number)->toBe($invoice->invoice_number)
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Pending) // the delivery record is opened
        ->and($sale->items->sole()->reserved_quantity)->toBe(2)           // stock is reserved, not taken out
        ->and($product->fresh()->stock)->toBe(10);
});

it('IT-08 keeps an order pending with its balance after a partial payment, and saves nothing when verification is refused', function () {
    $sale = capOrder($this->team, $this->sales, [[capProduct($this->team, ['price' => 100]), 2]]); // 224.00
    $pay = fn (float $amount, string $ref) => capAs($this, 'sales')->post("{$this->url}/sales/{$sale->id}/payments", ['method' => 'Bank Transfer', 'amount' => $amount, 'reference' => $ref, 'payment_date' => now()->toDateString()]);
    $verify = fn (Payment $p, float $amount) => capAs($this, 'sales')->post("{$this->url}/sales/{$sale->id}/payments/{$p->id}/verify", ['reference' => $p->submitted_reference, 'amount' => $amount, 'payment_date' => now()->toDateString()]);

    $pay(100, 'BDO-1')->assertSessionHasNoErrors();
    $verify(Payment::sole(), 100)->assertSessionHasNoErrors();
    expect((float) $sale->fresh()->outstanding_balance)->toBe(124.0)
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Pending)
        ->and(SalesInvoice::count())->toBe(0);

    // More than the balance: refused, and the transaction leaves no trace.
    $pay(200, 'BDO-2')->assertSessionHasNoErrors();
    $second = Payment::latest('id')->first();
    $verify($second, 200)->assertSessionHasErrors('amount');
    expect($second->fresh()->status)->toBe(PaymentRecordStatus::Submitted)
        ->and($second->fresh()->verified_amount)->toBeNull()
        ->and(PaymentReceipt::count())->toBe(1)
        ->and((float) $sale->fresh()->amount_paid)->toBe(100.0)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Pending);

    // Sales actions are closed to other modules.
    capAs($this, 'warehouse')->post("{$this->url}/sales/{$sale->id}/payments", ['method' => 'GCash', 'amount' => 1, 'payment_date' => now()->toDateString()])->assertForbidden();
});

// ----------------------------------------- 9. Sales Order → Outbound Delivery → Inventory Update

it('IT-09 delivers a confirmed order and takes its units out of inventory once, completing the order', function () {
    $product = capProduct($this->team, ['price' => 100, 'stock' => 10, 'reorder_level' => 2]);
    $sale = capConfirmed($this->team, $this->admin, [[$product, 4]]);
    expect($product->fresh()->stock)->toBe(10)->and($product->fresh()->availableStock())->toBe(6); // reserved on confirmation

    $release = capDeliverAll($this, $sale);

    $sale->refresh();
    $line = $sale->items->sole();
    $movement = StockMovement::sole();
    expect($release->status)->toBe(DeliveryRelease::DELIVERED)
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Delivered)
        ->and($sale->status)->toBe(SalesOrderStatus::Completed)
        ->and($line->only(['delivered_quantity', 'reserved_quantity', 'deducted_quantity']))->toBe(['delivered_quantity' => 4, 'reserved_quantity' => 0, 'deducted_quantity' => 4])
        ->and($product->fresh()->stock)->toBe(6)
        ->and($product->fresh()->availableStock())->toBe(6)
        // One stock movement, linked to the order, the release and the product.
        ->and($movement->only(['type', 'quantity', 'previous_stock', 'new_stock', 'sale_id', 'delivery_release_id', 'product_id']))
        ->toBe(['type' => 'sale_release', 'quantity' => -4, 'previous_stock' => 10, 'new_stock' => 6, 'sale_id' => $sale->id, 'delivery_release_id' => $release->id, 'product_id' => $product->id]);

    // Repeating the delivery changes nothing.
    capAs($this, 'driver')->post("{$this->url}/deliveries/fulfillments/{$sale->fulfillment->id}/deliver", [
        'proof_of_delivery' => UploadedFile::fake()->image('dr.jpg', 400, 400), 'recipient_name' => 'Site Engr. Lito']);
    expect($product->fresh()->stock)->toBe(6)->and(StockMovement::count())->toBe(1);
});

it('IT-10 keeps reserved units from being sold or taken out twice', function () {
    $product = capProduct($this->team, ['price' => 100, 'stock' => 5]);
    $first = capConfirmed($this->team, $this->admin, [[$product, 4]]);  // reserves 4 of 5
    $second = capConfirmed($this->team, $this->admin, [[$product, 3]]); // only 1 left to reserve

    expect($first->items->sole()->reserved_quantity)->toBe(4)
        ->and($second->items->sole()->reserved_quantity)->toBe(1)
        ->and($product->fresh()->availableStock())->toBe(0);

    // Inventory staff cannot take reserved units out by hand.
    capAs($this, 'warehouse')->post("{$this->url}/inventory/stock-out", ['product_id' => $product->id, 'quantity' => 1, 'reason' => 'Damaged'])->assertSessionHasErrors('quantity');
    // The second order cannot release more than is held for it.
    $line = $second->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($second->fulfillment, [$line->id => 3], $this->warehouse);
    $service->markReady($release, $this->warehouse);
    expect(fn () => $service->dispatch($release, $this->admin))->toThrow(WorkflowException::class, 'Not enough stock');

    expect($product->fresh()->stock)->toBe(5)->and(StockMovement::count())->toBe(0);
});

// ------------------------------------------------------ 10. Sales Records → Demand Forecasting

it('IT-11 shows delivered sales as demand in Demand Forecasting', function () {
    $product = capProduct($this->team, ['price' => 100, 'stock' => 30]);
    [$forecaster, $cookies] = capActor('staff_forecast', $this->team);
    $forecast = fn () => $this->actingAs($forecaster)->withCookies($cookies)->get("{$this->url}/forecast?product_id={$product->id}")->assertOk()->viewData('page')['props'];

    $before = $forecast();
    expect($before['forecastData']['hasData'])->toBeFalse();

    capDeliverAll($this, capConfirmed($this->team, $this->admin, [[$product, 7]]));

    $after = $forecast();
    expect($after['forecastData']['hasData'])->toBeTrue()
        ->and(last($after['forecastData']['historical']))->toBe(7)     // this month's demand
        ->and(last($after['forecastData']['months']))->toBe(now()->format('Y-m'))
        ->and($after['forecastData']['sourceRecordCount'])->toBe(2)   // the sales order line and its stock movement
        ->and($after['productWindow']['stock'])->toBe(23);
});

// --------------------------------------------- 11. Transactions → Reporting and Analytics

it('IT-12 carries sales, inventory and quotation transactions into Reports and Analytics', function () {
    $product = capProduct($this->team, ['price' => 100, 'cost_price' => 60, 'stock' => 10, 'reorder_level' => 2, 'category' => 'Breakers']);
    [$reporter, $cookies] = capActor('staff_reports', $this->team);
    $report = fn () => $this->actingAs($reporter)->withCookies($cookies)->get("{$this->url}/reports")->assertOk()->viewData('page')['props'];

    $before = $report();
    expect($before['metrics']['salesCount'])->toBe(0)->and((float) $before['metrics']['totalValuation'])->toBe(600.0);

    $sale = capConfirmed($this->team, $this->admin, [[$product, 4]]); // 448.00
    capDeliverAll($this, $sale);
    capOrder($this->team, $this->sales, [[$product, 1]]);             // pending payment: not a sale yet

    $after = $report();
    expect($after['metrics']['salesCount'])->toBe(1)
        ->and((float) $after['metrics']['totalRevenue'])->toBe(448.0)
        ->and((float) $after['metrics']['totalValuation'])->toBe(360.0) // 6 left × 60.00
        ->and($after['metrics']['quotationsCount'])->toBe(2)
        ->and($after['quotationsSalesAnalytics']['conversion'])->toMatchArray(['total' => 2, 'converted' => 2])
        ->and($after['quotationsSalesAnalytics']['topSellingProducts'][0])->toMatchArray(['sku' => $product->sku, 'quantity' => 4])
        ->and(collect($after['inventoryAnalytics']['items'])->firstWhere('sku', $product->sku)['current_stock'])->toBe(6)
        ->and(collect($after['activityFeed']['items'])->pluck('module')->unique()->values()->all())->toContain('Sales');
});

// ---------------------------------------------- 12. Quotation Management → Email Integration

it('IT-13 emails an approved quotation to the customer with its PDF and records the email', function () {
    $quotation = capQuotation($this->team, QuotationStatus::Draft, [[capProduct($this->team), 2]], 'buyer@example.com');

    // Not approved yet: nothing is sent.
    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/send")->assertSessionHasErrors();
    expect(NotificationLog::count())->toBe(0);

    capAs($this, 'admin')->post("{$this->url}/quotations/{$quotation->id}/approve")->assertSessionHasNoErrors();
    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/send")->assertSessionHasNoErrors();

    $email = capEmails('quotation_sent')->sole();
    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Sent)
        ->and($quotation->sent_at)->not->toBeNull()
        ->and($email->recipient)->toBe('buyer@example.com')
        ->and($email->status)->toBe(EmailStatus::Sent)
        ->and($email->subject)->toContain($quotation->quotation_number)
        ->and($email->notifiable_id)->toBe($quotation->id);
    Http::assertNothingSent(); // the mail service is replaced in testing: no message left the machine
});

it('IT-14 keeps the quotation unsent and records the failure when the mail service fails', function () {
    app()->instance(ResendService::class, new class extends ResendService
    {
        public function sendCustomerEmail(string $toEmail, string $subject, string $html, string $kind, array $attachments = []): array
        {
            return ['success' => false, 'id' => null, 'error' => 'Mail server unreachable'];
        }
    });
    $quotation = capQuotation($this->team, QuotationStatus::Draft, [[capProduct($this->team), 2]]);
    capAs($this, 'admin')->post("{$this->url}/quotations/{$quotation->id}/approve")->assertSessionHasNoErrors();

    capAs($this, 'quoter')->post("{$this->url}/quotations/{$quotation->id}/send");

    $email = capEmails('quotation_sent')->sole();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft) // still approved, still not Sent
        ->and($quotation->fresh()->is_approved)->toBeTrue()
        ->and($quotation->fresh()->sent_at)->toBeNull()
        ->and($email->status)->toBe(EmailStatus::Failed)
        ->and($email->error)->toContain('Mail server unreachable')
        ->and(OrderFulfillment::count())->toBe(0);
});
