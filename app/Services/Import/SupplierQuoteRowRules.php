<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\SupplierQuote;
use App\Services\MaterialList\ProductMatcher;
use Illuminate\Support\Collection;

/**
 * Supplier quotation import: each line becomes a supplier quote for an inventory product.
 * Inventory prices are never touched; quotes stay unverified until an administrator verifies
 * them (only verified quotes count in Supplier Evaluation).
 *
 * Missing values: Amount = Quantity × Unit Price, and Unit Price = Amount ÷ Quantity (Quantity
 * > 0), both marked "calculated". A line whose figures disagree, or with no readable price,
 * no unit, or no matched product, must be fixed or skipped.
 */
class SupplierQuoteRowRules extends RowRules
{
    protected const NUMERIC = ['quantity', 'unit_price', 'amount'];

    private const LABELS = ['description' => 'Description', 'item_code' => 'Item code', 'unit' => 'Unit', 'quantity' => 'Quantity', 'unit_price' => 'Unit price', 'amount' => 'Amount'];

    /** Relative difference allowed between Quantity × Unit Price and Amount (rounding on the document). */
    public const TOLERANCE = 0.01;

    public function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    public function analyze(ImportBatch $batch, Collection $rows, bool $withMatching = false): void
    {
        $ocr = $batch->usedOcr();
        $meta = $batch->meta ?? [];
        $matcher = $withMatching ? new ProductMatcher($batch->team_id) : null;
        $products = Product::where('team_id', $batch->team_id)->whereIn('id', $rows->pluck('matched_product_id')->filter()->unique())->get(['id', 'name', 'sku', 'unit'])->keyBy('id');
        $existing = SupplierQuote::where('team_id', $batch->team_id)->where('supplier_id', $meta['supplier_id'] ?? 0)
            ->whereDate('quoted_on', $meta['quoted_on'] ?? '1900-01-01')->where('currency', $meta['currency'] ?? 'USD')
            ->where('tax_inclusive', (bool) ($meta['tax_inclusive'] ?? false))->get(['product_id', 'unit'])
            ->map(fn ($q) => $q->product_id.'|'.mb_strtolower($q->unit))->flip();
        $seen = [];

        foreach ($rows->sortBy('row_number') as $row) {
            $issues = [];
            $values = [];
            $sources = [];
            $hadOcrCorrection = false;
            foreach (['description', 'item_code', 'unit', 'quantity', 'unit_price', 'amount'] as $f) {
                $r = $this->field($row, $f, $ocr);
                $values[$f] = $r['value'];
                $sources[$f] = $r['value'] === null && $r['source'] === 'document' ? null : $r['source'];
                if ($r['problem']) {
                    $issues[] = self::issue('error', $f, "{$this->label($f)}: {$r['problem']}.");
                }
                if ($r['corrected_from'] !== null) {
                    $hadOcrCorrection = true;
                    $issues[] = self::issue('warning', $f, "{$this->label($f)} read by OCR as \"{$r['corrected_from']}\" and taken as {$r['value']}. Check it against the original.");
                }
            }

            [$qty, $price, $amount] = [$values['quantity'], $values['unit_price'], $values['amount']];
            if ($amount === null && $qty !== null && $price !== null) {
                $values['amount'] = round($qty * $price, 2);
                $sources['amount'] = 'calculated';
                $issues[] = self::issue('info', 'amount', 'Amount calculated as quantity × unit price.');
            } elseif ($price === null && $amount !== null && $qty !== null && $qty > 0) {
                $values['unit_price'] = round($amount / $qty, 4);
                $sources['unit_price'] = 'calculated';
                $issues[] = self::issue('info', 'unit_price', 'Unit price calculated as amount ÷ quantity (implied price).');
            } elseif ($qty !== null && $price !== null && $amount !== null) {
                $expected = round($qty * $price, 2);
                if (abs($expected - $amount) > max(0.01, self::TOLERANCE * abs($amount))) {
                    $issues[] = self::issue('error', 'amount', "Quantity × unit price is {$expected}, but the amount says {$amount}. Correct the value that was misread.");
                }
            }

            if ($values['description'] === null) {
                $issues[] = self::issue('error', 'description', 'Description is missing or unreadable.');
            }
            if ($values['unit_price'] === null) {
                $issues[] = self::issue('error', 'unit_price', 'No unit price, and it cannot be calculated (needs amount and quantity).');
            } elseif ($values['unit_price'] <= 0) {
                $issues[] = self::issue('error', 'unit_price', 'Unit price must be more than zero.');
            }
            if ($values['quantity'] !== null && $values['quantity'] <= 0) {
                $issues[] = self::issue('warning', 'quantity', 'Quantity is zero or negative.');
            }
            if ($values['unit'] === null) {
                $issues[] = self::issue('error', 'unit', 'Unit is missing: prices are compared only for the same unit.');
            }
            if ($row->extracted['_total_line'] ?? false) {
                $issues[] = self::issue('info', null, 'Looks like a total or tax line, so it was skipped. Restore it if it is an item.');
            }
            if ($row->extracted['_no_header'] ?? false) {
                $issues[] = self::issue('warning', null, 'No column headers were recognised: the whole line was put in the description. Fill in the unit, quantity and price.');
            }

            // Product matching: an exact product code is pre-selected; anything else needs staff to choose.
            if ($matcher && ($row->sources['product'] ?? null) !== 'edited') {
                $m = $matcher->match(['item_code' => $values['item_code'], 'description' => $values['description']]);
                $row->suggestions = $m['suggestions'];
                $row->matched_product_id = $m['match']['product_id'] ?? null;
                if ($row->matched_product_id && ! $products->has($row->matched_product_id)) {
                    $products->put($row->matched_product_id, Product::find($row->matched_product_id, ['id', 'name', 'sku', 'unit']));
                }
            }
            $sources['product'] = $row->sources['product'] ?? ($row->matched_product_id ? 'matched' : null);

            $forced = null;
            if (! $row->matched_product_id) {
                $issues[] = self::issue('error', 'product', 'Choose the inventory product this line is for, or skip the line.');
            } else {
                $product = $products->get($row->matched_product_id);
                if ($product && $values['unit'] !== null && $product->unit && mb_strtolower($product->unit) !== mb_strtolower($values['unit'])) {
                    $issues[] = self::issue('warning', 'unit', "Quoted per {$values['unit']}, but the product is stocked per {$product->unit}. It is compared only with quotes in the same unit.");
                }
                $key = $row->matched_product_id.'|'.mb_strtolower((string) $values['unit']);
                if (isset($existing[$key])) {
                    $issues[] = self::issue('error', 'product', 'A quote from this supplier for this product, unit and date is already recorded.');
                } elseif (isset($seen[$key])) {
                    $issues[] = self::issue('info', 'product', "Same product and unit as row {$seen[$key]} of this document.");
                    $forced = ImportRow::DUPLICATE;
                } else {
                    $seen[$key] = $row->row_number;
                }
            }

            $sources['_confidence'] = $this->confidence($ocr, $row, $issues, $sources, $hadOcrCorrection);
            $row->values = $values;
            $row->sources = $sources;
            $row->issues = $issues;
            $row->status = $this->status($row, $issues, $forced);
        }
    }
}
