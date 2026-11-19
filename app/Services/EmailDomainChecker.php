<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Can this domain receive email? A domain is valid when it publishes a mail server (MX record) or, as
 * mail delivery falls back, an address (A or AAAA record). A domain with neither, one that does not exist,
 * or one that declares "no mail" (a null MX) is invalid.
 *
 * This confirms the DOMAIN only. It cannot prove that a particular mailbox exists, and no verification
 * email is sent.
 *
 * A failed lookup is not proof of a bad domain: the network or the resolver may be down. PHP reports a
 * missing domain and a broken resolver the same way, so after a failed lookup a well-known control domain is
 * looked up: if that resolves, the resolver works and the domain really does not exist; if it does not, the
 * result is "unavailable" and the caller accepts the address rather than refusing every visitor.
 *
 * Results are cached (valid and invalid domains only, never "unavailable").
 */
class EmailDomainChecker
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const UNAVAILABLE = 'unavailable';

    /** A domain that always has a mail server, used to tell "this domain does not exist" from "DNS is down". */
    private const CONTROL_DOMAIN = 'gmail.com';

    public function status(string $domain): string
    {
        $domain = strtolower(trim($domain, ". \t"));
        $key = 'email-domain:'.$domain;

        try {
            $cached = Cache::get($key);
            if (in_array($cached, [self::VALID, self::INVALID], true)) {
                return $cached;
            }
            $status = $this->lookup($domain);
            if ($status !== self::UNAVAILABLE) {
                Cache::put($key, $status, $status === self::VALID ? 86400 : 3600);
            }

            return $status;
        } catch (Throwable $e) {
            Log::warning("Email domain check unavailable for {$domain}: ".$e->getMessage());

            return self::UNAVAILABLE;
        }
    }

    private function lookup(string $domain): string
    {
        $mx = $this->records($domain, DNS_MX);
        if ($mx === false) {
            // Does not exist, or the resolver is down?
            return $this->resolverWorks() ? self::INVALID : self::UNAVAILABLE;
        }
        if ($mx !== []) {
            // RFC 7505 "null MX": a single "." target means the domain accepts no mail.
            $targets = array_filter(array_map(fn ($r) => trim((string) ($r['target'] ?? ''), '.'), $mx));

            return $targets === [] ? self::INVALID : self::VALID;
        }

        // No mail server published: delivery falls back to the domain's address records.
        $addresses = $this->records($domain, DNS_A | DNS_AAAA);
        if ($addresses === false) {
            return $this->resolverWorks() ? self::INVALID : self::UNAVAILABLE;
        }

        return $addresses === [] ? self::INVALID : self::VALID;
    }

    private function resolverWorks(): bool
    {
        $control = $this->records(self::CONTROL_DOMAIN, DNS_MX);

        return is_array($control) && $control !== [];
    }

    /**
     * DNS records of a type, an empty array when the lookup worked and found none, false when it failed.
     * A separate method so tests can replace the network.
     *
     * @return array<int, array<string, mixed>>|false
     */
    protected function records(string $domain, int $type): array|false
    {
        return @dns_get_record($domain.'.', $type);
    }
}
