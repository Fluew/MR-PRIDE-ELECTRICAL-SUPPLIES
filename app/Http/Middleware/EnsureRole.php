<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            abort(404);
        }

        // Immediately block deactivated accounts
        if (! $user->isActive()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(404);
        }

        // Admin can access ALL modules unconditionally
        if ($user->isAdmin()) {
            return $next($request);
        }

        if (! self::allows($user, $roles)) {
            AuditLogger::security(
                'auth.unauthorized_access',
                "User {$user->email} (role: {$user->role}) attempted unauthorized access to {$request->method()} /{$request->path()}.",
                $user,
                'danger',
                ['path' => $request->path(), 'method' => $request->method(), 'required_roles' => $roles],
                'blocked',
                $request
            );

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthorized access. Your assigned role lacks permissions for this operation.'], 403);
            }

            // Staff who open a module they are not assigned to (or an admin-only area) get the
            // 403 Forbidden page. Customers never get this far: admin_or_staff hides the whole
            // workspace from them with a 404.
            abort(403, 'You do not have access to this module. Ask an administrator if you need it.');
        }

        return $next($request);
    }

    /**
     * Whether this user passes a `role:` check with these arguments. Also used after login to
     * decide whether a staff member may be sent to the page they originally asked for.
     *
     * @param  array<int, string>  $roles
     */
    public static function allows(User $user, array $roles): bool
    {
        // Admin can access ALL modules unconditionally
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isStaff() && $user->hasExplicitModules()) {
            // Staff with explicit module assignments: only the assigned modules are accessible.
            // Permissions are read from the database on every request, so changes apply immediately.
            return $user->hasAnyModule(self::modulesForRoles($roles));
        }

        // Legacy single-role accounts (and non-staff roles): unchanged behaviour.
        if (in_array($user->role, $roles, true)) {
            return true;
        }

        // Also check if any module-level shorthand was passed (e.g. 'inventory' matches 'staff_inventory')
        foreach ($roles as $requiredRole) {
            if ($user->role === 'staff_'.$requiredRole
                || ($user->role === 'staff' && $requiredRole === 'inventory')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate role middleware arguments (e.g. "admin,staff_inventory,staff,sales") into module slugs.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private static function modulesForRoles(array $roles): array
    {
        $modules = [];

        foreach ($roles as $role) {
            if ($role === 'admin') {
                continue;
            }

            if ($role === 'staff') {
                $modules[] = 'inventory';
            } elseif (str_starts_with($role, 'staff_')) {
                $modules[] = substr($role, strlen('staff_'));
            } else {
                $modules[] = $role;
            }
        }

        return $modules;
    }
}
