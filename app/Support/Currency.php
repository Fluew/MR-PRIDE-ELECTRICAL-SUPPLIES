<?php

namespace App\Support;

/**
 * Currencies the Admin/Staff interface can show amounts in.
 *
 * Every amount stored in the database (prices, costs, quotation, sale, purchase order and invoice
 * totals) is in USD. Choosing PHP only changes how amounts are displayed: they are converted in
 * the browser at the current Frankfurter rate and never saved back.
 */
final class Currency
{
    /** The currency of every stored amount. */
    public const BASE = 'USD';

    public const USD = 'USD';

    public const PHP = 'PHP';

    /** @var list<string> */
    public const SUPPORTED = [self::USD, self::PHP];

    public const DEFAULT = self::USD;
}
