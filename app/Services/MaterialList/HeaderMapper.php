<?php

namespace App\Services\MaterialList;

/**
 * Recognises the columns of a client's material list, whatever their order or wording
 * ("Item Description", "QTY.", "Q'ty", "U.O.M", "Stock_Quantity", "Preferred Brand", ...).
 *
 * A mapping is "confident" only when both a description column and a quantity column are found.
 * Otherwise staff are asked to choose the columns themselves.
 *
 * A row counts as a header row only when it names at least two known columns AND most of its
 * cells are column labels. Titles ("Bill of Materials and Quantities"), supplier details
 * ("Supplier: ABC Trading") and data rows therefore never become headers.
 */
class HeaderMapper
{
    /** Fields we extract, with the header wordings recognised for each (already normalised). */
    public const SYNONYMS = [
        'description' => [
            'description', 'item description', 'product description', 'material description', 'materials description',
            'product name', 'material name', 'item name', 'particulars', 'item particulars', 'item details',
            'description of materials', 'description of goods', 'description of items', 'description of item',
            'name of item', 'name of material', 'item desc', 'product desc', 'material desc', 'desc',
            'item', 'items', 'product', 'products', 'material', 'materials', 'name', 'article', 'goods',
        ],
        'quantity' => [
            'quantity', 'qty', 'quantities', 'amount required', 'qty required', 'required qty', 'quantity required',
            'no of units', 'number of units', 'qty needed', 'quantity needed', 'order qty', 'req qty',
            'quantity ordered', 'qty ordered', 'ordered qty', 'requested qty', 'qty requested', 'quantity requested',
            'stock quantity', 'stock qty', 'available quantity', 'available qty', 'avail qty', 'qty available',
            'quantity available', 'current stock', 'current quantity', 'current qty', 'on hand', 'qty on hand',
            'quantity on hand', 'on hand qty', 'on hand quantity', 'stock on hand', 'soh', 'qoh',
            'total qty', 'total quantity', 'count', 'pcs', 'nos', 'stock',
        ],
        'unit' => ['unit', 'units', 'uom', 'unit of measure', 'unit of measurement', 'measure', 'um'],
        'brand' => ['brand', 'manufacturer', 'preferred brand', 'make', 'brand name', 'mfr', 'maker'],
        'specs' => [
            'specifications', 'specification', 'specs', 'spec', 'technical details', 'technical specifications',
            'details', 'rating', 'size', 'model', 'model no', 'type',
        ],
        'unit_price' => ['unit price', 'price', 'cost', 'unit cost', 'rate', 'price per unit', 'srp', 'amount per unit'],
        'remarks' => ['remarks', 'notes', 'note', 'comments', 'comment', 'remark'],
        'item_code' => ['code', 'item code', 'product code', 'sku', 'part no', 'part number', 'catalog no', 'catalogue no', 'ref', 'reference'],
    ];

    /**
     * Generic wordings: used only when no column names the field more specifically. "Item" is
     * often the line-number column ("Item | Description | Qty"), so "Description" wins over it.
     */
    public const WEAK = [
        'description' => ['item', 'items', 'product', 'products', 'material', 'materials', 'name', 'article', 'goods'],
        'quantity' => ['count', 'pcs', 'nos', 'stock'],
        'unit' => ['measure', 'um'],
        'specs' => ['details', 'type', 'size', 'rating'],
        'item_code' => ['ref', 'reference'],
    ];

    /** A generic wording that loses its field to a more specific column names this field instead. */
    public const FALLBACK = ['item' => 'item_code', 'items' => 'item_code'];

    /**
     * Column labels that are not fields we read ("No.", "Amount", "Status"). They count as header
     * labels when judging whether a row is a header, and are never taken loosely as a field
     * ("Item No." is a line number, not an item description).
     */
    public const NEUTRAL = [
        'no', 'nr', 'sn', 's n', 'sr no', 'sl no', 'serial no', 'line', 'line no', 'item no', 'item number', 'items no',
        'amount', 'total', 'total price', 'total amount', 'line total', 'subtotal', 'status', 'action', 'actions',
        'reorder level', 'reorder quantity', 'reorder point', 'location', 'warehouse', 'bin', 'image', 'photo', 'picture',
        'category', 'supplier', 'lead time', 'availability', 'discount', 'vat', 'tax', 'color', 'colour', 'weight',
        'dimensions', 'date', 'delivery date', 'selling price', 'cost price', 'retail price', 'amount php', 'amount usd',
    ];

    public const REQUIRED = ['description', 'quantity'];

    /** Field names as staff read them in messages. */
    public const LABELS = [
        'description' => 'Description', 'quantity' => 'Quantity', 'unit' => 'Unit', 'brand' => 'Brand',
        'specs' => 'Specifications', 'unit_price' => 'Unit price', 'remarks' => 'Remarks', 'item_code' => 'Item code',
    ];

    /** Header wordings that name the required fields, shown when a column is missing. */
    public const EXAMPLES = [
        'description' => '"Description", "Product Name", "Item Name" or "Material"',
        'quantity' => '"Qty", "Quantity", "Stock Quantity" or "On Hand"',
    ];

    /**
     * Lowercase, drop punctuation (QTY. → qty, U.O.M → uom, Q'ty → qty, Part # → part no,
     * Stock_Quantity → stock quantity, productName → product name), collapse spaces.
     */
    public static function normalise(mixed $header): string
    {
        $h = trim((string) $header);
        $h = preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $h) ?? $h;              // camelCase → camel case
        $h = mb_strtolower($h);
        $h = str_replace(["'", '’', '‘', '`', '´'], '', $h);                        // Q'ty → qty
        $h = str_replace(['#', 'no.'], [' no ', 'no '], $h);
        $h = preg_replace('/(?<=\b[a-z])\.(?=[a-z]\b)/u', '', $h) ?? $h;           // u.o.m → uom
        $h = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $h) ?? $h;                      // _ - / . : and spaces

        return trim(preg_replace('/\s+/', ' ', $h) ?? $h);
    }

    /** The field a single header names, or null when it is unrelated. */
    public static function fieldFor(mixed $header): ?string
    {
        return self::rankedField($header)[0] ?? null;
    }

    /** The field a cell names with an exact wording only ("Description", not "Description of works"). */
    public static function exactFieldFor(mixed $cell): ?string
    {
        $found = self::rankedField($cell);

        return $found !== null && $found[2] ? $found[0] : null;
    }

    /**
     * The field a header names, how specific the wording is (0 = specific, 1 = generic), and
     * whether it matched a wording exactly (false: it only starts with one, as "Qty (pcs)").
     *
     * @return array{0: string, 1: int, 2: bool, 3: string}|null field, rank, exact, the wording matched
     */
    public static function rankedField(mixed $header): ?array
    {
        return self::resolve(self::normalise($header), self::SYNONYMS, self::WEAK, self::NEUTRAL);
    }

    /**
     * Resolve a normalised header against synonym lists (shared with the staff document import).
     *
     * @param  array<string, list<string>>  $synonyms
     * @param  array<string, list<string>>  $weak
     * @param  list<string>  $neutral
     * @return array{0: string, 1: int, 2: bool, 3: string}|null
     */
    public static function resolve(string $h, array $synonyms, array $weak, array $neutral): ?array
    {
        if ($h === '' || mb_strlen($h) > 40) {
            return null;
        }

        foreach ($synonyms as $field => $words) {
            if (in_array($h, $words, true)) {
                return [$field, in_array($h, $weak[$field] ?? [], true) ? 1 : 0, true, $h];
            }
        }
        if (in_array($h, $neutral, true)) {
            return null;
        }

        // Looser: "qty (pcs)", "brand / make", "item description & specs" — the header *starts*
        // with a known wording followed by at most three more words. The longest wording wins,
        // so "unit price incl vat" is a price and "item code no" a code, not a unit or an item.
        $best = null;
        foreach ($synonyms as $field => $words) {
            foreach ($words as $word) {
                if (str_starts_with($h, $word.' ') && ($best === null || mb_strlen($word) > mb_strlen($best[2]))
                    && substr_count(mb_substr($h, mb_strlen($word)), ' ') <= 3) {
                    $best = [$field, in_array($word, $weak[$field] ?? [], true) ? 1 : 0, $word];
                }
            }
        }

        return $best === null ? null : [$best[0], $best[1], false, $best[2]];
    }

    /** A cell that is a known column label (a field, or a neutral label such as "No." or "Amount"). */
    public static function isLabel(mixed $cell): bool
    {
        if (self::isLabelledValue($cell)) {
            return false;
        }

        return self::fieldFor($cell) !== null || in_array(self::normalise($cell), self::NEUTRAL, true);
    }

    /** "Supplier: ABC Trading", "Date: 2026-10-01": a label with its value, never a column header. */
    public static function isLabelledValue(mixed $cell): bool
    {
        return (bool) preg_match('/^[^:]{1,40}:\s*\S/u', trim((string) $cell));
    }

    /**
     * Map a header row to {field => column index}. When two columns name the same field, the
     * more specific wording wins, then the first column.
     *
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    public static function map(array $row): array
    {
        return self::scan($row, fn (string $cell) => self::rankedField($cell), false, self::FALLBACK)['mapping'];
    }

    /** @param  array<string, int>  $mapping */
    public static function isConfident(array $mapping): bool
    {
        return isset($mapping['description'], $mapping['quantity']);
    }

    /**
     * Whether a row is a column header row (see scan()).
     *
     * @param  array<int, mixed>  $row
     */
    public static function isHeaderRow(array $row, bool $wordLevel = false): bool
    {
        return self::scan($row, fn (string $cell) => self::rankedField($cell), $wordLevel)['header'];
    }

    /**
     * Read a row as a possible header row: its {field => column} mapping, and whether it is a
     * header at all. A header row:
     *  - names two or more known columns, at least one of them with an exact wording;
     *  - has no number cells (data rows have quantities and prices; header rows do not);
     *  - consists mostly of column labels: half of its cells, or three quarters when the cells
     *    are single words ($wordLevel: OCR or PDF words, where a title like "Bill of Materials
     *    and Quantities" also splits into words). "Supplier: ABC" style cells are never labels.
     * Specks such as table borders read by OCR ("|", "l") are ignored.
     *
     * @param  array<int, mixed>  $row
     * @param  callable(string): ?array{0: string, 1: int, 2: bool, 3: string}  $resolve  field, rank, exact, wording
     * @param  array<string, string>  $fallback  generic wording => field it names when it loses its own field
     * @return array{mapping: array<string, int>, header: bool}
     */
    public static function scan(array $row, callable $resolve, bool $wordLevel = false, array $fallback = []): array
    {
        $mapping = [];
        $rank = [];
        $lost = [];
        $wording = [];
        $exact = 0;
        $significant = 0;
        $labels = 0;
        $numeric = false;

        foreach (array_values($row) as $i => $cell) {
            $text = is_scalar($cell) && ! is_bool($cell) ? trim((string) $cell) : '';
            if ($text === '') {
                continue;
            }
            $tiny = preg_match_all('/[\p{L}\p{N}]/u', $text) < 2 && $text !== '#';
            if (! $tiny) {
                $significant++;
                $numeric = $numeric || (bool) preg_match('/^[\p{Sc}]?\s*[-+]?\d[\d,]*(?:\.\d+)?\s*%?$/u', $text);
            }
            if (self::isLabelledValue($text)) {
                continue;
            }
            $found = $resolve($text);
            if ($found !== null) {
                [$field, $r, $isExact] = $found;
                if (! isset($mapping[$field]) || $r < $rank[$field]) {
                    if (isset($mapping[$field])) {
                        $lost[$mapping[$field]] = $wording[$mapping[$field]] ?? null;
                    }
                    $mapping[$field] = $i;
                    $rank[$field] = $r;
                } else {
                    $lost[$i] = $found[3] ?? null;
                }
                $wording[$i] = $found[3] ?? null;
                $exact += $isExact ? 1 : 0;
            }
            if (! $tiny && ($found !== null || self::isLabel($text))) {
                $labels++;
            }
        }

        // "Item | Particulars | Qty": the "Item" column lost the description, so it holds the item code.
        foreach ($lost as $i => $word) {
            $field = $fallback[$word] ?? null;
            if ($field !== null && ! isset($mapping[$field])) {
                $mapping[$field] = $i;
            }
        }

        $header = count($mapping) >= 2 && $exact >= 1 && ! $numeric && $significant >= 2
            && $labels / $significant >= ($wordLevel ? 0.75 : 0.5);

        return ['mapping' => $mapping, 'header' => $header];
    }

    /**
     * A header row of a material list: a header row with a description and a quantity column.
     *
     * @param  array<int, mixed>  $row
     */
    public static function isMaterialHeader(array $row, bool $wordLevel = false): bool
    {
        return self::isHeaderRow($row, $wordLevel) && self::isConfident(self::map($row));
    }

    /**
     * Required fields not in a mapping.
     *
     * @param  array<string, int>  $mapping
     * @return list<string>
     */
    public static function missingRequired(array $mapping): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($f) => ! isset($mapping[$f])));
    }

    /**
     * Staff-facing explanation of which required columns are missing, naming the columns found.
     *
     * @param  array<string, int>  $mapping
     * @param  array<int, string>  $columns  header labels of the table (empty when none)
     */
    public static function describeMissing(array $mapping, array $columns = []): string
    {
        $missing = self::missingRequired($mapping);
        if ($missing === []) {
            return '';
        }
        $names = array_map(fn ($f) => mb_strtolower(self::LABELS[$f]), $missing);
        $examples = implode(' and ', array_map(fn ($f) => self::EXAMPLES[$f], $missing));
        $found = array_values(array_filter(array_map('trim', $columns), fn ($c) => $c !== '' && ! preg_match('/^Column [A-Z]+$/', $c)));
        $seen = $found !== [] ? ' Columns found: '.implode(', ', array_map(fn ($c) => '"'.mb_substr($c, 0, 40).'"', array_slice($found, 0, 12))).'.' : ' No column headers were recognised.';

        return 'No '.implode(' or ', $names).' column was found.'.$seen.' Choose the '.implode(' and ', $names)
            .' column'.(count($missing) > 1 ? 's' : '').' below, or add a column headed '.$examples.' to the file.';
    }

    /**
     * Find the header row in the first rows of a table (titles, addresses and supplier details
     * often come first). The row with the most recognised columns wins; a row with both a
     * description and a quantity column always beats one without.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{index: ?int, mapping: array<string, int>}
     */
    public static function findHeaderRow(array $rows, int $searchRows = 50): array
    {
        $best = ['index' => null, 'mapping' => [], 'score' => 0];

        foreach (array_slice($rows, 0, $searchRows, true) as $i => $row) {
            if (! self::isHeaderRow($row)) {
                continue;
            }
            $mapping = self::map($row);
            $score = count($mapping) + (self::isConfident($mapping) ? 10 : 0);
            if ($score > $best['score']) {
                $best = ['index' => $i, 'mapping' => $mapping, 'score' => $score];
            }
        }

        return ['index' => $best['index'], 'mapping' => $best['mapping']];
    }
}
