<?php

namespace App\Services\Import;

use App\Services\MaterialList\HeaderMapper;

/**
 * Turns extracted table rows into import lines, field by field. Every header row (also a
 * repeated one on the next sheet or page) sets the column positions for the rows below it.
 * Text above the first header (titles, addresses) is kept as "unparsed lines" for display.
 * Total/subtotal/VAT lines are kept but pre-skipped, so nothing disappears silently.
 * When no header exists at all, each line becomes a row for staff to complete by hand, or
 * staff choose the header row and columns themselves ($forced).
 */
class RowMapper
{
    private const TOTAL_LINE = '/^(sub\s*-?\s*total|grand\s*total|total(\s+amount)?|vat|tax|less|discount|amount\s+due|net\s+total|balance)\b/iu';

    /** Rows searched for a suggested header row. */
    private const SEARCH_ROWS = 50;

    /**
     * $forced: columns chosen by staff, ['header_row' => ?int, 'columns' => [field => column index]].
     * Rows above the header row are kept as unparsed lines; the header row (and the same header
     * repeated on a later page) is skipped; every other line is read with the chosen columns.
     *
     * @param  array<int, array<int, string>>  $table
     * @param  array{header_row?: ?int, columns?: array<string, int|null>}|null  $forced
     * @return array{rows: list<array{row_number: int, raw: string, extracted: array<string, ?string>, total_line: bool, no_header: bool}>, unparsed: list<string>, header_found: bool, mapping: array<string, int>, header_row: ?int, columns: list<string>}
     */
    public static function map(string $module, array $table, ?array $forced = null): array
    {
        $fields = array_keys(ColumnSchema::FIELDS[$module]);
        $key = ColumnSchema::KEY[$module];
        $out = [];
        $unparsed = [];
        $nonHeaderUnparsed = [];

        $forcedHeader = null;
        $forcedColumns = [];
        if ($forced !== null) {
            $forcedHeader = isset($forced['header_row']) ? (int) $forced['header_row'] : null;
            $forcedColumns = array_map('intval', array_filter(array_intersect_key($forced['columns'] ?? [], ColumnSchema::FIELDS[$module]), fn ($c) => $c !== null));
        }
        $map = $forced !== null ? $forcedColumns : null;
        $firstMap = null;
        $headerRow = null;
        $headerCells = null;

        foreach ($table as $i => $cells) {
            $raw = trim(implode(' | ', array_filter($cells, fn ($c) => $c !== '')));
            if ($raw === '') {
                continue;
            }
            if ($forced !== null) {
                if ($forcedHeader !== null && $i < $forcedHeader) {
                    $unparsed[] = $raw; // titles above the chosen header

                    continue;
                }
                if ($i === $forcedHeader) {
                    $headerCells = self::cellsKey($cells);

                    continue;
                }
                if ($headerCells !== null && self::cellsKey($cells) === $headerCells) {
                    continue; // the header repeated on the next page
                }
            } elseif ($header = ColumnSchema::headerMap($module, $cells)) {
                $map = $header;
                $firstMap ??= $header;
                $headerRow ??= $i;

                continue;
            }
            if (ColumnSchema::isHeaderOrLabelRow($module, $cells)) {
                $unparsed[] = $raw;

                continue;
            }
            if ($map === null) {
                $unparsed[] = $raw;
                $nonHeaderUnparsed[] = $raw;

                continue;
            }

            $extracted = [];
            foreach ($fields as $f) {
                $v = isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';
                $extracted[$f] = $v === '' ? null : $v;
            }

            $label = (string) ($extracted[$key] ?? '');
            $numericFields = array_intersect(['cost_price', 'price', 'stock', 'quantity', 'unit_price', 'amount'], $fields);
            $hasNumericValue = false;
            foreach ($numericFields as $nf) {
                if (($extracted[$nf] ?? null) !== null && ValueParser::number($extracted[$nf], true)['value'] !== null) {
                    $hasNumericValue = true;
                    break;
                }
            }
            if ($label !== '' && ColumnSchema::exactFieldFor($module, $label) === $key && ! $hasNumericValue) {
                $unparsed[] = $raw;

                continue;
            }

            // If the unit column was omitted or empty, check whether the quantity/stock cell includes a unit (e.g. "10 pcs").
            $qtyField = isset($extracted['quantity']) ? 'quantity' : (isset($extracted['stock']) ? 'stock' : null);
            if ($qtyField !== null && ($extracted['unit'] ?? null) === null && $extracted[$qtyField] !== null) {
                $qu = ValueParser::quantityAndUnit($extracted[$qtyField], true);
                if ($qu['unit'] !== null && $qu['value'] !== null) {
                    $extracted['unit'] = $qu['unit'];
                    $extracted[$qtyField] = preg_replace('/\s*[a-zA-Z]{1,12}\.?\s*$/u', '', $extracted[$qtyField]) ?: (string) $qu['value'];
                }
            }
            $numbersOnly = $label === '' && collect($extracted)->filter()->isNotEmpty();
            $out[] = [
                'row_number' => $i + 1,
                'raw' => $raw,
                'extracted' => $extracted,
                'total_line' => (bool) preg_match(self::TOTAL_LINE, $label !== '' ? $label : $raw) || ($numbersOnly && preg_match(self::TOTAL_LINE, $raw)),
                'no_header' => false,
            ];
        }

        // No recognisable header anywhere: offer non-header lines for manual completion.
        if ($map === null) {
            foreach ($nonHeaderUnparsed as $n => $line) {
                $extracted = array_fill_keys($fields, null);
                $extracted[$key] = mb_substr($line, 0, 500);
                $out[] = ['row_number' => $n + 1, 'raw' => $line, 'extracted' => $extracted, 'total_line' => (bool) preg_match(self::TOTAL_LINE, $line), 'no_header' => true];
            }
            $unparsed = array_values(array_diff($unparsed, $nonHeaderUnparsed));
        }

        $usedHeader = $forced !== null ? $forcedHeader : $headerRow;

        return [
            'rows' => $out,
            'unparsed' => array_slice($unparsed, 0, 50),
            'header_found' => $map !== null,
            'mapping' => $forced !== null ? $forcedColumns : ($firstMap ?? []),
            'header_row' => $usedHeader,
            'columns' => self::columnLabels($table, $usedHeader),
        ];
    }

    /**
     * The header row and columns to offer on the column-mapping screen: the first recognised
     * header, else the first row naming two or more known columns, else nothing.
     *
     * @param  array<int, array<int, string>>  $table
     * @return array{0: ?int, 1: array<string, int>}
     */
    public static function suggest(string $module, array $table): array
    {
        foreach ($table as $i => $cells) {
            if ($header = ColumnSchema::headerMap($module, $cells)) {
                return [$i, $header];
            }
        }
        foreach (array_slice($table, 0, self::SEARCH_ROWS, true) as $i => $cells) {
            $guess = ColumnSchema::guessMap($module, $cells);
            if (count($guess) >= 2) {
                return [$i, $guess];
            }
        }

        return [null, []];
    }

    /**
     * Cells that look like column labels, for messages when no header was recognised: the
     * suggested header row, else the first line with two or more text cells (no numbers, no
     * "Supplier: ABC" details). Empty when there is none.
     *
     * @param  array<int, array<int, string>>  $table
     * @return list<string>
     */
    public static function likelyHeaderCells(string $module, array $table): array
    {
        [$index] = self::suggest($module, $table);
        if ($index !== null) {
            return self::nonEmpty($table[$index]);
        }
        foreach (array_slice(array_filter($table, fn ($cells) => self::nonEmpty($cells) !== []), 0, 10) as $cells) {
            $texts = self::nonEmpty($cells);
            $labels = array_filter($texts, fn ($c) => ! preg_match('/\d/u', $c) && ! HeaderMapper::isLabelledValue($c));
            if (count($texts) >= 2 && count($labels) === count($texts)) {
                return $texts;
            }
        }

        return [];
    }

    /**
     * A label per column: the header row's text, or "Column A", "Column B"... where it has none.
     *
     * @param  array<int, array<int, string>>  $table
     * @return list<string>
     */
    public static function columnLabels(array $table, ?int $headerRow, ?int $width = null): array
    {
        $width ??= self::width($table);
        $header = $headerRow !== null ? array_values($table[$headerRow] ?? []) : [];
        $labels = [];
        for ($c = 0; $c < $width; $c++) {
            $text = trim((string) ($header[$c] ?? ''));
            $labels[] = $text !== '' ? mb_substr($text, 0, 120) : self::columnName($c);
        }

        return $labels;
    }

    /** @param  array<int, array<int, string>>  $table */
    public static function width(array $table): int
    {
        return $table === [] ? 0 : max(array_map('count', $table));
    }

    /** "Column A" for index 0, "Column AA" for index 26. */
    public static function columnName(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return 'Column '.$name;
    }

    /** @param  array<int, string>  $cells */
    private static function cellsKey(array $cells): string
    {
        return implode("\x1F", array_map(fn ($c) => ColumnSchema::normalise($c), self::nonEmpty($cells)));
    }

    /**
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private static function nonEmpty(array $cells): array
    {
        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), $cells), fn ($c) => $c !== ''));
    }
}
