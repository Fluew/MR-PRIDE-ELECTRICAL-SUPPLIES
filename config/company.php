<?php

/*
| Company details printed on customer documents (quotations, Sales Orders, receipts, invoices)
| and in customer emails. Set the real values in .env.
|
| bir_registered: set to true only when these system-generated invoices are BIR-registered
| (accredited CAS / authority to print). While false, every invoice and receipt says it is a
| system-generated document that is not a BIR-registered invoice or official receipt.
*/

return [
    'name' => env('COMPANY_NAME', 'MR-PRIDE Electrical Supplies'),
    'tagline' => env('COMPANY_TAGLINE', 'Industrial & Commercial Electrical Solutions'),
    'address' => env('COMPANY_ADDRESS', '610 Esguerra St. Tondo Manila Philippines 1012'),
    'phone' => env('COMPANY_PHONE', '+63 9397366907 / +63 9083602754'),
    'email' => env('COMPANY_EMAIL', 'sales.rcjlent@gmail.com'),
    'tin' => env('COMPANY_TIN', ''),
    'bir_registered' => (bool) env('COMPANY_BIR_REGISTERED', false),

    // Shown in the "Sales Order created" email. Leave empty until the real account details are set.
    'payment_instructions' => [
        'Bank Transfer' => env('COMPANY_BANK_DETAILS', ''),
        'GCash' => env('COMPANY_GCASH_DETAILS', ''),
    ],

    // Printed on the official quotation (terms 6, "PAYMENT TO").
    'bank' => [
        'name' => env('COMPANY_BANK_NAME', 'CHINA BANKING CORPORATION'),
        'account_name' => env('COMPANY_BANK_ACCOUNT_NAME', 'MR-PRIDE ELECTRICAL SUPPLIES'),
        'account_no' => env('COMPANY_BANK_ACCOUNT_NO', '100952000262'),
        'address' => env('COMPANY_BANK_ADDRESS', '947 APL-YSL BLDG. ILAYA ST. TONDO MANILA PHILIPPINES - ILAYA BRANCH'),
        'swift' => env('COMPANY_BANK_SWIFT', 'CHBKPHMM'),
    ],

    // How long a quotation is valid: "Valid Until" is set when the quotation is approved, to the
    // approval date + these days (a new Draft has none). The quotation document prints the days
    // from the quotation date to Valid Until as term 2.
    'quotation_validity_days' => 3,

    // Amounts are stored and printed in this currency (see config/currency.php).
    'currency' => 'USD',
];
