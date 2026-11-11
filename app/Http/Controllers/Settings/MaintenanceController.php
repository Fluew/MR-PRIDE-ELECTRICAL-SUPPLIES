<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    /**
     * Show Maintenance Mode settings and control dashboard (Admin only).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Maintenance controls require administrative privileges.');

        $recentLogs = AuditLog::where('event', 'like', 'system.maintenance.%')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'user_email' => $log->user_email ?: 'Admin',
                'outcome' => $log->outcome ?: 'success',
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->format('M d, Y h:i:s A') ?? '',
            ]);

        return Inertia::render('settings/Maintenance', [
            'status' => MaintenanceService::getStatus(),
            'isSuperAdmin' => (bool) $user->isAdmin(),
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Step 1 & 2 — Admin enables or updates maintenance mode and schedule.
     */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Maintenance controls require administrative privileges.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'scheduled_start_at' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! empty($value)) {
                        try {
                            $parsed = Carbon::parse($value);
                            // Allow 2-minute tolerance for "now" while rejecting past dates
                            if ($parsed->lt(now()->subMinutes(2))) {
                                $fail('The scheduled start date and time cannot be in the past.');
                            }
                        } catch (\Throwable) {
                            $fail('The scheduled start date and time format is invalid.');
                        }
                    }
                },
            ],
            'duration_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'expected_return_time' => ['nullable', 'string', 'max:150'],
            'is_manual_override' => ['nullable', 'boolean'],
        ]);

        $hours = isset($validated['duration_hours']) ? (int) $validated['duration_hours'] : 0;
        $minutes = isset($validated['duration_minutes'])
            ? (int) $validated['duration_minutes']
            : ($request->has('duration_hours') ? 0 : 30);

        if (($hours * 60 + $minutes) < 1) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Estimated duration must be at least 1 minute.',
            ]);
        }

        MaintenanceService::enable($validated, $user);

        return back()->with('success', 'Maintenance Mode has been enabled. The system is protected and displaying the maintenance notice.');
    }

    /**
     * Reopen system and disable maintenance mode.
     */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Maintenance controls require administrative privileges.');

        MaintenanceService::disable($user);

        return back()->with('success', 'Maintenance Mode disabled. MRP ElectroDesk is now reopened and fully operational.');
    }

    /**
     * Step 4 — Run pre-reopening system readiness verification checks.
     */
    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Maintenance controls require administrative privileges.');

        $results = MaintenanceService::verifyReadiness();

        return response()->json($results);
    }
}
