<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordNotExpired
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdminOrStaff()) {
            return $next($request);
        }

        if ($user->isPasswordExpired()) {
            $allowedRoutes = [
                'password.change',
                'password.change.update',
                'logout',
            ];

            if (! in_array($request->route()?->getName(), $allowedRoutes, true)) {
                return redirect()->route('password.change')->with(
                    'notice',
                    $user->must_change_password
                        ? 'Initial login: You must update your temporary password before accessing the dashboard.'
                        : 'Security policy: Your password has expired (90 days). You must update your password to proceed.'
                );
            }
        }

        return $next($request);
    }
}
