<?php

use App\Services\ResendService;

/**
 * Email delivery rules in ResendService. phpunit.xml clears RESEND_API_KEY, so these tests never
 * call the real Resend API; they exercise the Laravel-mailer path and the dev redirect.
 */
function sentMessages(): array
{
    return app('mailer')->getSymfonyTransport()->messages()->all();
}

beforeEach(function () {
    config(['services.resend.key' => '', 'mail.default' => 'array']);
});

it('redirects every email to the Resend account address in local development', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.resend.dev_redirect_to' => 'owner@example.com']);

    $result = app(ResendService::class)->sendDeviceEnrollmentInvitation('staff@example.com', 'Staff', 'MRP-ABC123', 'http://localhost/device/enroll/x');

    expect($result['success'])->toBeTrue();
    $message = sentMessages()[0]->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('owner@example.com')
        ->and($message->getSubject())->toStartWith('[DEV for staff@example.com]');
});

it('never redirects outside local development', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.resend.dev_redirect_to' => 'owner@example.com']);

    expect(app(ResendService::class)->devRedirectAddress())->toBeNull();

    app(ResendService::class)->sendPasswordResetOtp('staff@example.com', '123456', 'Staff');
    $message = sentMessages()[0]->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('staff@example.com')
        ->and($message->getSubject())->not->toContain('[DEV');
});

it('reports failure instead of "sent" when only the log mailer is available', function () {
    config(['mail.default' => 'log']);

    $result = app(ResendService::class)->sendDeviceEnrollmentInvitation('staff@example.com', 'Staff', 'MRP-ABC123', 'http://localhost/device/enroll/x');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeEmpty();
});
