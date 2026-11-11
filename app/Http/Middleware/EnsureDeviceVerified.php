<?php

namespace App\Http\Middleware;

use App\Models\TrustedDevice;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route()?->getName() === 'logout') {
            return $next($request);
        }

        $device = TrustedDevice::findActiveByCredential($request);

        // 1. Device check: device must be registered and active
        if (! $device) {
            $user = $request->user();

            if ($user) {
                auth()->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                AuditLogger::security(
                    'auth.threat.unregistered_device',
                    "Threat Blocked: Access attempt from unregistered or revoked workstation for {$user->email}.",
                    $user,
                    'danger',
                    ['ip' => $request->ip(), 'path' => $request->path()],
                    'blocked',
                    $request
                );
            } else {
                AuditLogger::security(
                    'auth.threat.unregistered_device',
                    "Threat Blocked: Unregistered workstation attempted access to /{$request->path()}.",
                    null,
                    'warning',
                    ['ip' => $request->ip(), 'path' => $request->path()],
                    'blocked',
                    $request
                );
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Not Found',
                ], 404);
            }

            return Inertia::render('NotFound')
                ->toResponse($request)
                ->setStatusCode(404);
        }

        // 2. Account binding check: if authenticated, verify account is authorized on this device
        $user = $request->user();
        if ($user) {
            if (! $user->isActive()) {
                auth()->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['message' => 'Your account has been deactivated. Please contact the administrator.'], 403);
                }

                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been deactivated. Please contact the administrator.',
                ]);
            }

            if (! $device->isAuthorizedForUser($user)) {
                auth()->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                AuditLogger::security(
                    'auth.device.account_mismatch',
                    "Workstation mismatch: Account {$user->email} denied access to device '{$device->device_name}' ({$device->device_uuid}).",
                    $user,
                    'warning',
                    ['device_id' => $device->id, 'device_uuid' => $device->device_uuid, 'user_id' => $user->id],
                    'blocked',
                    $request
                );

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Login Restricted: This account is not authorized to use this workstation. Please contact your administrator.',
                    ], 403);
                }

                return redirect()->route('login')->withErrors([
                    'email' => 'This account is not authorized to use this workstation. Please contact your administrator.',
                ]);
            }

            // Update device last used timestamp and IP
            $device->updateQuietly([
                'last_used_at' => now(),
                'ip_address' => $request->ip(),
            ]);
        }

        return $next($request);
    }
}

