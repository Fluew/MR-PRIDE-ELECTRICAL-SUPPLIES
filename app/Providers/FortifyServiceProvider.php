<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\RegisterResponse;
use App\Models\TeamInvitation;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureSecurityListeners();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request) {
            $email = (string) $request->input(Fortify::username());
            $password = (string) $request->input('password');

            $device = TrustedDevice::findActiveByCredential($request);
            if (! $device) {
                AuditLogger::security(
                    'auth.login.unregistered_device',
                    "Login rejected: Attempted login for email '{$email}' from unregistered or revoked device.",
                    null,
                    'warning',
                    ['ip' => $request->ip(), 'email' => $email],
                    'blocked',
                    $request
                );

                abort(404);
            }

            $user = User::where('email', $email)->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                return null;
            }

            // Only Admin and Staff accounts may log in
            if (! $user->isAdminOrStaff()) {
                AuditLogger::security(
                    'auth.login.customer_blocked',
                    "Login rejected: Public customer account {$user->email} attempted backend login.",
                    $user,
                    'warning',
                    ['role' => $user->role],
                    'blocked',
                    $request
                );

                abort(404);
            }

            // Deactivated account check
            if (! $user->isActive()) {
                AuditLogger::security(
                    'auth.login.deactivated',
                    "Login blocked: User {$user->email} is deactivated.",
                    $user,
                    'warning',
                    ['role' => $user->role],
                    'blocked',
                    $request
                );

                throw ValidationException::withMessages([
                    Fortify::username() => 'Your account has been deactivated. Please contact the administrator.',
                ]);
            }

            // Account-to-Device Binding check
            if (! $device->isAuthorizedForUser($user)) {
                AuditLogger::security(
                    'auth.device.account_mismatch',
                    "Denied login attempt: Account {$user->email} is not authorized on device '{$device->device_name}' ({$device->device_uuid}).",
                    $user,
                    'warning',
                    ['device_id' => $device->id, 'device_uuid' => $device->device_uuid, 'user_id' => $user->id],
                    'blocked',
                    $request
                );

                throw ValidationException::withMessages([
                    Fortify::username() => 'This account is not authorized to use this workstation. Please contact your administrator.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $device = TrustedDevice::findActiveByCredential($request);

            if (! $device) {
                AuditLogger::security(
                    'auth.threat.unregistered_device',
                    'Threat Blocked: Unregistered workstation attempted access to /login.',
                    null,
                    'warning',
                    ['ip' => $request->ip(), 'path' => $request->path()],
                    'blocked',
                    $request
                );

                return Inertia::render('NotFound')
                    ->toResponse($request)
                    ->setStatusCode(404);
            }

            $user = $request->user();
            if ($user && $user->isAdminOrStaff()) {
                $team = $user->currentTeam ?: $user->teams()->first() ?: \App\Models\Team::first();
                $teamSlug = $team?->slug ?? 'mr-pride';

                if ($user->isAdmin()) {
                    return redirect("/{$teamSlug}/dashboard");
                }

                return redirect("/{$teamSlug}/" . $user->assignedModuleSlug());
            }

            return Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'status' => $request->session()->get('status'),
                'teamInvitation' => $this->teamInvitation($request),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            'initialEmail' => $request->session()->get('reset_email') ?? $request->query('email'),
            'initialOtpSent' => (bool) $request->session()->get('otp_sent', false),
            'initialOtpVerified' => (bool) $request->session()->get('otp_verified', false),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input(Fortify::username());
            $throttleKey = Str::transliterate(Str::lower($email).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Configure audit logging listeners for authentication events.
     */
    private function configureSecurityListeners(): void
    {
        Event::listen(Failed::class, function (Failed $event) {
            $email = $event->credentials['email'] ?? 'unknown';
            $ip = request()->ip();
            $cacheKey = "failed_logins:{$ip}:" . strtolower((string) $email);
            $attempts = 1;
            try {
                $attempts = (int) cache()->increment($cacheKey, 1);
                if ($attempts === 1) {
                    cache()->put($cacheKey, 1, now()->addMinutes(15));
                }
            } catch (\Throwable) {
                // cache fallback
            }

            $isThreat = $attempts >= 3;
            $severity = $isThreat ? 'danger' : 'warning';
            $outcome = $attempts >= 5 ? 'throttled' : 'failure';
            $description = $isThreat
                ? "Threat Detected: Multiple repeated failed login attempts ({$attempts} attempts) for email '{$email}'."
                : 'Failed login attempt for email: ' . $email;

            AuditLogger::security(
                $isThreat ? 'auth.threat.brute_force' : 'auth.login.failed',
                $description,
                $event->user,
                $severity,
                ['email' => $email, 'attempts' => $attempts, 'ip' => $ip],
                $outcome
            );
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                AuditLogger::security(
                    'auth.logout',
                    "User {$event->user->email} logged out.",
                    $event->user,
                    'info',
                    null,
                    'success'
                );
            }
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event) {
            AuditLogger::security(
                'auth.password.reset',
                "Password reset completed for {$event->user->email}.",
                $event->user,
                'info',
                null,
                'success'
            );
        });
    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
        ];
    }
}
