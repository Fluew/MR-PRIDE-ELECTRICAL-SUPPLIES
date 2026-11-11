<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests attempting to access dashboard are sent to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = $user->currentTeam;
    $deviceData = createTrustedDeviceFor($user);

    $response = $this
        ->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create([
        'email' => 'invited@example.com',
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $deviceData = createTrustedDeviceFor($invitedUser);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create([
        'email' => 'invited@example.com',
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = Team::factory()->create();
    $deviceData = createTrustedDeviceFor($invitedUser);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create([
        'email' => 'invited@example.com',
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = Team::factory()->create();
    $deviceData = createTrustedDeviceFor($invitedUser);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create([
        'email' => 'invited@example.com',
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = Team::factory()->create();
    $deviceData = createTrustedDeviceFor($invitedUser);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard passes isFirstLogin true on first time login and includes user first name', function () {
    $user = User::factory()->create([
        'name' => 'Juan Dela Cruz',
        'role' => 'admin',
        'password_changed_at' => now(),
        'last_login_at' => null,
    ]);
    $deviceData = createTrustedDeviceFor($user);

    $response = $this
        ->actingAs($user)
        ->withSession(['is_first_login' => true])
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('isFirstLogin', true)
        ->where('auth.user.first_name', 'Juan')
    );
});

test('dashboard passes isFirstLogin false for returning user', function () {
    $user = User::factory()->create([
        'name' => 'Juan Dela Cruz',
        'role' => 'admin',
        'password_changed_at' => now(),
        'last_login_at' => now()->subDays(2),
        'created_at' => now()->subDays(10),
    ]);
    $deviceData = createTrustedDeviceFor($user);

    $response = $this
        ->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('isFirstLogin', false)
        ->where('auth.user.first_name', 'Juan')
    );
});
