<?php

namespace App\Support;

/**
 * Syntax and domain-structure checks for an email address, with no network access. The form
 * (resources/js/lib/validation.ts) applies the same rules; DeliverableEmail adds the DNS check.
 *
 * Beyond the standard syntax this rejects what is almost certainly a typo: a domain without a dot
 * ("name@gmail"), a one-letter domain name ("name@g.com"), and a top-level domain that is not
 * letters. A one-letter second-level name is rejected because such domains are not used for mail.
 */
class EmailAddress
{
    /** Why the address is malformed, or null when its syntax and domain structure are acceptable. */
    public static function syntaxError(string $email): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return 'Enter your email address.';
        }
        $malformed = 'Enter a valid email address, for example name@company.com.';
        if (strlen($email) > 254 || substr_count($email, '@') !== 1 || preg_match('/\s/', $email)) {
            return $malformed;
        }
        [$local, $domain] = explode('@', $email);
        if ($local === '' || $domain === '') {
            return $malformed;
        }
        if (strlen($local) > 64 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $malformed;
        }

        return self::domainStructureError(strtolower($domain)) ? $malformed : null;
    }

    /** @return string|null a reason, or null when the domain is shaped like a real one */
    public static function domainStructureError(string $domain): ?string
    {
        if (strlen($domain) > 253) {
            return 'too long';
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return 'no top-level domain';
        }
        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return 'invalid label';
            }
        }
        $tld = end($labels);
        if (! preg_match('/^([a-z]{2,24}|xn--[a-z0-9-]{2,59})$/', $tld)) {
            return 'invalid top-level domain';
        }
        // The name just before the top-level domain: at least two characters.
        if (strlen($labels[count($labels) - 2]) < 2) {
            return 'name too short';
        }

        return null;
    }

    public static function domain(string $email): string
    {
        return strtolower(substr(strrchr(trim($email), '@') ?: '', 1));
    }
}
