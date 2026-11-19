<?php

return [
    /*
    | Public quotation request: check that the domain of the email address can receive mail (MX record, with an
    | A/AAAA fallback). A domain that cannot be checked because DNS is unavailable is accepted. Turn this off
    | (EMAIL_DNS_CHECK=false) on a machine without internet access.
    */
    'email_dns_check' => (bool) env('EMAIL_DNS_CHECK', true),
];
