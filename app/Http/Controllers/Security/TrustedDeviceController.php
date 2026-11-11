<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TrustedDeviceController extends Controller
{
    /**
     * Display trusted devices list.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        $currentDeviceUuid = $request->cookie('mrpride_device_id');

        $query = TrustedDevice::with('user:id,name,email,role');

        // Staff can only view and manage their own devices; Admin can view all
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $devices = $query->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TrustedDevice $d) => [
                'id' => $d->id,
                'user' => [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                    'email' => $d->user->email,
                    'role' => $d->user->role,
                ],
                'device_name' => $d->device_name,
                'ip_address' => $d->ip_address,
                'status' => $d->status,
                'is_current' => $d->device_uuid === $currentDeviceUuid,
                'last_used_at' => $d->last_used_at?->diffForHumans() ?? 'Never',
                'created_at' => $d->created_at->format('M d, Y H:i'),
            ]);

        return Inertia::render('Security/Devices', [
            'devices' => $devices,
            'isAdmin' => $user->isAdmin(),
            'currentDeviceUuid' => $currentDeviceUuid,
            'adminRecoveryKey' => $user->isAdmin() ? $user->admin_recovery_key : null,
        ]);
    }

    /**
     * Approve a pending or revoked device (Admin only).
     */
    public function approve(Request $request, TrustedDevice $device): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdmin(), 403, 'Approving devices requires administrative privileges.');

        $device->update([
            'status' => 'approved',
            'last_used_at' => now(),
        ]);

        AuditLogger::log(
            'auth.device.approved_by_admin',
            "Administrator {$user->email} approved device '{$device->device_name}' for user {$device->user->email}.",
            $device->user,
            ['device_id' => $device->id],
            $request
        );

        return back()->with('success', "Device '{$device->device_name}' has been approved.");
    }

    /**
     * Revoke a device and terminate any active sessions.
     */
    public function revoke(Request $request, TrustedDevice $device): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        // Staff can only revoke their own devices
        if (! $user->isAdmin() && $device->user_id !== $user->id) {
            abort(403, 'You can only manage your own registered devices.');
        }

        $device->update(['status' => 'revoked']);

        // Invalidate sessions associated with this user
        if ($device->ip_address) {
            DB::table('sessions')
                ->where('user_id', $device->user_id)
                ->where('ip_address', $device->ip_address)
                ->delete();
        }

        AuditLogger::log(
            'auth.device.revoked',
            "User {$user->email} revoked trusted device '{$device->device_name}' (User: {$device->user->email}). Associated sessions terminated.",
            $device->user,
            ['device_id' => $device->id],
            $request
        );

        return back()->with('success', "Device '{$device->device_name}' has been revoked.");
    }

    /**
     * Delete a device record.
     */
    public function destroy(Request $request, TrustedDevice $device): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user || ! $user->isAdminOrStaff(), 403);

        if (! $user->isAdmin() && $device->user_id !== $user->id) {
            abort(403, 'You can only remove your own registered devices.');
        }

        $name = $device->device_name;
        $device->delete();

        AuditLogger::log(
            'auth.device.deleted',
            "Trusted device '{$name}' was removed from the system.",
            $user,
            ['device_name' => $name],
            $request
        );

        return back()->with('success', "Device '{$name}' removed.");
    }
}
