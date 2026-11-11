<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request when the system is under maintenance.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! MaintenanceService::isDown()) {
            return $next($request);
        }

        $user = $request->user();

        // Allow administrators to bypass maintenance mode so they can perform
        // upgrades, test functions, and execute pre-reopening verification.
        if ($user && $user->isAdmin()) {
            return $next($request);
        }

        // Allow maintenance status endpoint or admin login if needed
        if ($request->is('settings/maintenance*', 'device/enroll*')) {
            return $next($request);
        }

        $status = MaintenanceService::getStatus();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'title' => 'System Under Maintenance',
                'message' => 'MRP ElectroDesk is temporarily unavailable while we perform system maintenance.',
                'reason' => $status['reason'],
                'scheduled_start_at' => $status['scheduled_start_at'] ?? null,
                'expected_return_time' => $status['expected_return_time'],
                'expected_end_at' => $status['expected_end_at'] ?? null,
            ], 503, [
                'Retry-After' => '300',
            ]);
        }

        return Inertia::render('Maintenance', [
            'title' => 'System Under Maintenance',
            'message' => 'MRP ElectroDesk is temporarily unavailable while we perform system maintenance.',
            'reason' => $status['reason'],
            'scheduledStartAt' => $status['scheduled_start_at'] ?? null,
            'expectedReturnTime' => $status['expected_return_time'],
            'expectedEndAt' => $status['expected_end_at'] ?? null,
            'enabledAt' => $status['enabled_at'],
        ])->toResponse($request)->setStatusCode(503)->header('Retry-After', '300');
    }
}
