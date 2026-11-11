<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Services\MaterialList\ProductMatcher;
use Illuminate\Support\Collection;

/**
 * Inventory import: new product records only. Creating products, receiving stock and changing
 * prices stay separate operations, so a row whose SKU already exists is not imported (nor a row
 * without SKU that names an existing product, or repeats an earlier row), and a stock column is
 * shown but never imported. Missing values are left empty (never invented):
 * such a product is saved inactive and listed for completion. A missing SKU is generated.
 */
class InventoryRowRules extends RowRules
{
    protected const NUMERIC = ['cost_price', 'price', 'stock'];

    /** A product without these cannot be sold, so it stays inactive until they are filled in. */
    public const REQUIRED_TO_ACTIVATE = ['unit', 'price'];

    /** Fields tracked for completion when missing. */
    public const COMPLETION_FIELDS = ['unit', 'cost_price', 'price', 'category'];

    private const LABELS = ['sku' => 'SKU', 'name' => 'Product name', 'category' => 'Category', 'brand' => 'Brand', 'unit' => 'Unit', 'cost_price' => 'Cost price', 'price' => 'Selling price', 'stock' => 'Stock'];

    public function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    public function analyze(ImportBatch $batch, Collection $rows, bool $withMatching = false): void
    {
        $ocr = $batch->usedOcr();
        $products = Product::withTrashed()->where('team_id', $batch->team_id)->get(['id', 'sku', 'name', 'brand', 'deleted_at']);
        $existing = $products->keyBy(fn ($p) => mb_strtoupper($p->sku));
        $byName = $products->whereNull('deleted_at')->groupBy(fn ($p) => self::nameKey($p->name));
        $matcher = $withMatching ? new ProductMatcher($batch->team_id) : null;
        $seen = [];
        $seenNames = [];

        foreach ($rows->sortBy('row_number') as $row) {
            $issues = [];
            $values = [];
            $sources = array_intersect_key($row->sources ?? [], array_flip(['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price']));
            $hadOcrCorrection = false;

            foreach (['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price', 'stock'] as $f) {
                $r = $this->field($row, $f, $ocr);
                $values[$f] = $r['value'];
                $sources[$f] = $r['value'] === null && $r['source'] === 'document' ? null : $r['source'];
                if ($r['problem']) {
                    $issues[] = self::issue($f === 'stock' ? 'warning' : 'error', $f, "{$this->label($f)}: {$r['problem']}.");
                }
                if ($r['corrected_from'] !== null) {
                    $hadOcrCorrection = true;
                    $issues[] = self::issue('warning', $f, 'Check the '.mb_strtolower($this->label($f))." against the file: it was read as \"{$r['corrected_from']}\".");
                }
            }
            $values['sku'] = $values['sku'] !== null ? mb_strtoupper(mb_substr($values['sku'], 0, 100)) : null;
            $values['name'] = $values['name'] !== null ? mb_substr($values['name'], 0, 255) : null;
            $values['brand'] = $values['brand'] !== null ? mb_substr($values['brand'], 0, 100) : null;
            $values['unit'] = $values['unit'] !== null ? mb_substr($values['unit'], 0, 50) : null;

            if ($values['name'] === null) {
                $issues[] = self::issue('error', 'name', 'Product name is required.');
            }
            foreach (['cost_price', 'price'] as $f) {
                if ($values[$f] !== null && $values[$f] < 0) {
                    $issues[] = self::issue('error', $f, "{$this->label($f)} cannot be negative.");
                }
            }
            // Only what staff need to act on. A generated SKU, the ignored stock column and optional
            // empty fields are shown once by the preview, not repeated on every row.
            $missingRequired = array_values(array_filter(self::REQUIRED_TO_ACTIVATE, fn ($f) => $values[$f] === null));
            if ($missingRequired) {
                $issues[] = self::issue('warning', null, 'No '.implode(' or ', array_map(fn ($f) => mb_strtolower($this->label($f)), $missingRequired)).': saved as inactive until completed.');
            }
            if ($row->extracted['_total_line'] ?? false) {
                $issues[] = self::issue('info', null, 'Looks like a total line, so it was skipped.');
            }
            if (($row->extracted['_no_header'] ?? false) && ($sources['name'] ?? null) !== 'edited') {
                $issues[] = self::issue('error', 'name', 'No column headers were recognised. Choose the columns, or split this line into the right fields.');
            }

            // Without a SKU a new one would be generated, so a row naming an existing product (same
            // name, and the same brand or no brand on either side) would create a duplicate.
            $forced = null;
            $match = $values['sku'] !== null ? $existing->get($values['sku']) : null;
            $nameKey = $values['name'] !== null ? self::nameKey($values['name']) : '';
            $brandKey = self::brandKey($values['brand']);
            $sameName = $values['sku'] === null && $nameKey !== ''
                ? ($byName->get($nameKey) ?? collect())->first(fn ($p) => self::sameBrand(self::brandKey($p->brand), $brandKey))
                : null;
            $earlierRow = $values['sku'] === null && $nameKey !== ''
                ? collect($seenNames[$nameKey] ?? [])->first(fn ($s) => self::sameBrand($s['brand'], $brandKey))
                : null;
            if ($match) {
                $issues[] = self::issue('info', 'sku', "SKU {$values['sku']} is already used by \"{$match->name}\"".($match->deleted_at ? ' (archived)' : '').'.');
                $forced = ImportRow::EXISTING;
                $row->matched_product_id = $match->deleted_at ? null : $match->id;
            } elseif ($values['sku'] !== null && isset($seen[$values['sku']])) {
                $issues[] = self::issue('info', 'sku', "Same SKU as row {$seen[$values['sku']]}.");
                $forced = ImportRow::DUPLICATE;
            } elseif ($sameName) {
                $issues[] = self::issue('info', 'name', "A product named \"{$sameName->name}\" already exists (SKU {$sameName->sku}). Add a SKU if it is a different product.");
                $forced = ImportRow::EXISTING;
                $row->matched_product_id = $sameName->id;
            } elseif ($earlierRow) {
                $issues[] = self::issue('info', 'name', "Same product as row {$earlierRow['row']}.");
                $forced = ImportRow::DUPLICATE;
                $row->matched_product_id = null;
            } else {
                $row->matched_product_id = null;
                if ($values['sku'] !== null) {
                    $seen[$values['sku']] = $row->row_number;
                }
            }
            if ($forced === null && $nameKey !== '' && ! $row->skipped) {
                $seenNames[$nameKey][] = ['brand' => $brandKey, 'row' => $row->row_number];
            }

            if ($matcher && $values['name'] !== null && ! $match && ! $sameName) {
                $row->suggestions = array_values(array_filter($matcher->match(['description' => $values['name']])['suggestions'], fn ($s) => $s['level'] === 'high'));
            }
            if (! $match && ! $sameName && ! empty($row->suggestions)) {
                $issues[] = self::issue('warning', 'name', 'Similar to "'.$row->suggestions[0]['name'].'" ('.$row->suggestions[0]['sku'].'). Skip it if it is the same product.');
            }

            $sources['_confidence'] = $this->confidence($ocr, $row, $issues, $sources, $hadOcrCorrection);
            $row->values = $values;
            $row->sources = $sources;
            $row->issues = $issues;
            $row->status = $this->status($row, $issues, $forced);
        }
    }

    /** A product name for comparing: lowercase, single spaces, no punctuation at either end. */
    public static function nameKey(?string $name): string
    {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? (string) $name));

        return preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $n) ?? $n;
    }

    private static function brandKey(?string $brand): ?string
    {
        $b = self::nameKey($brand);

        return $b === '' ? null : $b;
    }

    /** The same brand, or a brand missing on either side. */
    private static function sameBrand(?string $a, ?string $b): bool
    {
        return $a === null || $b === null || $a === $b;
    }
}
