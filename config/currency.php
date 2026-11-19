<?php

return [
    /*
    | Currency display for the Admin/Staff interface (App\Support\Currency).
    |
    | Stored amounts are USD. When a user chooses PHP, amounts are converted for display with the
    | latest USD→PHP rate from Frankfurter (https://frankfurter.dev). The rate is cached, never
    | hardcoded, and never used when it could not be fetched or looks invalid.
    */
    'usd_php_rate_url' => env('CURRENCY_RATE_URL', 'https://api.frankfurter.dev/v2/rate/usd/php'),

    // How long a fetched rate is used before it is fetched again (seconds).
    'cache_seconds' => (int) env('CURRENCY_RATE_CACHE_SECONDS', 3600),

    // After a failed fetch, wait this long before asking the service again (seconds). "Retry" in
    // the header skips the wait.
    'failure_backoff_seconds' => 60,

    // Request timeout (seconds).
    'timeout' => 5,

    // Connect over IPv4 ('v4'), IPv6 ('v6') or whichever answers (null). IPv4 by default: on networks
    // with a slow IPv6 route the connection takes over 3 seconds and the fetch times out.
    'ip_resolve' => env('CURRENCY_IP_RESOLVE', 'v4') ?: null,

    // A rate dated further back than this is treated as unavailable rather than shown as current.
    // Frankfurter publishes on working days, so weekends and holidays are a few days at most.
    'max_age_days' => 10,

    // Path to a CA certificate bundle, for PHP installs without curl.cainfo / openssl.cafile set.
    // A CURRENCY_CA_BUNDLE path that does not exist (e.g. another machine's Git install) falls back to
    // the bundle shipped in storage/certs, the one ResendService uses, instead of failing every fetch.
    'ca_bundle' => (static function (): ?string {
        $configured = env('CURRENCY_CA_BUNDLE');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        $bundled = storage_path('certs/cacert.pem');

        return is_file($bundled) ? $bundled : null;
    })(),
];
