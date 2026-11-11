<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function logoutTestWorkspace(string $role = 'staff_inventory'): array
{
    $team = Team::factory()->create(['slug' => 'logout-team']);
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $device = createTrustedDeviceFor($user);

    return [$team, $user, $device['cookies']];
}

test('logout invalidates the session, regenerates the token and sends no-store headers', function () {
    [, $user, $cookies] = logoutTestWorkspace();

    $response = $this->actingAs($user)
        ->withCookies($cookies)
        ->withSession(['_token' => 'old-token', 'last_activity_time' => time()])
        ->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(session('_token'))->not->toBe('old-token');
    expect(session('last_activity_time'))->toBeNull();
});

test('the login page served right after logout tells the client to clear its history', function () {
    [, $user, $cookies] = logoutTestWorkspace();

    $this->actingAs($user)->withCookies($cookies)->post(route('logout'));

    $this->withCookies($cookies)->get(route('login'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('auth/Login');
            expect($page->toArray())->toHaveKey('clearHistory', true);
        });
});

test('protected workspace pages are not reachable after logout', function () {
    [$team, $user, $cookies] = logoutTestWorkspace();

    $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/inventory")->assertOk();

    $this->post(route('logout'));
    $this->assertGuest();

    // Refresh / direct URL entry / Back-then-reload all hit the server unauthenticated.
    $this->withCookies($cookies)->get("/{$team->slug}/inventory")->assertRedirect(route('login'));
    $this->withCookies($cookies)->get("/{$team->slug}/dashboard")->assertRedirect(route('login'));
    $this->withCookies($cookies)->get('/settings/profile')->assertRedirect(route('login'));
});

test('authenticated pages are sent with no-store and encrypted client history', function () {
    [$team, $user, $cookies] = logoutTestWorkspace();

    $workspace = $this->actingAs($user)->withCookies($cookies)->get("/{$team->slug}/inventory");
    $workspace->assertOk();
    expect($workspace->headers->get('Cache-Control'))->toContain('no-store');
    $workspace->assertInertia(fn (Assert $page) => expect($page->toArray())->toHaveKey('encryptHistory', true));

    $settings = $this->actingAs($user)->withCookies($cookies)->get('/settings/profile');
    $settings->assertOk();
    expect($settings->headers->get('Cache-Control'))->toContain('no-store');
});

test('guest pages do not use encrypted history', function () {
    [, , $cookies] = logoutTestWorkspace();

    $this->withCookies($cookies)->get(route('login'))
        ->assertInertia(fn (Assert $page) => expect($page->toArray())->not->toHaveKey('encryptHistory'));
});

test('login then logout still works end to end', function () {
    $team = Team::factory()->create(['slug' => 'e2e-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $cookies = createTrustedDeviceFor($user)['cookies'];

    $this->withCookies($cookies)->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirectContains('/dashboard');
    $this->assertAuthenticated();

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});
