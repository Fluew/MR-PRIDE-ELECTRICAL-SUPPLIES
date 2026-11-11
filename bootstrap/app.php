<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnforceInactivityTimeout;
use App\Http\Middleware\EnsureAdminForDeletion;
use App\Http\Middleware\EnsureAdminOrStaff;
use App\Http\Middleware\EnsureDeviceVerified;
use App\Http\Middleware\EnsurePasswordNotExpired;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoHttpCache;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Models\Team;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Behind Railway's proxy (TLS ends there): trust its X-Forwarded-* headers so URLs,
        // redirects and secure cookies use https.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            EnforceInactivityTimeout::class,
            SecurityHeaders::class,
            CheckMaintenanceMode::class,
        ]);

        $middleware->alias([
            'admin_or_staff' => EnsureAdminOrStaff::class,
            'role' => EnsureRole::class,
            'device_verified' => EnsureDeviceVerified::class,
            'password_valid' => EnsurePasswordNotExpired::class,
            'no_cache' => NoHttpCache::class,
            'admin_delete' => EnsureAdminForDeletion::class,
        ]);
        // Guests (including expired sessions) on any protected page go to the login page.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));

        // Authenticated users hitting guest-only pages (e.g. /login, /register) are redirected appropriately.
        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();

            if (! $user || $user->isUser()) {
                return '/';
            }

            if ($user->isPasswordExpired()) {
                return route('password.change');
            }

            $team = $user->currentTeam ?: $user->teams()->first() ?: Team::first();
            $teamSlug = $team?->slug ?? 'mr-pride';

            if ($user->isAdmin()) {
                return "/{$teamSlug}/dashboard";
            }

            if ($user->isStaff()) {
                return "/{$teamSlug}/".$user->assignedModuleSlug();
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Uploads larger than the server accepts (PHP post_max_size) arrive with no form data at
        // all. Say so plainly instead of showing an error page; the form keeps what was typed.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = 'The files you selected are larger than the server accepts. Please remove a file or choose smaller ones (25 MB each at most).';
            if (! $request->header('X-Inertia') && ($request->expectsJson() || $request->ajax() || $request->is('api/*'))) {
                return response()->json(['message' => $message, 'reason' => 'too_large'], 413);
            }

            return back()->withErrors(['upload' => $message]);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            return Inertia::render('NotFound')
                ->toResponse($request)
                ->setStatusCode(404);
        });

        // 403 Forbidden: a signed-in user without permission. Show a page that keeps them inside
        // the app (link back to their own landing page) instead of Laravel's plain error page.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 403 || $request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            $user = $request->user();

            return Inertia::render('Forbidden', [
                'message' => $e->getMessage() ?: 'You do not have permission to open this page.',
                // /dashboard sends admins to their dashboard and staff to their first module.
                'homeUrl' => $user?->isAdminOrStaff() ? '/dashboard' : '/',
            ])
                ->toResponse($request)
                ->setStatusCode(403);
        });
    })->create();
