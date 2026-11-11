<?php

use App\Enums\DeliveryStatus;
use App\Enums\TeamRole;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function createDeliveryActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    $deviceData = createTrustedDeviceFor($user);

    return [$user, $deviceData['cookies']];
}

it('allows administrator to view all deliveries and assign delivery to delivery staff', function () {
    [$admin, $adminCookies] = createDeliveryActor('admin', $this->team);
    [$driver, $driverCookies] = createDeliveryActor('staff_deliveries', $this->team);

    $sale = Sale::create([
        'team_id' => $this->team->id,
        'invoice_number' => 'INV-DEL-001',
        'client_name' => 'Solar Solutions Ph',
        'sale_date' => now(),
        'subtotal' => 15000.00,
        'discount' => 0.00,
        'tax_amount' => 0.00,
        'grand_total' => 15000.00,
        'status' => 'completed',
        'processed_by_id' => $admin->id,
    ]);

    $fulfillment = OrderFulfillment::create([
        'team_id' => $this->team->id,
        'sale_id' => $sale->id,
        'fulfillment_number' => 'FUL-TEST-001',
        'fulfillment_type' => 'delivery',
        'recipient_name' => 'Engr. Manuel Roxas',
        'contact_number' => '09175554433',
        'delivery_address' => 'Phase 2 Industrial Park, Calamba, Laguna',
        'status' => 'pending',
        'handled_by_id' => null,
    ]);

    // Admin assigns delivery to driver
    $response = $this->actingAs($admin)->withCookies($adminCookies)->post("/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}/assign", [
        'staff_id' => $driver->id,
    ]);

    $response->assertSessionHasNoErrors();
    $fulfillment->refresh();
    expect($fulfillment->handled_by_id)->toBe($driver->id);
    expect($fulfillment->attemptLogs)->toHaveCount(1);
    expect($fulfillment->attemptLogs->first()->remarks)->toContain($driver->name);
});

it('scopes dashboard so delivery staff views only their assigned deliveries while admin views all', function () {
    [$admin, $adminCookies] = createDeliveryActor('admin', $this->team);
    [$driver1, $driver1Cookies] = createDeliveryActor('staff_deliveries', $this->team);
    [$driver2, $driver2Cookies] = createDeliveryActor('staff_deliveries', $this->team);

    $sale = Sale::create([
        'team_id' => $this->team->id,
        'invoice_number' => 'INV-DEL-SCOPE',
        'client_name' => 'Prime Distribution',
        'sale_date' => now(),
        'subtotal' => 5000.00,
        'discount' => 0.00,
        'tax_amount' => 0.00,
        'grand_total' => 5000.00,
        'status' => 'completed',
        'processed_by_id' => $admin->id,
    ]);

    // Fulfillment 1 assigned to driver 1
    $ful1 = OrderFulfillment::create([
        'team_id' => $this->team->id,
        'sale_id' => $sale->id,
        'fulfillment_number' => 'FUL-DRIVER1-01',
        'fulfillment_type' => 'delivery',
        'recipient_name' => 'Customer A',
        'contact_number' => '09171111111',
        'status' => 'pending',
        'handled_by_id' => $driver1->id,
    ]);

    // Fulfillment 2 assigned to driver 2 (a second order: one delivery record per order)
    $sale2 = $sale->replicate()->fill(['invoice_number' => 'INV-DEL-SCOPE-2']);
    $sale2->save();
    $ful2 = OrderFulfillment::create([
        'team_id' => $this->team->id,
        'sale_id' => $sale2->id,
        'fulfillment_number' => 'FUL-DRIVER2-02',
        'fulfillment_type' => 'delivery',
        'recipient_name' => 'Customer B',
        'contact_number' => '09172222222',
        'status' => 'pending',
        'handled_by_id' => $driver2->id,
    ]);

    // Driver 1 visits dashboard -> sees only FUL-DRIVER1-01
    $responseDriver1 = $this->actingAs($driver1)->withCookies($driver1Cookies)->get("/{$this->team->slug}/deliveries");
    $responseDriver1->assertOk();
    $pageProps = $responseDriver1->original->getData()['page']['props'];
    $outboundData = $pageProps['outboundDeliveries']['data'];
    $fulfillmentNumbers = array_column($outboundData, 'fulfillment_number');
    expect($fulfillmentNumbers)->toContain('FUL-DRIVER1-01');
    expect($fulfillmentNumbers)->not->toContain('FUL-DRIVER2-02');
    expect($pageProps['outboundMetrics']['totalAssigned'])->toBe(1);

    // Admin visits dashboard -> sees both
    $responseAdmin = $this->actingAs($admin)->withCookies($adminCookies)->get("/{$this->team->slug}/deliveries");
    $responseAdmin->assertOk();
    $adminProps = $responseAdmin->original->getData()['page']['props'];
    $adminFulfillments = array_column($adminProps['outboundDeliveries']['data'], 'fulfillment_number');
    expect($adminFulfillments)->toContain('FUL-DRIVER1-01');
    expect($adminFulfillments)->toContain('FUL-DRIVER2-02');
    expect($adminProps['outboundMetrics']['totalAssigned'])->toBe(2);
});

function createTestSale(Team $team, User $user, string $invoicePrefix = 'INV-TEST'): Sale
{
    return Sale::create([
        'team_id' => $team->id,
        'invoice_number' => $invoicePrefix.'-'.uniqid(),
        'client_name' => 'Test Client Corp',
        'sale_date' => now(),
        'subtotal' => 1000.00,
        'discount' => 0.00,
        'tax_amount' => 0.00,
        'grand_total' => 1000.00,
        'status' => 'completed',
        'processed_by_id' => $user->id,
    ]);
}

it('prevents delivery staff from updating another staff members delivery', function () {
    [$driver1, $driver1Cookies] = createDeliveryActor('staff_deliveries', $this->team);
    [$driver2, $driver2Cookies] = createDeliveryActor('staff_deliveries', $this->team);

    $sale = createTestSale($this->team, $driver1, 'INV-SEC');

    $ful = OrderFulfillment::create([
        'team_id' => $this->team->id,
        'sale_id' => $sale->id,
        'fulfillment_number' => 'FUL-SEC-01',
        'fulfillment_type' => 'delivery',
        'recipient_name' => 'Secret Client',
        'contact_number' => '09173333333',
        'status' => 'pending',
        'handled_by_id' => $driver1->id,
    ]);

    // Driver 2 attempts to start Driver 1's delivery -> 403 Forbidden
    $response = $this->actingAs($driver2)->withCookies($driver2Cookies)->post("/{$this->team->slug}/deliveries/fulfillments/{$ful->id}/start");
    $response->assertForbidden();
});

/** A confirmed order (approved credit terms) whose release is out for delivery with the driver. */
function dispatchedDelivery(Team $team, User $driver): OrderFulfillment
{
    $admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $product = Product::create(['team_id' => $team->id, 'sku' => 'DLV-'.uniqid(), 'name' => 'Panel Board', 'price' => 1000, 'stock' => 10, 'is_active' => true]);
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-'.uniqid(), 'client_name' => 'Warehouse Recipient', 'email' => 'site@example.com',
        'address' => 'Bldg 5 Cyberzone, Ortigas', 'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
        'subtotal' => 1000, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 1000, 'status' => 'accepted',
    ]);
    $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $orders = app(SalesOrderService::class);
    [$sale] = $orders->createFromQuotation($quotation, $admin);
    $orders->setTerms($sale, Sale::TERMS_CREDIT, null, 30, $admin);
    $fulfillment = $sale->fresh()->fulfillment;
    $fulfillment->update(['handled_by_id' => $driver->id]);

    $service = app(FulfillmentService::class);
    $release = $service->prepareRelease($fulfillment, [$sale->items()->sole()->id => 1], $admin);
    $service->markReady($release, $admin);
    $service->dispatch($release, $driver);

    return $fulfillment->fresh();
}

it('enforces strict three-attempt limit and disables further attempts after 3/3', function () {
    [$driver, $driverCookies] = createDeliveryActor('staff_deliveries', $this->team);
    $fulfillment = dispatchedDelivery($this->team, $driver);
    $base = "/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}";
    expect($fulfillment->status)->toBe(DeliveryStatus::InTransit);

    foreach ([1 => 'Customer unavailable', 2 => 'Customer unreachable', 3 => 'Customer refused delivery'] as $n => $reason) {
        if ($n > 1) {
            $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/start")->assertSessionHasNoErrors();
        }
        $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/attempt", ['reason' => $reason, 'remarks' => "Attempt {$n} did not work."])
            ->assertSessionHasNoErrors();

        $fulfillment->refresh();
        expect($fulfillment->attempt_count)->toBe($n)
            ->and($fulfillment->status)->toBe(DeliveryStatus::ReadyForDelivery)
            ->and($fulfillment->remaining_attempts)->toBe(3 - $n);
    }

    // No fourth try: neither dispatching again nor recording another attempt.
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/start")->assertSessionHasErrors('error');
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/attempt", ['reason' => 'Other reason', 'remarks' => 'Trying a fourth time anyway.'])
        ->assertSessionHasErrors('error');

    $fulfillment->refresh();
    expect($fulfillment->attempt_count)->toBe(3)
        ->and($fulfillment->attemptLogs()->where('attempt_number', '>', 0)->count())->toBe(3);
});

it('requires valid photo proof to mark delivery as Delivered and stops further attempts', function () {
    [$driver, $driverCookies] = createDeliveryActor('staff_deliveries', $this->team);
    $fulfillment = dispatchedDelivery($this->team, $driver);
    $base = "/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}";

    // 1. Missing proof photo -> fails validation
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/deliver", ['recipient_name' => 'Engr. Santos'])
        ->assertSessionHasErrors('proof_of_delivery');

    // 2. Invalid file type -> fails validation
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/deliver", ['proof_of_delivery' => UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf')])
        ->assertSessionHasErrors('proof_of_delivery');

    // 3. Valid JPG photo proof -> delivered
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/deliver", [
        'proof_of_delivery' => UploadedFile::fake()->image('delivery_receipt.jpg', 600, 600),
        'recipient_name' => 'Engr. Santos (Signed)',
        'remarks' => 'Received in good physical condition with 2 boxes.',
    ])->assertSessionHasNoErrors();

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe(DeliveryStatus::Delivered)
        ->and($fulfillment->proof_of_delivery)->not->toBeNull()
        ->and($fulfillment->delivered_at)->not->toBeNull();
    Storage::disk('local')->assertExists($fulfillment->proof_of_delivery);

    $this->actingAs($driver)->withCookies($driverCookies)->get("{$base}/proof")->assertOk();

    // A delivered order takes no more attempts.
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/attempt", ['reason' => 'Other reason', 'remarks' => 'Should be blocked.'])
        ->assertSessionHasErrors('error');
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('allows administrator to reschedule delivery and reset attempts after limit is reached', function () {
    [$admin, $adminCookies] = createDeliveryActor('admin', $this->team);
    [$driver, $driverCookies] = createDeliveryActor('staff_deliveries', $this->team);
    $fulfillment = dispatchedDelivery($this->team, $driver);
    $service = app(FulfillmentService::class);
    foreach (range(1, 3) as $n) {
        $service->recordFailedAttempt($fulfillment->fresh(), 'Customer unavailable', 'Closed', null, $driver);
        if ($n < 3) {
            $service->retryDispatch($fulfillment->fresh(), $driver);
        }
    }
    $base = "/{$this->team->slug}/deliveries/fulfillments/{$fulfillment->id}";

    // Driver cannot reschedule
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/reschedule", [
        'delivery_schedule' => now()->addDays(2)->toDateTimeString(),
        'reset_attempts' => true,
    ])->assertForbidden();

    // Admin authorizes rescheduling with fresh attempts
    $newSchedule = now()->addDays(3)->toDateTimeString();
    $this->actingAs($admin)->withCookies($adminCookies)->post("{$base}/reschedule", [
        'delivery_schedule' => $newSchedule,
        'reset_attempts' => true,
        'notes' => 'Customer confirmed site is now open and ready for handover.',
    ])->assertSessionHasNoErrors();

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe(DeliveryStatus::ReadyForDelivery)
        ->and($fulfillment->attempt_count)->toBe(0)
        ->and($fulfillment->rescheduled_by_id)->toBe($admin->id);

    // The driver can go again.
    $this->actingAs($driver)->withCookies($driverCookies)->post("{$base}/start")->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::InTransit);
});
