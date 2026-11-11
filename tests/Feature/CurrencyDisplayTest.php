<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\ExchangeRates;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The header currency switch: a per-user display preference (USD by default, or PHP converted
 * at the Frankfurter rate). Stored amounts are USD and are never changed by it.
 */
const RATE_URL = 'https://api.frankfurter.dev/v2/rate/usd/php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-10-30 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
});

function currencyUser(Team $team, string $role = 'staff_inventory'): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function fakeRate(float $rate = 63.838, string $date = '2026-10-30'): void
{
    Http::fake([RATE_URL => Http::response(['date' => $date, 'base' => 'USD', 'quote' => 'PHP', 'rate' => $rate])]);
}

// --- Preference ---------------------------------------------------------------------------

it('shows amounts in USD when the user has not chosen a currency', function () {
    [$staff, $cookies] = currencyUser($this->team);

    $this->actingAs($staff)->withCookies($cookies)->withCredentials()->get('/mr-pride/inventory')
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency.preferred', 'USD')
            ->where('currency.base', 'USD')
            ->where('currency.rate', null));

    expect($staff->fresh()->preferred_currency)->toBe('USD');
    Http::assertNothingSent(); // USD needs no exchange rate
});

it('saves the choice per user, keeps it across pages and visits, and switches back', function () {
    [$staff, $cookies] = currencyUser($this->team);
    $as = fn () => $this->actingAs($staff->fresh())->withCookies($cookies)->withCredentials();

    $as()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk()->assertJson(['preferred' => 'PHP']);
    expect($staff->fresh()->preferred_currency)->toBe('PHP');

    // Another module, and a fresh page load (same as a browser refresh or signing in again).
    $as()->get('/mr-pride/inventory')->assertInertia(fn (Assert $p) => $p->where('currency.preferred', 'PHP'));
    $as()->get('/settings/profile')->assertInertia(fn (Assert $p) => $p->where('currency.preferred', 'PHP'));

    $as()->putJson('/settings/currency', ['currency' => 'USD'])->assertOk();
    $as()->get('/mr-pride/inventory')->assertInertia(fn (Assert $p) => $p->where('currency.preferred', 'USD'));
});

it('keeps each staff account preference separate', function () {
    [$ana, $anaCookies] = currencyUser($this->team);
    [$ben, $benCookies] = currencyUser($this->team, 'staff_sales');

    $this->actingAs($ana)->withCookies($anaCookies)->withCredentials()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk();

    expect($ana->fresh()->preferred_currency)->toBe('PHP')
        ->and($ben->fresh()->preferred_currency)->toBe('USD');
    $this->actingAs($ben->fresh())->withCookies($benCookies)->withCredentials()->get('/mr-pride/inventory')
        ->assertInertia(fn (Assert $p) => $p->where('currency.preferred', 'USD'));
});

it('only accepts USD or PHP', function () {
    [$staff, $cookies] = currencyUser($this->team);

    $this->actingAs($staff)->withCookies($cookies)->withCredentials()->putJson('/settings/currency', ['currency' => 'EUR'])
        ->assertStatus(422)->assertJsonPath('message', 'Choose USD or PHP.');
    $this->actingAs($staff)->withCookies($cookies)->withCredentials()->putJson('/settings/currency', [])->assertStatus(422);

    expect($staff->fresh()->preferred_currency)->toBe('USD');
});

it('is not available to guests or customers, and public pages get no currency setting', function () {
    $this->putJson('/settings/currency', ['currency' => 'PHP'])->assertRedirect(route('login'));
    $this->getJson('/settings/currency/rate')->assertRedirect(route('login'));

    $customer = User::factory()->create(['role' => 'user', 'password_changed_at' => now()]);
    $this->actingAs($customer)->putJson('/settings/currency', ['currency' => 'PHP'])->assertNotFound();
    expect($customer->fresh()->preferred_currency)->toBe('USD');

    auth()->logout();
    $this->get('/quotation')->assertInertia(fn (Assert $p) => $p->where('currency', null));
    Http::assertNothingSent();
});

// --- Exchange rate ------------------------------------------------------------------------

it('fetches the USD to PHP rate, caches it for an hour, and shares it with pages', function () {
    fakeRate(63.838);
    [$staff, $cookies] = currencyUser($this->team);
    $as = fn () => $this->actingAs($staff)->withCookies($cookies)->withCredentials();

    $as()->getJson('/settings/currency/rate')->assertOk()
        ->assertJson(['status' => 'ok', 'rate' => 63.838, 'date' => '2026-10-30']);
    $as()->getJson('/settings/currency/rate')->assertOk();
    Http::assertSentCount(1);

    $as()->get('/mr-pride/inventory')->assertInertia(fn (Assert $p) => $p
        ->where('currency.rate.rate', 63.838)
        ->where('currency.rate.date', '2026-10-30'));

    // After an hour the rate is fetched again.
    $this->travel(61)->minutes();
    $as()->getJson('/settings/currency/rate')->assertOk();
    Http::assertSentCount(2);
});

it('reports an unavailable rate instead of converting when the service fails', function (mixed $response, string $message) {
    Http::fake([RATE_URL => $response]);
    [$staff, $cookies] = currencyUser($this->team);

    $this->actingAs($staff)->withCookies($cookies)->withCredentials()->getJson('/settings/currency/rate')
        ->assertStatus(503)
        ->assertJson(['status' => 'unavailable', 'message' => $message, 'last_success' => null])
        ->assertJsonMissingPath('rate');

    expect(app(ExchangeRates::class)->cached())->toBeNull();
})->with([
    'timeout' => [fn () => Http::failedConnection(), 'The exchange rate service could not be reached.'],
    'server error' => [fn () => Http::response('Service Unavailable', 503), 'The exchange rate service returned an error (HTTP 503).'],
    'not JSON' => [fn () => Http::response('<html>maintenance</html>', 200), 'The exchange rate service sent an invalid response.'],
    'missing rate' => [fn () => Http::response(['date' => '2026-10-30', 'base' => 'USD', 'quote' => 'PHP']), 'The exchange rate service sent an invalid response.'],
    'wrong pair' => [fn () => Http::response(['date' => '2026-10-30', 'base' => 'EUR', 'quote' => 'PHP', 'rate' => 70.1]), 'The exchange rate service sent an invalid response.'],
    'zero rate' => [fn () => Http::response(['date' => '2026-10-30', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 0]), 'The exchange rate service sent an invalid response.'],
    'impossible date' => [fn () => Http::response(['date' => '2026-02-31', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 63.8]), 'The exchange rate service sent an invalid response.'],
    'too old' => [fn () => Http::response(['date' => '2026-09-01', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 58.1]), 'The latest exchange rate available is from Sep 1, 2026, which is too old to use.'],
]);

it('waits a minute after a failure unless Retry is pressed, then recovers', function () {
    Http::fakeSequence(RATE_URL)
        ->push('down', 500)
        ->push(['date' => '2026-10-30', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 63.9]);
    [$staff, $cookies] = currencyUser($this->team);
    $as = fn () => $this->actingAs($staff)->withCookies($cookies)->withCredentials();

    $as()->getJson('/settings/currency/rate')->assertStatus(503);
    $as()->getJson('/settings/currency/rate')->assertStatus(503); // not asked again yet
    Http::assertSentCount(1);

    $as()->getJson('/settings/currency/rate?refresh=1')->assertOk()->assertJson(['status' => 'ok', 'rate' => 63.9]);
    Http::assertSentCount(2);
});

it('names the last good rate date when the service is down, without using that rate', function () {
    Http::fakeSequence(RATE_URL)
        ->push(['date' => '2026-10-30', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 63.838])
        ->push('down', 500);
    [$staff, $cookies] = currencyUser($this->team);
    $as = fn () => $this->actingAs($staff)->withCookies($cookies)->withCredentials();

    $as()->getJson('/settings/currency/rate')->assertOk();
    $this->travel(61)->minutes(); // the cached rate has expired

    $as()->getJson('/settings/currency/rate')->assertStatus(503)
        ->assertJsonPath('status', 'unavailable')
        ->assertJsonPath('last_success.date', '2026-10-30')
        ->assertJsonMissingPath('rate');
    $as()->get('/mr-pride/inventory')->assertInertia(fn (Assert $p) => $p->where('currency.rate', null));
});

// --- Stored amounts are never changed -----------------------------------------------------

it('never changes stored amounts: pages always receive the original USD values', function () {
    fakeRate(63.838);
    [$admin, $cookies] = currencyUser($this->team, 'admin');
    $product = Product::create([
        'team_id' => $this->team->id, 'sku' => 'BRK-100', 'name' => 'Breaker 100A', 'category' => 'Breakers',
        'unit' => 'pcs', 'price' => 100.00, 'cost_price' => 70.00, 'stock' => 5,
    ]);
    $as = fn () => $this->actingAs($admin->fresh())->withCookies($cookies)->withCredentials();

    $as()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk();
    $as()->getJson('/settings/currency/rate')->assertOk();
    $as()->get('/mr-pride/inventory')->assertInertia(fn (Assert $p) => $p
        ->where('currency.preferred', 'PHP')
        ->where('products.data.0.price', '100.00'));
    $as()->putJson('/settings/currency', ['currency' => 'USD'])->assertOk();
    $as()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk();

    expect($product->fresh())->price->toBe('100.00')->cost_price->toBe('70.00');

    // Archived records send their amount as a number for the page to format.
    $product->delete();
    $as()->get('/mr-pride/recycle-bin')->assertInertia(fn (Assert $p) => $p
        ->where('records.data.0.amount', 100)
        ->where('records.data.0.summary', '5 pcs'));
});
