<?php

test('web requests return iso 25010 compliant security headers', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-XSS-Protection', '1; mode=block');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
});

test('the cross-origin opener policy is only sent over https, where browsers honour it', function () {
    $this->get('/')->assertHeaderMissing('Cross-Origin-Opener-Policy');
    $this->get('https://localhost/')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
});

test('the content security policy allows the resources the app actually loads', function () {
    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("img-src 'self' data: blob:");
});
