<?php

/*
 * 4.4.1 Unit Testing — Authentication and Access Administration (UT-01 to UT-14).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 */

use App\Http\Middleware\EnsureRole;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
});

function capLoginUser(array $attributes = []): array
{
    $user = User::factory()->create($attributes + ['role' => 'admin', 'password_changed_at' => now()]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

it('UT-01 accepts valid login credentials on an authorized device', function () {
    [$user, $cookies] = capLoginUser();

    $response = $this->withCookies($cookies)->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/dashboard');
});

it('UT-02 rejects a wrong password', function () {
    [$user, $cookies] = capLoginUser();

    $this->withCookies($cookies)->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('UT-03 requires the email and password fields', function () {
    [, $cookies] = capLoginUser();

    $this->withCookies($cookies)->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
});

it('UT-04 rejects an email address that has no account', function () {
    [, $cookies] = capLoginUser();

    $this->withCookies($cookies)->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('UT-05 blocks a login attempt from a device that is not registered', function () {
    $user = User::factory()->create(['role' => 'admin', 'password_changed_at' => now()]);

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertNotFound();
    $this->assertGuest();
});

it('UT-06 enforces the password policy: 12 characters with upper and lower case, a number and a symbol', function () {
    $passes = fn (string $password) => Validator::make(['password' => $password], ['password' => [Password::default()]])->passes();

    expect($passes('Abcdefgh1!x'))->toBeFalse()        // 11 characters: one below the minimum
        ->and($passes('Abcdefgh1!xy'))->toBeTrue()     // 12 characters: the minimum
        ->and($passes('abcdefgh1!xyz'))->toBeFalse()   // no uppercase letter
        ->and($passes('ABCDEFGH1!XYZ'))->toBeFalse()   // no lowercase letter
        ->and($passes('Abcdefghij!xyz'))->toBeFalse()  // no number
        ->and($passes('Abcdefgh12xyz'))->toBeFalse()   // no symbol
        ->and($passes(''))->toBeFalse();
});

it('UT-07 changes a password only with the correct current password and a new, different one', function () {
    $user = User::factory()->create(['role' => 'staff_sales', 'password' => Hash::make('OldPassw0rd!x'), 'must_change_password' => true]);
    $put = fn (array $data) => $this->actingAs($user->fresh())->put('/password/change', $data);

    $put(['current_password' => 'not-my-password', 'password' => 'NewPassw0rd!xy', 'password_confirmation' => 'NewPassw0rd!xy'])
        ->assertSessionHasErrors('current_password');
    $put(['current_password' => 'OldPassw0rd!x', 'password' => 'OldPassw0rd!x', 'password_confirmation' => 'OldPassw0rd!x'])
        ->assertSessionHasErrors('password');
    $put(['current_password' => 'OldPassw0rd!x', 'password' => 'NewPassw0rd!xy', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors('password');
    expect(Hash::check('OldPassw0rd!x', $user->fresh()->password))->toBeTrue();

    $put(['current_password' => 'OldPassw0rd!x', 'password' => 'NewPassw0rd!xy', 'password_confirmation' => 'NewPassw0rd!xy'])
        ->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $user->refresh();
    expect(Hash::check('NewPassw0rd!xy', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->password_history)->toHaveCount(1);
});

it('UT-08 expires a staff password at 90 days and never expires a customer password', function () {
    $staff = fn (array $a) => new User($a + ['role' => 'staff_sales', 'must_change_password' => false]);

    expect($staff(['password_changed_at' => now()->subDays(89)])->isPasswordExpired())->toBeFalse()
        ->and($staff(['password_changed_at' => now()->subDays(90)])->isPasswordExpired())->toBeTrue()
        ->and($staff(['password_changed_at' => now()->subDays(91)])->isPasswordExpired())->toBeTrue()
        ->and($staff(['password_changed_at' => null])->isPasswordExpired())->toBeTrue()
        ->and($staff(['password_changed_at' => now(), 'must_change_password' => true])->isPasswordExpired())->toBeTrue()
        ->and((new User(['role' => 'user', 'password_changed_at' => null]))->isPasswordExpired())->toBeFalse();
});

it('UT-09 grants each role only its own module (role-based permissions)', function () {
    $user = fn (string $role) => User::factory()->create(['role' => $role]);

    expect(EnsureRole::allows($user('admin'), ['staff_sales']))->toBeTrue()                       // admin: every module
        ->and(EnsureRole::allows($user('staff_sales'), ['admin', 'staff_sales']))->toBeTrue()
        ->and(EnsureRole::allows($user('staff_sales'), ['admin', 'staff_inventory']))->toBeFalse()
        ->and(EnsureRole::allows($user('staff_inventory'), ['admin', 'staff_procurement', 'staff_inventory']))->toBeTrue()
        ->and(EnsureRole::allows($user('staff'), ['admin', 'staff_inventory', 'staff']))->toBeTrue() // legacy role = inventory
        ->and(EnsureRole::allows($user('staff_reports'), ['admin']))->toBeFalse()                 // admin-only area
        ->and(EnsureRole::allows($user('user'), ['admin', 'staff_sales']))->toBeFalse();          // customer account
});

it('UT-10 uses explicit module assignments instead of the legacy role when they exist', function () {
    $user = User::factory()->create(['role' => 'staff_sales']);
    $user->syncModules(['inventory', 'reports', 'not-a-module']);
    $user = $user->fresh();

    expect($user->assignedModules())->toBe(['inventory', 'reports'])
        ->and(EnsureRole::allows($user, ['admin', 'staff_inventory']))->toBeTrue()
        ->and(EnsureRole::allows($user, ['admin', 'staff_reports']))->toBeTrue()
        ->and(EnsureRole::allows($user, ['admin', 'staff_sales']))->toBeFalse() // the stale role does not widen access
        ->and($user->roleLabel())->toBe('Staff (Inventory, Reports & Analytics)');
});

it('UT-11 recognises a device only by its registered id and matching credential', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $data = createTrustedDeviceFor($user);
    $request = fn (array $cookies) => Request::create('/login', 'GET', [], $cookies);

    expect(TrustedDevice::findActiveByCredential($request($data['cookies']))?->id)->toBe($data['device']->id)
        ->and(TrustedDevice::findActiveByCredential($request(['mrpride_device_uuid' => $data['device']->device_uuid, 'mrpride_device_credential' => 'forged-credential'])))->toBeNull()
        ->and(TrustedDevice::findActiveByCredential($request(['mrpride_device_uuid' => $data['device']->device_uuid])))->toBeNull()
        ->and(TrustedDevice::findActiveByCredential($request(['mrpride_device_uuid' => 'unknown-device', 'mrpride_device_credential' => $data['raw_credential']])))->toBeNull()
        ->and(TrustedDevice::findActiveByCredential($request([])))->toBeNull();

    $data['device']->update(['status' => 'revoked']);
    expect(TrustedDevice::findActiveByCredential($request($data['cookies'])))->toBeNull();
});

it('UT-12 authorizes a device for the one account it is bound to', function () {
    $owner = User::factory()->create(['role' => 'staff_sales']);
    $other = User::factory()->create(['role' => 'staff_sales']);
    $device = createTrustedDeviceFor($owner)['device'];

    expect($device->isAuthorizedForUser($owner))->toBeTrue()
        ->and($device->isAuthorizedForUser($other))->toBeFalse();

    $device->update(['status' => 'pending']);
    expect($device->fresh()->isAuthorizedForUser($owner))->toBeFalse();
});

it('UT-13 builds the temporary first-login password from the first name', function () {
    expect(User::initialPasswordFor('juan carlos'))->toBe('Juan1234')
        ->and(User::initialPasswordFor('MARÍA'))->toBe('Maria1234')
        ->and(User::initialPasswordFor(''))->toBe('User1234')
        ->and(User::initialPasswordFor('1234'))->toBe('User1234')
        ->and(User::initialPasswordFor('J'))->toMatch(User::INITIAL_PASSWORD_PATTERN);
});

it('UT-14 treats only active and pending-activation accounts as usable, and blocks a deactivated one', function () {
    expect((new User(['status' => 'active']))->isActive())->toBeTrue()
        ->and((new User(['status' => 'pending_activation']))->isActive())->toBeTrue()
        ->and((new User(['status' => 'inactive']))->isActive())->toBeFalse()
        ->and((new User(['status' => 'suspended']))->isActive())->toBeFalse();

    $team = Team::factory()->create();
    [$staff, $cookies] = capActor('staff_inventory', $team, ['status' => 'inactive']);
    // A deactivated account is signed out and the workspace is hidden from it (404).
    $this->actingAs($staff)->withCookies($cookies)->get("/{$team->slug}/inventory")->assertNotFound();
    $this->assertGuest();
});
