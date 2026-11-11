<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'name' => env('RESEND_FROM_NAME', 'mrp-electrodesk-dev'),
        'from_email' => env('RESEND_FROM_EMAIL', 'onboarding@resend.dev'),
        // Development only (APP_ENV=local): send every email to this address instead. Use the
        // email of your Resend account, because the sandbox sender only delivers there.
        'dev_redirect_to' => env('RESEND_DEV_REDIRECT_TO'),
    ],

    // Automatic customer emails (delivery status, order and payment updates). "auto": sent by the
    // web request itself right after its response, so no queue worker is needed; console code
    // uses the queue. "queue": always through the queue (run `php artisan queue:work`).
    // "after_response": always in the same process.
    'customer_emails' => [
        'send' => env('CUSTOMER_EMAILS_SEND', 'auto'),
    ],

    // OCR for uploaded material lists (images and scanned PDFs). Point these at the installed
    // programs if they are not on the PATH, e.g. "C:\Program Files\Tesseract-OCR\tesseract.exe".
    'ocr' => [
        'tesseract' => env('TESSERACT_PATH', 'tesseract'),
        'pdftoppm' => env('PDFTOPPM_PATH', 'pdftoppm'),
        'pdftotext' => env('PDFTOTEXT_PATH'), // default: next to pdftoppm
        'lang' => env('TESSERACT_LANG', 'eng'),
        'timeout' => (int) env('OCR_TIMEOUT', 60), // seconds per page
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
