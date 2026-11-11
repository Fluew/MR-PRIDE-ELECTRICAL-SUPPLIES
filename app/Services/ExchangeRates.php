<?php

namespace App\Services;

use App\Exceptions\ExchangeRateUnavailable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The USD→PHP exchange rate used to show amounts in pesos.
 *
 * A rate is only returned when it was fetched from Frankfurter within the cache period and
 * passed validation. When the service fails, the result says so (with the date of the last good
 * rate, for information only), and callers must not convert with an old or made-up rate.
 */
class ExchangeRates
{
    public const CACHE_KEY = 'currency:usd_php:rate';

    /** The last good rate, kept only so the interface can say when it was from. */
    public const LAST_GOOD_KEY = 'currency:usd_php:last_good';

    public const FAILURE_KEY = 'currency:usd_php:failure';

    /**
     * The cached rate, without contacting the service (for every page load).
     *
     * @return array{rate: float, date: string, fetched_at: string}|null
     */
    public function cached(): ?array
    {
        $rate = Cache::get(self::CACHE_KEY);

        return is_array($rate) ? $rate : null;
    }

    /**
     * The current rate, fetched from the service when the cached one has expired.
     *
     * @param  bool  $refresh  fetch now even after a recent failure (the "Retry" button)
     * @return array{status: 'ok', rate: float, date: string, fetched_at: string}|array{status: 'unavailable', message: string, last_success: array{rate: float, date: string, fetched_at: string}|null}
     */
    public function usdToPhp(bool $refresh = false): array
    {
        if (! $refresh && ($cached = $this->cached())) {
            return ['status' => 'ok'] + $cached;
        }
        if (! $refresh && is_string($failure = Cache::get(self::FAILURE_KEY))) {
            return $this->unavailable($failure);
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout((int) config('currency.timeout', 5))
                // A CA bundle for PHP installs without one (otherwise HTTPS fails with cURL error 60).
                ->when(config('currency.ca_bundle'), fn ($http, $bundle) => $http->withOptions(['verify' => $bundle]))
                ->get((string) config('currency.usd_php_rate_url'));
            $rate = $this->parse($response);
        } catch (Throwable $e) {
            // Timeouts, DNS and connection errors: a plain message. Our own checks: their message.
            $message = $e instanceof ExchangeRateUnavailable
                ? $e->getMessage()
                : 'The exchange rate service could not be reached.';
            Log::warning('USD→PHP exchange rate fetch failed: '.$e->getMessage());
            Cache::put(self::FAILURE_KEY, $message, (int) config('currency.failure_backoff_seconds', 60));

            return $this->unavailable($message);
        }

        Cache::put(self::CACHE_KEY, $rate, (int) config('currency.cache_seconds', 3600));
        Cache::forever(self::LAST_GOOD_KEY, $rate);
        Cache::forget(self::FAILURE_KEY);

        return ['status' => 'ok'] + $rate;
    }

    /**
     * Check the response: {"date":"2026-10-30","base":"USD","quote":"PHP","rate":63.838}.
     *
     * @return array{rate: float, date: string, fetched_at: string}
     */
    private function parse(Response $response): array
    {
        if (! $response->successful()) {
            throw new ExchangeRateUnavailable("The exchange rate service returned an error (HTTP {$response->status()}).");
        }

        $data = $response->json();
        $rate = is_array($data) ? ($data['rate'] ?? null) : null;
        $date = is_array($data) ? ($data['date'] ?? null) : null;

        if (! is_array($data)
            || strtoupper((string) ($data['base'] ?? '')) !== 'USD'
            || strtoupper((string) ($data['quote'] ?? '')) !== 'PHP'
            || ! is_numeric($rate)
            || (float) $rate <= 0
            || ! is_string($date)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d)
            || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            throw new ExchangeRateUnavailable('The exchange rate service sent an invalid response.');
        }

        $rateDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        if ($rateDate->isAfter(now()->addDay())) {
            throw new ExchangeRateUnavailable('The exchange rate service sent a rate dated in the future.');
        }
        if ($rateDate->lt(now()->startOfDay()->subDays((int) config('currency.max_age_days', 10)))) {
            throw new ExchangeRateUnavailable("The latest exchange rate available is from {$rateDate->format('M j, Y')}, which is too old to use.");
        }

        return ['rate' => (float) $rate, 'date' => $date, 'fetched_at' => now()->toIso8601String()];
    }

    /**
     * @return array{status: 'unavailable', message: string, last_success: array{rate: float, date: string, fetched_at: string}|null}
     */
    private function unavailable(string $message): array
    {
        $last = Cache::get(self::LAST_GOOD_KEY);

        return ['status' => 'unavailable', 'message' => $message, 'last_success' => is_array($last) ? $last : null];
    }
}
