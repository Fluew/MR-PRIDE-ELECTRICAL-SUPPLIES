<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Issues a one-time device enrollment invitation: an OTP (enrollment code) plus a link token,
 * both valid for 5 minutes and single-use. Only SHA-256 hashes are stored; the raw values are
 * returned once so they can be emailed and shown to the administrator.
 */
class DeviceEnrollmentService
{
    public const MINUTES = 5;

    public function __construct(private ResendService $mailer) {}

    /**
     * @return array{device: TrustedDevice, code: string, link: string, expires_at: Carbon, email_sent: bool, email_error: ?string}
     */
    public function issueFor(User $target, User $admin, ?string $deviceName, Request $request): array
    {
        $code = TrustedDevice::generateEnrollmentCode();
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(self::MINUTES);

        // One device per account: a new invitation replaces the account's previous device(s).
        // Revoking access this way is admin-only (this service is only called from admin routes).
        TrustedDevice::where('user_id', $target->id)->delete();

        $device = TrustedDevice::create([
            'user_id' => $target->id,
            'is_shared' => false,
            'authorized_user_ids' => null,
            'device_uuid' => (string) Str::uuid(),
            'device_name' => $deviceName ?: "{$target->name}'s Device",
            'status' => 'pending',
            'enrollment_code' => TrustedDevice::hashSecret($code),
            'enrollment_code_expires_at' => $expiresAt,
            'enrollment_token' => TrustedDevice::hashSecret($token),
            'enrollment_token_expires_at' => $expiresAt,
        ]);

        $link = url("/device/enroll/{$token}?email=".urlencode($target->email));

        $emailSent = false;
        $emailError = null;
        try {
            $result = $this->mailer->sendDeviceEnrollmentInvitation($target->email, $target->name, $code, $link, self::MINUTES);
            $emailSent = (bool) ($result['success'] ?? false);
            $emailError = $emailSent ? null : ($result['error'] ?? 'Unknown email error.');
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
        }

        if (! $emailSent) {
            // The error only; never the code or link.
            Log::warning("Device enrollment email to {$target->email} failed: {$emailError}");
        }

        AuditLogger::audit(
            'device.enrollment_initiated',
            "Administrator {$admin->email} issued a ".self::MINUTES."-minute device enrollment invitation for {$target->email}"
                .($emailSent ? ' (emailed).' : ' (EMAIL FAILED: not delivered).'),
            $admin,
            $target,
            ['device_id' => $device->id, 'expires_in_minutes' => self::MINUTES, 'email_sent' => $emailSent],
            $emailSent ? 'success' : 'warning',
            $request
        );

        return [
            'device' => $device,
            'code' => $code,
            'link' => $link,
            'expires_at' => $expiresAt,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ];
    }
}
