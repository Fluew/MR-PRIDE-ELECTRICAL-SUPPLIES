<?php

use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Sales\PaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Covers the deletion pathways that are not plain DELETE routes:
 * "replace" CSV imports, self-deleting an account, permanent deletes from the recycle bin,
 * and the `auth.can_delete` flag the frontend uses to hide delete controls.
 */
function gapActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function gapProduct(Team $team, string $sku = 'SKU-OLD-1'): Product
{
    return Product::create([
        'team_id' => $team->id, 'sku' => $sku, 'name' => 'Existing Product',
        'price' => 100, 'stock' => 5, 'is_active' => true,
    ]);
}

function gapCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('import.csv', "sku,name,price,stock\nSKU-NEW-1,New Product,250,4\n");
}

// --- Inventory imports --------------------------------------------------------------------
// Replace mode (deactivating the catalog) was removed with the old one-step import. The
// reviewed document import only adds new products, for staff and admins alike.

it('no longer offers a replace-mode inventory import to anyone', function (string $role) {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$user, $cookies] = gapActor($role, $team);
    $existing = gapProduct($team);

    $this->actingAs($user)->withCookies($cookies)
        ->post("/{$team->slug}/inventory/import-csv", ['file' => gapCsv(), 'mode' => 'replace', 'execute' => true])
        ->assertNotFound();

    expect($existing->fresh()->is_active)->toBeTrue()
        ->and(Product::where('sku', 'SKU-NEW-1')->exists())->toBeFalse();
})->with(['staff_inventory', 'admin']);

it('lets staff import new products without touching the existing catalog', function () {
    Storage::fake('local');
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$staff, $cookies] = gapActor('staff_inventory', $team);
    $existing = gapProduct($team);

    $id = $this->actingAs($staff)->withCookies($cookies)
        ->post("/{$team->slug}/imports/inventory", ['file' => gapCsv()])->assertCreated()->json('id');
    $this->actingAs($staff)->withCookies($cookies)
        ->post("/{$team->slug}/imports/batches/{$id}/confirm")->assertOk();

    expect(Product::where('team_id', $team->id)->where('sku', 'SKU-NEW-1')->exists())->toBeTrue()
        ->and($existing->fresh()->is_active)->toBeTrue()
        ->and($existing->fresh()->stock)->toBe(5);
});

it('blocks staff from replace-mode quotation imports', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$staff, $cookies] = gapActor('staff_quotations', $team);

    $this->actingAs($staff)->withCookies($cookies)
        ->post("/{$team->slug}/quotations/import-csv", ['file' => gapCsv(), 'mode' => 'replace', 'execute' => true])
        ->assertForbidden();

    expect(Quotation::where('team_id', $team->id)->count())->toBe(0);
});

// --- Account self-deletion ----------------------------------------------------------------

it('blocks staff from deleting their own account', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$staff, $cookies] = gapActor('staff_sales', $team);

    $this->actingAs($staff)->withCookies($cookies)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertForbidden();

    expect(User::find($staff->id))->not->toBeNull();
});

it('rejects guests on protected delete routes', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    $product = gapProduct($team);

    $this->delete("/{$team->slug}/inventory/products/{$product->id}")->assertRedirect(route('login'));
    $this->delete('/settings/profile', ['password' => 'password'])->assertRedirect(route('login'));

    expect(Product::find($product->id))->not->toBeNull();
});

// --- Recycle bin: authorized Admin can permanently delete archived sales ------------------

it('allows an authorized admin to permanently delete a voided sale', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);
    $sale = Sale::create([
        'team_id' => $team->id, 'invoice_number' => 'INV-GAP-1', 'client_name' => 'Someone',
        'sale_date' => now(), 'subtotal' => 100, 'discount' => 0, 'tax_amount' => 0,
        'grand_total' => 100, 'status' => 'completed',
    ]);
    $sale->delete();

    $this->actingAs($admin)->withCookies($cookies)
        ->delete("/{$team->slug}/recycle-bin/sale/{$sale->id}/force")
        ->assertSessionHas('success');

    expect(Sale::withTrashed()->find($sale->id))->toBeNull();
});

it('refuses to permanently delete a product with stock history, but purges one without', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);

    $withHistory = gapProduct($team, 'SKU-HIST');
    StockMovement::create([
        'team_id' => $team->id, 'product_id' => $withHistory->id, 'user_id' => $admin->id,
        'type' => 'stock_in', 'quantity' => 5, 'previous_stock' => 0, 'new_stock' => 5,
        'reference_number' => 'REF-1', 'reason' => 'Initial', 'movement_date' => now(),
    ]);
    $withHistory->delete();

    $clean = gapProduct($team, 'SKU-CLEAN');
    $clean->delete();

    $this->actingAs($admin)->withCookies($cookies)
        ->post("/{$team->slug}/recycle-bin/bulk-force-delete", ['items' => [
            ['type' => 'product', 'id' => $withHistory->id],
            ['type' => 'product', 'id' => $clean->id],
        ]])
        ->assertSessionHas('success');

    expect(Product::withTrashed()->find($withHistory->id))->not->toBeNull()
        ->and(StockMovement::where('product_id', $withHistory->id)->count())->toBe(1)
        ->and(Product::withTrashed()->find($clean->id))->toBeNull();
});

it('purges archived sales when the recycle bin is emptied by an admin', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);
    $sale = Sale::create([
        'team_id' => $team->id, 'invoice_number' => 'INV-GAP-2', 'client_name' => 'Someone',
        'sale_date' => now(), 'subtotal' => 100, 'discount' => 0, 'tax_amount' => 0,
        'grand_total' => 100, 'status' => 'completed',
    ]);
    $sale->delete();
    $supplier = Supplier::create(['team_id' => $team->id, 'name' => 'Old Supplier']);
    $supplier->delete();

    $this->actingAs($admin)->withCookies($cookies)
        ->post("/{$team->slug}/recycle-bin/empty")
        ->assertSessionHas('success');

    expect(Sale::withTrashed()->find($sale->id))->toBeNull()
        ->and(Supplier::withTrashed()->find($supplier->id))->toBeNull();
});

// --- Frontend flag ------------------------------------------------------------------------

it('tells the frontend only admins can delete', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$staff, $staffCookies] = gapActor('staff_suppliers', $team);
    [$admin, $adminCookies] = gapActor('admin', $team);

    $this->actingAs($staff)->withCookies($staffCookies)
        ->get("/{$team->slug}/suppliers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can_delete', false));

    $this->actingAs($admin)->withCookies($adminCookies)
        ->get("/{$team->slug}/suppliers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can_delete', true));
});

// --- Recycle bin: restoring a voided sale -------------------------------------------------

it('cancels before archiving: reserved stock is freed once, and restoring the archived order does not take it again', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);
    $product = Product::create([
        'team_id' => $team->id, 'sku' => 'SKU-RST', 'name' => 'Restorable', 'price' => 100, 'stock' => 10, 'is_active' => true,
    ]);

    // An order created from an accepted quotation, then paid and confirmed: 2 units reserved for it.
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-RESTORE-1', 'client_name' => 'Restore Test Co', 'address' => 'Pasig',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
        'subtotal' => 200, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 200, 'status' => QuotationStatus::Accepted,
    ]);
    $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 100, 'discount' => 0, 'subtotal' => 200]);
    $this->actingAs($admin)->withCookies($cookies)->post("/{$team->slug}/sales", ['quotation_id' => $quotation->id])->assertSessionHasNoErrors();
    $sale = Sale::where('quotation_id', $quotation->id)->sole();
    $payments = app(PaymentService::class);
    $payments->verify(
        $payments->record($sale, ['method' => 'GCash', 'amount' => 200, 'reference' => 'GCASH-RESTORE-1', 'payment_date' => now()->toDateString()], null, $admin),
        ['reference' => 'GCASH-RESTORE-1', 'amount' => 200, 'payment_date' => now()->toDateString()], $admin,
    );
    expect($product->fresh()->stock)->toBe(10)->and($product->fresh()->availableStock())->toBe(8);

    // Only cancelled orders can be archived.
    $this->delete("/{$team->slug}/sales/{$sale->id}")->assertSessionHasErrors('error');
    expect(Sale::find($sale->id))->not->toBeNull();

    $this->post("/{$team->slug}/sales/{$sale->id}/cancel", ['reason' => 'Customer changed their mind'])->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(10)->and($product->fresh()->availableStock())->toBe(10);

    $this->delete("/{$team->slug}/sales/{$sale->id}")->assertSessionHas('success');
    $this->post("/{$team->slug}/recycle-bin/sale/{$sale->id}/restore")->assertSessionHas('success');

    expect($product->fresh()->availableStock())->toBe(10);
    expect(Sale::find($sale->id)?->status)->toBe(SalesOrderStatus::Cancelled)
        ->and($product->fresh()->stock)->toBe(10);
});

/** A sale archived before the order workflow: archiving it then put its items back in stock. */
function gapLegacyArchivedSale(Team $team, Product $product): Sale
{
    $sale = Sale::create([
        'team_id' => $team->id, 'invoice_number' => 'INV-LEGACY-1', 'client_name' => 'Someone', 'sale_date' => now(),
        'subtotal' => 200, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => 200, 'status' => 'completed',
    ]);
    $sale->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200]);
    $sale->delete();

    return $sale;
}

it('takes the stock back out when an older voided sale is restored', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);
    $product = Product::create(['team_id' => $team->id, 'sku' => 'SKU-RST', 'name' => 'Restorable', 'price' => 100, 'stock' => 10, 'is_active' => true]);
    $sale = gapLegacyArchivedSale($team, $product);

    $this->actingAs($admin)->withCookies($cookies)->post("/{$team->slug}/recycle-bin/sale/{$sale->id}/restore")->assertSessionHas('success');

    expect(Sale::find($sale->id))->not->toBeNull()
        ->and($product->fresh()->stock)->toBe(8)
        ->and(StockMovement::where('product_id', $product->id)->where('reason', 'like', '%restored from recycle bin%')->count())->toBe(1);
});

it('refuses to restore an older voided sale when the stock is no longer there', function () {
    $team = Team::factory()->create(['slug' => 'gap-team']);
    [$admin, $cookies] = gapActor('admin', $team);
    $product = Product::create(['team_id' => $team->id, 'sku' => 'SKU-RST2', 'name' => 'Sold Out', 'price' => 100, 'stock' => 1, 'is_active' => true]);
    $sale = gapLegacyArchivedSale($team, $product);

    $this->actingAs($admin)->withCookies($cookies)->post("/{$team->slug}/recycle-bin/sale/{$sale->id}/restore")->assertSessionHas('error');

    expect(Sale::find($sale->id))->toBeNull()
        ->and(Sale::withTrashed()->find($sale->id))->not->toBeNull()
        ->and($product->fresh()->stock)->toBe(1);
});
