<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\ResendService;
use App\Support\Csv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('the public one-click "trust this device" link no longer exists', function () {
    $this->get('/device/trust-this-device')->assertNotFound();

    expect(TrustedDevice::count())->toBe(0);
});

test('a wrong reset code is cancelled after 5 guesses, even from different IPs', function () {
    $user = User::factory()->create(['email' => 'victim@example.com']);

    DB::table('password_reset_tokens')->insert([
        'email' => 'victim@example.com',
        'token' => Hash::make('123456'),
        'created_at' => now(),
    ]);

    // Each guess comes from a new IP, so the old per-IP limit would never trigger.
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post('/forgot-password/check-otp', ['email' => 'victim@example.com', 'otp' => '000000'])
            ->assertSessionHasErrors('otp');
    }

    // The code is gone, so even the correct code no longer works.
    expect(DB::table('password_reset_tokens')->where('email', 'victim@example.com')->exists())->toBeFalse();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post('/forgot-password/check-otp', ['email' => 'victim@example.com', 'otp' => '123456'])
        ->assertSessionHasErrors('otp');
});

test('reset codes per email are capped across IPs', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    $this->mock(ResendService::class)
        ->shouldReceive('sendPasswordResetOtp')
        ->andReturn(['success' => true, 'id' => 'test']);

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])
            ->post('/forgot-password', ['email' => 'victim@example.com'])
            ->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.50'])
        ->post('/forgot-password', ['email' => 'victim@example.com'])
        ->assertSessionHasErrors('email');
});

test('csv cells that look like formulas are neutralised, numbers are not', function () {
    expect(Csv::cell('=HYPERLINK("http://evil","x")'))->toBe('\'=HYPERLINK("http://evil","x")');
    expect(Csv::cell('+cmd|calc'))->toBe("'+cmd|calc");
    expect(Csv::cell('@SUM(A1)'))->toBe("'@SUM(A1)");
    expect(Csv::cell('-2+3'))->toBe("'-2+3");
    expect(Csv::cell('-5'))->toBe('-5');
    expect(Csv::cell(-5))->toBe(-5);
    expect(Csv::cell('Acme Corp'))->toBe('Acme Corp');
});

test('the device-check bypass flag is ignored outside the local environment', function () {
    config(['auth.skip_device_verification' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('/login')->assertNotFound();
    expect(TrustedDevice::where('device_uuid', 'local-development-workstation')->exists())->toBeFalse();
});
