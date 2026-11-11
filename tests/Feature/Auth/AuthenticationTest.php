<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('login screen can be rendered on a trusted device', function () {
    $deviceData = createTrustedDeviceFor();

    $response = $this->withCookies($deviceData['cookies'])->get(route('login'));

    $response->assertOk();
});

test('login screen includes team invitation context', function () {
    $deviceData = createTrustedDeviceFor();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->withCookies($deviceData['cookies'])->get(route('login', ['invitation' => $invitation->code]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('teamInvitation.code', $invitation->code)
        ->where('teamInvitation.teamName', 'Laravel Team'),
    );
});

test('users can authenticate using the login screen on authorized trusted device', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $teamSlug = $user->personalTeam()?->slug ?? 'mr-pride';
    $deviceData = createTrustedDeviceFor($user);

    $response = $this->withCookies($deviceData['cookies'])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect("/{$teamSlug}/dashboard");
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $deviceData = createTrustedDeviceFor($user);

    $response = $this->withCookies($deviceData['cookies'])->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $deviceData = createTrustedDeviceFor($user);

    $this->withCookies($deviceData['cookies'])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
});

test('users are rate limited', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $deviceData = createTrustedDeviceFor($user);

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->withCookies($deviceData['cookies'])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('authenticated admin visiting login is redirected to dashboard without url generation error', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $team = $user->currentTeam ?: $user->personalTeam();
    $deviceData = createTrustedDeviceFor($user);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get(route('login'));

    $response->assertRedirect("/{$team->slug}/dashboard");
});

test('authenticated staff with null current_team_id visiting login is redirected to module without url error', function () {
    $user = User::factory()->create([
        'role' => 'staff_inventory',
        'current_team_id' => null,
        'password_changed_at' => now(),
    ]);
    $team = Team::first() ?: Team::factory()->create(['slug' => 'mr-pride']);
    $team->members()->syncWithoutDetaching([$user->id => ['role' => TeamRole::Member->value]]);
    $user->forceFill(['current_team_id' => null])->saveQuietly();

    $deviceData = createTrustedDeviceFor($user);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get(route('login'));

    $response->assertRedirect("/{$team->slug}/inventory");
});
