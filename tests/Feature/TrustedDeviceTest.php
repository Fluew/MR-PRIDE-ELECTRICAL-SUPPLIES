<?php

use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\ResendService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('public routes are accessible without any device registration', function () {
    $this->get('/')->assertSuccessful();
    $this->get('/products')->assertSuccessful();
    $this->get('/company')->assertSuccessful();
    $this->get('/contact-us')->assertSuccessful();
    $this->get('/quotation')->assertSuccessful();
});

test('unregistered device attempting to access login receives 404 page not found', function () {
    $response = $this->get('/login');
    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('NotFound')
    );
});

test('guest accessing dashboard route receives 404 page not found', function () {
    $response = $this->get('/dashboard');
    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('NotFound')
    );
});

test('guest accessing admin dashboard route receives 404 page not found', function () {
    $response = $this->get('/admin/dashboard');
    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('NotFound')
    );
});

test('guest accessing staff dashboard route receives 404 page not found', function () {
    $response = $this->get('/staff/dashboard');
    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('NotFound')
    );
});

test('direct access to enrollment page without invitation returns 404', function () {
    $this->get('/device/enroll')->assertNotFound();
});

/**
 * A pending invitation as DeviceEnrollmentService stores it: only hashes of the code and the
 * link token are saved; the raw values are what the user receives by email.
 */
function pendingInvite(User $user, int $expiresInMinutes = 5): array
{
    $code = 'MRP-'.strtoupper(Str::random(6));
    $token = Str::random(48);

    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Pending Workstation',
        'user_id' => $user->id,
        'status' => 'pending',
        'enrollment_code' => TrustedDevice::hashSecret($code),
        'enrollment_code_expires_at' => now()->addMinutes($expiresInMinutes),
        'enrollment_token' => TrustedDevice::hashSecret($token),
        'enrollment_token_expires_at' => now()->addMinutes($expiresInMinutes),
    ]);

    return ['device' => $device, 'code' => $code, 'token' => $token];
}

function inviteeUser(): User
{
    return User::factory()->create([
        'role' => 'staff_inventory',
        'status' => 'pending_activation',
        'password' => Hash::make('Juan1234'),
        'must_change_password' => true,
        'password_changed_at' => now(),
    ]);
}

test('candidate device can view enrollment page with a valid link, but never sees the code', function () {
    $user = inviteeUser();
    $invite = pendingInvite($user);

    $this->get("/device/enroll/{$invite['token']}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/EnrollDevice')
            ->missing('expectedCode')
            ->where('assignedUser.email', $user->email));

    // The code alone (without the link) does not open the page.
    $this->get('/device/enroll?code='.$invite['code'])->assertNotFound();
});

test('submitting an invalid enrollment link or code is rejected', function () {
    $user = inviteeUser();
    $invite = pendingInvite($user);

    // Unknown link
    $this->post('/device/enroll', [
        'token' => 'not-a-real-token', 'code' => $invite['code'], 'email' => $user->email, 'password' => 'Juan1234',
    ])->assertSessionHasErrors('code');

    // Right link, wrong code
    $this->post('/device/enroll', [
        'token' => $invite['token'], 'code' => 'MRP-WRONG1', 'email' => $user->email, 'password' => 'Juan1234',
    ])->assertSessionHasErrors('code');

    // The code alone, without the link, is not accepted
    $this->post('/device/enroll', [
        'code' => $invite['code'], 'email' => $user->email, 'password' => 'Juan1234',
    ])->assertSessionHasErrors('token');

    expect($invite['device']->fresh()->status)->toBe('pending');
});

test('submitting an expired enrollment code is rejected', function () {
    $user = inviteeUser();
    $invite = pendingInvite($user);

    $this->travel(6)->minutes();

    $this->get("/device/enroll/{$invite['token']}")->assertStatus(410);

    $this->post('/device/enroll', [
        'token' => $invite['token'], 'code' => $invite['code'], 'email' => $user->email, 'password' => 'Juan1234',
    ])->assertSessionHasErrors('code');

    expect($invite['device']->fresh()->status)->toBe('pending')
        ->and($invite['device']->fresh()->enrollment_token)->toBeNull(); // expired invitation is wiped
});

test('submitting a valid enrollment code registers device, approves it, and issues cookies', function () {
    $user = inviteeUser();
    $invite = pendingInvite($user);

    $response = $this->post('/device/enroll', [
        'token' => $invite['token'],
        'code' => strtolower($invite['code']), // codes are not case-sensitive
        'email' => $user->email,
        'password' => 'Juan1234',
        'device_name' => 'My Workstation Laptop',
    ]);

    $response->assertRedirect('/login');
    $response->assertCookie('mrpride_device_credential');
    $response->assertCookie('mrpride_device_uuid');

    $device = $invite['device']->fresh();
    expect($device->status)->toBe('approved')
        ->and($device->credential_hash)->not->toBeNull()
        ->and($device->enrollment_code)->toBeNull() // code consumed
        ->and($device->enrollment_token)->toBeNull();
    expect($user->fresh()->status)->toBe('active');
});

test('reusing an enrollment code is rejected', function () {
    $user = inviteeUser();
    $invite = pendingInvite($user);
    $payload = ['token' => $invite['token'], 'code' => $invite['code'], 'email' => $user->email, 'password' => 'Juan1234'];

    // First use
    $this->post('/device/enroll', $payload + ['device_name' => 'First Browser'])->assertRedirect('/login');

    // Second use attempt
    $this->post('/device/enroll', $payload + ['device_name' => 'Second Browser'])->assertSessionHasErrors('code');
});

test('registered and active device can access login page', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'password_changed_at' => now(),
    ]);
    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Admin Laptop',
        'user_id' => $user->id,
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    $response = $this->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->get('/login');

    $response->assertSuccessful();
});

test('authorized user on registered device can log in', function () {
    $password = 'Secret123!';
    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'password' => Hash::make($password),
        'password_changed_at' => now(),
    ]);

    $teamSlug = $user->personalTeam()?->slug ?? 'mr-pride';

    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Admin Workstation',
        'user_id' => $user->id,
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    $response = $this->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

    $response->assertRedirect("/{$teamSlug}/dashboard");
    $this->assertAuthenticatedAs($user);
});

test('account-device mismatch rejects login without creating a session and redirects to login with exact error message', function () {
    $password = 'Secret123!';
    $authorizedUser = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'password' => Hash::make($password),
        'password_changed_at' => now(),
    ]);

    $otherUser = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'password' => Hash::make($password),
        'password_changed_at' => now(),
    ]);

    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Admin Only Workstation',
        'user_id' => $authorizedUser->id,
        'is_shared' => false,
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    $response = $this->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->from('/login')
        ->post('/login', [
            'email' => $otherUser->email,
            'password' => $password,
        ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['email' => 'This account is not authorized to use this workstation. Please contact your administrator.']);
    $this->assertGuest();
});

test('one device, one account: a legacy shared workstation only lets its owner log in', function () {
    $password = 'Secret123!';
    $owner = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'password' => Hash::make($password),
        'password_changed_at' => now(),
    ]);

    $other = User::factory()->create([
        'role' => 'staff_quotations',
        'status' => 'active',
        'password' => Hash::make($password),
        'password_changed_at' => now(),
    ]);

    // Old-style shared row: owned by $owner, but listing $other as well.
    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Shared Front Desk Station',
        'user_id' => $owner->id,
        'is_shared' => true,
        'authorized_user_ids' => [$owner->id, $other->id],
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    $ownerSlug = $owner->personalTeam()?->slug ?? 'mr-pride';

    // The owner can still log in on it.
    $this->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->post('/login', ['email' => $owner->email, 'password' => $password])
        ->assertRedirect("/{$ownerSlug}/".$owner->assignedModuleSlug());
    $this->assertAuthenticatedAs($owner);

    $this->post('/logout');
    $this->assertGuest();

    // Another account on the same device is refused.
    $this->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->post('/login', ['email' => $other->email, 'password' => $password])
        ->assertSessionHasErrors(['email' => 'This account is not authorized to use this workstation. Please contact your administrator.']);
    $this->assertGuest();
});

test('revoked device immediately loses access to protected routes and APIs with 404 page not found', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'password_changed_at' => now(),
    ]);
    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Revoked Laptop',
        'user_id' => $user->id,
        'status' => 'revoked',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->get('/admin/dashboard');

    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('NotFound')
    );
});

test('role-based access control blocks staff from admin dashboard with 404 page not found', function () {
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'password_changed_at' => now(),
    ]);
    $rawCredential = Str::random(64);
    $device = TrustedDevice::create([
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Staff Terminal',
        'user_id' => $staff->id,
        'status' => 'approved',
        'credential_hash' => hash('sha256', $rawCredential),
        'approved_at' => now(),
    ]);

    // Staff visits admin dashboard -> 404 Page Not Found
    $response = $this->actingAs($staff)
        ->withCookie('mrpride_device_uuid', $device->device_uuid)
        ->withCookie('mrpride_device_credential', $rawCredential)
        ->get('/admin/dashboard');

    $response->assertNotFound();
});

test('only authenticated admins can enroll or manage trusted devices', function () {
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'password_changed_at' => now(),
    ]);
    $teamSlug = $staff->personalTeam()?->slug ?? 'mr-pride';
    $deviceData = createTrustedDeviceFor($staff);

    // Staff attempts to access device enrollment API -> rejected
    $response = $this->actingAs($staff)
        ->withCookies($deviceData['cookies'])
        ->post("/{$teamSlug}/users/devices/enroll", [
            'assignment_type' => 'single',
            'user_id' => $staff->id,
        ]);

    $response->assertForbidden(); // role:admin middleware blocks staff with 403
});

test('end-to-end flow: admin creates pending staff, a 5-minute invitation is emailed, staff verifies identity, enrolls browser, accesses login, and authenticates', function () {
    // Capture the email instead of sending it.
    $sent = new stdClass;
    $this->mock(ResendService::class)
        ->shouldReceive('sendDeviceEnrollmentInvitation')
        ->andReturnUsing(function ($email, $name, $code, $link) use ($sent) {
            $sent->email = $email;
            $sent->code = $code;
            $sent->link = $link;

            return ['success' => true, 'id' => 'test', 'error' => null];
        });

    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'password_changed_at' => now(),
    ]);
    $team = $admin->personalTeam() ?? Team::factory()->create();
    $adminDevice = createTrustedDeviceFor($admin);

    // 1. Admin creates staff account (status: pending_activation, temp password: Maria1234).
    //    The 5-minute enrollment invitation is created and emailed automatically.
    $response = $this->actingAs($admin)
        ->withCookies($adminDevice['cookies'])
        ->post("/{$team->slug}/users", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@mrpride.com',
            'role' => 'staff_inventory',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('enrollmentNotice');
    $staff = User::where('email', 'maria.santos@mrpride.com')->firstOrFail();
    expect($staff->status)->toBe('pending_activation');
    expect($staff->isPendingActivation())->toBeTrue();
    expect($staff->isActive())->toBeTrue(); // allowed to activate
    expect(Hash::check('Maria1234', $staff->password))->toBeTrue();
    expect($sent->email)->toBe('maria.santos@mrpride.com');

    // 2. Only hashes are stored; the invitation lasts 5 minutes.
    $device = TrustedDevice::where('user_id', $staff->id)->firstOrFail();
    $token = Str::between($sent->link, '/device/enroll/', '?');
    expect($device->status)->toBe('pending');
    expect($device->enrollment_token)->toBe(TrustedDevice::hashSecret($token))->not->toBe($token);
    expect($device->enrollment_code)->toBe(TrustedDevice::hashSecret($sent->code))->not->toBe($sent->code);
    expect((int) round(now()->diffInMinutes($device->enrollment_token_expires_at)))->toBe(5);

    // 3. Before enrollment, unauthenticated/unregistered candidate browser gets 404 on /login
    auth()->logout();
    $this->flushSession();
    $this->defaultCookies = [];
    $this->get('/login')->assertNotFound();

    // 4. Staff opens one-time enrollment link on new browser (with bound email)
    $this->get($sent->link)->assertSuccessful();

    // 4b. Forwarded link opened with another email is rejected (403 Forbidden)
    $this->get("/device/enroll/{$token}?email=".urlencode('other.person@mrpride.com'))->assertForbidden();

    // 5. Staff verifies identity with enrollment code + email + temporary password
    $enrollResponse = $this->post('/device/enroll', [
        'token' => $token,
        'code' => $sent->code,
        'email' => 'maria.santos@mrpride.com',
        'password' => 'Maria1234',
        'device_name' => "Maria's Work Laptop",
    ]);

    $enrollResponse->assertRedirect('/login');
    $enrollResponse->assertCookie('mrpride_device_credential');
    $enrollResponse->assertCookie('mrpride_device_uuid');

    // Cookies issued and account activated
    $staff->refresh();
    expect($staff->status)->toBe('active');
    $device->refresh();
    expect($device->status)->toBe('approved');
    expect($device->enrollment_token)->toBeNull(); // single-use token consumed

    // 6. /login is now accessible with the enrolled cookies!
    $deviceCookies = [
        'mrpride_device_uuid' => $enrollResponse->getCookie('mrpride_device_uuid')->getValue(),
        'mrpride_device_credential' => $enrollResponse->getCookie('mrpride_device_credential')->getValue(),
    ];

    $loginPage = $this->withCookies($deviceCookies)->get('/login');
    $loginPage->assertSuccessful();

    // 7. Staff authenticates with Email + Temporary Password
    $authResponse = $this->withCookies($deviceCookies)->post('/login', [
        'email' => 'maria.santos@mrpride.com',
        'password' => 'Maria1234',
    ]);

    // Redirected to mandatory password change because must_change_password is true
    $authResponse->assertRedirect('/password/change');
    $this->assertAuthenticatedAs($staff);
});

test('administrator can view emergency device recovery page without trusted device', function () {
    $this->get('/device/recover')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/RecoverDevice')
        );
});

test('administrator can bypass device lockout using emergency admin recovery key', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'password' => Hash::make('AdminPass123!'),
        'admin_recovery_key' => 'RECOVER-AAA111-BBB222-CCC333',
    ]);

    // 1. Unregistered device gets 404 on /login
    $this->get('/login')->assertNotFound();

    // 2. Submit wrong key -> validation error
    $failResponse = $this->post('/device/recover', [
        'email' => $admin->email,
        'password' => 'AdminPass123!',
        'recovery_key' => 'RECOVER-WRONG-KEY-123456',
    ]);
    $failResponse->assertSessionHasErrors('recovery_key');

    // 3. Submit correct key -> success, sets cookies, rotates key
    $successResponse = $this->post('/device/recover', [
        'email' => $admin->email,
        'password' => 'AdminPass123!',
        'recovery_key' => 'RECOVER-AAA111-BBB222-CCC333',
        'device_name' => 'Admin Recovery Laptop',
    ]);

    $successResponse->assertRedirect('/login');
    $successResponse->assertCookie('mrpride_device_credential');
    $successResponse->assertCookie('mrpride_device_uuid');

    // Key is single-use and rotated
    $admin->refresh();
    expect($admin->admin_recovery_key)->not->toBe('RECOVER-AAA111-BBB222-CCC333')
        ->and($admin->admin_recovery_key)->toStartWith('RECOVER-');

    // 4. Old key cannot be reused
    $reuseResponse = $this->post('/device/recover', [
        'email' => $admin->email,
        'password' => 'AdminPass123!',
        'recovery_key' => 'RECOVER-AAA111-BBB222-CCC333',
    ]);
    $reuseResponse->assertSessionHasErrors('recovery_key');

    // 5. Browser with the issued cookies can now access /login
    $deviceCookies = [
        'mrpride_device_uuid' => $successResponse->getCookie('mrpride_device_uuid')->getValue(),
        'mrpride_device_credential' => $successResponse->getCookie('mrpride_device_credential')->getValue(),
    ];

    $this->withCookies($deviceCookies)->get('/login')->assertSuccessful();
});

test('artisan device:recover command validates key, rotates it, and generates access link', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'admin_recovery_key' => 'RECOVER-TEST01-TEST02-TEST03',
    ]);

    $this->artisan('device:recover', [
        'email' => $admin->email,
        'key' => 'RECOVER-TEST01-TEST02-TEST03',
    ])
        ->expectsOutputToContain('EMERGENCY ADMIN DEVICE RECOVERY VERIFIED')
        ->expectsOutputToContain('Option B (Direct 1-Click Enrollment):')
        ->assertExitCode(0);

    $admin->refresh();
    expect($admin->admin_recovery_key)->not->toBe('RECOVER-TEST01-TEST02-TEST03');
});

