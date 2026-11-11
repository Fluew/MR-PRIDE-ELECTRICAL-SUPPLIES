<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can update an inventory product without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'name' => 'Original Circuit Breaker 63A',
        'sku' => 'CB-63A-001',
        'category' => 'Circuit Breakers',
        'unit' => 'pcs',
        'stock' => 50,
        'min_stock' => 10,
        'reorder_level' => 10,
        'reorder_quantity' => 20,
        'price' => 750.00,
        'cost_price' => 500.00,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->put("/{$team->slug}/inventory/products/{$product->id}", [
            'name' => 'Updated Circuit Breaker 63A 3P',
            'sku' => 'CB-63A-001',
            'category' => 'Circuit Breakers',
            'unit' => 'pcs',
            'price' => 850.00,
            'cost_price' => 550.00,
            'reorder_level' => 15,
            'reorder_quantity' => 30,
            'description' => 'Updated technical specs and industrial enclosure.',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Circuit Breaker 63A 3P',
        'price' => 850.00,
        'cost_price' => 550.00,
        'reorder_level' => 15,
    ]);
});

test('can delete or archive an inventory product without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'name' => 'Obsolete Switch',
        'sku' => 'OBS-SW-01',
        'category' => 'Switches',
        'price' => 100.00,
        'stock' => 0,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/inventory/products/{$product->id}");

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertSoftDeleted('products', [
        'id' => $product->id,
    ]);
});

test('can update a supplier without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $supplier = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Original Supplier Corp',
        'contact_person' => 'Jane Doe',
        'phone' => '09123456789',
        'email' => 'supplier@example.com',
        'status' => 'Active',
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->put("/{$team->slug}/suppliers/{$supplier->id}", [
            'name' => 'Updated Supplier Corp',
            'contact_person' => 'Jane Doe',
            'phone' => '09123456789',
            'email' => 'supplier@example.com',
            'status' => 'Active',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('suppliers', [
        'id' => $supplier->id,
        'name' => 'Updated Supplier Corp',
    ]);
});

test('reports and analytics provides the backend ahp product prioritization', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $supplier = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Schneider Electric Philippines',
        'contact_person' => 'Juan Dela Cruz',
        'phone' => '09123456789',
        'email' => 'juan@schneider.ph',
        'status' => 'Active',
    ]);

    Product::create([
        'team_id' => $team->id,
        'name' => 'Molded Case Circuit Breaker 100A',
        'sku' => 'MCCB-100A',
        'category' => 'Circuit Breakers',
        'unit' => 'pcs',
        'stock' => 5,
        'min_stock' => 10,
        'reorder_level' => 20,
        'reorder_quantity' => 50,
        'price' => 3500.00,
        'cost_price' => 2200.00,
        'supplier_id' => $supplier->id,
        'status' => 'Low Stock',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/reports?view=priority");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Reports/Index')
        ->has('productPriority.items', 1)
        ->where('productPriority.items.0.product.sku', 'MCCB-100A')
        // No sales history and no verified supplier availability: reported as incomplete, not scored as 0.
        ->where('productPriority.items.0.complete', false)
        ->where('productPriority.items.0.classification', null)
        ->where('productPriority.items.0.criteria.0.key', 'demand')
        ->where('productPriority.items.0.criteria.0.weight', 0.4)
        ->where('productPriority.items.0.criteria.0.available', false)
        ->where('productPriority.weights.demand', 0.4)
        ->where('productPriority.forecastMonths', 3)
        ->where('productPriority.canRequestPurchase', true)
    );

    // Inventory no longer carries the AHP prioritization.
    $this->actingAs($user)->withCookies($deviceData['cookies'])->get("/{$team->slug}/inventory")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Index')->missing('priorities')->missing('forecastPeriods')->has('products.data', 1));
});

test('reports-only staff see the AHP prioritization but cannot create purchase requests from it', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create(['role' => 'staff_reports', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $cookies = createTrustedDeviceFor($user)['cookies'];
    Product::create(['team_id' => $team->id, 'name' => 'Wire 2.0mm', 'sku' => 'WIRE-2', 'price' => 10, 'stock' => 3, 'is_active' => true]);

    $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/reports?view=priority")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('productPriority.items', 1)->where('productPriority.canRequestPurchase', false));
    $this->actingAs($user)->withCookies($cookies)->post("/{$team->slug}/procurement/requests", ['lines' => []])->assertForbidden();
});

test('inventory views: product catalog and product movement; an old AHP link opens the catalog', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $cookies = createTrustedDeviceFor($user)['cookies'];

    Product::create([
        'team_id' => $team->id,
        'name' => 'Wire 2.0mm',
        'sku' => 'WIRE-2',
        'price' => 10,
        'stock' => 3,
        'is_active' => true,
    ]);

    // AHP Product Prioritization moved to Reports and Analytics: an old link falls back to the Product Catalog.
    $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/inventory?view=prioritization")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.view', 'table')->missing('priorities')->has('products.data', 1)->where('products.data.0.sku', 'WIRE-2'));

    $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/inventory?view=history")
        ->assertInertia(fn ($page) => $page->where('filters.view', 'history'));

    // Unknown views open the Product Catalog.
    $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/inventory?view=something-else")
        ->assertInertia(fn ($page) => $page->where('filters.view', 'table'));
});
