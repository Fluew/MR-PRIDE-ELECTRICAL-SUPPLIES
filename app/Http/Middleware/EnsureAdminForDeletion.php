<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts destructive (delete / archive) endpoints to administrators.
 *
 * Staff keep whatever view/create/edit/process access their module grants; this only
 * guards the delete endpoints. Unlike `role:admin` (which hides admin pages behind a 404),
 * this returns an explicit 403 Forbidden.
 */
class EnsureAdminForDeletion
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            abort(404);
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        AuditLogger::security(
            'auth.unauthorized_delete',
            "User {$user->email} (role: {$user->role}) attempted a delete operation: {$request->method()} /{$request->path()}.",
            $user,
            'danger',
            ['path' => $request->path(), 'method' => $request->method()],
            'blocked',
            $request
        );

        $message = 'Only administrators can delete records.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }
}
