<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Search, filter and page changes reload only the list. The heavier lookup props (products, clients
 * and suppliers for the Quotation form) still come with every full
 * page load, but are not rebuilt and resent on each filter change.
 */

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'reload-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
    Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'Breaker 63A', 'category' => 'Breakers', 'price' => 750, 'stock' => 5, 'is_active' => true]);
});

/** An Inertia partial reload (what router.get(..., { only }) sends), with the trusted-device cookie. */
function partialReload($t, string $url, string $component, array $only): TestResponse
{
    $version = $t->actingAs($t->admin)->withCookies($t->cookies)->get($url)->viewData('page')['version'] ?? '';

    return $t->actingAs($t->admin)->withCookies($t->cookies)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => implode(',', $only),
    ]);
}

it('reloads only the inventory list on a filter change', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/reload-team/inventory?search=Breaker')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->missing('priorities') // AHP Product Prioritization is in Reports and Analytics
            ->has('products.data', 1)
        );

    partialReload($this, '/reload-team/inventory?search=Breaker', 'Inventory/Index', ['products', 'summary', 'recent_movements', 'filters'])
        ->assertOk()
        ->assertJsonMissingPath('props.priorities')
        ->assertJsonCount(1, 'props.products.data')
        ->assertJsonPath('props.filters.search', 'Breaker');
});

it('sends the quotation form lists on a full load but not on a filter reload', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/reload-team/quotations')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotations/AdminIndex')
            ->has('products', 1)
            ->has('clients')
            ->has('suppliers')
            ->where('nextNumber', fn ($number) => filled($number))
        );

    partialReload($this, '/reload-team/quotations', 'Quotations/AdminIndex', ['quotations', 'filters', 'metrics'])
        ->assertOk()
        ->assertJsonMissingPath('props.products')
        ->assertJsonMissingPath('props.clients')
        ->assertJsonMissingPath('props.suppliers')
        ->assertJsonPath('props.metrics.totalQuotations', 0)
        ->assertJsonStructure(['props' => ['quotations' => ['data']]]);
});
