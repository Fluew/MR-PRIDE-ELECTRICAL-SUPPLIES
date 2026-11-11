<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'filter-team']);

    $member = function (string $name, string $role, string $status = 'active'): User {
        $user = User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => $status,
            'current_team_id' => $this->team->id,
            'password_changed_at' => now(),
        ]);
        $this->team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

        return $user;
    };

    $this->admin = $member('Alice Admin', 'admin');
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
    $member('Oscar Owner', 'superadmin');
    $member('Ivy Inventory', 'staff_inventory');
    $member('Sam Sales', 'staff_sales', 'inactive');
    $member('Uma User', 'user');
});

it('offers only Administrator and Staff as role filter options', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roleFilterOptions', [
                ['value' => 'admin', 'label' => 'Administrator'],
                ['value' => 'staff', 'label' => 'Staff'],
            ])
            ->where('filters.role', 'All Roles')
            ->where('accounts.total', 5)
            ->where('roleCounts', ['All Roles' => 5, 'admin' => 2, 'staff' => 2])
        );
});

it('lists only staff accounts of any module when filtering by Staff', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=staff')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.role', 'staff')
            ->where('accounts.total', 2)
            ->where('accounts.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ivy Inventory', 'Sam Sales'])
            // The unfiltered member list used by the device pickers stays complete.
            ->has('users', 5)
        );
});

it('lists every account shown as Administrator when filtering by Administrator', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=admin')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Alice Admin', 'Oscar Owner'])
        );
});

it('combines the role filter with search and status, with counts following them', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=staff&search=sam')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.total', 1)
            ->where('accounts.data.0.name', 'Sam Sales')
            ->where('roleCounts', ['All Roles' => 1, 'admin' => 0, 'staff' => 1])
        );

    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=staff&status=active')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.total', 1)
            ->where('accounts.data.0.name', 'Ivy Inventory')
        );
});

it('shows an empty list, not every member, when nothing matches', function () {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=staff&search=nobody')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.total', 0)
            ->has('accounts.data', 0)
        );
});

it('treats the removed Owner / Superadmin and User options as All Roles without changing any account', function (string $role) {
    $this->actingAs($this->admin)->withCookies($this->cookies)->get("/filter-team/users?role={$role}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.role', 'All Roles')
            ->where('accounts.total', 5)
        );

    expect(User::where('role', 'superadmin')->count())->toBe(1)
        ->and(User::where('role', 'user')->count())->toBe(1);
})->with(['superadmin', 'user']);

it('keeps the role filter across pages', function () {
    foreach (range(1, 16) as $i) {
        $user = User::factory()->create(['name' => sprintf('Staff %02d', $i), 'role' => 'staff_reports', 'current_team_id' => $this->team->id]);
        $this->team->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=staff&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.total', 18)
            ->where('accounts.current_page', 2)
            ->has('accounts.data', 3)
            ->where('accounts.links.1.url', fn ($url) => str_contains($url, 'role=staff'))
        );

    // A page past the end of a narrower filter shows its last page instead of an empty list.
    $this->actingAs($this->admin)->withCookies($this->cookies)->get('/filter-team/users?role=admin&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.current_page', 1)
            ->has('accounts.data', 2)
        );
});
