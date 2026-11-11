<?php

namespace App\Support;

/**
 * The fixed Terms and Conditions of every quotation (not editable per quotation). Shown on the
 * Create/Edit Quotation form and saved on the quotation; bank details come from config/company.php.
 */
final class QuotationTerms
{
    /** @return list<string> */
    public static function lines(): array
    {
        $bank = (array) config('company.bank');

        return [
            '100% ADVANCE PAYMENT (PROCESSING P.O UPON RECEIVED PAYMENT)',
            'THIS QUOTATION IS VALID 30 DAYS.',
            'DELIVERY LEAD TIME ON STOCK SUBJECT TO PRIOR SALE.',
            'PRICES QUOTED HEREIN ARE SUBJECT TO CHANGE WITHOUT PRIOR NOTICE.',
            'CANCELLATION CHARGES: A CHARGE WILL BE MADE ON ALL CANCELLED ORDER, TOGETHER WITH THE CHARGE FOR ALL WORK CARRIED OUT TO THE DATE OF WRITTEN CANCELLATION WILL BE CHARGED 25% OF PURCHASE ORDER.',
            "PAYMENT TO:\n"
                .'- BANK NAME: '.($bank['name'] ?? '')."\n"
                .'- ACCOUNT NAME: '.($bank['account_name'] ?? '')."\n"
                .'- ACCOUNT NO.: '.($bank['account_no'] ?? '')."\n"
                .'- ADDRESS: '.($bank['address'] ?? '')."\n"
                .'- SWIFT CODE: '.($bank['swift'] ?? ''),
        ];
    }

    /** As stored on the quotation: numbered, one term per line. */
    public static function text(): string
    {
        return "TERMS AND CONDITIONS\n".collect(self::lines())->map(fn ($line, $i) => ($i + 1).'. '.$line)->implode("\n");
    }
}
