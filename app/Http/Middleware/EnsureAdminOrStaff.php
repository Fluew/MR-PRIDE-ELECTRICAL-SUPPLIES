<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminOrStaff
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(404);
        }

        // Deactivated accounts cannot access workspace
        if (! $user->isActive()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(404);
        }

        // Regular users attempting to access admin or staff routes receive 404 Page Not Found
        if (! $user->isAdminOrStaff()) {
            \App\Services\AuditLogger::security(
                'auth.unauthorized_access',
                "Unauthorized user {$user->email} blocked from admin/staff path {$request->path()}.",
                $user,
                'warning',
                ['path' => $request->path()],
                'blocked',
                $request
            );

            abort(404);
        }

        return $next($request);
    }
}
