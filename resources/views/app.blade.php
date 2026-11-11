<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Force light mode background & suppress native browser password reveal icons --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }
            input::-ms-reveal,
            input::-ms-clear {
                display: none !important;
                width: 0 !important;
                height: 0 !important;
            }
            input::-webkit-contacts-auto-fill-button,
            input::-webkit-credentials-auto-fill-button {
                visibility: hidden !important;
                display: none !important;
                pointer-events: none !important;
            }
        </style>

        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
