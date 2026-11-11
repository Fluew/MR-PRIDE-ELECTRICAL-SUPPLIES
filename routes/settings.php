<?php

use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\MaintenanceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Settings and Teams are part of the internal Admin/Staff interface (they render inside the
// dashboard layout), so they get the same checks as the workspace: customers get 404, and the
// trusted-device and password-expiry rules apply.
$internal = ['admin_or_staff', 'device_verified', 'password_valid'];

Route::middleware(['auth', ...$internal, 'no_cache'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Header currency switch (display only; amounts are stored in USD).
    Route::put('settings/currency', [CurrencyController::class, 'update'])->middleware('throttle:30,1')->name('currency.update');
    Route::get('settings/currency/rate', [CurrencyController::class, 'rate'])->middleware('throttle:30,1')->name('currency.rate');
});

Route::middleware(['auth', 'verified', ...$internal, 'no_cache'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::redirect('settings/appearance', '/settings/profile');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });

    // System Maintenance section (Admin only)
    Route::middleware(['role:admin'])->group(function () {
        Route::get('settings/maintenance', [MaintenanceController::class, 'index'])->name('settings.maintenance');
        Route::post('settings/maintenance/enable', [MaintenanceController::class, 'enable'])->name('settings.maintenance.enable');
        Route::post('settings/maintenance/disable', [MaintenanceController::class, 'disable'])->name('settings.maintenance.disable');
        Route::match(['GET', 'POST'], 'settings/maintenance/verify', [MaintenanceController::class, 'verify'])->name('settings.maintenance.verify');

        // Recycle Bin under Settings -> System Maintenance
        Route::get('settings/maintenance/recycle-bin', [RecycleBinController::class, 'index'])->name('settings.maintenance.recycle-bin');
        Route::post('settings/maintenance/recycle-bin/bulk-restore', [RecycleBinController::class, 'bulkRestore'])->name('settings.maintenance.recycle-bin.bulk-restore');
        Route::post('settings/maintenance/recycle-bin/bulk-force-delete', [RecycleBinController::class, 'bulkForceDelete'])->name('settings.maintenance.recycle-bin.bulk-force-delete');
        Route::post('settings/maintenance/recycle-bin/empty', [RecycleBinController::class, 'emptyBin'])->name('settings.maintenance.recycle-bin.empty');
        Route::post('settings/maintenance/recycle-bin/{type}/{id}/restore', fn (Request $request, string $type, string $id) => app(RecycleBinController::class)->restore($request, null, $type, $id))->name('settings.maintenance.recycle-bin.restore');
        Route::delete('settings/maintenance/recycle-bin/{type}/{id}/force', fn (Request $request, string $type, string $id) => app(RecycleBinController::class)->forceDelete($request, null, $type, $id))->name('settings.maintenance.recycle-bin.force-delete');

        // Security Logs & System Audit Trail under Settings -> System Maintenance
        Route::get('settings/maintenance/security-logs', function (Request $request) {
            $request->merge(['tab' => 'security']);

            return app(UserController::class)->index($request);
        })->name('settings.maintenance.security-logs');

        Route::get('settings/maintenance/audit-trail', function (Request $request) {
            $request->merge(['tab' => 'audit']);

            return app(UserController::class)->index($request);
        })->name('settings.maintenance.audit-trail');
    });
});
