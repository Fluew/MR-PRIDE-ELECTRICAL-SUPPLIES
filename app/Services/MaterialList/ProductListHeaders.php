<?php

namespace App\Services\MaterialList;

/**
 * Recognises the columns of a client's product list: Description and Quantity (required) and
 * Unit (optional), in any order, whatever the capitalisation, spacing or punctuation ("Desc",
 * "QTY.", "Q'ty", "U.O.M.", "U/M").
 *
 * A header cell is matched in this order, stopping at the first hit:
 *  1. an exact alias (after HeaderMapper::normalise);
 *  2. a cell naming another known column ("Unit Price", "Brand", "Item No.", "Amount") is
 *     never one of ours;
 *  3. an alias followed by at most three more words ("Qty (pcs)", "Description / Specs");
 *  4. a conservative fuzzy match for OCR errors: look-alike characters (0/o, 1/l, rn/m), and
 *     for long words only (6+ letters), one wrong letter (two from 9 letters on).
 *
 * Ranks: 0 = specific ("Description", "Qty"), 1 = generic ("Item", "Product"; they lose to a
 * specific column), 2 = ambiguous ("No.", "Number", "UM"; used only when the column's data
 * confirms them, see confirmFromData()).
 */
final class ProductListHeaders
{
    public const FIELDS = ['description', 'quantity', 'unit'];

    public const LABELS = ['description' => 'Description', 'quantity' => 'Quantity', 'unit' => 'Unit'];

    /** field => rank => aliases (already normalised: lowercase, no punctuation). */
    public const ALIASES = [
        'description' => [
            0 => [
                'description', 'descriptions', 'desc', 'descr', 'dscr', 'dscrptn', 'product name', 'product description',
                'item name', 'item description', 'material description', 'materials description', 'material name',
                'particulars', 'item particulars', 'item desc', 'product desc', 'material desc', 'description of materials',
                'description of items', 'description of item', 'description of goods', 'name of item', 'name of product',
                'name of material', 'product item', 'item product',
            ],
            1 => ['product', 'products', 'item', 'items', 'material', 'materials', 'prod', 'article', 'articles', 'goods'],
        ],
        'quantity' => [
            0 => [
                'quantity', 'quantities', 'qty', 'qtys', 'qnty', 'qnt', 'qtty', 'quant', 'q ty', 'number of items',
                'no of items', 'qty required', 'quantity required', 'required qty', 'req qty', 'qty needed', 'quantity needed',
                'order qty', 'qty ordered', 'quantity ordered', 'requested qty', 'qty requested', 'quantity requested',
                'total qty', 'total quantity',
            ],
            1 => ['count', 'nos', 'no of pcs', 'number of pcs', 'pcs'],
            2 => ['no', 'number', 'num'],
        ],
        'unit' => [
            0 => ['unit', 'units', 'uom', 'unit of measure', 'unit of measurement', 'units of measure', 'u m', 'unit measure', 'unit of issue'],
            1 => ['measure', 'measurement'],
            2 => ['um'],
        ],
    ];

    /** Common OCR misreadings of short headers, too short for the letter-distance rule. */
    private const OCR_VARIANTS = [
        'oty' => 'quantity', 'gty' => 'quantity', 'qtv' => 'quantity', 'qly' => 'quantity', 'qiy' => 'quantity',
        'otv' => 'quantity', 'q ly' => 'quantity', 'uorn' => 'unit', 'uon' => 'unit', 'unlt' => 'unit', 'umt' => 'unit',
        'dese' => 'description', 'deso' => 'description', 'dcsc' => 'description',
    ];

    /** Last words that make a header a price or total column ("Material Cost", "Item Amount"). */
    private const MONEY_WORDS = ['cost', 'costs', 'price', 'prices', 'amount', 'total', 'php', 'usd', 'value', 'rate', 'peso', 'pesos'];

    /** @var array<string, array{0: string, 1: int}>|null alias => [field, rank] */
    private static ?array $index = null;

    /** @return array<string, array{0: string, 1: int}> */
    private static function index(): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::ALIASES as $field => $ranks) {
                foreach ($ranks as $rank => $aliases) {
                    foreach ($aliases as $alias) {
                        self::$index[$alias] = [$field, $rank];
                    }
                }
            }
        }

        return self::$index;
    }

    /**
     * The field a header cell names: [field, rank, exact, wording matched, fuzzy], or null.
     * The first four entries are what HeaderMapper::scan() expects.
     *
     * @return array{0: string, 1: int, 2: bool, 3: string, 4: bool}|null
     */
    public static function resolve(mixed $cell): ?array
    {
        // Lowercased first, so "dEsCrIpTiOn" or "qTy" is not split up as camelCase ("productName" still is).
        $text = is_scalar($cell) ? (string) $cell : '';
        foreach (array_unique([HeaderMapper::normalise(mb_strtolower($text)), HeaderMapper::normalise($text)]) as $h) {
            $found = self::resolveNormalised($h);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: int, 2: bool, 3: string, 4: bool}|null */
    private static function resolveNormalised(string $h): ?array
    {
        if ($h === '' || mb_strlen($h) > 40) {
            return null;
        }
        $index = self::index();

        // 1. Exact alias.
        if (isset($index[$h])) {
            return [$index[$h][0], $index[$h][1], true, $h, false];
        }

        // 2. Another column: price/total columns, brand, remarks, item code, line number, ...
        $words = explode(' ', $h);
        if (in_array(end($words), self::MONEY_WORDS, true) || in_array($h, HeaderMapper::NEUTRAL, true)) {
            return null;
        }
        $other = HeaderMapper::rankedField($h);
        if ($other !== null && ! in_array($other[0], self::FIELDS, true)) {
            return null;
        }

        // 3. An alias followed by a few more words.
        $best = null;
        foreach ($index as $alias => [$field, $rank]) {
            if ($rank < 2 && str_starts_with($h, $alias.' ') && substr_count(mb_substr($h, mb_strlen($alias)), ' ') <= 3
                && ($best === null || mb_strlen($alias) > mb_strlen($best[3]))) {
                $best = [$field, $rank, false, $alias, false];
            }
        }
        if ($best !== null) {
            return $best;
        }

        // 4. OCR errors.
        return self::fuzzy($h);
    }

    /** @return array{0: string, 1: int, 2: bool, 3: string, 4: bool}|null */
    private static function fuzzy(string $h): ?array
    {
        $index = self::index();

        // Look-alike characters: deterministic, so treated like an exact wording.
        $folded = strtr($h, ['0' => 'o', '1' => 'l', '5' => 's', '|' => 'l', 'rn' => 'm', 'vv' => 'w']);
        foreach ([$folded, str_replace('l', 'i', $folded)] as $candidate) {
            if ($candidate !== $h && isset($index[$candidate]) && $index[$candidate][1] < 2) {
                return [$index[$candidate][0], $index[$candidate][1], true, $candidate, true];
            }
        }
        if (isset(self::OCR_VARIANTS[$folded])) {
            return [self::OCR_VARIANTS[$folded], 0, true, $folded, true];
        }

        // A wrong letter or two in a long word ("Quantlty", "Descriptlon", "Particu1ars").
        $best = null;
        $tie = false;
        foreach ($index as $alias => [$field, $rank]) {
            $length = mb_strlen($alias);
            if ($rank === 2 || $length < 6 || str_contains($alias, ' ') !== str_contains($folded, ' ') || $alias[0] !== $folded[0]) {
                continue;
            }
            $max = $length >= 9 ? 2 : 1;
            if (abs($length - mb_strlen($folded)) > $max) {
                continue;
            }
            $distance = levenshtein($folded, $alias);
            if ($distance > $max) {
                continue;
            }
            if ($best === null || $distance < $best[2]) {
                $best = [$field, $rank, $distance, $alias];
                $tie = false;
            } elseif ($distance === $best[2] && $field !== $best[0]) {
                $tie = true; // equally close to two different columns: not reliable
            }
        }

        return $best === null || $tie ? null : [$best[0], $best[1], false, $best[3], true];
    }

    /**
     * Read a row as a header row: {field => column}, the rank and wording of each, and whether
     * it is a header row at all (see HeaderMapper::scan(): mostly labels, no numbers, at least
     * two known columns with one exact wording).
     *
     * @param  array<int, mixed>  $row
     * @return array{mapping: array<string, int>, header: bool, ranks: array<string, int>, wordings: array<string, string>, fuzzy: array<string, string>}
     */
    public static function scan(array $row, bool $wordLevel = false): array
    {
        $scan = HeaderMapper::scan($row, fn (string $cell) => self::resolve($cell), $wordLevel);
        $ranks = [];
        $wordings = [];
        $fuzzy = [];
        $cells = array_values($row);
        foreach ($scan['mapping'] as $field => $column) {
            $found = self::resolve($cells[$column] ?? '');
            $ranks[$field] = $found[1] ?? 0;
            $wordings[$field] = trim((string) ($cells[$column] ?? ''));
            if ($found[4] ?? false) {
                $fuzzy[$field] = $wordings[$field];
            }
        }

        return ['mapping' => $scan['mapping'], 'header' => $scan['header'], 'ranks' => $ranks, 'wordings' => $wordings, 'fuzzy' => $fuzzy];
    }

    /**
     * A product-list header row: a header row with a description column and a quantity or unit
     * column. (A missing quantity column is reported to the client rather than ignored.)
     *
     * @param  array<int, mixed>  $row
     */
    public static function isProductHeader(array $row, bool $wordLevel = false): bool
    {
        $scan = self::scan($row, $wordLevel);

        return $scan['header'] && isset($scan['mapping']['description'])
            && (isset($scan['mapping']['quantity']) || isset($scan['mapping']['unit']));
    }

    /**
     * Find the header row among the first rows of a table (titles and addresses often come
     * first). The row naming the most of the columns wins.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{index: ?int, mapping: array<string, int>, ranks: array<string, int>, wordings: array<string, string>, fuzzy: array<string, string>}
     */
    public static function findHeaderRow(array $rows, int $searchRows = 50): array
    {
        $best = null;
        $bestScore = 0;
        foreach (array_slice($rows, 0, $searchRows, true) as $i => $row) {
            $scan = self::scan($row);
            if (! $scan['header'] || ! isset($scan['mapping']['description'])) {
                continue;
            }
            $score = count($scan['mapping']) + (isset($scan['mapping']['quantity']) ? 10 : 0);
            if ($score > $bestScore) {
                $best = ['index' => $i] + $scan;
                $bestScore = $score;
            }
        }

        return $best === null
            ? ['index' => null, 'mapping' => [], 'ranks' => [], 'wordings' => [], 'fuzzy' => []]
            : array_diff_key($best, ['header' => 0]);
    }

    /**
     * Check generic and ambiguous columns against the data below them:
     *  - "No." / "Number" is the quantity only when its numbers are not a 1, 2, 3, ... count
     *    and it is not the first column; a running count is a line number.
     *  - "UM" is the unit only when most of its values are units.
     *  - "Item" / "Product" is the description only when its values are words, not numbers.
     * A column that cannot be confirmed is removed from the mapping and returned as ambiguous,
     * for the client to confirm.
     *
     * @param  array<string, int>  $mapping
     * @param  array<string, int>  $ranks
     * @param  array<string, string>  $wordings
     * @param  array<int, array<int, string>>  $data  rows below the header
     * @return array{mapping: array<string, int>, notes: list<string>, ambiguous: list<array{field: string, column: int, label: string, reason: string}>}
     */
    public static function confirmFromData(array $mapping, array $ranks, array $wordings, array $data): array
    {
        $notes = [];
        $ambiguous = [];
        $values = function (int $column) use ($data): array {
            $out = [];
            foreach (array_slice($data, 0, 60) as $row) {
                $v = trim((string) ($row[$column] ?? ''));
                if ($v !== '') {
                    $out[] = $v;
                }
            }

            return $out;
        };
        $share = fn (array $vs, callable $test) => $vs === [] ? 0.0 : count(array_filter($vs, $test)) / count($vs);
        $isNumber = fn (string $v) => (bool) preg_match('/^\d[\d,]*(?:\.\d+)?$/', $v);
        $drop = function (string $field, string $reason) use (&$mapping, &$ambiguous, $wordings) {
            $ambiguous[] = ['field' => $field, 'column' => $mapping[$field], 'label' => $wordings[$field] ?? '', 'reason' => $reason];
            unset($mapping[$field]);
        };

        if (isset($mapping['quantity']) && ($ranks['quantity'] ?? 0) === 2) {
            $label = $wordings['quantity'] ?? 'No.';
            $vs = $values($mapping['quantity']);
            $numbers = array_map(fn ($v) => (float) str_replace(',', '', $v), array_filter($vs, $isNumber));
            $running = count($vs) >= 2 && count($numbers) === count($vs) && $numbers === array_map('floatval', range(1, count($numbers)));
            $first = $mapping['quantity'] === min($mapping);
            if ($running) {
                $notes[] = "The \"{$label}\" column counts the lines (1, 2, 3, …), so it was not used as the quantity.";
                unset($mapping['quantity']);
            } elseif ($vs !== [] && $share($vs, $isNumber) >= 0.8 && ! $first) {
                $notes[] = "The \"{$label}\" column was read as the quantity.";
            } else {
                $drop('quantity', "We couldn't tell whether \"{$label}\" is the quantity or a line number.");
            }
        } elseif (isset($mapping['quantity']) && ($ranks['quantity'] ?? 0) === 1) {
            $vs = $values($mapping['quantity']);
            if ($vs !== [] && $share($vs, fn ($v) => (bool) preg_match('/^\d/', $v)) < 0.6) {
                $drop('quantity', "The \"{$wordings['quantity']}\" column does not hold numbers, so it may not be the quantity.");
            }
        }

        if (isset($mapping['unit']) && ($ranks['unit'] ?? 0) === 2) {
            $vs = $values($mapping['unit']);
            if ($vs === [] || $share($vs, fn ($v) => ProductListParser::isKnownUnit($v)) < 0.6) {
                $drop('unit', "We couldn't tell whether \"{$wordings['unit']}\" is the unit column.");
            }
        }

        if (isset($mapping['description']) && ($ranks['description'] ?? 0) === 1) {
            $vs = $values($mapping['description']);
            if ($vs !== [] && $share($vs, fn ($v) => (bool) preg_match('/\p{L}{2,}/u', $v)) < 0.6) {
                $drop('description', "The \"{$wordings['description']}\" column holds numbers or codes, not product names.");
            }
        }

        return ['mapping' => $mapping, 'notes' => $notes, 'ambiguous' => $ambiguous];
    }
}
