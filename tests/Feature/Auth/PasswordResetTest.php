<?php

use App\Models\User;
use App\Services\ResendService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The app resets passwords with a 6-digit code (OTP) sent by email through Resend,
 * instead of Laravel's default emailed reset link.
 */
const NEW_PASSWORD = 'New#Password123';

/** Mock Resend and capture the code it would have emailed. */
function captureOtp(): object
{
    $box = new stdClass;
    $box->otp = null;

    test()->mock(ResendService::class)
        ->shouldReceive('sendPasswordResetOtp')
        ->andReturnUsing(function ($email, $otp) use ($box) {
            $box->otp = $otp;

            return ['success' => true, 'id' => 'test'];
        });

    return $box;
}

test('forgot password screen can be rendered', function () {
    $this->get(route('password.request'))->assertOk();
});

test('requesting a reset emails a 6-digit code and stores only its hash', function () {
    $user = User::factory()->create();
    $sent = captureOtp();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('otp_sent', true);

    expect($sent->otp)->toMatch('/^\d{6}$/');

    $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();
    expect($record)->not->toBeNull()
        ->and($record->token)->not->toBe($sent->otp)
        ->and(Hash::check($sent->otp, $record->token))->toBeTrue();
});

test('unknown emails get the same response and no code is created', function () {
    $this->mock(ResendService::class)->shouldNotReceive('sendPasswordResetOtp');

    $this->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('otp_sent', true);

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

test('password can be reset with a valid code', function () {
    $user = User::factory()->create();
    $sent = captureOtp();
    $this->post(route('password.email'), ['email' => $user->email]);

    $this->post(route('password.check-otp'), ['email' => $user->email, 'otp' => $sent->otp])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('otp_verified', true);

    $this->post(route('password.verify-otp'), [
        'email' => $user->email,
        'otp' => $sent->otp,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

test('password cannot be reset with a wrong code', function () {
    $user = User::factory()->create();
    captureOtp();
    $this->post(route('password.email'), ['email' => $user->email]);

    $this->post(route('password.verify-otp'), [
        'email' => $user->email,
        'otp' => '000000',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasErrors('otp');

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeFalse();
});

test('password cannot be reset with an expired code', function () {
    $user = User::factory()->create();
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('123456'),
        'created_at' => now()->subMinutes(16),
    ]);

    $this->post(route('password.verify-otp'), [
        'email' => $user->email,
        'otp' => '123456',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasErrors('otp');

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeFalse();
});

test('a weak new password is rejected', function () {
    $user = User::factory()->create();
    $sent = captureOtp();
    $this->post(route('password.email'), ['email' => $user->email]);

    $this->post(route('password.verify-otp'), [
        'email' => $user->email,
        'otp' => $sent->otp,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');
});

test('the old link-based reset endpoint is closed, even with a valid code', function () {
    $user = User::factory()->create();
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('123456'),
        'created_at' => now(),
    ]);

    // This used to accept the OTP as a reset token, with no guess limit.
    $this->post('/reset-password', [
        'token' => '123456',
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertNotFound();

    $this->get('/reset-password/some-token')->assertRedirect(route('password.request'));

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeFalse();
});
