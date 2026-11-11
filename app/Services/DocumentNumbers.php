<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Race-free document numbers (Sales Orders, invoices, receipts, delivery releases), using the
 * same per-team sequence table as quotation numbers. The UPDATE takes a row lock until the
 * surrounding transaction ends, so two requests never get the same number; the unique indexes
 * on each table stay the final guard.
 *
 * Format: <SERIES>-<YEAR>-<0001>, e.g. SO-2026-0007, SI-2026-0003, AR-2026-0012, DR-2026-0002.
 */
class DocumentNumbers
{
    public const SALES_ORDER = 'SO';

    public const SALES_INVOICE = 'SI';

    public const RECEIPT = 'AR';

    public const DELIVERY_RELEASE = 'DR';

    public const FULFILLMENT = 'FUL';

    /** Where each series is stored, so numbers made before the sequence existed are never reused. */
    private const TABLES = [
        self::SALES_ORDER => ['sales', 'order_number'],
        self::SALES_INVOICE => ['sales_invoices', 'invoice_number'],
        self::RECEIPT => ['payment_receipts', 'receipt_number'],
        self::DELIVERY_RELEASE => ['delivery_releases', 'release_number'],
        self::FULFILLMENT => ['order_fulfillments', 'fulfillment_number'],
    ];

    public static function next(int $teamId, string $series, ?int $year = null): string
    {
        $prefix = $series.'-'.($year ?? (int) now()->format('Y'));

        $value = DB::transaction(function () use ($teamId, $series, $prefix) {
            $floor = self::highestUsed($teamId, $series, $prefix);
            $sequence = fn () => DB::table('quotation_number_sequences')->where('team_id', $teamId)->where('prefix', $prefix);
            $bump = fn () => $sequence()->update([
                'last_value' => DB::raw("(CASE WHEN last_value > {$floor} THEN last_value ELSE {$floor} END) + 1"),
                'updated_at' => now(),
            ]);

            if ($bump() === 0) {
                $created = DB::table('quotation_number_sequences')->insertOrIgnore([
                    'team_id' => $teamId,
                    'prefix' => $prefix,
                    'last_value' => $floor + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($created === 0) {
                    $bump();
                }
            }

            return (int) $sequence()->value('last_value');
        });

        return sprintf('%s-%04d', $prefix, $value);
    }

    private static function highestUsed(int $teamId, string $series, string $prefix): int
    {
        [$table, $column] = self::TABLES[$series];

        $highest = 0;
        foreach (DB::table($table)->where('team_id', $teamId)->where($column, 'like', $prefix.'-%')->pluck($column) as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{1,9})$/i', (string) $number, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $highest;
    }
}
