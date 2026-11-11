<?php

namespace App\Services\MaterialList;

/**
 * Turns a client's product list (table rows, or text lines) into rows of Description and
 * Quantity for the client to review. The unit is optional: kept as written when the document
 * has one, otherwise null, never guessed (staff verify units when preparing the quotation).
 *
 * Nothing is invented or silently fixed: a description or quantity that is missing, invalid or
 * hard to read is returned as found (or empty) with a message, so the form shows the row as
 * "Needs Review".
 *  - errors: the value cannot be used as it is (missing, not a number);
 *  - checks: the value may be right but should be looked at (low OCR confidence, a letter read
 *    as a digit).
 * Rows read exactly from a spreadsheet, CSV or text PDF table are "reliable"; rows read by OCR
 * or from free text are not, and need the client's confirmation like any other.
 */
final class ProductListParser
{
    public const MAX_ITEMS = 500;

    public const MAX_QUANTITY = 1000000;

    public const MAX_DECIMALS = 3;

    public const MAX_DESCRIPTION = 500;

    public const MAX_UNIT = 30;

    /** OCR confidence (0-100) below which a value is flagged for review. */
    public const LOW_CONFIDENCE = 60;

    /** Units and packaging we recognise (normalised: lowercase, no dots or spaces; plurals also match). */
    public const UNITS = [
        'pc', 'pcs', 'piece', 'pce', 'pces', 'ea', 'each', 'unit', 'nos', 'no', 'set', 'pack', 'pk', 'pkt', 'packet',
        'box', 'boxes', 'bx', 'bag', 'sack', 'roll', 'rl', 'coil', 'reel', 'spool', 'm', 'meter', 'metre', 'mtr', 'mtrs', 'lm',
        'lnm', 'linm', 'linearmeter', 'ft', 'feet', 'foot', 'lft', 'in', 'inch', 'inches', 'cm', 'mm', 'km', 'yd', 'yard', 'kg',
        'kgs', 'kilo', 'kilogram', 'g', 'gram', 'grams', 'ton', 'tons', 'tonne', 'mt', 'l', 'lt', 'ltr', 'liter', 'litre', 'ml',
        'gal', 'gallon', 'length', 'lengths', 'lgth', 'lot', 'pair', 'pr', 'doz', 'dozen', 'sheet', 'bundle', 'bdl', 'can',
        'pail', 'tube', 'bottle', 'btl', 'cartridge', 'drum', 'carton', 'ctn', 'case', 'cs', 'tray', 'stick', 'pole', 'cyl',
        'cylinder', 'sqm', 'm2', 'sqft', 'ft2', 'cum', 'm3', 'cuft', 'ls', 'lumpsum', 'assembly', 'assy', 'kit', 'strip',
        'panel', 'tin', 'jar', 'ream', 'gross', 'card', 'block', 'bar', 'plate', 'pad', 'unitset',
    ];

    // ------------------------------------------------------------------------------- tables

    /**
     * Parse a table. Without a mapping the header row is found (ProductListHeaders) and generic
     * or ambiguous columns are checked against their data.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array{method?: string, mapping?: ?array<string, int>, header_index?: ?int, conf?: array<int, array<int, float>>, ref?: 'row'|'line', reliable?: bool}  $options
     * @return array{status: 'ok'|'needs_mapping'|'no_products', items: list<array>, notes: list<string>, skipped: int, choice: array}
     */
    public static function parseTable(array $rows, array $options = []): array
    {
        $mapping = $options['mapping'] ?? null;
        $headerIndex = $options['header_index'] ?? null;
        $ref = $options['ref'] ?? 'row';
        $reliable = (bool) ($options['reliable'] ?? false);

        // Drop empty rows, keeping each row's place in the file (for "Row 12") and its confidence.
        $clean = [];
        $origin = [];
        $conf = [];
        foreach (array_values($rows) as $i => $row) {
            $cells = array_map(fn ($c) => self::cellText($c), array_values((array) $row));
            if (implode('', $cells) === '') {
                continue;
            }
            $clean[] = array_slice($cells, 0, FileReader::MAX_COLS);
            $origin[] = $i;
            $conf[] = $options['conf'][$i] ?? [];
        }

        $notes = [];
        $ambiguous = [];
        if ($mapping === null) {
            $found = ProductListHeaders::findHeaderRow($clean);
            $headerIndex = $found['index'];
            $mapping = $found['mapping'];
            foreach ($found['fuzzy'] as $field => $wording) {
                $notes[] = "The header \"{$wording}\" was read as ".ProductListHeaders::LABELS[$field].'.';
            }
            if ($headerIndex !== null) {
                $checked = ProductListHeaders::confirmFromData($mapping, $found['ranks'], $found['wordings'], array_slice($clean, $headerIndex + 1));
                $mapping = $checked['mapping'];
                $notes = [...$notes, ...$checked['notes']];
                $ambiguous = $checked['ambiguous'];
            }
        }

        $choice = self::choice($clean, $headerIndex, $mapping, $ambiguous);
        if (! isset($mapping['description'], $mapping['quantity'])) {
            return ['status' => 'needs_mapping', 'items' => [], 'notes' => $notes, 'skipped' => 0, 'choice' => $choice];
        }
        $items = [];
        $skipped = 0;
        $start = $headerIndex === null ? 0 : $headerIndex + 1;
        for ($r = $start; $r < count($clean); $r++) {
            $row = $clean[$r];
            if (ProductListHeaders::scan($row)['header']) {
                continue; // the header repeated on a later page
            }
            $cell = fn (string $f) => isset($mapping[$f]) ? trim((string) ($row[$mapping[$f]] ?? '')) : '';
            $cellConf = fn (string $f) => isset($mapping[$f]) ? (float) ($conf[$r][$mapping[$f]] ?? 100) : 100.0;
            $description = $cell('description');
            $quantity = $cell('quantity');
            $unit = $cell('unit');

            if ($description === '' && $quantity === '' && $unit === '') {
                $skipped++;

                continue; // only other columns are filled (a page number, a remark)
            }
            if (self::isNotAProduct($description) || ($quantity === '' && $unit === '' && self::isHeading($description))) {
                $skipped++;

                continue;
            }

            $label = $ref === 'row' ? 'Row '.($origin[$r] + 1) : 'Line '.(count($items) + 1);
            $items[] = self::item($label, $description, $quantity, $unit, $reliable, [
                'description' => $cellConf('description'), 'quantity' => $cellConf('quantity'), 'unit' => $cellConf('unit'),
            ]);
            if (count($items) >= self::MAX_ITEMS) {
                $notes[] = 'Only the first '.self::MAX_ITEMS.' products were read from this file.';
                break;
            }
        }

        return [
            'status' => $items === [] ? 'no_products' : 'ok',
            'items' => self::markDuplicates($items),
            'notes' => $notes,
            'skipped' => $skipped,
            'choice' => $choice,
        ];
    }

    // ------------------------------------------------------------------------------- free text

    /**
     * Parse text lines (a PDF's text or OCR of a list without a table). Columns separated by
     * wide gaps (tabs) are tried as a table first; otherwise lines such as "10 pcs LED bulb",
     * "LED bulb - 10 pcs" and numbered lines ("1. LED bulb") are read. Free-text rows are never
     * "reliable": the client confirms each one.
     *
     * @param  array<int, string>  $lines
     * @param  array{method?: string, line_conf?: array<int, float>}  $options
     */
    public static function parseLines(array $lines, array $options = []): array
    {
        $lines = array_values($lines);
        $split = array_map(fn ($l) => explode("\t", $l), $lines);
        $header = ProductListHeaders::findHeaderRow($split, 40);
        if ($header['index'] !== null) {
            $lineConf = $options['line_conf'] ?? [];
            $conf = array_map(fn ($row, $i) => array_fill(0, count($row), (float) ($lineConf[$i] ?? 100)), $split, array_keys($split));
            $table = self::parseTable($split, ['ref' => 'line', 'reliable' => false, 'conf' => $conf] + $options);
            if ($table['status'] !== 'no_products') {
                return $table;
            }
        }

        $parsed = MaterialParser::parseText($lines, $options['method'] ?? 'text');
        $items = [];
        foreach ($parsed['items'] as $found) {
            $at = max(0, (int) $found['line'] - 1);
            $quantityRaw = (string) ($found['quantity_raw'] ?? '');
            $unit = (string) ($found['unit'] ?? '');
            $items[] = self::item('Line '.(count($items) + 1), (string) $found['description'], $quantityRaw, $unit, false, [
                'description' => (float) ($options['line_conf'][$at] ?? 100),
                'quantity' => (float) ($options['line_conf'][$at] ?? 100),
                'unit' => (float) ($options['line_conf'][$at] ?? 100),
            ]);
        }
        // Numbered lines without a quantity ("3. Junction box") are products too: kept for review.
        foreach ($parsed['unparsed_lines'] as $line) {
            if (preg_match('/^\s*\d{1,3}[.)]\s+(?=\p{L})(.+)$/u', str_replace(' | ', ' ', $line), $m) && ! self::isNotAProduct($m[1])) {
                $items[] = self::item('Line '.(count($items) + 1), $m[1], '', '', false, []);
            }
        }
        $items = array_slice($items, 0, self::MAX_ITEMS);

        return [
            'status' => $items === [] ? 'no_products' : 'ok',
            'items' => self::markDuplicates($items),
            'notes' => [],
            'skipped' => max(0, count($lines) - count($items)),
            'choice' => self::choice($split, null, [], []),
        ];
    }

    // ------------------------------------------------------------------------------- values

    /**
     * One product row. $confidence holds the OCR confidence of each value (100 when exact).
     *
     * @param  array<string, float>  $confidence
     */
    public static function item(string $ref, string $description, string $quantityRaw, string $unitRaw, bool $reliable, array $confidence): array
    {
        $errors = [];
        $checks = [];

        [$description, $error, $check] = self::description($description);
        if ($error) {
            $errors['description'] = $error;
        } elseif ($check) {
            $checks['description'] = $check;
        }

        [$quantity, $unitWithQuantity, $error, $check] = self::quantity($quantityRaw);
        if ($error) {
            $errors['quantity'] = $error;
        } elseif ($check) {
            $checks['quantity'] = $check;
        }

        // The unit is optional: kept as written in the document (its Unit column, or next to the
        // quantity as in "10 pcs"), or null. It is never guessed; staff verify units when quoting.
        $unit = self::optionalUnit(trim($unitRaw) !== '' ? $unitRaw : (string) $unitWithQuantity);

        foreach (['description', 'quantity'] as $field) {
            if (! isset($errors[$field]) && ! isset($checks[$field]) && ($confidence[$field] ?? 100) < self::LOW_CONFIDENCE) {
                $checks[$field] = 'This was hard to read. Please check it against your document.';
            }
        }

        return [
            'ref' => $ref,
            'description' => $description,
            'quantity' => $quantity,
            'quantity_raw' => mb_substr(trim($quantityRaw), 0, 60),
            'unit' => $unit,
            'errors' => $errors,
            'checks' => $checks,
            'reliable' => $reliable && $errors === [] && $checks === [],
            'duplicate_of' => null,
        ];
    }

    /** @return array{0: string, 1: ?string, 2: ?string} cleaned description, error, check */
    public static function description(string $raw): array
    {
        $d = self::cellText($raw);
        $d = preg_replace('/^[•▪◦●■□\*\-–]+\s+/u', '', $d) ?? $d; // a bullet
        if ($d === '') {
            return ['', 'Description is missing.', null];
        }
        // "=HYPERLINK(...)", "+cmd|...", "@SUM(...)": spreadsheet formulas, never product names.
        if (preg_match('/^[=+@]/', $d) || preg_match('/^-\s*[=(]/', $d)) {
            return [$d, 'This looks like a spreadsheet formula, not a product name. Please type the product name.', null];
        }
        if (mb_strlen($d) > self::MAX_DESCRIPTION) {
            return [mb_substr($d, 0, self::MAX_DESCRIPTION), null, 'This description was longer than '.self::MAX_DESCRIPTION.' characters and was shortened. Please check it.'];
        }
        if (! preg_match('/\p{L}/u', $d)) {
            return [$d, null, 'This has no letters. Please check the product name.'];
        }

        return [$d, null, null];
    }

    /**
     * A quantity: "20", "1,000", "2.5", "10 pcs" (the unit is returned too), or "1O" (OCR read a
     * zero as the letter O: taken as 10 but flagged). "2,5" is refused: it could be 2.5 or 25.
     *
     * @return array{0: ?float, 1: ?string, 2: ?string, 3: ?string} quantity, unit written with it, error, check
     */
    public static function quantity(string $raw): array
    {
        $v = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        $shown = mb_substr($v, 0, 30);
        if ($v === '' || in_array($v, ['-', '—', '–', '?'], true)) {
            return [null, null, 'Quantity is missing.', null];
        }
        $v = rtrim($v, '.');

        $number = '[\dOoIl][\dOoIl,]*(?:\.\d+)?';
        $unit = null;
        // "10 pcs": only a unit we know is split off, so "1S" (a misread 15) is not taken as 1 "S".
        if (! preg_match("/^{$number}$/", $v) && preg_match("/^(?<n>{$number})\s*(?<u>\p{L}[\p{L}\d.\/²³ ]{0,19})$/u", $v, $m)
            && preg_match('/\d/', $m['n']) && self::isKnownUnit($m['u'])) {
            $v = $m['n'];
            $unit = trim($m['u'], ' .');
        }
        if (preg_match('/^-\s*\d/', $v)) {
            return [null, $unit, 'Quantity must be greater than zero.', null];
        }

        $ocrFixed = false;
        if (preg_match("/^{$number}$/", $v) && preg_match('/\d/', $v) && preg_match('/[OoIl]/', $v)) {
            $v = strtr($v, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);
            $ocrFixed = true;
        }
        if (preg_match('/^\d+,\d{1,2}$/', $v)) {
            return [null, $unit, "\"{$shown}\" could mean ".str_replace(',', '.', $v).' or '.str_replace(',', '', $v).'. Please enter the quantity.', null];
        }
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $v)) {
            $v = str_replace(',', '', $v);
        }
        if (! preg_match('/^\d+(?:\.(\d+))?$/', $v, $m)) {
            return [null, $unit, "\"{$shown}\" is not a valid quantity. Please enter a number.", null];
        }
        if (strlen($m[1] ?? '') > self::MAX_DECIMALS) {
            return [null, $unit, 'Use at most '.self::MAX_DECIMALS.' decimal places for the quantity.', null];
        }

        $q = (float) $v;
        if ($q <= 0) {
            return [null, $unit, 'Quantity must be greater than zero.', null];
        }
        if ($q > self::MAX_QUANTITY) {
            return [null, $unit, 'Quantity is too large (more than 1,000,000).', null];
        }

        return [$q, $unit, null, $ocrFixed ? "Read as \"{$shown}\" and taken as ".self::formatNumber($q).'. Please check it.' : null];
    }

    /**
     * The unit as written (trimmed, "pcs." → "pcs"), or null when there is none. Text that cannot
     * be a unit (no letters, or too long) is left out rather than corrected.
     */
    public static function optionalUnit(string $raw): ?string
    {
        $u = trim(preg_replace('/\s+/u', ' ', self::cellText($raw)) ?? '', " \t.,;:");

        return $u === '' || mb_strlen($u) > self::MAX_UNIT || ! preg_match('/\p{L}/u', $u) ? null : $u;
    }

    public static function isKnownUnit(string $unit): bool
    {
        $key = self::unitKey($unit);
        if ($key === '') {
            return false;
        }
        foreach ([$key, preg_replace('/es$/', '', $key), preg_replace('/s$/', '', $key)] as $candidate) {
            if ($candidate !== '' && in_array($candidate, self::UNITS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function unitKey(string $unit): string
    {
        $u = mb_strtolower(trim($unit));
        $u = str_replace(['²', '³', 'sq.', 'cu.'], ['2', '3', 'sq', 'cu'], $u);

        return preg_replace('/[\s.\-\/]+/u', '', $u) ?? $u;
    }

    public static function formatNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, self::MAX_DECIMALS, '.', ''), '0'), '.');
    }

    // ------------------------------------------------------------------------------- helpers

    /** Totals, signatures, page numbers and document details are never products. */
    private static function isNotAProduct(string $text): bool
    {
        $t = trim($text);

        return (bool) preg_match('/^(sub\s*-?\s*total|grand\s*total|total|vat|tax|amount\s+due|discount|less)\b/iu', $t)
            || (bool) preg_match('/^(prepared|requested|approved|checked|noted|received|certified|submitted)\s+by\b/iu', $t)
            || (bool) preg_match('/^(signature|date|page|attention|attn|address|tel|telephone|phone|mobile|e-?mail|project|client|company|subject|ref|reference|note|notes|remarks)\b\s*[:#.]/iu', $t)
            || (bool) preg_match('/^(?:page\s*)?\d{1,4}\s*(?:of|\/)\s*\d{1,4}$/iu', $t);
    }

    /** "ELECTRICAL WORKS", "Lighting:": a section title inside a table, with no quantity or unit. */
    private static function isHeading(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || preg_match('/\d/', $t)) {
            return str_ends_with($t, ':');
        }

        return str_ends_with($t, ':')
            || (preg_match('/\p{Lu}{2}/u', $t) && mb_strtoupper($t) === $t && str_word_count($t) <= 6);
    }

    /** Cell value as plain text: no control or zero-width characters, single spaces. */
    public static function cellText(mixed $cell): string
    {
        if ($cell === null || is_bool($cell) || ! is_scalar($cell)) {
            return '';
        }
        if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
            $cell = (int) $cell;
        }
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', (string) $cell) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Later rows with the same description and unit as an earlier row point to it (shown, never removed). */
    private static function markDuplicates(array $items): array
    {
        $seen = [];
        foreach ($items as &$item) {
            $key = mb_strtolower($item['description'].'|'.$item['unit']);
            if ($item['description'] === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $item['duplicate_of'] = $seen[$key];
            } else {
                $seen[$key] = $item['ref'];
            }
        }
        unset($item);

        return $items;
    }

    /**
     * What the client needs to choose the columns: the first rows, column labels, the header row
     * and columns found so far, which required columns are missing and which were unclear.
     */
    private static function choice(array $rows, ?int $headerIndex, array $mapping, array $ambiguous): array
    {
        $width = $rows === [] ? 0 : min(FileReader::MAX_COLS, max(array_map('count', $rows)));
        $header = $headerIndex !== null ? ($rows[$headerIndex] ?? []) : [];
        $columns = [];
        for ($c = 0; $c < $width; $c++) {
            $label = trim((string) ($header[$c] ?? ''));
            $columns[] = mb_substr($label !== '' ? $label : 'Column '.self::columnLetter($c), 0, 60);
        }

        return [
            'columns' => $columns,
            'preview' => array_map(
                fn ($row) => array_map(fn ($c) => mb_substr((string) $c, 0, 80), array_pad(array_slice($row, 0, $width), $width, '')),
                array_slice($rows, 0, min(40, max(15, ($headerIndex ?? 0) + 8))),
            ),
            'header_index' => $headerIndex,
            'mapping' => (object) $mapping,
            'missing' => array_values(array_filter(['description', 'quantity'], fn ($f) => ! isset($mapping[$f]))), // Unit is optional
            'ambiguous' => $ambiguous,
        ];
    }

    private static function columnLetter(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + (($i - 1) % 26)).$s;
        }

        return $s;
    }
}
