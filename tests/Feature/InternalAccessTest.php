<?php

use App\Enums\TeamRole;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Separation between the public site and the internal Admin/Staff interface:
 * who can open what, what they see when they can't, and where login sends them.
 */
const INTERNAL_PASSWORD = 'Secret123!';

function internalUser(Team $team, string $role, array $modules = []): array
{
    $user = User::factory()->create([
        'role' => $role,
        'status' => 'active',
        'current_team_id' => $team->id,
        'password' => Hash::make(INTERNAL_PASSWORD),
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    // The factory switches new users to their personal team; work in the shared team instead.
    $user->forceFill(['current_team_id' => $team->id])->save();
    if ($modules !== []) {
        $user->syncModules($modules);
    }

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'int-team']);
});

// --- Customers: browse and request quotes only ---------------------------------------------

it('keeps signed-in customer accounts out of settings and teams (404)', function () {
    $customer = User::factory()->create(['role' => 'user']);
    $teamCount = Team::count();

    foreach (['/settings', '/settings/profile', '/settings/security', '/settings/teams'] as $url) {
        $this->actingAs($customer)->get($url)->assertNotFound();
    }
    $this->actingAs($customer)->post('/settings/teams', ['name' => 'Customer Team'])->assertNotFound();

    expect(Team::count())->toBe($teamCount);
});

it('keeps customers out of the internal workspace', function () {
    $customer = User::factory()->create(['role' => 'user']);
    $this->team->members()->attach($customer, ['role' => TeamRole::Member->value]);

    $this->actingAs($customer)->get('/int-team/inventory')->assertNotFound();
    $this->actingAs($customer)->get('/dashboard')->assertNotFound();
});

it('shows the public 404 page for unknown public URLs', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('NotFound'));
});

// --- Guests --------------------------------------------------------------------------------

it('sends guests and expired sessions on internal pages to the login page', function () {
    foreach (['/int-team/dashboard', '/int-team/inventory', '/int-team/users', '/settings/profile'] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

// --- Staff ---------------------------------------------------------------------------------

it('lets staff with a trusted device use their own settings pages', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_inventory', ['inventory']);

    $this->actingAs($staff)->withCookies($cookies)->get('/settings/profile')->assertOk();
});

it('shows staff the 403 page for modules they are not assigned', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_inventory', ['inventory']);

    $this->actingAs($staff)->withCookies($cookies)
        ->get('/int-team/reports')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Forbidden')
            ->where('homeUrl', '/dashboard')
            ->has('message'));

    // Admin-only areas too.
    $this->actingAs($staff)->withCookies($cookies)->get('/int-team/users')->assertForbidden();
});

it('returns JSON 403 to API-style requests instead of the page', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_inventory', ['inventory']);

    $this->actingAs($staff)->withCredentials()->withCookies($cookies)
        ->getJson('/int-team/reports')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('shows the 403 page when staff try to delete', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_suppliers', ['suppliers']);
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Keep Me']);

    $this->actingAs($staff)->withCookies($cookies)
        ->delete("/int-team/suppliers/{$supplier->id}")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Forbidden')
            ->where('message', 'Only administrators can delete records.'));

    expect(Supplier::find($supplier->id))->not->toBeNull();
});

it('tells the frontend who is an admin', function () {
    [$staff, $staffCookies] = internalUser($this->team, 'staff_inventory', ['inventory']);
    [$admin, $adminCookies] = internalUser($this->team, 'admin');

    $this->actingAs($staff)->withCookies($staffCookies)->get('/int-team/inventory')
        ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', false)->where('auth.modules', ['inventory']));

    $this->actingAs($admin)->withCookies($adminCookies)->get('/int-team/inventory')
        ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', true));
});

// --- Where login sends you -----------------------------------------------------------------

function loginAs(User $user, array $cookies, ?string $intended = null)
{
    $request = test()->withCookies($cookies);
    if ($intended) {
        $request = $request->withSession(['url.intended' => $intended]);
    }

    return $request->post('/login', ['email' => $user->email, 'password' => INTERNAL_PASSWORD]);
}

it('sends admins to the dashboard and staff to their first module', function () {
    [$admin, $adminCookies] = internalUser($this->team, 'admin');
    loginAs($admin, $adminCookies)->assertRedirect('/int-team/dashboard');

    auth()->logout();

    [$staff, $staffCookies] = internalUser($this->team, 'staff_sales', ['sales', 'inventory']);
    loginAs($staff, $staffCookies)->assertRedirect('/int-team/sales');
});

it('sends staff back to the page they asked for when they may open it', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_sales', ['sales', 'inventory']);

    loginAs($staff, $cookies, url('/int-team/inventory'))->assertRedirect(url('/int-team/inventory'));
});

it('ignores a requested page staff may not open and uses their landing page', function () {
    [$staff, $cookies] = internalUser($this->team, 'staff_sales', ['sales']);
    $otherTeam = Team::factory()->create(['slug' => 'other-team']);

    foreach ([
        url('/int-team/reports'),      // unassigned module
        url('/int-team/users'),        // admin-only
        url('/other-team/sales'),      // a team they are not in
        'https://evil.example/int-team/sales', // another site
    ] as $intended) {
        loginAs($staff, $cookies, $intended)->assertRedirect('/int-team/sales');
        auth()->logout();
    }
});
