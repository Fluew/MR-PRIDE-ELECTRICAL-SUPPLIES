<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        // Off on Railway (no SSR server runs there); on locally, where Vite serves it.
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

        // Pages fall back to client rendering if SSR doesn't answer in time, so never wait long.
        'timeout' => 3,

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Records every request (props, queries, response) to disk for the Inertia
    | DevTools browser extension. The package turns it on in local, where it
    | makes pages 3-4x slower, so it is opt-in: set INERTIA_DEVTOOLS_ENABLED
    | to true while debugging with the extension.
    |
    */

    'devtools' => [

        'enabled' => (bool) env('INERTIA_DEVTOOLS_ENABLED', false),

        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],

        'storage' => [

            'path' => storage_path('inertia-devtools'),

            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),

            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),

            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
