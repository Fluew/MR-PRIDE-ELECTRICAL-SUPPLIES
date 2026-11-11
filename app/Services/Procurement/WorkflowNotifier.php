<?php

namespace App\Services\Procurement;

use App\Http\Middleware\EnsureRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ProcurementActivity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one in-app notification per workflow step to the team's admins and/or the staff of the
 * given roles. The person who did the action is not notified. A failed notification never
 * undoes the business action; it is logged instead.
 */
class WorkflowNotifier
{
    /**
     * @param  list<string>  $roles  staff roles to notify, e.g. ['staff_procurement']; 'admin' for admins
     */
    public function notify(Team $team, array $roles, string $event, string $title, string $message, ?User $actor = null, ?string $path = null): void
    {
        try {
            $recipients = $team->members()->with('modulePermissions')->get()->filter(function (User $u) use ($roles, $actor) {
                if ($actor && $u->id === $actor->id) {
                    return false;
                }
                if (method_exists($u, 'isActive') && ! $u->isActive()) {
                    return false;
                }

                if ($u->isAdmin()) {
                    return in_array('admin', $roles, true);
                }
                $staffRoles = array_values(array_diff($roles, ['admin']));

                // Same module check as the routes, so staff with several assigned modules are included.
                return $staffRoles !== [] && EnsureRole::allows($u, $staffRoles);
            });

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ProcurementActivity($event, $title, $message, $path ? "/{$team->slug}/{$path}" : null));
            }
        } catch (Throwable $e) {
            Log::warning("Procurement notification '{$event}' was not sent: ".$e->getMessage());
        }
    }
}
