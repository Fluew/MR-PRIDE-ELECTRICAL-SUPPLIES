<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DeviceVerificationController extends Controller
{
    /** Wrong codes / passwords allowed per invitation before it is cancelled. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Show device enrollment page (/device/enroll?token=...).
     * Only reachable through an active invitation link; the code (OTP) alone is not enough.
     */
    public function showEnroll(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->renderEnrollment($request, trim((string) $request->query('token')));
    }

    /**
     * Show enrollment page for a one-time invitation link (/device/enroll/{token}).
     */
    public function showEnrollWithToken(Request $request, string $token): \Symfony\Component\HttpFoundation\Response
    {
        return $this->renderEnrollment($request, $token);
    }

    private function renderEnrollment(Request $request, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $device = TrustedDevice::findByEnrollmentToken($token);
        $device?->load('user:id,name,email,role');

        if (! $device || ! $device->user) {
            abort(404);
        }

        if ($device->enrollmentExpired()) {
            return $this->invitationExpiredPage($request);
        }

        // Forwarding protection: only works on the email registered by the Admin
        if ($request->filled('email')) {
            $queryEmail = strtolower(trim((string) $request->query('email')));
            if ($queryEmail !== strtolower($device->user->email)) {
                return Inertia::render('auth/AccessDenied', [
                    'title' => 'Access Restricted',
                    'message' => "This invitation link is strictly restricted to {$device->user->email}. It cannot be accessed by other accounts even if forwarded.",
                    'canEnroll' => false,
                ])->toResponse($request)->setStatusCode(403);
            }
        }

        // The code is NOT sent to the page: the user must type the OTP from their email.
        return Inertia::render('auth/EnrollDevice', [
            'initialCode' => '',
            'token' => $token,
            'assignedUser' => [
                'name' => $device->user->name,
                'email' => $device->user->email,
            ],
            'expiresAt' => ($device->enrollment_token_expires_at ?? $device->enrollment_code_expires_at)?->diffForHumans(),
            'isInvitation' => true,
            'status' => session('status'),
        ])->toResponse($request);
    }

    private function invitationExpiredPage(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::render('auth/AccessDenied', [
            'title' => 'Invitation Expired',
            'message' => 'This device enrollment link has expired or was already used. Invitations are valid for 5 minutes and work once. Please ask your administrator for a new invitation.',
            'canEnroll' => false,
        ])->toResponse($request)->setStatusCode(410);
    }

    /**
     * Complete enrollment: link token + OTP + registered email + temporary password.
     * On success the invitation is used up, this browser receives the device credential, and the
     * user is sent to the login page.
     */
    public function processEnroll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'token.required' => 'Open the enrollment link from your email to register this device.',
        ]);

        $device = TrustedDevice::findByEnrollmentToken(trim($validated['token']));
        $targetUser = $device?->user;

        if (! $device || ! $targetUser) {
            // Never log the submitted code or token.
            AuditLogger::security(
                'auth.device.enrollment_failed',
                'Device enrollment failed: unknown or already-used invitation link.',
                null,
                'warning',
                ['ip' => $request->ip()],
                'failure',
                $request
            );

            return back()->withErrors([
                'code' => 'This enrollment link is invalid or has already been used. Please ask your administrator for a new invitation.',
            ]);
        }

        if ($device->enrollmentExpired()) {
            $device->clearEnrollment();

            AuditLogger::security(
                'auth.device.enrollment_expired',
                "Device enrollment failed: expired invitation for {$targetUser->email}.",
                $targetUser,
                'warning',
                ['ip' => $request->ip(), 'device_id' => $device->id],
                'failure',
                $request
            );

            return back()->withErrors([
                'code' => 'This enrollment link has expired (links are valid for 5 minutes only). Please ask your administrator for a new invitation.',
            ]);
        }

        // Check every factor; any mistake counts against this invitation's attempt budget.
        $error = null;
        if (strtolower(trim($validated['email'])) !== strtolower($targetUser->email)) {
            $error = ['email' => 'This invitation is registered to a different email address. It cannot be used with another email, even if forwarded.'];
        } elseif (! $device->enrollment_code || ! hash_equals($device->enrollment_code, TrustedDevice::hashSecret(strtoupper(trim($validated['code']))))) {
            $error = ['code' => 'The enrollment code (OTP) is incorrect. Please check your email.'];
        } elseif (! Hash::check($validated['password'], $targetUser->password)) {
            $error = ['password' => 'Incorrect temporary password.'];
        }

        if ($error) {
            return back()->withErrors($this->recordFailedAttempt($request, $device, $error));
        }

        // One device, one account: a browser that already holds a valid credential for a different
        // account cannot be registered to this one. (The local dev bypass device is ignored.)
        $existing = TrustedDevice::bypassActive() ? null : TrustedDevice::findActiveByCredential($request);
        if ($existing && (int) $existing->user_id !== (int) $targetUser->id) {
            AuditLogger::security(
                'auth.device.already_bound',
                "Enrollment for {$targetUser->email} refused: this browser is already registered to another account (device #{$existing->id}).",
                $targetUser,
                'warning',
                ['ip' => $request->ip(), 'device_id' => $device->id, 'existing_device_id' => $existing->id],
                'blocked',
                $request
            );

            return back()->withErrors([
                'code' => 'This device is already registered to another account. Each device can belong to one account only. Ask your administrator to revoke it first, or use a different device.',
            ]);
        }

        // Activate account if pending activation
        if ($targetUser->status === 'pending_activation') {
            $targetUser->update(['status' => 'active']);
            AuditLogger::audit(
                'account.activated',
                "User {$targetUser->email} verified identity and activated account during workstation enrollment.",
                $targetUser,
                $targetUser,
                ['device_id' => $device->id],
                'success',
                $request
            );
        }

        $deviceName = ! empty($validated['device_name'])
            ? trim($validated['device_name'])
            : ($device->device_name ?: $this->parseDeviceName($request));

        // Use up the invitation first (single use), then bind this browser to the device.
        $device->forceFill([
            'user_id' => $targetUser->id,
            'is_shared' => false,
            'authorized_user_ids' => null,
            'device_uuid' => $device->device_uuid ?: (string) Str::uuid(),
            'device_name' => $deviceName,
            'status' => 'approved',
            'approved_at' => now(),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
        ]);
        $device->clearEnrollment();
        $device->issueCredential();

        RateLimiter::clear($this->failedAttemptsKey($device));

        AuditLogger::security(
            'auth.device.enrolled',
            "Browser enrolled as trusted workstation '{$deviceName}' ({$device->device_uuid}) for {$targetUser->email}.",
            $targetUser,
            'info',
            ['device_id' => $device->id, 'device_uuid' => $device->device_uuid, 'ip' => $request->ip()],
            'success',
            $request
        );

        return redirect()->route('login')->with(
            'status',
            'Device registered. Log in with your email and temporary password; you will then be asked to choose a new password.'
        );
    }

    private function failedAttemptsKey(TrustedDevice $device): string
    {
        return 'device-enroll-failures:'.$device->id;
    }

    /**
     * Count a wrong code/email/password against the invitation; cancel it once the budget is spent.
     *
     * @param  array<string, string>  $error
     * @return array<string, string>
     */
    private function recordFailedAttempt(Request $request, TrustedDevice $device, array $error): array
    {
        $key = $this->failedAttemptsKey($device);
        RateLimiter::hit($key, 3600);
        $field = array_key_first($error);

        if (RateLimiter::attempts($key) >= self::MAX_FAILED_ATTEMPTS) {
            $device->clearEnrollment();
            RateLimiter::clear($key);

            AuditLogger::security(
                'auth.device.enrollment_locked',
                'Device enrollment invitation for '.($device->user?->email ?? 'unknown').' cancelled after '.self::MAX_FAILED_ATTEMPTS.' failed attempts.',
                $device->user,
                'danger',
                ['ip' => $request->ip(), 'device_id' => $device->id, 'last_failed_field' => $field],
                'blocked',
                $request
            );

            return ['code' => 'Too many incorrect attempts. This invitation has been cancelled. Please ask your administrator for a new one.'];
        }

        AuditLogger::security(
            'auth.device.identity_verification_failed',
            'Device enrollment attempt failed ('.$field.') for '.($device->user?->email ?? 'unknown').'.',
            $device->user,
            'warning',
            ['ip' => $request->ip(), 'device_id' => $device->id, 'field' => $field],
            'failure',
            $request
        );

        return $error;
    }

    /**
     * Show access denied view.
     */
    public function accessDenied(Request $request): Response
    {
        return Inertia::render('auth/AccessDenied', [
            'title' => 'Access Denied',
            'message' => 'This device is not registered or is no longer authorized to access MRP ElectroDesk. Contact your system administrator.',
            'canEnroll' => true,
        ]);
    }

    /**
     * Show emergency admin device recovery view (/device/recover).
     */
    public function showRecover(Request $request): Response
    {
        return Inertia::render('auth/RecoverDevice', [
            'status' => session('status'),
        ]);
    }

    /**
     * Process emergency admin recovery using the single-use Emergency Admin Recovery Key.
     */
    public function processRecover(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'recovery_key' => ['required', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'recovery_key.required' => 'Please enter your Emergency Admin Recovery Key.',
        ]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();
        $submittedKey = strtoupper(trim($validated['recovery_key']));

        // Verify credentials and administrator status
        if (! $user || ! $user->isAdmin() || ! Hash::check($validated['password'], $user->password)) {
            AuditLogger::security(
                'auth.recovery_key.failed',
                "Emergency recovery failed (invalid credentials) for {$validated['email']}.",
                $user,
                'warning',
                ['ip' => $request->ip()],
                'failure',
                $request
            );

            return back()->withErrors([
                'recovery_key' => 'Invalid administrator credentials or recovery key.',
            ]);
        }

        // Verify recovery key
        if (empty($user->admin_recovery_key) || ! hash_equals($user->admin_recovery_key, $submittedKey)) {
            AuditLogger::security(
                'auth.recovery_key.mismatch',
                "Emergency recovery failed (key mismatch) for {$user->email}.",
                $user,
                'warning',
                ['ip' => $request->ip()],
                'failure',
                $request
            );

            return back()->withErrors([
                'recovery_key' => 'The recovery key provided is invalid or has already been used. Please check the key or contact server administrator.',
            ]);
        }

        // Rotate single-use recovery key immediately
        $newKey = 'RECOVER-'.strtoupper(Str::random(6)).'-'.strtoupper(Str::random(6)).'-'.strtoupper(Str::random(6));
        $user->forceFill(['admin_recovery_key' => $newKey])->save();

        $deviceName = ! empty($validated['device_name'])
            ? trim($validated['device_name'])
            : ($this->parseDeviceName($request).' (Emergency Recovery)');

        // Clear existing devices for this user if single-device policy, or register new approved device
        TrustedDevice::where('user_id', $user->id)->delete();

        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'device_name' => $deviceName,
            'status' => 'approved',
            'approved_at' => now(),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'is_shared' => false,
        ]);

        // Issue permanent cookie credentials to current browser
        $device->issueCredential();

        AuditLogger::security(
            'auth.recovery_key.used',
            "Administrator {$user->email} bypassed workstation verification using Emergency Recovery Key. Device '{$deviceName}' authorized.",
            $user,
            'info',
            ['device_id' => $device->id, 'ip' => $request->ip()],
            'success',
            $request
        );

        return redirect()->route('login')->with(
            'status',
            'Emergency recovery successful! This workstation is now authorized. Please sign in with your administrator credentials.'
        );
    }

    /**
     * Old one-click authorization link. It used to trust the browser from the link alone; it
     * now forwards to the normal enrollment page, which also requires the OTP, email and password.
     */
    public function authorizeDevice(Request $request, string $token): RedirectResponse
    {
        return redirect()->route('device.enroll-token', ['token' => $token]);
    }

    /**
     * Parse human-friendly device name from User-Agent.
     */
    public function parseDeviceName(Request $request): string
    {
        $agent = $request->userAgent() ?? 'Unknown Device';

        $os = 'Unknown OS';
        if (str_contains($agent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($agent, 'Macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($agent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($agent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) {
            $os = 'iOS';
        }

        $browser = 'Browser';
        if (str_contains($agent, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($agent, 'Chrome/')) {
            $browser = 'Chrome';
        } elseif (str_contains($agent, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($agent, 'Safari/') && ! str_contains($agent, 'Chrome/')) {
            $browser = 'Safari';
        }

        return "{$os} • {$browser}";
    }
}
