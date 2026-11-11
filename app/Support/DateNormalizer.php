<?php

namespace App\Support;

/**
 * Turns dates read from imported files (CSV, Excel, PDF text, OCR) into ISO 8601 YYYY-MM-DD,
 * the one date format the application stores and exchanges.
 *
 * Accepted: YYYY-MM-DD, MM-DD-YYYY, DD-MM-YYYY (with "-", "/" or "." between the parts),
 * an ISO date-time (its date part), and dates with a month name ("3 Nov 2026", "Nov 3, 2026",
 * "2026-Nov-03").
 *
 * How a two-number date is read (never from the current date):
 *  - a part above 12 can only be the day, so 25-12-2025 is DD-MM-YYYY and 12-25-2025 is MM-DD-YYYY;
 *  - when both parts are 12 or less (05-06-2026) the date is ambiguous: it is read with the
 *    caller's $preference only when that format is known to apply to the document (a template
 *    that says MM-DD-YYYY); otherwise it is flagged, never guessed;
 *  - the year must have four digits, and the date must exist on the calendar (leap years
 *    included). Invalid dates are rejected, never adjusted into valid ones.
 *
 * The original text is always returned next to the normalized value, for review and audit.
 */
final class DateNormalizer
{
    /** Preferences for ambiguous two-number dates. */
    public const MDY = 'mdy';

    public const DMY = 'dmy';

    /** Result statuses. */
    public const OK = 'ok';

    public const EMPTY = 'empty';

    public const AMBIGUOUS = 'ambiguous';

    public const INVALID = 'invalid';

    private const MONTH_WORD = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** Characters OCR puts in place of digits, and the digit each stands for. */
    private const OCR_DIGITS = ['O' => '0', 'o' => '0', 'D' => '0', 'I' => '1', 'l' => '1', '|' => '1', 'S' => '5', 'B' => '8'];

    /**
     * @param  string|null  $preference  self::MDY or self::DMY, only when the document's format is known
     * @param  bool  $ocr  the text came from OCR: digit look-alikes inside a date (2O26) are corrected and reported
     * @return array{value: ?string, original: ?string, status: string, format: ?string, by_preference: bool, problem: ?string, corrected_from: ?string}
     */
    public static function parse(mixed $input, ?string $preference = null, bool $ocr = false): array
    {
        $original = is_scalar($input) ? trim((string) $input) : '';
        if ($original === '') {
            return self::result(self::EMPTY, null, null, null);
        }

        // One kind of dash and no spaces around separators: "11 - 03 - 2026", "11–03–2026".
        $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $original) ?? $original;
        $s = preg_replace('/\s*([\-\/.])\s*/u', '$1', $s) ?? $s;

        $corrected = null;
        $look = '[\dOoDIl|SB]';
        if ($ocr && preg_match("/^{$look}{1,4}[-\\/.]{$look}{1,2}[-\\/.]{$look}{2,4}$/u", $s) && preg_match('/[OoDIl|SB]/u', $s)) {
            $corrected = $original;
            $s = strtr($s, self::OCR_DIGITS);
        }

        $r = self::read($s, $original, $preference);
        $r['corrected_from'] = $r['status'] === self::OK ? $corrected : null;

        return $r;
    }

    /** The normalized YYYY-MM-DD value, or null when the text is empty, ambiguous or invalid. */
    public static function toIso(mixed $input, ?string $preference = null): ?string
    {
        return self::parse($input, $preference)['value'];
    }

    /** The message staff see for an ambiguous date. */
    public static function ambiguousMessage(string $original): string
    {
        return "Date could not be interpreted reliably: {$original}. Please verify the intended date format.";
    }

    /**
     * Find a labelled date in rows of document text, e.g. "Quotation Date: 11-03-2026" in one cell,
     * or "Date:" in one cell and the date in the next cell of the same row. Only text that starts
     * right after one of the labels and has the shape of a date is read, so quantities, codes,
     * reference numbers and prices are never taken for dates. A label must start its cell, so
     * "Delivery Date" does not match the label "date".
     *
     * When the labels appear more than once with different dates, the result is ambiguous: the
     * document itself disagrees and staff must choose.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  list<string>  $labels  lowercase label wordings, most specific first
     * @return array{value: ?string, original: ?string, status: string, format: ?string, by_preference: bool, problem: ?string, corrected_from: ?string, label: string, row: int}|null
     */
    public static function findLabeled(array $rows, array $labels, ?string $preference = null, bool $ocr = false): ?array
    {
        usort($labels, fn ($a, $b) => strlen($b) <=> strlen($a)); // "dated" before "date"
        $labelRe = implode('|', array_map(fn ($l) => str_replace('\ ', '\s+', preg_quote($l, '/')), $labels));
        $candidates = [];

        foreach (array_values($rows) as $r => $cells) {
            $cells = array_values(array_map(fn ($c) => is_scalar($c) ? trim((string) $c) : '', (array) $cells));
            foreach ($cells as $c => $cell) {
                if (! preg_match('/^('.$labelRe.')\s*[:;.\-]?\s*(.*)$/iu', $cell, $m)) {
                    continue;
                }
                $rest = trim($m[2]);
                if ($rest === '') {
                    // "Date:" alone: the value is the next filled cell of the row.
                    for ($n = $c + 1; $n < count($cells); $n++) {
                        if ($cells[$n] !== '') {
                            $rest = $cells[$n];
                            break;
                        }
                    }
                }
                $token = self::leadingDate($rest, $ocr);
                if ($token === null) {
                    continue;
                }
                $candidates[] = self::parse($token, $preference, $ocr) + ['label' => trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]), 'row' => $r + 1];
            }
        }

        if (! $candidates) {
            return null;
        }

        $readable = array_values(array_filter($candidates, fn ($c) => $c['status'] === self::OK));
        $values = array_values(array_unique(array_column($readable, 'value')));
        if (count($values) > 1) {
            $originals = implode(', ', array_unique(array_column($readable, 'original')));

            return array_merge($readable[0], [
                'value' => null,
                'status' => self::AMBIGUOUS,
                'original' => $originals,
                'problem' => "The document shows different dates for \"{$readable[0]['label']}\": {$originals}. Please verify the intended date.",
            ]);
        }

        return $readable[0] ?? $candidates[0];
    }

    /** The date-shaped text at the start of $text (followed by its end, a space or punctuation), or null. */
    private static function leadingDate(string $text, bool $ocr): ?string
    {
        $d = $ocr ? '[\dOoDIl|SB]' : '\d';
        $sep = '\s*[\-\/.\x{2010}-\x{2015}\x{2212}]\s*';
        $mw = self::MONTH_WORD;
        $patterns = [
            "{$d}{4}{$sep}{$d}{1,2}{$sep}{$d}{1,2}",
            "{$d}{1,2}{$sep}{$d}{1,2}(?:{$sep}{$d}{1,4})?",
            "\\d{1,2}(?:st|nd|rd|th)?[\\s\\-\\/.,]*(?:{$mw})\\.?[\\s\\-\\/.,]*\\d{4}",
            "(?:{$mw})\\.?[\\s\\-\\/.,]*\\d{1,2}(?:st|nd|rd|th)?[\\s,\\-\\/.]*\\d{4}",
            "\\d{4}[\\s\\-\\/.]*(?:{$mw})\\.?[\\s\\-\\/.]*\\d{1,2}",
        ];
        foreach ($patterns as $p) {
            if (preg_match('/^('.$p.')(?=$|[\s,;|)\]])/iu', $text, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return array{value: ?string, original: ?string, status: string, format: ?string, by_preference: bool, problem: ?string, corrected_from: ?string} */
    private static function read(string $s, string $original, ?string $preference): array
    {
        $q = "\"{$original}\"";

        // ISO date, or ISO date-time (its date part is what the document says).
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $s, $m)
            || preg_match('/^(\d{4})[\/.](\d{1,2})[\/.](\d{1,2})$/', $s, $m)) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3], 'YYYY-MM-DD', $original);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) {
            return self::twoNumbers((int) $m[1], (int) $m[2], (int) $m[3], $original, $preference);
        }

        if (preg_match('/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{1,3}$/', $s)) {
            return self::result(self::INVALID, $original, null, "Date {$q} does not have a four-digit year, so it cannot be read reliably.");
        }
        if (preg_match('/^\d{1,4}[-\/.]\d{1,2}$/', $s)) {
            return self::result(self::INVALID, $original, null, "Date {$q} is incomplete: the day, month and year are all needed.");
        }

        $mw = self::MONTH_WORD;
        $sepRe = '[\s\-\/.,]*';
        if (preg_match("/^(\\d{1,2})(?:st|nd|rd|th)?{$sepRe}({$mw})\\.?{$sepRe}(\\d{4})$/iu", $s, $m)) {
            return self::build((int) $m[3], self::month($m[2]), (int) $m[1], 'D MMM YYYY', $original);
        }
        if (preg_match("/^({$mw})\\.?{$sepRe}(\\d{1,2})(?:st|nd|rd|th)?{$sepRe}(\\d{4})$/iu", $s, $m)) {
            return self::build((int) $m[3], self::month($m[1]), (int) $m[2], 'MMM D YYYY', $original);
        }
        if (preg_match("/^(\\d{4}){$sepRe}({$mw})\\.?{$sepRe}(\\d{1,2})$/iu", $s, $m)) {
            return self::build((int) $m[1], self::month($m[2]), (int) $m[3], 'YYYY-MMM-DD', $original);
        }
        if (preg_match("/^({$mw})\\.?{$sepRe}(\\d{4})$/iu", $s) || preg_match("/^(\\d{1,2}){$sepRe}({$mw})\\.?$/iu", $s)) {
            return self::result(self::INVALID, $original, null, "Date {$q} is incomplete: the day, month and year are all needed.");
        }

        return self::result(self::INVALID, $original, null, "{$q} is not a recognisable date. Use YYYY-MM-DD, MM-DD-YYYY or DD-MM-YYYY.");
    }

    /** NN-NN-YYYY: decide which number is the month. */
    private static function twoNumbers(int $a, int $b, int $year, string $original, ?string $preference): array
    {
        $q = "\"{$original}\"";
        if ($a === 0 || $b === 0) {
            return self::result(self::INVALID, $original, null, "Date {$q} has a day or month of 00.");
        }
        if ($a > 12 && $b > 12) {
            return self::result(self::INVALID, $original, null, "Date {$q} has no valid month (months are 01 to 12).");
        }
        if ($a > 12) {
            return self::build($year, $b, $a, 'DD-MM-YYYY', $original);
        }
        if ($b > 12) {
            return self::build($year, $a, $b, 'MM-DD-YYYY', $original);
        }
        if ($a === $b) {
            // 04-04-2026 means the same date in both orders.
            return self::build($year, $a, $b, 'MM-DD-YYYY', $original);
        }
        if ($preference === self::MDY) {
            return self::build($year, $a, $b, 'MM-DD-YYYY', $original, true);
        }
        if ($preference === self::DMY) {
            return self::build($year, $b, $a, 'DD-MM-YYYY', $original, true);
        }

        return self::result(self::AMBIGUOUS, $original, null, self::ambiguousMessage($original));
    }

    private static function build(int $year, int $month, int $day, string $format, string $original, bool $byPreference = false): array
    {
        $q = "\"{$original}\"";
        if ($year < 1900 || $year > 2099) {
            return self::result(self::INVALID, $original, $format, "Date {$q} has the year {$year}, which looks misread. Please check the year.");
        }
        if ($month < 1 || $month > 12) {
            return self::result(self::INVALID, $original, $format, "Date {$q} has no valid month (months are 01 to 12).");
        }
        if ($day < 1) {
            return self::result(self::INVALID, $original, $format, "Date {$q} has a day of 00.");
        }
        if (! checkdate($month, $day, $year)) {
            $first = mktime(0, 0, 0, $month, 1, $year);
            $days = (int) date('t', $first);
            $name = date('F', $first);

            return self::result(self::INVALID, $original, $format, "Date {$q} is not a real calendar date: {$name} {$year} has {$days} days.");
        }

        $r = self::result(self::OK, $original, $format, null);
        $r['value'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $r['by_preference'] = $byPreference;

        return $r;
    }

    private static function month(string $word): int
    {
        return self::MONTHS[strtolower(substr($word, 0, 3))];
    }

    /** @return array{value: ?string, original: ?string, status: string, format: ?string, by_preference: bool, problem: ?string, corrected_from: ?string} */
    private static function result(string $status, ?string $original, ?string $format, ?string $problem): array
    {
        return ['value' => null, 'original' => $original, 'status' => $status, 'format' => $format, 'by_preference' => false, 'problem' => $problem, 'corrected_from' => null];
    }
}
