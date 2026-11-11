<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserSessionController extends Controller
{
    /**
     * Display active sessions list.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        $currentSessionId = $request->session()->getId();

        $query = DB::table('sessions')
            ->join('users', 'sessions.user_id', '=', 'users.id')
            ->select(
                'sessions.id',
                'sessions.user_id',
                'sessions.ip_address',
                'sessions.user_agent',
                'sessions.last_activity',
                'users.name as user_name',
                'users.email as user_email',
                'users.role as user_role'
            );

        if (! $user->isAdmin()) {
            $query->where('sessions.user_id', $user->id);
        }

        $sessions = $query->orderByDesc('sessions.last_activity')
            ->get()
            ->map(function ($s) use ($currentSessionId) {
                return [
                    'id' => $s->id,
                    'user' => [
                        'id' => $s->user_id,
                        'name' => $s->user_name,
                        'email' => $s->user_email,
                        'role' => $s->user_role,
                    ],
                    'ip_address' => $s->ip_address,
                    'device_name' => $this->parseUserAgent($s->user_agent),
                    'is_current' => $s->id === $currentSessionId,
                    'last_activity' => Carbon::createFromTimestamp($s->last_activity)->diffForHumans(),
                ];
            });

        return Inertia::render('Security/Sessions', [
            'sessions' => $sessions,
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    /**
     * Revoke a single active session.
     */
    public function destroy(Request $request, string $sessionId): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        $session = DB::table('sessions')->where('id', $sessionId)->first();

        if (! $session) {
            return back()->with('error', 'Session not found or already expired.');
        }

        // Staff can only revoke their own sessions
        if (! $user->isAdmin() && $session->user_id !== $user->id) {
            abort(403, 'You can only revoke your own sessions.');
        }

        DB::table('sessions')->where('id', $sessionId)->delete();

        AuditLogger::log(
            'auth.session.revoked',
            "Session '{$sessionId}' revoked by {$user->email}.",
            $user,
            ['target_user_id' => $session->user_id, 'ip' => $session->ip_address],
            $request
        );

        if ($sessionId === $request->session()->getId()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your session was terminated.');
        }

        return back()->with('success', 'Active session terminated successfully.');
    }

    /**
     * Revoke all other sessions for current user.
     */
    public function revokeOthers(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        $currentSessionId = $request->session()->getId();

        $count = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        AuditLogger::log(
            'auth.sessions.revoked_others',
            "User {$user->email} revoked {$count} other active session(s).",
            $user,
            ['revoked_count' => $count],
            $request
        );

        return back()->with('success', "Revoked {$count} other active session(s).");
    }

    protected function parseUserAgent(?string $agent): string
    {
        if (! $agent) return 'Unknown Device';

        $os = 'Unknown OS';
        if (str_contains($agent, 'Windows')) $os = 'Windows';
        elseif (str_contains($agent, 'Macintosh')) $os = 'macOS';
        elseif (str_contains($agent, 'Linux')) $os = 'Linux';
        elseif (str_contains($agent, 'Android')) $os = 'Android';
        elseif (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) $os = 'iOS';

        $browser = 'Browser';
        if (str_contains($agent, 'Edg/')) $browser = 'Edge';
        elseif (str_contains($agent, 'Chrome/')) $browser = 'Chrome';
        elseif (str_contains($agent, 'Firefox/')) $browser = 'Firefox';
        elseif (str_contains($agent, 'Safari/') && ! str_contains($agent, 'Chrome/')) $browser = 'Safari';

        return "{$os} • {$browser}";
    }
}
