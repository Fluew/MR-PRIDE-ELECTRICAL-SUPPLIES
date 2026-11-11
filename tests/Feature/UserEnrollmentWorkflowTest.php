<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\ResendService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Admin creates a user → "Firstname1234" temporary password + 5-minute, single-use OTP and link
 * by email → user enrolls their device → logs in → must change the password → logs in again.
 */
const NEW_PERMANENT_PASSWORD = 'Strong#Pass2026';

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'enroll-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->admin->forceFill(['current_team_id' => $this->team->id])->save();
    $this->admin->unsetRelation('currentTeam'); // the factory cached the personal team
    $this->adminCookies = createTrustedDeviceFor($this->admin)['cookies'];

    // Capture invitation emails instead of sending them.
    $this->mail = new stdClass;
    $this->mail->ok = true;
    $this->mock(ResendService::class)
        ->shouldReceive('sendDeviceEnrollmentInvitation')
        ->andReturnUsing(function ($email, $name, $code, $link) {
            $this->mail->code = $code;
            $this->mail->link = $link;
            $this->mail->token = Str::between($link, '/device/enroll/', '?');

            return $this->mail->ok
                ? ['success' => true, 'id' => 'test', 'error' => null]
                : ['success' => false, 'id' => null, 'error' => 'SMTP unreachable'];
        });
});

function createStaffViaAdmin(array $fields = []): TestResponse
{
    return test()->actingAs(test()->admin)->withCookies(test()->adminCookies)->post('/enroll-team/users', $fields + [
        'first_name' => 'juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'account_type' => 'staff',
        'modules' => ['inventory', 'sales'],
    ]);
}

function enroll(array $overrides = []): TestResponse
{
    return test()->post('/device/enroll', $overrides + [
        'token' => test()->mail->token,
        'code' => test()->mail->code,
        'email' => 'juan@example.com',
        'password' => 'Juan1234',
    ]);
}

/** Forget the session and every cookie, like opening a different browser. */
function freshBrowser(): void
{
    auth()->logout();
    test()->flushSession();
    // Blank device cookies count as "no device" (TrustedDevice::findActiveByCredential).
    test()->withCookies(['mrpride_device_uuid' => '', 'mrpride_device_credential' => '', 'mrpride_device_id' => '']);
}

it('normalises the first name into the Firstname1234 format', function () {
    expect(User::initialPasswordFor('juan'))->toBe('Juan1234')
        ->and(User::initialPasswordFor('JUAN CARLOS'))->toBe('Juan1234')
        ->and(User::initialPasswordFor('María'))->toBe('Maria1234')
        ->and(User::initialPasswordFor("O'Brien"))->toBe('Obrien1234')
        ->and(User::initialPasswordFor(''))->toBe('User1234');

    foreach (['juan', 'María', 'J', '李'] as $name) {
        expect(User::initialPasswordFor($name))->toMatch(User::INITIAL_PASSWORD_PATTERN);
    }
});

it('runs the whole flow: create, enroll, forced password change, log in again', function () {
    createStaffViaAdmin()->assertSessionHasNoErrors()->assertSessionHas('enrollmentNotice');

    $user = User::where('email', 'juan@example.com')->firstOrFail();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->status)->toBe('pending_activation')
        ->and(Hash::check('Juan1234', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('Juan1234')
        ->and($user->assignedModules())->toBe(['inventory', 'sales']);

    // Enroll this (new) browser with link + OTP + email + temporary password.
    freshBrowser();
    $enrolled = enroll()->assertRedirect(route('login'));
    $cookies = [
        'mrpride_device_uuid' => $enrolled->getCookie('mrpride_device_uuid')->getValue(),
        'mrpride_device_credential' => $enrolled->getCookie('mrpride_device_credential')->getValue(),
    ];

    // Log in with the temporary password -> forced to the change-password page.
    $this->withCookies($cookies)->post('/login', ['email' => 'juan@example.com', 'password' => 'Juan1234'])
        ->assertRedirect('/password/change');

    // Typing a module URL is not a way around it.
    $this->withCookies($cookies)->get('/enroll-team/inventory')->assertRedirect(route('password.change'));

    // Weak, mismatched, and unchanged passwords are rejected with field errors.
    $this->withCookies($cookies)->put('/password/change', [
        'current_password' => 'Juan1234', 'password' => 'Short#1a', 'password_confirmation' => 'Short#1a',
    ])->assertSessionHasErrors('password');
    $this->withCookies($cookies)->put('/password/change', [
        'current_password' => 'Juan1234', 'password' => 'nouppercase#2026', 'password_confirmation' => 'nouppercase#2026',
    ])->assertSessionHasErrors('password');
    $this->withCookies($cookies)->put('/password/change', [
        'current_password' => 'Juan1234', 'password' => NEW_PERMANENT_PASSWORD, 'password_confirmation' => 'Different#Pass2026',
    ])->assertSessionHasErrors('password');
    $this->withCookies($cookies)->put('/password/change', [
        'current_password' => 'Wrong1234', 'password' => NEW_PERMANENT_PASSWORD, 'password_confirmation' => NEW_PERMANENT_PASSWORD,
    ])->assertSessionHasErrors('current_password');

    // A valid change logs the user out and sends them to the login page.
    $this->withCookies($cookies)->put('/password/change', [
        'current_password' => 'Juan1234', 'password' => NEW_PERMANENT_PASSWORD, 'password_confirmation' => NEW_PERMANENT_PASSWORD,
    ])->assertRedirect(route('login'));
    $this->assertGuest();
    expect($user->fresh()->must_change_password)->toBeFalse();

    // The temporary password no longer works; the new one does, and lands on their first module.
    $this->withCookies($cookies)->post('/login', ['email' => 'juan@example.com', 'password' => 'Juan1234'])
        ->assertSessionHasErrors();
    $this->assertGuest();

    $this->withCookies($cookies)->post('/login', ['email' => 'juan@example.com', 'password' => NEW_PERMANENT_PASSWORD])
        ->assertRedirect('/enroll-team/inventory');
    $this->withCookies($cookies)->get('/enroll-team/sales')->assertOk();
    $this->withCookies($cookies)->get('/enroll-team/reports')->assertForbidden();
    $this->withCookies($cookies)->get('/enroll-team/users')->assertForbidden();
});

it('cancels the invitation after 5 wrong attempts', function () {
    createStaffViaAdmin();
    freshBrowser();

    foreach (range(1, 5) as $i) {
        enroll(['code' => 'MRP-WRONG'.$i])->assertSessionHasErrors('code');
    }

    // Even the right code is useless now.
    enroll()->assertSessionHasErrors('code');
    expect(TrustedDevice::where('enrollment_token', TrustedDevice::hashSecret($this->mail->token))->exists())->toBeFalse();
    expect(AuditLog::where('event', 'auth.device.enrollment_locked')->exists())->toBeTrue();
});

it('lets an admin issue a replacement invitation after the first one expires', function () {
    createStaffViaAdmin();
    $user = User::where('email', 'juan@example.com')->firstOrFail();
    $oldToken = $this->mail->token;

    $this->travel(6)->minutes();
    freshBrowser();
    enroll()->assertSessionHasErrors('code');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post('/enroll-team/users/devices/enroll', ['user_id' => $user->id])
        ->assertSessionHas('success');
    expect($this->mail->token)->not->toBe($oldToken);

    freshBrowser();
    enroll(['token' => $oldToken])->assertSessionHasErrors('code');
    enroll()->assertRedirect(route('login'));
    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to register a browser that already belongs to another account', function () {
    createStaffViaAdmin();
    $other = User::factory()->create(['role' => 'staff_sales', 'status' => 'active', 'password_changed_at' => now()]);
    $otherBrowser = createTrustedDeviceFor($other)['cookies'];

    freshBrowser();
    $this->withCookies($otherBrowser);
    enroll()->assertSessionHasErrors(['code' => 'This device is already registered to another account. Each device can belong to one account only. Ask your administrator to revoke it first, or use a different device.']);

    // The invitation is untouched, so Juan can still use it on his own device.
    freshBrowser();
    enroll()->assertRedirect(route('login'));
});

it('does not allow shared workstation invitations', function () {
    $staff = User::factory()->create(['role' => 'staff_sales', 'status' => 'active', 'password_changed_at' => now()]);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post('/enroll-team/users/devices/enroll', [
            'assignment_type' => 'shared',
            'user_id' => $staff->id,
            'authorized_user_ids' => [$staff->id, $this->admin->id],
        ])
        ->assertSessionHasErrors('assignment_type');

    expect(TrustedDevice::where('user_id', $staff->id)->exists())->toBeFalse();
});

it('reports a failed invitation email instead of pretending it was sent', function () {
    $this->mail->ok = false;

    createStaffViaAdmin()
        ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'could not be sent') && str_contains($msg, 'SMTP unreachable'))
        ->assertSessionHas('enrollmentNotice', fn ($notice) => $notice['email_sent'] === false && $notice['code'] === $this->mail->code);
});

it('never writes the code or link token to the audit log', function () {
    createStaffViaAdmin();
    freshBrowser();
    enroll(['code' => 'MRP-BADONE']);
    enroll();

    $logs = AuditLog::all()->map(fn ($log) => json_encode($log->toArray()))->implode("\n");
    expect($logs)->not->toContain($this->mail->code)
        ->and($logs)->not->toContain($this->mail->token)
        ->and($logs)->not->toContain('MRP-BADONE')
        ->and($logs)->not->toContain('Juan1234');
});

it('turns the old one-click device link into the full enrollment page', function () {
    createStaffViaAdmin();
    $device = TrustedDevice::where('user_id', User::where('email', 'juan@example.com')->value('id'))->firstOrFail();
    freshBrowser();

    $this->get("/device/access/{$this->mail->token}")
        ->assertRedirect(route('device.enroll-token', ['token' => $this->mail->token]));

    expect($device->fresh()->status)->toBe('pending')
        ->and($device->fresh()->credential_hash)->toBeNull();
});

it('stops staff from creating accounts or granting admin', function () {
    $staff = User::factory()->create(['role' => 'staff_inventory', 'status' => 'active', 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $staff->forceFill(['current_team_id' => $this->team->id])->save();

    $this->actingAs($staff)->withCookies(createTrustedDeviceFor($staff)['cookies'])
        ->post('/enroll-team/users', ['first_name' => 'Evil', 'last_name' => 'Admin', 'email' => 'evil@example.com', 'account_type' => 'admin'])
        ->assertForbidden();

    expect(User::where('email', 'evil@example.com')->exists())->toBeFalse();
});
