<?php

namespace App\Rules;

use App\Services\EmailDomainChecker;
use App\Support\EmailAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A properly formed email address whose domain can receive mail (see EmailDomainChecker). Only a domain
 * that is confirmed not to exist is refused; a temporary DNS failure lets the address through. It cannot
 * tell whether the mailbox itself exists.
 */
class DeliverableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = trim((string) $value);

        if ($error = EmailAddress::syntaxError($email)) {
            $fail($error);

            return;
        }
        if (! config('validation.email_dns_check')) {
            return;
        }

        $domain = EmailAddress::domain($email);
        if (app(EmailDomainChecker::class)->status($domain) === EmailDomainChecker::INVALID) {
            $fail("We couldn't find a mail server for {$domain}. Please check your email address.");
        }
    }
}
