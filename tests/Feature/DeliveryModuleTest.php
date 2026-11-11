<?php

use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('deliveries index loads delivery records and metrics', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    Delivery::create([
        'team_id' => $team->id,
        'delivery_date' => '2026-10-25',
        'delivery_number' => 'DEL-102526-001',
        'purchase_order_number' => '4000005472',
        'quantity' => 100,
        'unit' => 'PCS',
        'description' => '0.68mm Stainless Steel Conduit Rod 2m',
        'status' => 'Delivered',
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/deliveries");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Deliveries/Index')
        ->has('inboundDeliveries.data', 1)
        ->where('inboundDeliveries.data.0.delivery_number', 'DEL-102526-001')
        ->where('inboundDeliveries.data.0.purchase_order_number', '4000005472')
        // One row per delivery record (Delivery Date, Delivery #); products are shown with View.
        ->where('inboundDeliveries.data.0.delivery_date', fn ($d) => str_starts_with((string) $d, '2026-10-25'))
        ->where('inboundDeliveries.data.0.items_count', 1)
        ->where('inboundDeliveries.data.0.received_total', fn ($q) => (int) $q === 100)
        ->where('inboundDeliveries.data.0.quality_status', 'Pending Inspection')
        ->has('inboundMetrics')
        ->where('inboundMetrics.totalDeliveries', 1)
        ->where('inboundMetrics.totalQuantityDelivered', 100)
    );
});

test('an inbound delivery can only be recorded against a sent purchase order', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create(['role' => 'admin', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    // The old free-form endpoint is gone.
    $this->actingAs($user)->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/deliveries", ['delivery_date' => '2026-10-25', 'quantity' => 50, 'description' => 'X'])
        ->assertStatus(405);

    $po = PurchaseOrder::create(['team_id' => $team->id, 'po_date' => '2026-10-20', 'po_number' => '4000005473', 'quantity' => 50, 'description' => 'Breaker', 'unit_cost' => 10, 'total_cost' => 500, 'status' => PurchaseOrder::DRAFT]);
    $this->actingAs($user)->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-25', 'quantity' => 50])
        ->assertSessionHasErrors('purchase_order_id');
    $this->assertDatabaseCount('deliveries', 0);
});

test('can import deliveries csv with required headers', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $csvContent = implode("\n", [
        'Date,Delivery Number,Purchase Order Number,Quantity,Unit,Description',
        '10-25-2026,DEL-102526-001,4000005472,100,PCS,"0.68mm Stainless Steel Conduit Rod 2m"',
        '10-25-2026,DEL-102526-002,4000005473,40,BOX,"Circuit Breaker 2P 40A Rail Mounted"',
    ]);

    $file = UploadedFile::fake()->createWithContent('deliveries.csv', $csvContent);

    // Preview validation
    $previewResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/deliveries/import-csv", [
            'file' => $file,
            'execute' => false,
        ], [
            'Accept' => 'application/json',
        ]);

    $previewResponse->assertSuccessful();
    $previewData = $previewResponse->json();

    expect($previewData['success'])->toBeTrue()
        ->and($previewData['summary']['valid_count'])->toBe(2)
        ->and($previewData['valid_rows'][0]['delivery_number'])->toBe('DEL-102526-001')
        ->and($previewData['valid_rows'][0]['purchase_order_number'])->toBe('4000005472')
        ->and($previewData['valid_rows'][0]['quantity'])->toBe(100)
        ->and($previewData['valid_rows'][0]['unit'])->toBe('PCS')
        ->and($previewData['valid_rows'][0]['description'])->toBe('0.68mm Stainless Steel Conduit Rod 2m');

    // Execute import
    $executeResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/deliveries/import-csv", [
            'file' => $file,
            'execute' => true,
        ], [
            'Accept' => 'application/json',
        ]);

    $executeResponse->assertSuccessful();
    $this->assertDatabaseHas('deliveries', [
        'team_id' => $team->id,
        'delivery_number' => 'DEL-102526-001',
        'purchase_order_number' => '4000005472',
        'quantity' => 100,
        'unit' => 'PCS',
    ]);
    $this->assertDatabaseHas('deliveries', [
        'team_id' => $team->id,
        'delivery_number' => 'DEL-102526-002',
        'purchase_order_number' => '4000005473',
        'quantity' => 40,
        'unit' => 'BOX',
    ]);
});

test('can search inbound deliveries by description and PO number', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    Delivery::create([
        'team_id' => $team->id,
        'delivery_date' => '2026-10-25',
        'delivery_number' => 'DEL-ALPHA-01',
        'purchase_order_number' => '4000001001',
        'quantity' => 20,
        'unit' => 'BOX',
        'description' => 'Cable Box',
        'status' => 'Delivered',
    ]);

    Delivery::create([
        'team_id' => $team->id,
        'delivery_date' => '2026-10-25',
        'delivery_number' => 'DEL-BETA-02',
        'purchase_order_number' => '4000001002',
        'quantity' => 500,
        'unit' => 'PCS',
        'description' => 'Screws',
        'status' => 'In Transit',
    ]);

    // Search by description
    $responseUnit = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/deliveries?tab=inbound&search=Cable");

    $responseUnit->assertSuccessful();
    $responseUnit->assertInertia(fn ($page) => $page
        ->where('inboundDeliveries.total', 1)
        ->where('inboundDeliveries.data.0.delivery_number', 'DEL-ALPHA-01')
    );

    // Search by PO number
    $responseStatus = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/deliveries?tab=inbound&search=4000001002");

    $responseStatus->assertSuccessful();
    $responseStatus->assertInertia(fn ($page) => $page
        ->where('inboundDeliveries.total', 1)
        ->where('inboundDeliveries.data.0.delivery_number', 'DEL-BETA-02')
    );
});

test('can delete a delivery record by id or delivery number', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $delivery1 = Delivery::create([
        'team_id' => $team->id,
        'delivery_date' => '2026-10-25',
        'delivery_number' => 'DEL-DELETE-01',
        'purchase_order_number' => '4000009991',
        'quantity' => 15,
        'unit' => 'PCS',
        'description' => 'To be deleted by id',
        'status' => 'Delivered',
    ]);

    $delivery2 = Delivery::create([
        'team_id' => $team->id,
        'delivery_date' => '2026-10-25',
        'delivery_number' => 'DEL-DELETE-02',
        'purchase_order_number' => '4000009992',
        'quantity' => 25,
        'unit' => 'BOX',
        'description' => 'To be deleted by delivery number',
        'status' => 'Delivered',
    ]);

    // Delete by ID
    $response1 = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/deliveries/{$delivery1->id}");

    $response1->assertSessionHasNoErrors();
    $response1->assertSessionHas('success', 'Delivery record deleted successfully.');
    $this->assertSoftDeleted('deliveries', ['id' => $delivery1->id]);

    // Delete by delivery number
    $response2 = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/deliveries/{$delivery2->delivery_number}");

    $response2->assertSessionHasNoErrors();
    $response2->assertSessionHas('success', 'Delivery record deleted successfully.');
    $this->assertSoftDeleted('deliveries', ['id' => $delivery2->id]);
});
