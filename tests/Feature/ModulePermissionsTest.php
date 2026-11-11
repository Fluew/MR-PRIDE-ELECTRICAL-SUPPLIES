<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function moduleTestUser(Team $team, string $role, array $modules = []): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    if ($modules !== []) {
        $user->syncModules($modules);
    }

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'mod-team']);
});

it('lets an administrator create a staff account with several modules', function () {
    [$admin, $cookies] = moduleTestUser($this->team, 'admin');

    $this->actingAs($admin)->withCookies($cookies)
        ->post('/mod-team/users', [
            'first_name' => 'Maria', 'last_name' => 'Cruz', 'email' => 'maria@example.com',
            'account_type' => 'staff', 'modules' => ['inventory', 'sales', 'reports'],
        ])
        ->assertSessionHasNoErrors();

    $staff = User::where('email', 'maria@example.com')->firstOrFail();
    expect($staff->role)->toBe('staff_inventory')
        ->and($staff->assignedModules())->toBe(['inventory', 'sales', 'reports'])
        ->and($staff->roleLabel())->toBe('Staff (Inventory, Sales, Reports & Analytics)')
        ->and($staff->assignedModuleSlug())->toBe('inventory');
});

it('creates administrators without module rows', function () {
    [$admin, $cookies] = moduleTestUser($this->team, 'admin');

    $this->actingAs($admin)->withCookies($cookies)
        ->post('/mod-team/users', [
            'first_name' => 'Ana', 'last_name' => 'Lim', 'email' => 'ana@example.com',
            'account_type' => 'admin', 'modules' => ['sales'],
        ])->assertSessionHasNoErrors();

    $created = User::where('email', 'ana@example.com')->firstOrFail();
    expect($created->role)->toBe('admin')->and($created->modulePermissions()->count())->toBe(0);
});

it('rejects staff accounts without modules and unknown modules', function () {
    [$admin, $cookies] = moduleTestUser($this->team, 'admin');
    $base = ['first_name' => 'Bo', 'last_name' => 'Ng', 'email' => 'bo@example.com', 'account_type' => 'staff'];

    $this->actingAs($admin)->withCookies($cookies)->post('/mod-team/users', $base + ['modules' => []])
        ->assertSessionHasErrors('modules');
    $this->actingAs($admin)->withCookies($cookies)->post('/mod-team/users', $base + ['modules' => ['payroll']])
        ->assertSessionHasErrors('modules.0');
    $this->actingAs($admin)->withCookies($cookies)->post('/mod-team/users', $base + ['modules' => ['sales', 'sales']])
        ->assertSessionHasErrors('modules.0');

    expect(User::where('email', 'bo@example.com')->exists())->toBeFalse();
});

it('still accepts the legacy single role format', function () {
    [$admin, $cookies] = moduleTestUser($this->team, 'admin');

    $this->actingAs($admin)->withCookies($cookies)
        ->post('/mod-team/users', [
            'first_name' => 'Old', 'last_name' => 'Style', 'email' => 'old@example.com', 'role' => 'staff_deliveries',
        ])->assertSessionHasNoErrors();

    $u = User::where('email', 'old@example.com')->firstOrFail();
    expect($u->role)->toBe('staff_deliveries')->and($u->assignedModules())->toBe(['deliveries']);
});

it('lets staff open only their assigned modules, including by typing URLs', function () {
    [$staff, $cookies] = moduleTestUser($this->team, 'staff_inventory', ['inventory', 'sales']);
    $as = fn () => $this->actingAs($staff)->withCookies($cookies);

    $as()->get('/mod-team/inventory')->assertOk();
    $as()->get('/mod-team/sales')->assertOk();

    // Unassigned modules / admin-only areas show the 403 Forbidden page.
    foreach (['quotations', 'suppliers', 'procurement', 'forecast', 'reports', 'users', 'recycle-bin'] as $path) {
        $as()->get("/mod-team/{$path}")->assertForbidden();
    }
});

it('does not let a stale legacy role widen explicit module access', function () {
    // role says inventory, but the explicit assignment is only "sales"
    [$staff, $cookies] = moduleTestUser($this->team, 'staff_inventory', ['sales']);

    $this->actingAs($staff)->withCookies($cookies)->get('/mod-team/inventory')->assertForbidden();
    $this->actingAs($staff)->withCookies($cookies)->get('/mod-team/sales')->assertOk();
});

it('keeps legacy single-role staff working without module rows', function () {
    [$staff, $cookies] = moduleTestUser($this->team, 'staff_quotations');

    expect($staff->hasExplicitModules())->toBeFalse()->and($staff->assignedModules())->toBe(['quotations']);
    $this->actingAs($staff)->withCookies($cookies)->get('/mod-team/quotations')->assertOk();
    $this->actingAs($staff)->withCookies($cookies)->get('/mod-team/inventory')->assertForbidden();
});

it('applies permission changes on the very next request', function () {
    [$admin, $adminCookies] = moduleTestUser($this->team, 'admin');
    [$staff, $staffCookies] = moduleTestUser($this->team, 'staff_inventory', ['inventory']);

    $this->actingAs($staff)->withCookies($staffCookies)->get('/mod-team/sales')->assertForbidden();

    $this->actingAs($admin)->withCookies($adminCookies)
        ->put("/mod-team/users/{$staff->id}", ['name' => $staff->name, 'account_type' => 'staff', 'modules' => ['sales']])
        ->assertSessionHasNoErrors();

    $this->actingAs($staff->fresh())->withCookies($staffCookies)->get('/mod-team/sales')->assertOk();
    $this->actingAs($staff->fresh())->withCookies($staffCookies)->get('/mod-team/inventory')->assertForbidden();
    expect($staff->fresh()->role)->toBe('staff_sales');
});

it('shows assigned modules in the user list and the shared auth props', function () {
    [$admin, $adminCookies] = moduleTestUser($this->team, 'admin');
    [$staff, $staffCookies] = moduleTestUser($this->team, 'staff_inventory', ['inventory', 'deliveries']);

    $this->actingAs($admin)->withCookies($adminCookies)->get('/mod-team/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Users/Index')
            ->has('moduleOptions', count(User::moduleOptions()))
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $staff->id)['modules'] === ['inventory', 'deliveries']),
        );

    $this->actingAs($staff)->withCookies($staffCookies)->get('/mod-team/inventory')
        ->assertInertia(fn (Assert $page) => $page->where('auth.modules', ['inventory', 'deliveries']));
});

it('stops staff from creating accounts, editing assignments or granting themselves admin', function () {
    [$staff, $cookies] = moduleTestUser($this->team, 'staff_inventory', ['inventory']);
    $as = fn () => $this->actingAs($staff)->withCookies($cookies);

    $as()->put("/mod-team/users/{$staff->id}", ['name' => 'Me', 'account_type' => 'admin'])->assertForbidden();
    $as()->put("/mod-team/users/{$staff->id}", ['name' => 'Me', 'role' => 'admin'])->assertForbidden();
    $as()->put("/mod-team/users/{$staff->id}", ['name' => 'Me', 'account_type' => 'staff', 'modules' => ['reports']])->assertForbidden();
    $as()->post('/mod-team/users', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'account_type' => 'admin'])->assertForbidden();

    $staff->refresh();
    expect($staff->role)->toBe('staff_inventory')
        ->and($staff->assignedModules())->toBe(['inventory'])
        ->and(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

it('does not let an administrator change their own role through the new fields', function () {
    [$admin, $cookies] = moduleTestUser($this->team, 'admin');

    $this->actingAs($admin)->withCookies($cookies)
        ->put("/mod-team/users/{$admin->id}", ['name' => 'Admin', 'account_type' => 'staff', 'modules' => ['sales']])
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->role)->toBe('admin');
});

it('keeps delivery access for procurement and inventory staff as before', function () {
    [$procurement, $c1] = moduleTestUser($this->team, 'staff_procurement', ['procurement']);
    [$sales, $c2] = moduleTestUser($this->team, 'staff_sales', ['sales']);

    $this->actingAs($procurement)->withCookies($c1)->get('/mod-team/deliveries')->assertOk();
    $this->actingAs($sales)->withCookies($c2)->get('/mod-team/deliveries')->assertForbidden();
});

it('sends staff to their first assigned module from the workspace dashboard', function () {
    [$staff, $cookies] = moduleTestUser($this->team, 'staff_reports', ['reports', 'inventory']);

    $this->actingAs($staff)->withCookies($cookies)->get('/mod-team/dashboard')->assertRedirect('/mod-team/reports');
});
