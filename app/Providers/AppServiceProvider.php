<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->useLoopbackForDevSsr();

        // Server-side render only the public marketing pages (search engines, fast first paint).
        // Staff pages are behind login, so SSR would only add a render round-trip to each load.
        Inertia::disableSsr(fn (Request $request) => ! config('inertia.ssr.enabled') || ! $request->routeIs(
            'home', 'products.index', 'company.index', 'contact.index', 'quotations.create',
        ));
        // Customer emails are queued by App\Services\Notifications\CustomerNotifier from the
        // workflow services, one per business event.
    }

    /**
     * PHP and the Vite dev server always run on the same machine, but public/hot holds the LAN IP
     * (so other devices can load assets). That IP goes stale when the network changes, and every
     * full page load then waits on server-side rendering until it times out.
     */
    protected function useLoopbackForDevSsr(): void
    {
        if (config('inertia.ssr.hot_url') || ! Vite::isRunningHot()) {
            return;
        }

        $port = parse_url(trim((string) file_get_contents(Vite::hotFile())), PHP_URL_PORT) ?: 5173;

        config(['inertia.ssr.hot_url' => "http://127.0.0.1:{$port}"]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Report N+1 queries in development without breaking the page.
        Model::preventLazyLoading(! app()->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::channel('query')->warning('Lazy loaded ['.$relation.'] on ['.$model::class.']', [
                'url' => request()?->path(),
            ]);
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        DB::whenQueryingForLongerThan(500, function ($connection) {
            Log::channel('query')->warning('Request spent over 500 ms in database queries', [
                'url' => request()?->path(),
                'total_ms' => round($connection->totalQueryDuration()),
            ]);
        });

        // One permanent-password policy everywhere (password change, reset, settings): at least 12
        // characters with upper- and lowercase letters, a number and a symbol. In production the
        // password is also checked against known data breaches (needs internet access).
        Password::defaults(function (): Password {
            $rule = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
