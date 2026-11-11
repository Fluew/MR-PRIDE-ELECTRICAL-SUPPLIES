<?php

namespace App\Http\Responses;

use App\Http\Middleware\EnsureRole;
use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    use RedirectsToCurrentTeam;

    public function toResponse($request): Response
    {
        $user = $request->user();

        // 1. Check if account is active
        if (! $user->isActive()) {
            auth()->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            AuditLogger::security(
                'auth.login.deactivated',
                "Login blocked: User {$user->email} is deactivated.",
                $user,
                'warning',
                ['role' => $user->role],
                'blocked',
                $request
            );

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Please contact the administrator.',
            ]);
        }

        // 2. Strict Device Check (for all accounts including Admin and Staff)
        $device = TrustedDevice::findActiveByCredential($request);
        if (! $device) {
            auth()->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            AuditLogger::security(
                'auth.login.unregistered_device',
                "Login blocked: User {$user->email} attempted login on unregistered or revoked device.",
                $user,
                'warning',
                ['ip' => $request->ip()],
                'blocked',
                $request
            );

            return redirect()->route('login')->withErrors([
                'email' => 'Access Denied: This device is not registered or is no longer authorized to access MRP ElectroDesk. Contact your system administrator.',
            ]);
        }

        // 3. Account-to-Device Binding check
        if (! $device->isAuthorizedForUser($user)) {
            auth()->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            AuditLogger::security(
                'auth.device.account_mismatch',
                "Login rejected: Account {$user->email} is not authorized to use device '{$device->device_name}' ({$device->device_uuid}).",
                $user,
                'warning',
                ['device_id' => $device->id, 'device_uuid' => $device->device_uuid, 'user_id' => $user->id],
                'blocked',
                $request
            );

            return redirect()->route('login')->withErrors([
                'email' => 'This account is not authorized to use this workstation. Please contact your administrator.',
            ]);
        }

        // 4. Update last login and device usage
        $isFirstLogin = ($user->last_login_at === null);
        if ($isFirstLogin) {
            session(['is_first_login' => true]);
        }

        $userUpdates = ['last_login_at' => now()];
        if ($user->status === 'pending_activation') {
            $userUpdates['status'] = 'active';
        }
        $user->update($userUpdates);
        $device->updateQuietly([
            'last_used_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        AuditLogger::security(
            'auth.login.success',
            "User {$user->email} logged in successfully on device '{$device->device_name}'.",
            $user,
            'info',
            ['role' => $user->role, 'device_id' => $device->id, 'device_name' => $device->device_name],
            'success',
            $request
        );

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        // Regular users go to home page
        if ($user->isUser()) {
            return redirect('/');
        }

        // Mandatory password change check
        if ($user->isPasswordExpired()) {
            return redirect()->route('password.change');
        }

        $team = $user->currentTeam ?: $user->teams()->first() ?: Team::first();
        $teamSlug = $team?->slug ?? 'mr-pride';

        if ($user->isAdmin()) {
            return redirect()->intended("/{$teamSlug}/dashboard");
        }

        if ($user->isStaff()) {
            // Only honour the page they originally asked for if they may open it; otherwise a
            // fresh login would land on a 403. Fall back to their first assigned module.
            $landing = "/{$teamSlug}/".$user->assignedModuleSlug();
            $intended = $request->session()->pull('url.intended');

            return redirect()->to($intended && $this->staffMayOpen($request, $user, $intended) ? $intended : $landing);
        }

        return redirect()->intended($this->redirectPathForCurrentTeam($request, Fortify::redirects('login')));
    }

    /**
     * Whether a staff member may open this URL: same site, a team they belong to, and every
     * `role:` check on the matching route passes (the same rules EnsureRole applies).
     */
    protected function staffMayOpen($request, User $user, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && $host !== $request->getHost()) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($url, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        $teamSlug = $route->parameter('current_team');
        if (is_string($teamSlug) && ! $user->teams()->where('slug', $teamSlug)->exists()) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')
                && ! EnsureRole::allows($user, explode(',', substr($middleware, 5)))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse human-friendly device name from User-Agent.
     */
    protected function parseDeviceName($request): string
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
