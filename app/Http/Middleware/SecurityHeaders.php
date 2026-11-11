<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', $this->buildCsp());

        // Browsers ignore COOP on plain-HTTP origins (e.g. phones opening the LAN IP) and log a
        // console error on every page, so only send it where it takes effect.
        if ($request->isSecure()) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        return $response;
    }

    private function buildCsp(): string
    {
        // The Instrument Sans webfont is self-hosted: laravel-vite-plugin downloads it from Bunny
        // at build time, so no font CDN is contacted at runtime and 'self' covers it.
        $script = ["'self'", "'unsafe-inline'"];
        $style = ["'self'", "'unsafe-inline'"];
        $font = ["'self'", 'data:'];
        $img = ["'self'", 'data:', 'blob:'];
        $connect = ["'self'"];

        // While `npm run dev` is running, every asset is served from the Vite dev server on
        // another origin (its LAN IP changes between machines, so it is read rather than guessed).
        if ($dev = $this->viteDevServerOrigin()) {
            $socket = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $dev);
            $script[] = $dev;
            $style[] = $dev;
            $font[] = $dev;
            $img[] = $dev;
            $connect[] = $dev;
            $connect[] = $socket;
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'font-src '.implode(' ', $font),
            'img-src '.implode(' ', $img),
            'connect-src '.implode(' ', $connect),
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }

    private function viteDevServerOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $origin = trim((string) file_get_contents($hotFile));

        return $origin !== '' ? rtrim($origin, '/') : null;
    }
}
