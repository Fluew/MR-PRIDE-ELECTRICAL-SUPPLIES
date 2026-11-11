<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        // Rotate the client-side history encryption key so previously cached
        // authenticated pages (Back/Forward) cannot be decrypted after logout.
        Inertia::clearHistory();

        $response = new RedirectResponse(route('login'));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
