<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Display paginated audit logs (Admin only).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Audit logs access requires administrator privileges.');

        $search = $request->input('search');
        $eventFilter = $request->input('event');

        $query = AuditLog::with('user:id,name,email,role');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('user_email', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        if ($eventFilter && $eventFilter !== 'all') {
            $query->where('event', $eventFilter);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                    'role' => $log->user->role,
                ] : null,
                'user_email' => $log->user_email,
                'event' => $log->event,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->format('M d, Y H:i:s'),
                'time_ago' => $log->created_at->diffForHumans(),
            ]);

        $distinctEvents = AuditLog::select('event')->distinct()->pluck('event');

        return Inertia::render('Security/AuditLogs', [
            'logs' => $logs,
            'filters' => [
                'search' => $search,
                'event' => $eventFilter ?? 'all',
            ],
            'availableEvents' => $distinctEvents,
        ]);
    }
}
