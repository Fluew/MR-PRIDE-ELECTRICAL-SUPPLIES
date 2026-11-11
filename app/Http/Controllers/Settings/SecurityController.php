<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password with password history check and session invalidation.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Prevent reuse of current password
        if (Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Your new password cannot be the same as your current password.'],
            ]);
        }

        // Prevent reuse of recent passwords from history
        $history = $user->password_history ?? [];
        foreach ($history as $oldHash) {
            if (Hash::check($request->password, $oldHash)) {
                throw ValidationException::withMessages([
                    'password' => ['You cannot reuse any of your recent passwords. Please choose a new, unique password.'],
                ]);
            }
        }

        $history[] = $user->password;
        if (count($history) > 3) {
            $history = array_slice($history, -3);
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'password_history' => $history,
        ])->save();

        // Invalidate other active sessions
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        AuditLogger::log(
            'auth.password.changed',
            "User {$user->email} updated their account password. Other active sessions revoked.",
            $user,
            [],
            $request
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated successfully and other sessions signed out.')]);

        return back();
    }
}
