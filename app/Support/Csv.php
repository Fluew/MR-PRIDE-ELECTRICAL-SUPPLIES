<?php

namespace App\Support;

/**
 * CSV writing that is safe to open in Excel / Google Sheets.
 *
 * Text that starts with = + - @ (or a tab / carriage return) is treated as a formula by
 * spreadsheet apps. Since exports include text typed by the public (e.g. company names from
 * the quotation form), such cells are prefixed with an apostrophe so they display as plain text.
 * Real numbers (including negatives like -5) are left untouched.
 */
final class Csv
{
    /**
     * Drop-in replacement for fputcsv() that neutralises formula-like cells.
     *
     * @param  resource  $handle
     * @param  array<int|string, mixed>  $fields
     */
    public static function put($handle, array $fields): int|false
    {
        return fputcsv($handle, array_map(self::cell(...), $fields));
    }

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
