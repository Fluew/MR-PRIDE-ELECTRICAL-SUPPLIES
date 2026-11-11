<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceInactivityTimeout
{
    /**
     * Timeout duration in seconds (30 minutes).
     */
    protected const TIMEOUT_SECONDS = 1800;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isAdminOrStaff()) {
            $lastActivity = $request->session()->get('last_activity_time');

            if ($lastActivity && (time() - $lastActivity > self::TIMEOUT_SECONDS)) {
                AuditLogger::log(
                    'auth.session.expired',
                    'Session terminated due to 30 minutes of inactivity.',
                    $user,
                    ['inactive_duration' => time() - $lastActivity],
                    $request
                );

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with(
                    'status',
                    'Your session has timed out due to 30 minutes of inactivity. Please log in again.'
                );
            }

            $request->session()->put('last_activity_time', time());
        }

        return $next($request);
    }
}
