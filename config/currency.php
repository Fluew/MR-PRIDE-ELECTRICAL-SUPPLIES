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

    // A rate dated further back than this is treated as unavailable rather than shown as current.
    // Frankfurter publishes on working days, so weekends and holidays are a few days at most.
    'max_age_days' => 10,

    // Path to a CA certificate bundle, for PHP installs without curl.cainfo / openssl.cafile set.
    'ca_bundle' => env('CURRENCY_CA_BUNDLE'),
];
