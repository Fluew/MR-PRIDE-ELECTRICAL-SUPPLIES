<?php

namespace App\Services\MaterialList;

/**
 * Turns extracted content into material lines.
 *
 *  - Tables (Excel/CSV, or text laid out in columns): headers are recognised by HeaderMapper.
 *  - Free text (PDF text / OCR): only lines with an explicit quantity become items, and they are
 *    always flagged for review. Everything else is returned as "unparsed lines" for the client to
 *    add by hand — nothing is silently dropped and no quantity is ever invented.
 */
class MaterialParser
{
    public const MAX_ITEMS = 500;

    public const MAX_QUANTITY = 1000000;

    /** Units we recognise after a quantity ("10 pcs", "100 m", "5 rolls"). */
    private const UNITS = 'pcs|pc|pieces|piece|pce|units|unit|ea|each|rolls|roll|meters|meter|metres|metre|mtrs|mtr|m|ft|feet|'
        .'boxes|box|bx|sets|set|lots|lot|pairs|pair|kg|kgs|g|lengths|length|lgth|coils|coil|spools|spool|packs|pack|pkt|'
        .'bags|bag|cans|can|gallons|gallon|gal|liters|liter|litres|litre|l|bundles|bundle|reels|reel|sheets|sheet|doz|dozen|nos|no';

    /** Labels of document details ("Date:", "Quotation No.", "Tel:"): their numbers are never quantities. */
    private const DETAIL_LABELS = 'date|tel|telephone|phone|mobile|cell|fax|e-?mail|tin|vat\s+reg(?:istration)?|quotation|quote|invoice|'
        .'p\.?\s?o\.?|purchase\s+order|sales\s+order|order|ref|reference|project|client|customer|supplier|vendor|company|address|'
        .'attention|attn|contact|prepared\s+by|approved\s+by|checked\s+by|requested\s+by|received\s+by|delivery\s+date|due\s+date|'
        .'valid\s+until|validity|terms|page';

    // ---------------------------------------------------------------------------------- cleaning

    /**
     * Clean raw text (per page) into meaningful lines. Runs of 2+ spaces / tabs become a TAB,
     * which marks a column boundary for column-aligned PDF text.
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public static function cleanLines(array $pages): array
    {
        $perPage = [];
        foreach ($pages as $page) {
            $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{2007}", "\u{202F}"], ["\n", "\n", ' ', ' ', ' '], (string) $page);
            $text = str_replace(['—', '–', '‒', '−'], '-', $text);
            $lines = [];
            foreach (explode("\n", $text) as $line) {
                $line = preg_replace('/^[\s•▪◦●■□\*\-–>]+(?=\S)/u', '', $line) ?? $line; // bullets
                $line = trim($line, " \t|¦");                                          // table borders
                $line = preg_replace('/\s*[|¦]\s*/u', "\t", $line) ?? $line;          // inner borders = columns
                $line = preg_replace('/(?: {2,}|\t+)/', "\t", $line) ?? $line;
                $line = preg_replace('/ +/', ' ', $line) ?? $line;
                $line = trim($line);
                if ($line === '' || self::isPageMarker($line) || ! preg_match('/[\p{L}\p{N}]/u', $line)) {
                    continue;
                }
                $lines[] = $line;
            }
            $perPage[] = $lines;
        }

        // Running headers/footers: identical short lines on most pages (only with 2+ pages).
        // Column header rows also repeat on every page; those are kept (the parser skips them).
        $repeated = [];
        if (count($perPage) >= 2) {
            $counts = [];
            foreach ($perPage as $lines) {
                foreach (array_unique($lines) as $l) {
                    $counts[$l] = ($counts[$l] ?? 0) + 1;
                }
            }
            foreach ($counts as $l => $n) {
                if ($n >= max(2, (int) ceil(count($perPage) * 0.6)) && mb_strlen($l) <= 80
                    && ! HeaderMapper::isHeaderRow(explode("\t", $l))) {
                    $repeated[$l] = true;
                }
            }
        }

        $out = [];
        foreach ($perPage as $lines) {
            foreach ($lines as $l) {
                if (! isset($repeated[$l])) {
                    $out[] = $l;
                }
            }
        }

        return $out;
    }

    private static function isPageMarker(string $line): bool
    {
        return (bool) preg_match('/^(?:page\s*)?\d{1,4}(?:\s*(?:of|\/)\s*\d{1,4})?$/i', $line)
            || (bool) preg_match('/^-\s*\d{1,4}\s*-$/', $line);
    }

    // ---------------------------------------------------------------------------------- tables

    /**
     * Parse a table (rows of cells). With no mapping given, the header row is detected.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int>|null  $mapping  client-chosen {field => column}
     * @param  int|null  $headerIndex  row holding the headers (null = no header row)
     */
    public static function parseTable(array $rows, ?array $mapping = null, ?int $headerIndex = null, string $source = 'table'): array
    {
        $rows = array_values(array_filter(
            array_map(fn ($r) => array_map(fn ($c) => self::cellText($c), array_values($r)), $rows),
            fn ($r) => implode('', $r) !== ''
        ));

        $autoDetect = $mapping === null;
        $headerDetected = ! $autoDetect && $headerIndex !== null;
        if ($autoDetect) {
            $found = HeaderMapper::findHeaderRow($rows);
            $mapping = $found['mapping'];
            $headerIndex = $found['index'];
            $headerDetected = $headerIndex !== null;
        }

        // No header row recognised: label the columns with the first row and suggest it as the
        // header row, so the client can map "Thing / How many" themselves (they can untick it).
        if ($autoDetect && $headerIndex === null && ! HeaderMapper::isConfident($mapping) && $rows !== []) {
            $headerIndex = 0;
        }

        $width = $rows === [] ? 0 : max(array_map('count', $rows));
        $headerRow = $headerIndex !== null ? ($rows[$headerIndex] ?? []) : [];
        $columns = [];
        for ($c = 0; $c < $width; $c++) {
            $label = trim((string) ($headerRow[$c] ?? ''));
            $columns[] = $label !== '' ? $label : 'Column '.self::columnLetter($c);
        }

        $result = [
            'confident' => HeaderMapper::isConfident($mapping),
            'mapping' => (object) $mapping,
            'header_index' => $headerIndex,
            'header_detected' => $headerDetected,
            'columns' => $columns,
            // Enough rows to choose the header row and columns by hand (the header may sit below a letterhead).
            'preview' => array_slice($rows, 0, min(40, max(15, ($headerIndex ?? 0) + 6))),
            'missing' => HeaderMapper::missingRequired($mapping),
            'items' => [],
            'unparsed_lines' => [],
            'warnings' => [],
        ];

        if (! $result['confident']) {
            // Name exactly which required columns are missing, and the headers that were found.
            $result['warnings'][] = HeaderMapper::describeMissing($mapping, $headerDetected ? $columns : []);

            return $result;
        }

        $start = $headerIndex === null ? 0 : $headerIndex + 1;
        foreach (array_slice($rows, $start, null, true) as $i => $row) {
            // Header rows repeated on later pages, and subtotal/total lines, are not items.
            if (HeaderMapper::isMaterialHeader($row)) {
                continue;
            }
            $get = fn (string $f) => isset($mapping[$f]) ? trim((string) ($row[$mapping[$f]] ?? '')) : '';

            $description = $get('description');
            if ($description === '' && implode('', $row) !== '' && $get('quantity') === '') {
                $result['unparsed_lines'][] = implode(' | ', array_filter($row, fn ($c) => $c !== ''));

                continue;
            }
            if (preg_match('/^(sub\s*-?\s*total|grand\s*total|total|vat|tax)\b/i', $description)) {
                continue;
            }
            if ($description !== '' && HeaderMapper::exactFieldFor($description) === 'description' && self::parseQuantity($get('quantity'))[0] === null) {
                continue;
            }

            $item = self::makeItem($i + 1, $description, $get('quantity'), $source);
            $item['unit'] = $get('unit') ?: $item['unit'];
            $item['brand'] = $get('brand') ?: null;
            $item['specs'] = $get('specs') ?: null;
            $item['remarks'] = $get('remarks') ?: null;
            $item['item_code'] = $get('item_code') ?: null;
            $item['client_price'] = self::parseNumber($get('unit_price'));
            $result['items'][] = self::finishItem($item);

            if (count($result['items']) >= self::MAX_ITEMS) {
                $result['warnings'][] = 'Only the first '.self::MAX_ITEMS.' items were read.';
                break;
            }
        }

        $result['items'] = self::flagDuplicates($result['items']);

        return $result;
    }

    // ---------------------------------------------------------------------------------- free text

    /** Parse cleaned text lines (from a PDF or OCR). */
    public static function parseText(array $lines, string $source = 'text'): array
    {
        // Text laid out in columns (tabs from cleanLines) is treated as a table.
        $split = array_map(fn ($l) => explode("\t", $l), $lines);
        $found = HeaderMapper::findHeaderRow($split, 40);
        if (HeaderMapper::isConfident($found['mapping'])) {
            $table = self::parseTable($split, $found['mapping'], $found['index'], $source);
            foreach ($table['items'] as &$item) {
                $item['needs_review'] = true; // column guess from text: always reviewed
            }
            unset($item);
            if ($table['items'] !== []) {
                return $table;
            }
        }

        $result = [
            'confident' => false, 'mapping' => (object) [], 'header_index' => null, 'columns' => [], 'preview' => [],
            'items' => [], 'unparsed_lines' => [], 'warnings' => [],
        ];

        $units = self::UNITS;
        $num = '\d[\d,]*(?:\.\d+)?';
        foreach ($lines as $n => $line) {
            $l = str_replace("\t", ' ', $line);
            $l = preg_replace('/^\d{1,3}[.)]\s+(?=\D)/', '', $l) ?? $l; // list numbering "1." / "2)"
            // OCR often reads 0 as O and 1 as l/I in a leading quantity ("1O pcs"). Fix it only when
            // the token already has a real digit and is followed by a unit or "x", so words are untouched.
            $ocrLeadingRaw = null;
            $l = preg_replace_callback(
                "/^(?=[OoIl]*\d)([\dOoIl][\dOoIl,]*)(?=\s*(?:(?:{$units})\.?|[x×])\s)/iu",
                function ($m) use (&$ocrLeadingRaw) {
                    if (preg_match('/[OoIl]/', $m[1])) {
                        $ocrLeadingRaw = $m[1];
                    }

                    return strtr($m[1], ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);
                },
                $l
            ) ?? $l;
            $m = null;

            // Document details are not materials: "Date: 2026-10-01" is not 1 of "Date: 2026-10".
            if (self::isDocumentDetail($l)) {
                $result['unparsed_lines'][] = str_replace("\t", ' | ', $line);

                continue;
            }

            if (preg_match("/^(?<qty>{$num})\s*(?<unit>{$units})\.?[_~-]*\s+(?:of\s+|x\s+|[-_~]+\s+)?(?<desc>[\p{L}].+)$/iu", $l, $m)      // 10 pcs Breaker 60A
                || preg_match("/^(?<qty>{$num})\s*[x×]\s*(?<desc>[\p{L}].+)$/iu", $l, $m)                                          // 10 x Breaker 60A
                || preg_match("/^(?<desc>.*[\p{L}].*?)\s*(?:[-:x×=]|qty\.?:?)\s*(?<qty>{$num})\s*(?<unit>{$units})?\.?$/iu", $l, $m) // Breaker 60A - 10 pcs
                || preg_match("/^(?<desc>.*[\p{L}].*?)\s+(?<qty>{$num})\s*(?<unit>{$units})\.?$/iu", $l, $m)) {                   // Breaker 60A 10 pcs
                $descText = trim($m['desc'], ' -:,');
                // The last part of a date, phone or reference number ("2026-10-01", "0917-555-1234") is not a quantity.
                $joined = preg_match('/\d[-\/.]'.preg_quote($m['qty'], '/')."\s*(?:(?:{$units})\.?)?$/iu", $l);
                if ($joined || HeaderMapper::exactFieldFor($descText) === 'description') {
                    $result['unparsed_lines'][] = str_replace("\t", ' | ', $line);

                    continue;
                }
                $item = self::makeItem($n + 1, $descText, $ocrLeadingRaw ?? $m['qty'], $source);
                $item['unit'] = ($m['unit'] ?? '') !== '' ? strtolower($m['unit']) : null;
                $item['needs_review'] = true;
                $result['items'][] = self::finishItem($item);
            } elseif (! HeaderMapper::isMaterialHeader(explode("\t", $line))) {
                $result['unparsed_lines'][] = str_replace("\t", ' | ', $line);
            }

            if (count($result['items']) >= self::MAX_ITEMS) {
                break;
            }
        }

        if ($result['items'] !== []) {
            $result['warnings'][] = 'These items were read from free text. Please check every line before submitting.';
        }
        $result['items'] = self::flagDuplicates($result['items']);

        return $result;
    }

    // ---------------------------------------------------------------------------------- helpers

    /** "Date: 2026-10-01", "Quotation No. 123", "Tel: 0917-555-1234", "PO # 4500": a document detail line. */
    private static function isDocumentDetail(string $line): bool
    {
        $labels = self::DETAIL_LABELS;

        return (bool) preg_match("/^\s*(?:{$labels})\s*(?:no\.?|number|#|date|name|ref\.?)?\s*[:#]/iu", $line)
            || (bool) preg_match("/^\s*(?:{$labels})\s+(?:no\.?|number)\s*\d/iu", $line);
    }

    private static function makeItem(int $line, string $description, string $quantityRaw, string $source): array
    {
        [$qty, $unitFromQty] = self::parseQuantity($quantityRaw);
        $hadOcrFix = $qty !== null && preg_match('/[OoIl]/', $quantityRaw);

        return [
            'line' => $line,
            'source' => $source,
            'description' => mb_substr(trim(preg_replace('/\s+/', ' ', $description) ?? $description), 0, 500),
            'quantity' => $qty,
            'quantity_raw' => $quantityRaw,
            'unit' => $unitFromQty,
            'brand' => null,
            'specs' => null,
            'remarks' => null,
            'item_code' => null,
            'client_price' => null,
            'errors' => [],   // block submission until fixed
            'warnings' => $hadOcrFix ? ["Quantity read as \"{$quantityRaw}\" and taken as {$qty}. Please verify against the original document."] : [],
            'needs_review' => (bool) $hadOcrFix,
            'extraction_confidence' => $hadOcrFix ? 'low' : (in_array($source, ['ocr', 'pdf-ocr', 'text'], true) ? 'medium' : 'high'),
        ];
    }

    private static function finishItem(array $item): array
    {
        if ($item['description'] === '') {
            $item['errors'][] = 'Missing item description.';
        }
        if ($item['quantity'] === null) {
            $item['errors'][] = $item['quantity_raw'] === '' ? 'Missing quantity.' : "Quantity \"{$item['quantity_raw']}\" is not a valid number.";
        } elseif ($item['quantity'] <= 0) {
            $item['errors'][] = 'Quantity must be greater than zero.';
        } elseif ($item['quantity'] > self::MAX_QUANTITY) {
            $item['errors'][] = 'Quantity is too large.';
        } elseif (floor($item['quantity']) !== $item['quantity']) {
            // Quotation and sale quantities are whole numbers (stock is counted in whole units).
            $item['errors'][] = 'Quantity must be a whole number.';
        }
        if ($item['errors'] !== []) {
            $item['needs_review'] = true;
            $item['extraction_confidence'] = 'low';
        } elseif (($item['needs_review'] ?? false) && ($item['extraction_confidence'] ?? 'high') === 'high') {
            $item['extraction_confidence'] = 'medium';
        }
        foreach (['unit' => 30, 'brand' => 100, 'specs' => 500, 'remarks' => 500, 'item_code' => 60] as $f => $max) {
            $item[$f] = $item[$f] !== null ? mb_substr((string) $item[$f], 0, $max) : null;
        }

        return $item;
    }

    /**
     * "1,000" → 1000, "10 pcs" → [10, pcs], "1O" (OCR) → 10. Letter-for-digit fixes are applied
     * only when everything else in the value is a digit, so they are reliable.
     *
     * @return array{0: ?float, 1: ?string}
     */
    public static function parseQuantity(string $raw): array
    {
        $v = trim($raw);
        if ($v === '') {
            return [null, null];
        }

        $unit = null;
        if (preg_match('/^([\dOoIl][\d,OoIl]*(?:\.\d+)?)\s*('.self::UNITS.')\.?$/i', $v, $m)) {
            $v = $m[1];
            $unit = strtolower($m[2]);
        }
        if (preg_match('/^[\dOoIl,.]+$/', $v) && preg_match('/\d/', $v)) {
            $v = strtr($v, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);
        }

        $n = self::parseNumber($v);

        return [$n, $unit];
    }

    public static function parseNumber(mixed $raw): ?float
    {
        $v = str_replace([',', '₱', 'PHP', 'Php', ' '], '', trim((string) $raw));
        if ($v === '' || ! is_numeric($v)) {
            return null;
        }

        return round((float) $v, 4);
    }

    /** Cell value as safe text. Spreadsheet formulas are never evaluated (see FileReader). */
    private static function cellText(mixed $cell): string
    {
        if ($cell === null || is_bool($cell)) {
            return '';
        }
        if (is_float($cell) && floor($cell) === $cell) {
            $cell = (int) $cell;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $cell) ?? '');
    }

    private static function columnLetter(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + (($i - 1) % 26)).$s;
        }

        return $s;
    }

    /** Mark repeated materials (same description, brand and unit) for review; never drop them. */
    private static function flagDuplicates(array $items): array
    {
        $seen = [];
        foreach ($items as &$item) {
            $key = mb_strtolower($item['description'].'|'.($item['brand'] ?? '').'|'.($item['unit'] ?? ''));
            if ($item['description'] !== '' && isset($seen[$key])) {
                $item['warnings'][] = "Possible duplicate of line {$seen[$key]}.";
                $item['needs_review'] = true;
            } else {
                $seen[$key] = $item['line'];
            }
        }
        unset($item);

        return $items;
    }
}
