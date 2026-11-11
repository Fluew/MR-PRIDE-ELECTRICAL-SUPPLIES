<?php

namespace App\Http\Middleware;

use App\Services\ExchangeRates;
use App\Support\Currency;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Encrypt Inertia's client-side history entries for authenticated sessions.
     *
     * Page props are otherwise stored in plain text in the browser's history state, which
     * the Back button restores without contacting the server. Encrypted entries become
     * unreadable once the key is cleared on logout (see LogoutResponse).
     */
    public function handle(Request $request, Closure $next)
    {
        // Browsers only offer the crypto API on HTTPS (or localhost). Devices opening the dev
        // server by LAN IP over plain HTTP can't encrypt, so Inertia warns on every visit there.
        $canEncrypt = app()->isProduction() || $request->isSecure() || in_array($request->getHost(), ['localhost', '127.0.0.1'], true);

        Inertia::encryptHistory($request->user() !== null && $canEncrypt);

        return parent::handle($request, $next);
    }

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // The user's teams are read once per request and serve both currentTeam and teams.
        $userTeams = null;
        $teams = function () use ($user, &$userTeams) {
            return $userTeams ??= $user?->toUserTeams(includeCurrent: true) ?? collect();
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                // Modules the current user may access (read from the database on each request).
                'modules' => fn () => $user ? $user->assignedModules() : [],
                // From User::isAdmin(). UI hints only; the server enforces both (role / admin_delete middleware).
                'is_admin' => (bool) $user?->isAdmin(),
                'can_delete' => (bool) $user?->isAdmin(),
                'is_first_login' => fn () => (bool) $request->session()->get('is_first_login', false),
            ],
            // Admin/Staff display currency (amounts are stored in USD). Only an already-cached rate is
            // included, so pages never wait on the rate service; the browser fetches it when needed.
            // Public visitors and customers get nothing.
            'currency' => fn () => $user?->isAdminOrStaff() ? [
                'preferred' => in_array($user->preferred_currency, Currency::SUPPORTED, true) ? $user->preferred_currency : Currency::DEFAULT,
                'base' => Currency::BASE,
                'rate' => app(ExchangeRates::class)->cached(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam
                ? ($teams()->firstWhere('id', $user->current_team_id) ?? $user->toUserTeam($user->currentTeam))
                : null,
            'teams' => fn () => $teams()->all(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'status' => fn () => $request->session()->get('status'),
                'device_notice' => fn () => $request->session()->get('device_notice'),
            ],
            'device_notice' => fn () => $request->session()->get('device_notice'),
        ];
    }
}
