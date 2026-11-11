<?php

use App\Enums\DeliveryStatus;
use App\Enums\EmailStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Exceptions\WorkflowException;
use App\Models\DeliveryRelease;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomerEmails;
use App\Services\Delivery\FulfillmentService;
use App\Services\Inventory\StockLedger;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\ResendService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The integrated workflow: quotation → approval → sending → acceptance → Sales Order →
 * payments → confirmation (invoice, reservation, delivery record) → releases → completion.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
    [$this->admin, $this->adminCookies] = swActor('admin', $this->team);
    [$this->sales, $this->salesCookies] = swActor('staff_sales', $this->team);
    [$this->quoter, $this->quoterCookies] = swActor('staff_quotations', $this->team);
    [$this->driver, $this->driverCookies] = swActor('staff_deliveries', $this->team);
    [$this->warehouse, $this->warehouseCookies] = swActor('staff_inventory', $this->team);
});

function swActor(string $role, Team $team): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function swProduct(Team $team, int $stock, float $price = 100, string $sku = 'BRK-30A'): Product
{
    $supplier = Supplier::create(['team_id' => $team->id, 'name' => 'Volt Supply '.$sku, 'status' => 'Active']);

    return Product::create([
        'team_id' => $team->id, 'sku' => $sku, 'name' => 'Product '.$sku, 'price' => $price, 'cost_price' => round($price * 0.6, 2),
        'supplier_id' => $supplier->id, 'stock' => $stock, 'reorder_level' => 2, 'status' => 'In Stock', 'is_active' => true,
    ]);
}

/** A quotation with one line per [product, quantity]. */
function swQuotation(Team $team, QuotationStatus $status, array $lines, ?string $email = 'buyer@example.com'): Quotation
{
    $subtotal = collect($lines)->sum(fn ($l) => $l[0]->price * $l[1]);
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'contact_person' => 'Engr. Ana Reyes',
        'email' => $email, 'phone' => '09171234567', 'address' => '12 Site Rd, Pasig City',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
        'subtotal' => $subtotal, 'discount' => 0, 'tax_rate' => 12, 'tax_amount' => round($subtotal * 0.12, 2),
        'grand_total' => round($subtotal * 1.12, 2), 'status' => $status, 'terms_and_conditions' => 'Net 30.',
        // A priced Draft made by these helpers is already waiting for approval.
        'submitted_for_approval_at' => $status === QuotationStatus::Draft ? now() : null,
    ]);
    foreach ($lines as [$product, $qty]) {
        $quotation->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty,
            'unit_price' => $product->price, 'unit_cost' => $product->cost_price, 'supplier_id' => $product->supplier_id,
            'discount' => 0, 'subtotal' => $product->price * $qty,
        ]);
    }

    return $quotation;
}

/** Approved by an administrator, not sent yet (e.g. approved before the email address was added). */
function swApproved(Team $team, array $lines, ?string $email = 'buyer@example.com'): Quotation
{
    $quotation = swQuotation($team, QuotationStatus::Draft, $lines, $email);
    $quotation->update(['approved_at' => now(), 'approved_by_id' => User::where('role', 'admin')->value('id')]);

    return $quotation->fresh();
}

/** Accepted quotation → Sales Order (pending payment). */
function swOrder(Team $team, User $user, array $lines, string $type = 'delivery'): Sale
{
    $quotation = swQuotation($team, QuotationStatus::Accepted, $lines);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $user, ['fulfillment_type' => $type]);

    return $sale->fresh();
}

/** Record and verify a payment. */
function swPay(Sale $sale, User $user, float $amount, string $reference): PaymentReceipt
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'Bank Transfer', 'amount' => $amount, 'reference' => $reference, 'payment_date' => now()->toDateString()], null, $user);

    return $payments->verify($payment, ['reference' => $reference, 'amount' => $amount, 'payment_date' => now()->toDateString()], $user);
}

/** A confirmed (fully paid) order. */
function swConfirmed(Team $team, User $admin, array $lines, string $type = 'delivery'): Sale
{
    $sale = swOrder($team, $admin, $lines, $type);
    swPay($sale, $admin, (float) $sale->grand_total, 'REF-'.uniqid());

    return $sale->fresh();
}

function swFailingMailer(): void
{
    app()->instance(ResendService::class, new class extends ResendService
    {
        public function sendCustomerEmail(string $toEmail, string $subject, string $html, string $kind, array $attachments = []): array
        {
            return ['success' => false, 'id' => null, 'error' => 'Mail server unreachable'];
        }
    });
}

function swEmails(string $type): Collection
{
    return NotificationLog::where('type', $type)->get();
}

// =============================================================================== quotations

it('dates a new quotation itself, stores the fixed terms, and sets Valid Until only on approval', function () {
    $product = swProduct($this->team, 50, 250);
    $url = "/{$this->team->slug}/quotations";
    $line = ['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 250];

    // Dates sent by the browser are ignored; no VAT unless staff enter one.
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post($url, [
        'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com',
        'quotation_date' => '2020-01-01', 'valid_until' => '2020-01-31', 'status' => 'draft',
        'terms_and_conditions' => 'Anything typed here', 'items' => [$line],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::sole();
    expect($quotation->quotation_date->toDateString())->toBe(now()->toDateString())
        ->and($quotation->valid_until)->toBeNull()
        ->and((float) $quotation->tax_rate)->toBe(0.0)
        ->and((float) $quotation->grand_total)->toBe(500.0)
        ->and($quotation->terms_and_conditions)->toStartWith("TERMS AND CONDITIONS\n1. 100% ADVANCE PAYMENT")
        ->and($quotation->terms_and_conditions)->toContain('ACCOUNT NO.: 100952000262')->not->toContain('Anything typed here');

    app(QuotationWorkflow::class)->submitForApproval($quotation, $this->quoter);
    app(QuotationWorkflow::class)->approve($quotation->fresh(), $this->admin);
    expect($quotation->fresh()->valid_until->toDateString())->toBe(now()->addDays(3)->toDateString());

    // Editing clears the approval, and with it the validity date, until it is approved again.
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->put("{$url}/{$quotation->id}", [
        'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com', 'status' => 'draft', 'items' => [$line],
    ])->assertSessionHasNoErrors();
    expect($quotation->fresh()->valid_until)->toBeNull()
        ->and($quotation->fresh()->quotation_date->toDateString())->toBe(now()->toDateString());
});

it('explains why an unpriced quotation cannot be submitted, and still lets staff reject it', function () {
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[swProduct($this->team, 5), 1]]);
    $quotation->items()->update(['unit_price' => 0, 'product_name' => '4 SIEMENS CONTACTOR 45A']);
    $quotation->update(['submitted_for_approval_at' => null]); // a fresh Draft, not submitted yet
    $url = "/{$this->team->slug}/quotations/{$quotation->id}/status";
    $as = fn () => $this->actingAs($this->quoter)->withCookies($this->quoterCookies);

    $as()->patch($url, ['status' => 'submit_for_approval'])->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain('Set a selling price for every line first')->toContain('4 SIEMENS CONTACTOR 45A')
        ->and($quotation->fresh()->submitted_for_approval_at)->toBeNull();

    $as()->patch($url, ['status' => 'rejected', 'remarks' => 'Customer cancelled'])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
});

it('keeps the supplier cost and supplier of each line when staff prepare a quotation (5)', function () {
    $product = swProduct($this->team, 50, 250);

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations", [
        'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
        'status' => 'draft',
        'items' => [['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 4, 'unit_price' => 250]],
    ])->assertSessionHasNoErrors();

    $item = Quotation::sole()->items->sole();
    expect(Quotation::sole()->status)->toBe(QuotationStatus::Draft)
        ->and((float) $item->unit_cost)->toBe(150.0)
        ->and($item->supplier_id)->toBe($product->supplier_id);

    // A later supplier price change does not touch the issued quotation.
    $product->update(['cost_price' => 199]);
    expect((float) $item->fresh()->unit_cost)->toBe(150.0);
});

it('never shows supplier costs on customer documents or emails (6)', function () {
    $product = swProduct($this->team, 50, 250);
    $quotation = swApproved($this->team, [[$product, 2]]);
    $quotation->items()->update(['unit_cost' => 123.45]);

    $html = view('documents.quotation', ['quotation' => $quotation->fresh(), 'company' => config('company'), 'title' => 'x'])->render();
    $email = app(CustomerEmails::class)->build('quotation_sent', $quotation->fresh());

    expect($html)->not->toContain('123.45')->and($html)->toContain('250.00')
        ->and($email['html'])->not->toContain('123.45')
        ->and($email['attachments'])->toHaveCount(1);
});

it('lets only administrators approve; approving records who and when, and emails nothing (7, 9)', function () {
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[swProduct($this->team, 5), 1]]);
    $url = "/{$this->team->slug}/quotations/{$quotation->id}/approve";

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post($url)->assertForbidden();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)->and(NotificationLog::count())->toBe(0);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post($url)->assertSessionHasNoErrors();
    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->is_approved)->toBeTrue()
        ->and($quotation->approved_by_id)->toBe($this->admin->id)
        ->and($quotation->approved_at)->not->toBeNull()
        ->and(NotificationLog::count())->toBe(0);
});

it('refuses to send a quotation without an email address, and sends it once one is added', function () {
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[swProduct($this->team, 5), 1]], email: null);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/approve")
        ->assertSessionHasNoErrors();
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/send")
        ->assertSessionHasErrors('email');
    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->is_approved)->toBeTrue()
        ->and(NotificationLog::count())->toBe(0);

    $quotation->update(['email' => 'late@example.com']);
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/send")->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent)->and(swEmails('quotation_sent')->sole()->recipient)->toBe('late@example.com');
});

it('refuses to send a quotation that is not approved (8)', function () {
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[swProduct($this->team, 5), 1]]);

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/send")
        ->assertSessionHasErrors(['error' => 'Only approved quotations can be sent to the customer.']);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)->and(NotificationLog::count())->toBe(0);
});

it('emails an approved quotation with its PDF and records the email status (9)', function () {
    $quotation = swApproved($this->team, [[swProduct($this->team, 5), 1]]);

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/send")->assertSessionHasNoErrors();

    $quotation->refresh();
    $log = swEmails('quotation_sent')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Sent)
        ->and($quotation->email_status)->toBe(EmailStatus::Sent) // accepted by the mail service, not "delivered"
        ->and($quotation->sent_by_id)->toBe($this->quoter->id)
        ->and($log->status)->toBe(EmailStatus::Sent)->and($log->recipient)->toBe('buyer@example.com')
        ->and(Storage::disk('local')->exists($quotation->sent_pdf_path))->toBeTrue();
});

it('records a failed quotation email and leaves the quotation approved, not Sent (10, 48)', function () {
    swFailingMailer();
    $quotation = swApproved($this->team, [[swProduct($this->team, 5), 1]]);

    app(QuotationWorkflow::class)->send($quotation, $this->quoter);

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->is_approved)->toBeTrue()
        ->and($quotation->email_status)->toBe(EmailStatus::Failed)
        ->and(swEmails('quotation_sent')->sole()->error)->toContain('Mail server unreachable');
});

it('records the customer acceptance or rejection with source and staff (11)', function () {
    $product = swProduct($this->team, 5);
    $accepted = swQuotation($this->team, QuotationStatus::Sent, [[$product, 1]]);
    $rejected = swQuotation($this->team, QuotationStatus::Sent, [[$product, 1]]);

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)
        ->post("/{$this->team->slug}/quotations/{$accepted->id}/decision", ['decision' => 'accepted', 'source' => 'email', 'remarks' => 'PO attached'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)
        ->post("/{$this->team->slug}/quotations/{$rejected->id}/decision", ['decision' => 'rejected', 'source' => 'phone'])
        ->assertSessionHasNoErrors();

    $accepted->refresh();
    expect($accepted->status)->toBe(QuotationStatus::Accepted)
        ->and($accepted->customer_decision_source)->toBe('email')
        ->and($accepted->customer_decision_by_id)->toBe($this->quoter->id)
        ->and($rejected->fresh()->status)->toBe(QuotationStatus::Rejected)
        // The acceptance does not create the Sales Order: staff create it in Sales → New Sales Order.
        ->and($accepted->converted_to_sale_id)->toBeNull()
        ->and(Sale::count())->toBe(0)
        // Neither "quotation accepted" nor "sales order created" is emailed (removed 2026-11-09).
        ->and(swEmails('quotation_accepted'))->toHaveCount(0)
        ->and(swEmails('sales_order_created'))->toHaveCount(0);
});

it('converts only accepted quotations (12)', function (QuotationStatus $status) {
    $quotation = swQuotation($this->team, $status, [[swProduct($this->team, 5), 1]]);

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/convert-to-sale")
        ->assertSessionHasErrors('error');
    expect(Sale::count())->toBe(0);
})->with([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Rejected]);

it('converts an accepted quotation once into a pending-payment Sales Order with the same details (13, 14, 15, 17)', function () {
    $product = swProduct($this->team, 20, 100);
    $quotation = swQuotation($this->team, QuotationStatus::Accepted, [[$product, 3]]);
    $url = "/{$this->team->slug}/quotations/{$quotation->id}/convert-to-sale";

    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post($url, ['fulfillment_type' => 'delivery'])->assertSessionHasNoErrors();
    $this->actingAs($this->quoter)->withCookies($this->quoterCookies)->post($url)->assertSessionHasErrors(['error' => 'This quotation was already converted to a Sales Order.']);

    $sale = Sale::with('items')->sole();
    $quotation->refresh();
    expect($sale->order_number)->toStartWith('SO-')
        ->and($sale->quotation_id)->toBe($quotation->id)
        ->and($sale->client_name)->toBe('Metro Builders Inc.')->and($sale->email)->toBe('buyer@example.com')
        ->and($sale->address)->toBe('12 Site Rd, Pasig City')
        ->and((float) $sale->grand_total)->toBe((float) $quotation->grand_total)
        ->and((float) $sale->tax_amount)->toBe((float) $quotation->tax_amount)
        ->and($sale->items->sole()->quantity)->toBe(3)->and((float) $sale->items->sole()->unit_price)->toBe(100.0)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($sale->invoice_number)->toBeNull()
        ->and($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->converted_by_id)->toBe($this->quoter->id)
        // Nothing reserved, nothing taken out, no delivery record yet.
        ->and($product->fresh()->stock)->toBe(20)
        ->and($sale->items->sole()->reserved_quantity)->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($sale->fulfillment)->toBeNull();
});

it('does not email the customer when a Sales Order is created (16)', function () {
    $sale = swOrder($this->team, $this->quoter, [[swProduct($this->team, 5), 1]]);

    expect(swEmails('sales_order_created'))->toHaveCount(0)
        ->and(NotificationLog::where('notifiable_type', 'Sale')->where('notifiable_id', $sale->id)->count())->toBe(0);

    // Emails of this type sent before the change can still be shown and resent from the history.
    $mail = app(CustomerEmails::class)->build('sales_order_created', $sale);
    expect($mail['subject'])->toBe("Your Sales Order Has Been Created – {$sale->order_number}")
        ->and($mail['attachments'])->toHaveCount(2);
});

// ================================================================================= payments

it('records a submitted payment with proof without verifying it, and without a receipt (18, 19, 25)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 5, 100), 2]]);

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", [
        'method' => 'GCash', 'amount' => 224, 'reference' => 'GC-778899', 'payment_date' => now()->toDateString(),
        'proof' => UploadedFile::fake()->image('gcash.png', 300, 600),
    ])->assertSessionHasNoErrors();

    $payment = Payment::sole();
    $sale->refresh();
    expect($payment->status)->toBe(PaymentRecordStatus::Submitted)
        ->and($payment->recorded_by_id)->toBe($this->sales->id)
        ->and(Storage::disk('local')->exists($payment->proof_path))->toBeTrue()
        ->and($sale->payment_status)->toBe(PaymentStatus::PaymentSubmitted)
        ->and((float) $sale->amount_paid)->toBe(0.0)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and(PaymentReceipt::count())->toBe(0)
        ->and(swEmails('payment_submitted'))->toHaveCount(0);
});

it('verifies a payment against the actual transaction, issues a receipt and confirms the paid order (20, 27, 28, 30, 31)', function () {
    $product = swProduct($this->team, 10, 100);
    $sale = swOrder($this->team, $this->admin, [[$product, 2]]);
    $payment = app(PaymentService::class)->record($sale, ['method' => 'Bank Transfer', 'amount' => 224, 'reference' => 'bdo 1234', 'payment_date' => now()->toDateString()], null, $this->sales);

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments/{$payment->id}/verify", [
        'reference' => 'BDO-1234-REAL', 'amount' => 224, 'payment_date' => now()->toDateString(), 'remarks' => 'Seen in bank statement',
    ])->assertSessionHasNoErrors();

    $sale->refresh();
    $payment->refresh();
    $receipt = PaymentReceipt::sole();
    expect($payment->status)->toBe(PaymentRecordStatus::Verified)
        ->and($payment->submitted_reference)->toBe('bdo 1234') // what the customer gave is kept
        ->and($payment->verified_reference)->toBe('BDO-1234-REAL')
        ->and($payment->verified_by_id)->toBe($this->sales->id)
        ->and((float) $receipt->amount_received)->toBe(224.0)->and((float) $receipt->balance_after)->toBe(0.0)
        ->and(Storage::disk('local')->exists($receipt->fresh()->pdf_path))->toBeTrue()
        ->and($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($sale->confirmed_at)->not->toBeNull()
        ->and(SalesInvoice::where('sale_id', $sale->id)->count())->toBe(1)
        ->and($sale->invoice_number)->toBe(SalesInvoice::sole()->invoice_number)
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Pending)
        ->and($sale->fulfillment->attemptLogs()->sole()->new_status)->toBe('pending')
        // Confirmation reserves stock; nothing is taken out yet.
        ->and($sale->items->sole()->reserved_quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10)
        ->and($product->fresh()->availableStock())->toBe(8)
        ->and(swEmails('payment_verified')->sole()->status)->toBe(EmailStatus::Sent)
        ->and(swEmails('order_confirmed'))->toHaveCount(1);

    // Confirming again (e.g. a retried request) does not issue a second invoice.
    app(SalesOrderService::class)->confirm($sale, $this->admin);
    expect(SalesInvoice::count())->toBe(1);
});

it('rejects an invalid payment and keeps it in the history (21)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 5), 1]]);
    $payment = app(PaymentService::class)->record($sale, ['method' => 'GCash', 'amount' => 50, 'reference' => 'FAKE', 'payment_date' => now()->toDateString()], null, $this->sales);

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments/{$payment->id}/reject", ['reason' => 'No matching GCash transaction'])
        ->assertSessionHasNoErrors();

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Rejected)
        ->and($payment->fresh()->rejection_reason)->toBe('No matching GCash transaction')
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and(Payment::count())->toBe(1)
        ->and(swEmails('payment_rejected'))->toHaveCount(1);

    // A rejected payment cannot be verified afterwards.
    expect(fn () => app(PaymentService::class)->verify($payment->fresh(), ['reference' => 'X', 'amount' => 50, 'payment_date' => now()->toDateString()], $this->sales))
        ->toThrow(WorkflowException::class);
});

it('handles several partial payments with receipts for the actual amounts and an approved down payment (22, 23, 26, 28)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10, 500), 2]]); // 1120.00
    app(SalesOrderService::class)->setTerms($sale, Sale::TERMS_DOWN_PAYMENT, 500, null, $this->admin);

    $first = swPay($sale->fresh(), $this->sales, 300, 'DP-1');
    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Pending) // below the agreed down payment
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);

    $second = swPay($sale->fresh(), $this->sales, 200, 'DP-2');
    $sale->refresh();
    expect((float) $first->amount_received)->toBe(300.0)
        ->and((float) $second->amount_received)->toBe(200.0)
        ->and((float) $second->previously_paid)->toBe(300.0)
        ->and((float) $second->balance_after)->toBe(620.0)
        ->and((float) $sale->amount_paid)->toBe(500.0)
        ->and((float) $sale->outstanding_balance)->toBe(620.0)
        ->and($sale->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($sale->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});

it('refuses to count the same verified transaction reference twice, and over-payments (24)', function () {
    $saleA = swOrder($this->team, $this->admin, [[swProduct($this->team, 10, 100, 'A1'), 1]]);
    $saleB = swOrder($this->team, $this->admin, [[swProduct($this->team, 10, 100, 'B1'), 1]]);
    swPay($saleA, $this->sales, 112, 'BDO-555');

    $payment = app(PaymentService::class)->record($saleB, ['method' => 'Bank Transfer', 'amount' => 112, 'reference' => 'bdo 555', 'payment_date' => now()->toDateString()], null, $this->sales);
    expect(fn () => app(PaymentService::class)->verify($payment, ['reference' => 'bdo-555', 'amount' => 112, 'payment_date' => now()->toDateString()], $this->sales))
        ->toThrow(WorkflowException::class, 'was already verified for another payment');
    expect(fn () => app(PaymentService::class)->verify($payment, ['reference' => 'BDO-999', 'amount' => 500, 'payment_date' => now()->toDateString()], $this->sales))
        ->toThrow(WorkflowException::class, 'more than the remaining balance');
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Submitted);
});

it('ignores a payment form submitted twice', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 5), 1]]);
    $data = ['method' => 'GCash', 'amount' => 50, 'reference' => 'GC-1', 'payment_date' => now()->toDateString(), 'idempotency_key' => 'form-123'];

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", $data);
    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", $data);

    expect(Payment::count())->toBe(1);
});

it('confirms an approved credit-term order without marking it paid (29)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10), 2]]);

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/terms", ['payment_terms' => 'credit', 'credit_days' => 30])
        ->assertForbidden();
    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("/{$this->team->slug}/sales/{$sale->id}/terms", ['payment_terms' => 'credit', 'credit_days' => 30])
        ->assertSessionHasNoErrors();

    $sale->refresh();
    expect($sale->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($sale->terms_approved_by_id)->toBe($this->admin->id)
        ->and($sale->credit_due_date?->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Pending);
});

// ==================================================================== delivery and inventory

it('never takes stock during quotation creation, approval, sending or conversion (36)', function () {
    $product = swProduct($this->team, 10);
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[$product, 4]]);

    app(QuotationWorkflow::class)->approve($quotation, $this->admin);
    app(QuotationWorkflow::class)->send($quotation, $this->admin);
    app(QuotationWorkflow::class)->recordDecision($quotation->fresh(), true, 'email', null, $this->admin);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);

    expect($product->fresh()->stock)->toBe(10)->and($product->fresh()->availableStock())->toBe(10)->and(StockMovement::count())->toBe(0);
});

it('walks a delivery through preparation, dispatch and delivery, taking stock out once, on delivery (32, 33, 35, 38)', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 4]]);
    $fulfillment = $sale->fulfillment;
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);

    $release = $service->prepareRelease($fulfillment, [$line->id => 4], $this->warehouse);
    expect(fn () => $service->dispatch($release, $this->admin))->toThrow(WorkflowException::class, 'Mark the release ready'); // invalid jump
    $service->markReady($release, $this->warehouse);
    $fulfillment->update(['handled_by_id' => $this->driver->id]);

    // Preparing and dispatching take nothing out of stock; the 4 stay reserved while on the road.
    expect($product->fresh()->stock)->toBe(10)->and(StockMovement::count())->toBe(0);
    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post("/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}/start")->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(10)->and($product->fresh()->availableStock())->toBe(6)
        ->and($fulfillment->fresh()->status)->toBe(DeliveryStatus::InTransit)->and(StockMovement::count())->toBe(0);

    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post("/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}/deliver", [
        'proof_of_delivery' => UploadedFile::fake()->image('dr.jpg', 400, 400), 'recipient_name' => 'Site Engr. Lito',
    ])->assertSessionHasNoErrors();

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe(DeliveryStatus::Delivered)
        ->and($line->fresh()->delivered_quantity)->toBe(4)
        ->and($line->fresh()->reserved_quantity)->toBe(0)
        ->and($product->fresh()->stock)->toBe(6)
        ->and(StockMovement::where('delivery_release_id', $release->id)->sole()->only(['type', 'quantity', 'previous_stock', 'new_stock']))
        ->toBe(['type' => 'sale_release', 'quantity' => -4, 'previous_stock' => 10, 'new_stock' => 6])
        ->and(NotificationLog::where('type', 'delivery_status')->pluck('context')->pluck('status')->all())
        ->toBe(['ready_for_delivery', 'in_transit', 'delivered']);

    // Taking it out again for the same release changes nothing.
    app(StockLedger::class)->deductDelivered($release->fresh(), $this->admin);
    expect($product->fresh()->stock)->toBe(6)->and(StockMovement::where('delivery_release_id', $release->id)->count())->toBe(1);

    // Everything delivered and paid: the order completed itself, and the customer was told.
    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Completed)
        ->and($fulfillment->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and(swEmails('order_completed'))->toHaveCount(1);
});

it('tracks partial deliveries over several releases and only completes when nothing is left (39, 40, 41, 42, 46)', function () {
    $wire = swProduct($this->team, 200, 2, 'WIRE');
    $breaker = swProduct($this->team, 20, 30, 'CB');
    $conduit = swProduct($this->team, 50, 5, 'PVC');
    $sale = swConfirmed($this->team, $this->admin, [[$wire, 100], [$breaker, 20], [$conduit, 50]]);
    $lines = $sale->items->keyBy('sku');
    $service = app(FulfillmentService::class);
    $fulfillment = $sale->fulfillment;
    $fulfillment->update(['handled_by_id' => $this->driver->id]);

    // First release: all the wire and 10 breakers.
    $first = $service->prepareRelease($fulfillment, [$lines['WIRE']->id => 100, $lines['CB']->id => 10], $this->warehouse);
    $service->markReady($first, $this->warehouse);
    $service->dispatch($first, $this->driver);
    $status = $service->recordReceipt($first, [], 'Site Engr. Lito', null, null, $this->driver);

    expect($status)->toBe(DeliveryStatus::Pending) // part delivered: back to Preparing, never Delivered
        ->and($lines['CB']->fresh()->remaining_quantity)->toBe(10)
        ->and($lines['PVC']->fresh()->remaining_quantity)->toBe(50)
        ->and($lines['WIRE']->fresh()->remaining_quantity)->toBe(0)
        ->and($breaker->fresh()->stock)->toBe(10)->and($conduit->fresh()->stock)->toBe(50)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Processing)
        ->and(NotificationLog::where('type', 'delivery_status')->get()->last()->context)->toMatchArray(['status' => 'delivered', 'partial' => true])
        ->and(fn () => app(SalesOrderService::class)->complete($sale->fresh(), $this->admin))->toThrow(WorkflowException::class, 'Not everything has been delivered');

    // Cannot release more than what is still owed.
    expect(fn () => $service->prepareRelease($fulfillment->fresh(), [$lines['CB']->id => 11], $this->warehouse))->toThrow(WorkflowException::class, 'only 10 can still be released');

    $second = $service->prepareRelease($fulfillment->fresh(), [$lines['CB']->id => 10, $lines['PVC']->id => 50], $this->warehouse);
    $service->markReady($second, $this->warehouse);
    $service->dispatch($second, $this->driver);
    expect($service->recordReceipt($second, [], 'Site Engr. Lito', null, null, $this->driver))->toBe(DeliveryStatus::Delivered);

    expect(DeliveryRelease::where('sale_id', $sale->id)->count())->toBe(2)
        ->and($first->fresh()->items->sum('delivered_quantity'))->toBe(110) // earlier release kept as it was
        ->and(StockMovement::where('sale_id', $sale->id)->sum('quantity'))->toBe(-170);
});

it('takes out only what the customer received; the rest stays in stock, held for the order (43, 44)', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);

    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    expect($product->fresh()->stock)->toBe(10);

    // Customer accepted 3, refused 2: only 3 leave stock; the 2 stay reserved.
    $service->recordReceipt($release, [$line->id => 3], 'Receiver', null, 'Two damaged boxes refused', $this->admin);
    expect($product->fresh()->stock)->toBe(7)
        ->and($line->fresh()->delivered_quantity)->toBe(3)
        ->and($line->fresh()->deducted_quantity)->toBe(3)
        ->and($line->fresh()->reserved_quantity)->toBe(2)
        ->and($product->fresh()->availableStock())->toBe(5)
        ->and($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::Pending);

    // A whole trip returned: nothing left stock, so nothing comes back.
    $again = $service->prepareRelease($sale->fulfillment->fresh(), [$line->id => 2], $this->admin);
    $service->markReady($again, $this->admin);
    $service->dispatch($again, $this->admin);
    $service->returnRelease($again, 'Site closed', $this->admin);
    expect($product->fresh()->stock)->toBe(7)
        ->and($line->fresh()->reserved_quantity)->toBe(2)
        ->and($again->fresh()->status)->toBe(DeliveryRelease::RETURNED)
        ->and($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::Pending)
        ->and(StockMovement::where('type', 'return')->count())->toBe(0)
        ->and(StockMovement::where('sale_id', $sale->id)->sum('quantity'))->toBe(-3);
});

it('keeps units on the road reserved when the rest of the order is cancelled', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 6]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);

    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 4], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);

    // The 2 not on the truck are cancelled: only they are freed.
    app(SalesOrderService::class)->cancelRemaining($sale->fresh(), null, 'Customer reduced the order', $this->admin);
    expect($line->fresh()->reserved_quantity)->toBe(4)->and($product->fresh()->availableStock())->toBe(6)->and($product->fresh()->stock)->toBe(10);

    $service->recordReceipt($release->fresh(), [], 'Receiver', null, null, $this->admin);
    expect($product->fresh()->stock)->toBe(6)->and($line->fresh()->reserved_quantity)->toBe(0)->and($product->fresh()->availableStock())->toBe(6);
});

it('never lets a manual Stock Out take units reserved for a sales order', function () {
    $product = swProduct($this->team, 10);
    swConfirmed($this->team, $this->admin, [[$product, 8]]);
    $url = "/{$this->team->slug}/inventory/stock-out";
    $as = fn () => $this->actingAs($this->admin)->withCookies($this->adminCookies);

    $as()->post($url, ['product_id' => $product->id, 'quantity' => 3, 'reason' => 'Damaged / Write-off'])->assertSessionHasErrors('quantity');
    $as()->post($url, ['product_id' => $product->id, 'quantity' => 2, 'reason' => 'Damaged / Write-off'])->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(8)->and($product->fresh()->availableStock())->toBe(0);
});

it('settles a release dispatched under the old rule (stock taken at dispatch) without double counting', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 5]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 5], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);

    // As the old code left it: 5 already taken out at dispatch.
    $item = $release->items()->sole();
    $item->update(['deducted_quantity' => 5]);
    $line->fresh()->forceFill(['deducted_quantity' => 5, 'reserved_quantity' => 0])->save();
    $product->update(['stock' => 5]);
    StockMovement::create(['team_id' => $this->team->id, 'product_id' => $product->id, 'sale_id' => $sale->id, 'delivery_release_id' => $release->id,
        'movement_key' => "release:{$release->id}:item:{$item->id}:out", 'type' => 'sale_release', 'quantity' => -5, 'previous_stock' => 10, 'new_stock' => 5, 'movement_date' => now()]);

    // Customer received 3: the 2 not received go back; nothing is taken out twice.
    $service->recordReceipt($release->fresh(), [$line->id => 3], 'Receiver', null, null, $this->admin);
    expect($product->fresh()->stock)->toBe(7)
        ->and($line->fresh()->deducted_quantity)->toBe(3)
        ->and($line->fresh()->reserved_quantity)->toBe(2)
        ->and(StockMovement::where('sale_id', $sale->id)->sum('quantity'))->toBe(-3);
});

it('enforces the 3-attempt limit until an administrator reschedules (32)', function () {
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 1], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    $fulfillment = $sale->fulfillment->fresh();

    foreach (range(1, 3) as $n) {
        $service->recordFailedAttempt($fulfillment->fresh(), 'Customer unavailable', 'Gate closed', null, $this->admin);
        if ($n < 3) {
            $service->retryDispatch($fulfillment->fresh(), $this->admin);
        }
    }
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery)
        ->and(fn () => $service->retryDispatch($fulfillment->fresh(), $this->admin))->toThrow(WorkflowException::class, '3-attempt limit');

    $service->reschedule($fulfillment->fresh(), now()->addDay()->toDateTimeString(), true, 'Customer asked for Monday', null, $this->admin);
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery);
    $service->retryDispatch($fulfillment->fresh(), $this->admin);
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::InTransit)->and($fulfillment->fresh()->attempt_count)->toBe(0)
        // Ready (1), rescheduled (1), and each failed attempt (3), which is emailed as "could not deliver".
        ->and(NotificationLog::where('type', 'delivery_status')->get()->pluck('context')->filter(fn ($c) => $c['status'] === 'ready_for_delivery')->count())->toBe(5)
        ->and(NotificationLog::where('type', 'delivery_status')->get()->pluck('context')->filter(fn ($c) => ! empty($c['attempt_failed']))->count())->toBe(3);
});

it('prevents overselling between orders through reservations (37)', function () {
    $product = swProduct($this->team, 10);
    $first = swConfirmed($this->team, $this->admin, [[$product, 8]]);
    $second = swConfirmed($this->team, $this->admin, [[$product, 5]]);

    expect($first->items->sole()->reserved_quantity)->toBe(8)
        ->and($second->items->sole()->reserved_quantity)->toBe(2) // only 2 were still free
        ->and($product->fresh()->availableStock())->toBe(0);

    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($second->fulfillment, [$second->items->sole()->id => 5], $this->admin);
    $service->markReady($release, $this->admin);
    expect(fn () => $service->dispatch($release, $this->admin))->toThrow(WorkflowException::class, 'Not enough stock');
    expect($product->fresh()->stock)->toBe(10);
});

it('handles pickup orders without trip statuses or out-for-delivery emails (45)', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 2]], 'pickup');
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 2], $this->warehouse);
    $service->markReady($release, $this->warehouse);

    expect(fn () => $service->dispatch($release, $this->admin))->toThrow(WorkflowException::class, 'Pickup orders are handed over');

    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)->post("/{$this->team->slug}/deliveries/fulfillments/{$sale->fulfillment->id}/deliver", ['recipient_name' => 'Juan (driver of customer)'])
        ->assertSessionHasNoErrors();

    $statuses = NotificationLog::where('type', 'delivery_status')->get()->pluck('context.status')->all();
    expect($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($release->fresh()->received_by_name)->toBe('Juan (driver of customer)')
        ->and($product->fresh()->stock)->toBe(8)
        ->and($statuses)->toBe(['ready_for_delivery', 'delivered'])
        ->and(app(CustomerEmails::class)->build('delivery_status', $sale->fulfillment->fresh(), ['status' => 'ready_for_delivery'])['subject'])->toContain('ready for pickup');
});

it('cancels an order before release: reservations freed, invoice cancelled, customer told (43)', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 4]]);

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->post("/{$this->team->slug}/sales/{$sale->id}/cancel", ['reason' => 'Project on hold'])->assertSessionHasErrors('error'); // admins only
    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("/{$this->team->slug}/sales/{$sale->id}/cancel", ['reason' => 'Project on hold'])->assertSessionHasNoErrors();

    $sale->refresh();
    expect($sale->status)->toBe(SalesOrderStatus::Cancelled)
        ->and($sale->items->sole()->reserved_quantity)->toBe(0)
        ->and($product->fresh()->availableStock())->toBe(10)
        ->and(SalesInvoice::sole()->status)->toBe(SalesInvoice::CANCELLED)
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Cancelled)
        ->and(Payment::sole()->status)->toBe(PaymentRecordStatus::Verified) // the money is still on record
        ->and(swEmails('order_cancelled'))->toHaveCount(1);
});

it('completes an authorised credit-term order that still has a balance (47)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);
    app(SalesOrderService::class)->setTerms($sale, Sale::TERMS_CREDIT, null, 30, $this->admin);
    $sale->refresh();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 1], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    $service->recordReceipt($release, [], 'Receiver', null, null, $this->admin);

    $sale->refresh();
    expect($sale->status)->toBe(SalesOrderStatus::Completed) // completed automatically: credit terms allow the balance
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Delivered)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and((float) $sale->outstanding_balance)->toBe((float) $sale->grand_total);
});

it('refuses to complete a full-payment order with a balance, or one with an open delivery problem (46)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);
    expect(fn () => app(SalesOrderService::class)->complete($sale, $this->admin))->toThrow(WorkflowException::class);

    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10, 100, 'X2'), 1]]);
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 1], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    $service->recordFailedAttempt($sale->fulfillment->fresh(), 'Customer unavailable', 'No one home', null, $this->admin);
    expect(fn () => app(SalesOrderService::class)->complete($sale->fresh(), $this->admin))->toThrow(WorkflowException::class);
});

it('cancels the remaining quantity so a partly delivered order can be closed', function () {
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 4]]);
    $line = $sale->items->sole();
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$line->id => 3], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    $service->recordReceipt($release, [], 'Receiver', null, null, $this->admin);

    app(SalesOrderService::class)->cancelRemaining($sale->fresh(), null, 'Item discontinued', $this->admin);

    expect($line->fresh()->cancelled_quantity)->toBe(1)
        ->and($line->fresh()->reserved_quantity)->toBe(0)
        ->and($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Completed)
        ->and(NotificationLog::where('type', 'delivery_status')->get()->last()->context)->toMatchArray(['status' => 'cancelled', 'remaining_cancelled' => true]);
});

it('keeps the order saved when its emails fail (48)', function () {
    swFailingMailer();
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);

    expect($sale->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(NotificationLog::where('status', 'failed')->count())->toBeGreaterThanOrEqual(2) // payment received, order confirmed
        ->and(Payment::sole()->status)->toBe(PaymentRecordStatus::Verified);
});

it('keeps sales and payment actions away from other modules (49)', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);
    $payment = app(PaymentService::class)->record($sale, ['method' => 'GCash', 'amount' => 10, 'reference' => 'R', 'payment_date' => now()->toDateString()], null, $this->sales);

    foreach ([[$this->warehouse, $this->warehouseCookies], [$this->driver, $this->driverCookies], [$this->quoter, $this->quoterCookies]] as [$user, $cookies]) {
        $this->actingAs($user)->withCookies($cookies)->get("/{$this->team->slug}/sales/{$sale->id}")->assertForbidden();
        $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments/{$payment->id}/verify", ['reference' => 'R', 'amount' => 10, 'payment_date' => now()->toDateString()])->assertForbidden();
    }
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Submitted);

    // Guests are sent to log in.
    auth()->logout();
    $this->post("/{$this->team->slug}/sales/{$sale->id}/payments", [])->assertRedirect();
});

// ======================================================================= pages render

it('renders the quotation, sales order and delivery pages with workflow data', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 3]]);
    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 2], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);
    $service->recordReceipt($release, [], 'Receiver', null, null, $this->admin);
    swQuotation($this->team, QuotationStatus::Draft, [[$product, 1]]);
    $slug = $this->team->slug;

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->get("/{$slug}/quotations")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quotations/AdminIndex')
            ->where('canApprove', true)
            ->has('quotations.data', 2)
            ->where('quotations.data.0.allowed_actions', fn ($actions) => collect($actions)->contains('approve')));

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->get("/{$slug}/sales?status=processing")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Sales/Index')->has('sales.data', 1)->where('sales.data.0.delivery_status_label', 'Pending'));

    $this->actingAs($this->sales)->withCookies($this->salesCookies)->get("/{$slug}/sales/{$sale->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Sales/Show')
            ->has('sale.payments', 1)->has('sale.invoices', 1)->has('sale.releases', 1)
            ->where('sale.items.0.remaining_quantity', 1)
            ->where('can.admin', false)
            ->has('activity')->has('notifications'));

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->get("/{$slug}/deliveries")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Deliveries/Index')
            ->where('outboundDeliveries.data.0.status', 'pending')
            ->where('outboundDeliveries.data.0.allowed_actions', fn ($a) => collect($a)->contains('prepare')));

    foreach (["/{$slug}/sales/{$sale->id}/pdf", "/{$slug}/sales/{$sale->id}/invoices/".SalesInvoice::sole()->id.'/pdf', "/{$slug}/sales/{$sale->id}/receipts/".PaymentReceipt::sole()->id.'/pdf', "/{$slug}/deliveries/releases/{$release->id}/pdf"] as $url) {
        $response = $this->actingAs($this->admin)->withCookies($this->adminCookies)->get($url)->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

// ================================================================== Update status button

it('lets the assigned delivery staff and admins move a delivery through Update status, with the same rules', function () {
    $product = swProduct($this->team, 10);
    $sale = swConfirmed($this->team, $this->admin, [[$product, 2]]);
    $fulfillment = $sale->fulfillment;
    $fulfillment->update(['handled_by_id' => $this->driver->id]);
    [$otherDriver, $otherCookies] = swActor('staff_deliveries', $this->team);
    $url = "/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}/status";
    $line = $sale->items->sole();

    // The warehouse prepares and marks ready; a driver who is not assigned cannot touch it.
    $this->actingAs($otherDriver)->withCookies($otherCookies)->post($url, ['action' => 'prepare', 'quantities' => [$line->id => 2]])->assertForbidden();
    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)->post($url, ['action' => 'prepare', 'quantities' => [$line->id => 2]])->assertSessionHasNoErrors();
    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)->post($url, ['action' => 'ready'])->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery);

    // Warehouse staff cannot dispatch (not the driver); the assigned driver can.
    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)->post($url, ['action' => 'dispatch'])->assertForbidden();
    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post($url, ['action' => 'dispatch'])->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::InTransit)->and($product->fresh()->stock)->toBe(10);

    // A failed attempt changes no stock.
    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post($url, ['action' => 'attempt', 'reason' => 'Customer unavailable', 'remarks' => 'Gate closed'])->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery)->and($product->fresh()->stock)->toBe(10);

    // Only an admin reschedules.
    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post($url, ['action' => 'reschedule', 'delivery_schedule' => now()->addDay()->toDateTimeString()])->assertForbidden();
    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post($url, ['action' => 'reschedule', 'delivery_schedule' => now()->addDay()->toDateTimeString(), 'reset_attempts' => 1])->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery);

    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post($url, ['action' => 'retry'])->assertSessionHasNoErrors();
    $this->actingAs($this->driver)->withCookies($this->driverCookies)->post($url, [
        'action' => 'deliver', 'recipient_name' => 'Engr. Ana', 'proof_of_delivery' => UploadedFile::fake()->image('dr.jpg'),
    ])->assertSessionHasNoErrors();

    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($sale->fresh()->status)->toBe(SalesOrderStatus::Completed)
        ->and($product->fresh()->stock)->toBe(8)
        // A status that does not apply any more is refused.
        ->and($this->actingAs($this->admin)->withCookies($this->adminCookies)->post($url, ['action' => 'dispatch'])->status())->toBe(403);
});

it('lets only an admin cancel a delivery, and emails the customer once', function () {
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 2]]);
    $url = "/{$this->team->slug}/deliveries/fulfillments/{$sale->fulfillment->id}/status";

    $this->actingAs($this->warehouse)->withCookies($this->warehouseCookies)->post($url, ['action' => 'cancel_delivery', 'reason' => 'Customer will collect elsewhere'])->assertForbidden();
    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post($url, ['action' => 'cancel_delivery', 'reason' => 'Customer will collect elsewhere'])->assertSessionHasNoErrors();

    expect($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::Cancelled)
        ->and($sale->items()->sole()->reserved_quantity)->toBe(0)
        ->and(NotificationLog::where('type', 'delivery_status')->get()->pluck('context.status')->all())->toBe(['cancelled']);
});

it('keeps the order status while it is on hold, and holds back confirmation and releases', function () {
    $sale = swOrder($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);
    app(SalesOrderService::class)->hold($sale, 'Checking the delivery site', $this->admin);
    swPay($sale->fresh(), $this->sales, (float) $sale->grand_total, 'HOLD-1');

    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Pending)->and($sale->fresh()->is_on_hold)->toBeTrue();

    app(SalesOrderService::class)->resume($sale->fresh(), $this->admin);
    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Confirmed)->and($sale->fresh()->is_on_hold)->toBeFalse();
});

// ================================================================= no duplicate records

it('keeps one delivery record per Sales Order, even if confirmation runs again', function () {
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 2]]);

    app(SalesOrderService::class)->confirm($sale->fresh(), $this->admin);
    app(FulfillmentService::class)->initialize($sale->fresh(), $this->admin);

    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($sale->fulfillment()->count())->toBe(1)
        ->and($sale->fulfillment->status)->toBe(DeliveryStatus::Pending)
        ->and($sale->fulfillment->attemptLogs()->count())->toBe(1);
});

it('lets the database refuse a second Sales Order for a quotation and a second delivery record for an order', function () {
    $sale = swConfirmed($this->team, $this->admin, [[swProduct($this->team, 10), 1]]);

    expect(fn () => Sale::create($sale->replicate(['order_number'])->toArray() + ['order_number' => 'SO-DUP']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $sale->fulfillment->replicate(['fulfillment_number'])->fill(['fulfillment_number' => 'FUL-DUP'])->save())->toThrow(UniqueConstraintViolationException::class);
});

it('needs a fresh approval after an approved Draft is edited', function () {
    $product = swProduct($this->team, 10);
    $quotation = swQuotation($this->team, QuotationStatus::Draft, [[$product, 1]], email: null);
    app(QuotationWorkflow::class)->approve($quotation, $this->admin);
    expect($quotation->fresh()->approved_at)->not->toBeNull();

    $this->actingAs($this->admin)->withCookies($this->adminCookies)->put("/{$this->team->slug}/quotations/{$quotation->id}", [
        'client_name' => 'Metro Builders Inc.', 'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
        'status' => 'submit_for_approval',
        'items' => [['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 300]],
    ])->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->approved_at)->toBeNull()
        ->and($quotation->submitted_for_approval_at)->not->toBeNull();
});
