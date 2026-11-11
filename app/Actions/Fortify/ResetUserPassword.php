<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Prevent reuse of recent passwords
        $history = $user->password_history ?? [];
        if (Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Your new password cannot be identical to your previous password.'],
            ]);
        }

        foreach ($history as $oldHash) {
            if (Hash::check($input['password'], $oldHash)) {
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
            'password' => Hash::make($input['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'password_history' => $history,
        ])->save();

        // Invalidate all existing sessions for this user upon password reset
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::log(
            'auth.password.reset',
            "Password reset successfully for {$user->email}. All existing sessions revoked.",
            $user
        );
    }
}
