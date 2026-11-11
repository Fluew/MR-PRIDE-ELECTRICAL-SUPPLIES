<?php

use App\Enums\DeliveryStatus;
use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\ResendService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Automatic delivery emails reach the customer at each stage, as they do in the app: sent by the
 * web request itself right after its response, with the queue set to "database" and NO queue
 * worker running. (Before, these emails waited for a worker and stayed "pending" when none ran.)
 *
 * ResendService is replaced by a recorder, so nothing leaves the machine.
 */
beforeEach(function () {
    Storage::fake('local');
    config(['queue.default' => 'database', 'services.customer_emails.send' => 'after_response']);

    $this->sent = collect();
    $this->sendOk = true;
    $this->mock(ResendService::class)
        ->shouldReceive('sendCustomerEmail')
        ->andReturnUsing(function ($to, $subject, $html, $kind, $attachments = []) {
            $this->sent->push(compact('to', 'subject', 'html', 'attachments'));

            return $this->sendOk ? ['success' => true, 'id' => 'msg-'.$this->sent->count(), 'error' => null] : ['success' => false, 'id' => null, 'error' => 'Resend API error (500)'];
        });

    $this->team = Team::factory()->create(['slug' => 'de-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->service = app(FulfillmentService::class);
});

/** A confirmed, fully paid order of $quantity units, for delivery or pickup. */
function deOrder(Team $team, User $admin, int $quantity = 4, string $type = 'delivery'): Sale
{
    $supplier = Supplier::create(['team_id' => $team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $product = Product::create([
        'team_id' => $team->id, 'sku' => 'BRK-30A', 'name' => 'Circuit Breaker 30A', 'price' => 100, 'cost_price' => 60,
        'supplier_id' => $supplier->id, 'stock' => 20, 'reorder_level' => 2, 'status' => 'In Stock', 'is_active' => true,
    ]);
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'contact_person' => 'Engr. Ana Reyes',
        'email' => 'buyer@metro.example', 'phone' => '09171234567', 'address' => '12 Site Rd, Pasig City',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
        'subtotal' => 100 * $quantity, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 100 * $quantity,
        'status' => QuotationStatus::Accepted, 'terms_and_conditions' => 'Net 30.',
    ]);
    $quotation->items()->create([
        'product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $quantity,
        'unit_price' => 100, 'unit_cost' => 60, 'supplier_id' => $supplier->id, 'discount' => 0, 'subtotal' => 100 * $quantity,
    ]);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $admin, ['fulfillment_type' => $type]);
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'Bank Transfer', 'amount' => 100 * $quantity, 'reference' => 'REF-1', 'payment_date' => now()->toDateString()], null, $admin);
    $payments->verify($payment, ['reference' => 'REF-1', 'amount' => 100 * $quantity, 'payment_date' => now()->toDateString()], $admin);

    return $sale->fresh();
}

/** End the "request": emails sent after the response go out now. */
function deFinishRequest(): void
{
    app()->terminate();
}

it('emails the customer when staff mark a release ready, through the real web request, with no queue worker', function () {
    $sale = deOrder($this->team, $this->admin);
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    deFinishRequest();
    $this->sent = collect();

    $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->post("/de-team/deliveries/releases/{$release->id}/ready")
        ->assertSessionHasNoErrors();

    $log = NotificationLog::where('type', 'delivery_status')->latest('id')->first();
    expect($this->sent)->toHaveCount(1)
        ->and($this->sent->first()['to'])->toBe('buyer@metro.example')
        ->and($this->sent->first()['subject'])->toBe("Your order is ready for delivery – {$sale->order_number}")
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and($log->provider_message_id)->toBe('msg-1')
        ->and(DB::table('jobs')->count())->toBe(0); // nothing left waiting for a worker
});

it('emails each delivery stage in order: ready, on the way, delivered', function () {
    $sale = deOrder($this->team, $this->admin);
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    deFinishRequest();
    $this->sent = collect();

    $this->service->markReady($release, $this->admin);
    // Sent after the response, not while staff wait for the page.
    expect($this->sent)->toHaveCount(0)
        ->and(NotificationLog::where('type', 'delivery_status')->sole()->status)->toBe(EmailStatus::Pending);
    deFinishRequest();
    expect($this->sent)->toHaveCount(1);

    $this->service->dispatch($release, $this->admin);
    deFinishRequest();
    $this->service->recordReceipt($release, [], 'Juan Cruz', null, null, $this->admin);
    deFinishRequest();

    $delivery = $this->sent->filter(fn ($m) => str_contains($m['subject'], $sale->order_number) && ! str_contains($m['subject'], 'Order Completed'))->values();
    expect($delivery->pluck('subject')->all())->toBe([
        "Your order is ready for delivery – {$sale->order_number}",
        "Your order is on the way – {$sale->order_number}",
        "Your order was delivered – {$sale->order_number}",
    ])
        ->and($delivery[2]['html'])->toContain('Juan Cruz')
        ->and($delivery[2]['attachments'][0]['filename'])->toBe("Delivery-{$release->release_number}.pdf")
        ->and(NotificationLog::where('type', 'delivery_status')->pluck('status')->unique()->values()->all())->toBe([EmailStatus::Sent])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('emails "ready for pickup" and "picked up" for pickup orders', function () {
    $sale = deOrder($this->team, $this->admin, 2, 'pickup');
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 2], $this->admin);
    $this->service->markReady($release, $this->admin);
    $this->service->recordReceipt($release, [], 'Customer driver', null, null, $this->admin);
    deFinishRequest();

    expect($this->sent->pluck('subject')->filter(fn ($s) => str_contains($s, 'pick'))->values()->all())->toBe([
        "Your order is ready for pickup – {$sale->order_number}",
        "Your order was picked up – {$sale->order_number}",
    ]);
});

it('emails "We could not deliver your order" after a failed attempt, and the new date after a reschedule', function () {
    $sale = deOrder($this->team, $this->admin);
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    $this->service->markReady($release, $this->admin);
    $this->service->dispatch($release, $this->admin);
    deFinishRequest();
    $this->sent = collect();

    $this->service->recordFailedAttempt($sale->fulfillment->fresh(), 'Customer unavailable', 'Gate locked, no answer', null, $this->admin);
    deFinishRequest();

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['subject'])->toBe("We could not deliver your order – {$sale->order_number}")
        ->and($this->sent[0]['html'])->toContain('Gate locked, no answer')
        ->and($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery);

    $newDate = now()->addDays(3)->setTime(9, 30);
    $this->service->reschedule($sale->fulfillment->fresh(), $newDate->format('Y-m-d H:i:s'), false, 'Call before arriving', null, $this->admin);
    deFinishRequest();

    expect($this->sent)->toHaveCount(2)
        ->and($this->sent[1]['subject'])->toBe("Your order is ready for delivery – {$sale->order_number}")
        ->and($this->sent[1]['html'])->toContain($newDate->format('M d, Y h:i A'));
});

it('emails a partial delivery with what is still to follow, then a cancellation notice for the rest', function () {
    $sale = deOrder($this->team, $this->admin, 4);
    $line = $sale->items->sole();
    $release = $this->service->prepareRelease($sale->fulfillment, [$line->id => 3], $this->admin);
    $this->service->markReady($release, $this->admin);
    $this->service->dispatch($release, $this->admin);
    deFinishRequest();
    $this->sent = collect();

    $status = $this->service->recordReceipt($release, [$line->id => 2], 'Juan Cruz', null, null, $this->admin);
    deFinishRequest();

    expect($status)->toBe(DeliveryStatus::Pending)
        ->and($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['subject'])->toBe("Part of your order was delivered – {$sale->order_number}")
        ->and($this->sent[0]['html'])->toContain('Still to follow')->toContain('Circuit Breaker 30A: 2');

    app(SalesOrderService::class)->cancelRemaining($sale->fresh(), null, 'Item discontinued', $this->admin);
    deFinishRequest();

    expect($this->sent->pluck('subject')->all())->toContain("The rest of your delivery was cancelled – {$sale->order_number}");
});

it('sends nothing for Pending, and nothing when a release returns to the warehouse', function () {
    $sale = deOrder($this->team, $this->admin);
    $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    deFinishRequest();

    expect(NotificationLog::where('type', 'delivery_status')->count())->toBe(0)
        ->and($this->sent->filter(fn ($m) => str_contains($m['subject'], 'deliver') || str_contains($m['subject'], 'ready')))->toHaveCount(0);
});

it('never sends an email for a change that was rolled back', function () {
    $sale = deOrder($this->team, $this->admin);
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    deFinishRequest();
    $this->sent = collect();

    try {
        DB::transaction(function () use ($release) {
            $this->service->markReady($release, $this->admin);
            throw new RuntimeException('Something failed after the status change');
        });
    } catch (RuntimeException) {
    }
    deFinishRequest();

    expect($this->sent)->toHaveCount(0)
        ->and(NotificationLog::where('type', 'delivery_status')->count())->toBe(0);
});

it('keeps a failed send visible and queues a retry, without blocking the status change', function () {
    $sale = deOrder($this->team, $this->admin);
    $release = $this->service->prepareRelease($sale->fulfillment, [$sale->items->sole()->id => 4], $this->admin);
    deFinishRequest();
    $this->sendOk = false;

    $this->service->markReady($release, $this->admin);
    deFinishRequest();

    $log = NotificationLog::where('type', 'delivery_status')->sole();
    expect($sale->fulfillment->fresh()->status)->toBe(DeliveryStatus::ReadyForDelivery)
        ->and($log->status)->toBe(EmailStatus::Failed)
        ->and($log->error)->toContain('Resend API error')
        ->and(DB::table('jobs')->count())->toBe(1); // a queued retry, for when a worker runs
});
