<?php

use App\Models\User;

/**
 * Customers browse and request quotations as guests; they never create accounts.
 * Admin and staff accounts are created by an administrator in User Management.
 */
test('the sign-up page does not exist', function () {
    $this->get('/register')->assertNotFound();
});

test('accounts cannot be created by posting to the sign-up endpoint', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('guests can still browse and open the quotation request form', function () {
    foreach (['/', '/products', '/company', '/contact-us', '/quotation'] as $url) {
        $this->get($url)->assertOk();
    }
});
