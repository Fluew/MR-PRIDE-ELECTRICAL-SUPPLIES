<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ResendService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    /** Wrong guesses allowed per code (across all IPs) before the code is destroyed. */
    private const MAX_FAILED_GUESSES = 5;

    /** Codes that can be requested per email per hour (across all IPs). */
    private const MAX_CODES_PER_HOUR = 5;

    protected ResendService $resendService;

    public function __construct(ResendService $resendService)
    {
        $this->resendService = $resendService;
    }

    /**
     * Send OTP code to user's email via Resend.
     */
    public function sendOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $throttleKey = 'forgot-password-otp:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "Too many OTP requests. Please wait {$seconds} seconds before trying again.",
            ]);
        }

        // Per-email cap (independent of IP) so rotating IPs can't mint unlimited codes to guess at.
        $emailSendKey = 'forgot-password-otp-email:'.$email;
        if (RateLimiter::tooManyAttempts($emailSendKey, self::MAX_CODES_PER_HOUR)) {
            $seconds = RateLimiter::availableIn($emailSendKey);

            return back()->withErrors([
                'email' => "Too many OTP requests for this email. Please wait {$seconds} seconds before trying again.",
            ]);
        }

        RateLimiter::hit($throttleKey, 300);
        RateLimiter::hit($emailSendKey, 3600);

        $user = User::where('email', $email)->first();

        if ($user) {
            // Generate secure 6-digit numeric OTP
            $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            // A fresh code gets a fresh guess budget.
            RateLimiter::clear($this->failedGuessKey($email));

            // Save hashed OTP in password_reset_tokens table
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($otp),
                    'created_at' => now(),
                ]
            );

            // Send via Resend
            $sendResult = $this->resendService->sendPasswordResetOtp($user->email, $otp, $user->name);

            // Keep OTP state in session
            $request->session()->put('reset_email', $email);
            $request->session()->put('otp_sent', true);

            AuditLogger::security(
                'auth.password.otp_sent',
                "Password reset OTP generated and dispatched via Resend for {$user->email}.",
                $user,
                'info',
                [
                    'ip' => $request->ip(),
                    'resend_id' => $sendResult['id'] ?? null,
                    'resend_success' => $sendResult['success'],
                ],
                $sendResult['success'] ? 'success' : 'warning',
                $request
            );

            if (! $sendResult['success']) {
                if (app()->isLocal()) {
                    Log::info("[DEV ONLY] Password reset OTP for {$user->email}: {$otp}");

                    $statusMessage = "OTP generated for testing. Note: the Resend test sandbox only delivers to the Resend account owner's address. For local testing, your OTP code is: {$otp}";

                    return back()->with('status', $statusMessage)
                        ->with('email', $email)
                        ->with('otp_sent', true);
                }

                return back()->withErrors([
                    'email' => 'Unable to send OTP email at this time. Please try again later or contact support.',
                ])->with('email', $email);
            }

            $statusMessage = "A 6-digit verification code (OTP) has been sent to {$email}. Please check your inbox and enter the code below.";

            return back()->with('status', $statusMessage)
                ->with('email', $email)
                ->with('otp_sent', true);
        }

        // Standard timing-safe response
        return back()->with(
            'status',
            'If an account exists with that email address, a verification code (OTP) has been sent to it.'
        )->with('email', $email)->with('otp_sent', true);
    }

    /**
     * Resend OTP code.
     */
    public function resendOtp(Request $request): RedirectResponse
    {
        return $this->sendOtp($request);
    }

    /**
     * Verify the entered OTP code before proceeding to password entry step.
     */
    public function checkOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = trim($validated['otp']);
        $throttleKey = 'verify-otp:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'otp' => "Too many invalid attempts. Please wait {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 300);

        // Fetch token record
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => 'No active password reset request was found for this email. Please request a new OTP.',
            ]);
        }

        // Check expiration (15 minutes)
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'otp' => 'The verification code has expired. Please request a new code.',
            ]);
        }

        // Verify OTP hash
        if (! Hash::check($otp, $record->token)) {
            $this->recordFailedGuess($email);
        }

        return back()->with('otp_verified', true)->with('email', $email);
    }

    /**
     * Verify the entered OTP and reset the user's password.
     */
    public function verifyOtpAndResetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => [
                'required',
                'string',
                Password::default(), // same policy everywhere (AppServiceProvider)
                'confirmed',
            ],
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = trim($validated['otp']);
        $throttleKey = 'verify-otp:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'otp' => "Too many invalid attempts. Please wait {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 300);

        // Fetch token record
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => 'No active password reset request was found for this email. Please request a new OTP.',
            ]);
        }

        // Check expiration (15 minutes)
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'otp' => 'The verification code has expired. Please request a new code.',
            ]);
        }

        // Verify OTP hash
        if (! Hash::check($otp, $record->token)) {
            $this->recordFailedGuess($email);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'User account not found.',
            ]);
        }

        // Update password and mark change completed
        $user->password = Hash::make($validated['password']);
        $user->must_change_password = false;
        $user->password_changed_at = \Illuminate\Support\Carbon::now();
        $user->save();

        // Invalidate all active sessions for this account to enforce 1-device policy & security
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Clear reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Clear session flags
        $request->session()->forget(['reset_email', 'otp_sent']);
        RateLimiter::clear($throttleKey);
        RateLimiter::clear($this->failedGuessKey($email));

        AuditLogger::security(
            'auth.password.otp_reset_success',
            "Password successfully reset via OTP verification for {$user->email}.",
            $user,
            'info',
            ['ip' => $request->ip()],
            'success',
            $request
        );

        return redirect()->route('login')->with(
            'status',
            'Your password has been reset successfully! Please log in with your new password.'
        );
    }

    private function failedGuessKey(string $email): string
    {
        return 'verify-otp-failures:'.$email;
    }

    /**
     * Count a wrong OTP against the email (not the IP) and destroy the code once the budget is spent,
     * so a 6-digit code can't be brute-forced from many IPs. Always throws.
     */
    private function recordFailedGuess(string $email): never
    {
        $key = $this->failedGuessKey($email);
        RateLimiter::hit($key, 900);

        if (RateLimiter::attempts($key) >= self::MAX_FAILED_GUESSES) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            RateLimiter::clear($key);

            AuditLogger::security(
                'auth.password.otp_locked',
                "Password reset code for {$email} destroyed after ".self::MAX_FAILED_GUESSES.' wrong guesses.',
                null,
                'danger',
                ['email' => $email, 'ip' => request()->ip()],
                'blocked',
                request()
            );

            throw ValidationException::withMessages([
                'otp' => 'Too many incorrect codes. This code has been cancelled. Please request a new one.',
            ]);
        }

        throw ValidationException::withMessages([
            'otp' => 'The entered OTP code is incorrect. Please verify and try again.',
        ]);
    }
}
