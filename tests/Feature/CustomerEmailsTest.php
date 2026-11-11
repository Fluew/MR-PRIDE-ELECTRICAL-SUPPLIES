<?php

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Exceptions\WorkflowException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\ResendService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\Storage;

/**
 * Customer emails, one per business event: quotation sent (Send Quotation, not approval), order
 * confirmed (no invoice), commercial invoice (Send Commercial Invoice), out for delivery. ResendService is mocked, so nothing
 * leaves the machine; we record what would be sent.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->sent = collect();
    $this->sendOk = true;
    $this->mock(ResendService::class)
        ->shouldReceive('sendCustomerEmail')
        ->andReturnUsing(function ($to, $subject, $html, $kind, $attachments = []) {
            $this->sent->push(compact('to', 'subject', 'html', 'kind', 'attachments'));

            return $this->sendOk
                ? ['success' => true, 'id' => 'test-id', 'error' => null]
                : ['success' => false, 'id' => null, 'error' => 'Resend API error (403): not allowed'];
        });

    $this->team = Team::factory()->create(['slug' => 'mail-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password_changed_at' => now(), 'current_team_id' => $this->team->id]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
});

function mailQuotation(Team $team, array $overrides = []): Quotation
{
    $product = Product::create(['team_id' => $team->id, 'sku' => 'BRK-60A', 'name' => 'Circuit Breaker 60A', 'price' => 1000, 'stock' => 10, 'is_active' => true]);
    $quotation = Quotation::create($overrides + [
        'team_id' => $team->id,
        'quotation_number' => 'QT-2026-0001',
        'client_name' => 'Apex Builders',
        'contact_person' => 'Ana Reyes',
        'email' => 'ana@apex.example',
        'address' => '12 Rizal St, Manila',
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal' => 2000, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 2000,
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->create([
        'product_id' => $product->id, 'sku' => 'BRK-60A', 'product_name' => 'Circuit Breaker 60A', 'quantity' => 2, 'unit_price' => 1000, 'discount' => 0, 'subtotal' => 2000,
    ]);

    return $quotation;
}

// --- Quotation -----------------------------------------------------------------------------

it('emails the quotation PDF only when staff click Send Quotation, then marks it Sent', function () {
    $quotation = mailQuotation($this->team, ['submitted_for_approval_at' => now()]);
    $as = fn () => $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies']);

    // Approval alone emails nothing.
    $as()->post("/mail-team/quotations/{$quotation->id}/approve")->assertSessionHasNoErrors();
    expect($this->sent)->toHaveCount(0)->and($quotation->fresh()->status)->toBe(QuotationStatus::Draft);

    $as()->post("/mail-team/quotations/{$quotation->id}/send")->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'ana@apex.example') && str_contains($m, 'Sent'));

    expect($this->sent)->toHaveCount(1)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Sent)
        ->and($quotation->fresh()->email_status)->toBe(EmailStatus::Sent)
        ->and($quotation->fresh()->sent_at)->not->toBeNull();
    $mail = $this->sent->first();
    expect($mail['to'])->toBe('ana@apex.example')
        ->and($mail['subject'])->toContain('QT-2026-0001')
        ->and($mail['html'])->toContain('Circuit Breaker 60A')->toContain('$2,000.00')->toContain('Ana Reyes')
        ->and($mail['attachments'][0]['filename'])->toBe('Quotation-QT-2026-0001.pdf')
        ->and($mail['attachments'][0]['content'])->toStartWith('%PDF');
    expect(AuditLog::where('event', 'email.quotation_sent')->exists())->toBeTrue();
});

it('falls back to the linked client email when the quotation has none', function () {
    $client = Client::create(['team_id' => $this->team->id, 'company_name' => 'Apex Builders', 'email' => 'buyer@apex.example']);
    $quotation = mailQuotation($this->team, ['email' => null, 'client_id' => $client->id, 'status' => QuotationStatus::Draft, 'approved_at' => now()]);

    app(QuotationWorkflow::class)->send($quotation, $this->admin);

    expect($this->sent->first()['to'])->toBe('buyer@apex.example');
});

it('does not mark the quotation Sent when the email fails, and tells staff', function () {
    $this->sendOk = false;
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Draft, 'approved_at' => now()]);

    $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->post("/mail-team/quotations/{$quotation->id}/send")
        ->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain('could not be sent')->toContain('403');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->fresh()->is_approved)->toBeTrue()
        ->and($quotation->fresh()->sent_at)->toBeNull()
        ->and($quotation->fresh()->email_status)->toBe(EmailStatus::Failed);
    $log = AuditLog::where('event', 'email.quotation_sent')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('failed')
        ->and(NotificationLog::sole()->error)->toContain('403');
});

it('sends a quotation again only on an explicit resend, recording each attempt', function () {
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Draft, 'approved_at' => now()]);
    $as = fn () => $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies']);
    $url = "/mail-team/quotations/{$quotation->id}/send";

    $as()->post($url)->assertSessionHasNoErrors();
    $as()->post($url)->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain('already emailed')->toContain('Resend')
        ->and($this->sent)->toHaveCount(1);

    $as()->post($url, ['resend' => true])->assertSessionHasNoErrors();
    expect($this->sent)->toHaveCount(2)
        ->and(NotificationLog::where('type', 'quotation_sent')->where('status', 'sent')->count())->toBe(2)
        ->and(AuditLog::where('event', 'quotation.resend_requested')->count())->toBe(1);
});

it('refuses to send an incomplete quotation (a line without a price)', function () {
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Draft, 'approved_at' => now()]);
    $quotation->items()->update(['unit_price' => 0]);

    expect(fn () => app(QuotationWorkflow::class)->send($quotation, $this->admin))->toThrow(WorkflowException::class, 'selling price');
    expect($this->sent)->toHaveCount(0)->and(NotificationLog::count())->toBe(0);
});

it('escapes customer-entered text in the email', function () {
    $quotation = mailQuotation($this->team, ['contact_person' => '<script>alert(1)</script>', 'status' => QuotationStatus::Draft, 'approved_at' => now()]);
    app(QuotationWorkflow::class)->send($quotation, $this->admin);

    expect($this->sent->first()['html'])->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

// --- Order confirmed (with the invoice) and delivery notices --------------------------------

function mailConfirmedOrder(Team $team, User $admin, ?string $email = 'ana@apex.example'): Sale
{
    $quotation = mailQuotation($team, ['status' => QuotationStatus::Accepted, 'email' => $email]);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $admin);
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'GCash', 'amount' => 2000, 'reference' => 'GC-123', 'payment_date' => now()->toDateString()], null, $admin);
    $payments->verify($payment, ['reference' => 'GC-123', 'amount' => 2000, 'payment_date' => now()->toDateString()], $admin);

    return $sale->fresh();
}

it('confirms the order without sending the commercial invoice, which staff send with the button', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);

    $confirmation = $this->sent->first(fn ($m) => str_contains($m['subject'], 'Order Confirmed'));
    expect($confirmation)->not->toBeNull()
        ->and($confirmation['to'])->toBe('ana@apex.example')
        ->and($confirmation['attachments'])->toBe([])
        ->and($confirmation['html'])->not->toContain($sale->invoice_number);
    // Payment received (with receipt) and order confirmed: no Sales Order or invoice email, no duplicates.
    expect($this->sent->pluck('subject')->all())->toHaveCount(2)
        ->and($this->sent->filter(fn ($m) => str_contains($m['subject'], 'Commercial Invoice')))->toHaveCount(0)
        ->and($sale->fulfillment)->not->toBeNull(); // the Customer Delivery record exists, as designed

    // Staff click "Send Commercial Invoice".
    $invoice = $sale->invoices()->sole();
    $as = fn () => $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies']);
    $url = "/mail-team/sales/{$sale->id}/invoices/{$invoice->id}/send";
    $as()->post($url)->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, $invoice->invoice_number));

    $mail = $this->sent->last();
    expect($mail['subject'])->toBe("Commercial Invoice {$invoice->invoice_number} – Order {$sale->order_number}")
        ->and($mail['to'])->toBe('ana@apex.example')
        ->and($mail['attachments'][0]['filename'])->toBe("Invoice-{$invoice->invoice_number}.pdf")
        ->and($mail['attachments'][0]['content'])->toStartWith('%PDF');
    $log = NotificationLog::where('type', 'invoice_issued')->sole();
    expect($log->status)->toBe(EmailStatus::Sent)->and($log->sent_at)->not->toBeNull();

    // A second click is refused; an explicit Resend sends it again and is recorded too.
    $as()->post($url)->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain('already emailed');
    $as()->post($url, ['resend' => true])->assertSessionHasNoErrors();
    expect(NotificationLog::where('type', 'invoice_issued')->where('status', 'sent')->count())->toBe(2)
        ->and(AuditLog::where('event', 'email.invoice_issued')->count())->toBe(2);
});

it('does not mark the commercial invoice sent when the email fails', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);
    $this->sendOk = false;
    $invoice = $sale->invoices()->sole();

    $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->post("/mail-team/sales/{$sale->id}/invoices/{$invoice->id}/send")
        ->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain('could not be sent')
        ->and(NotificationLog::where('type', 'invoice_issued')->sole()->status)->toBe(EmailStatus::Failed);
    // Not "already emailed": the next click is a normal send, not a resend.
    $this->sendOk = true;
    $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->post("/mail-team/sales/{$sale->id}/invoices/{$invoice->id}/send")->assertSessionHasNoErrors();
});

it('only sends the commercial invoice once the order is confirmed', function () {
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Accepted]);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $this->admin);
    $invoice = app(InvoiceService::class)->issue($sale, 'test', $this->admin); // forced, for the check

    expect(fn () => app(InvoiceService::class)->send($invoice, $this->admin))
        ->toThrow(WorkflowException::class, 'once the order is confirmed');
});

it('skips customer emails when the order has no email address, and logs why', function () {
    mailConfirmedOrder($this->team, $this->admin, email: null);

    expect($this->sent)->toHaveCount(0)
        ->and(NotificationLog::where('status', 'skipped')->count())->toBeGreaterThan(0);
});

it('tells the customer when the order goes out for delivery, once per dispatch', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);
    $this->sent = collect();
    $service = app(FulfillmentService::class);

    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->first()->id => 2], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);

    $inTransit = $this->sent->filter(fn ($m) => str_contains($m['subject'], 'on the way'));
    expect($inTransit)->toHaveCount(1)
        ->and($inTransit->first()['html'])->toContain($release->release_number)->toContain('12 Rizal St, Manila');

    $service->recordReceipt($release, [], 'Ana Reyes', null, null, $this->admin);
    expect($this->sent->filter(fn ($m) => str_contains($m['subject'], 'on the way')))->toHaveCount(1)
        ->and($this->sent->pluck('subject')->filter(fn ($s) => str_contains($s, 'was delivered')))->toHaveCount(1)
        // Everything delivered and paid: the order completed itself.
        ->and($this->sent->last()['subject'])->toContain('Order Completed');
});

it('sends no email when an acceptance is recorded and the Sales Order is created', function () {
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Sent]);

    $accepted = app(QuotationWorkflow::class)->recordDecision($quotation, true, 'phone', null, $this->admin);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($accepted, $this->admin);

    expect($sale)->not->toBeNull()
        ->and($this->sent)->toHaveCount(0)
        ->and(NotificationLog::whereIn('type', ['quotation_accepted', 'sales_order_created'])->count())->toBe(0);
});

it('tells the customer when an order is cancelled, once', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);
    $this->sent = collect();

    app(SalesOrderService::class)->cancel($sale, 'Customer changed plans', $this->admin);

    expect($this->sent)->toHaveCount(1) // the order email only; no separate delivery email
        ->and($this->sent->first()['subject'])->toContain('Order Cancelled');
});

it('retries a failed email once it is fixed, without sending a duplicate', function () {
    $this->sendOk = false;
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Draft, 'approved_at' => now()]);
    app(QuotationWorkflow::class)->send($quotation, $this->admin);
    $log = NotificationLog::sole();
    expect($log->status)->toBe(EmailStatus::Failed);

    $this->sendOk = true;
    app(CustomerNotifier::class)->retry($log);
    app(CustomerNotifier::class)->retry($log->fresh()); // already sent: nothing happens

    expect($log->fresh()->status)->toBe(EmailStatus::Sent)
        ->and($quotation->fresh()->email_status)->toBe(EmailStatus::Sent)
        ->and(NotificationLog::count())->toBe(1)
        ->and($this->sent->where('to', 'ana@apex.example'))->toHaveCount(2); // one failed try, one success
});
