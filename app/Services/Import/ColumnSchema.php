<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Services\MaterialList\HeaderMapper;

/**
 * The columns each import understands, with the header wordings recognised for each
 * (normalised: lowercase, no punctuation or underscores, "Q'ty" → "qty"). A row counts as a
 * header only when it names the module's key column plus at least one other known column, most
 * of its cells are column labels and none is a number, so data rows, titles and supplier details
 * are never taken for headers. Every repeated header (next sheet, next PDF page) starts a new
 * table.
 */
class ColumnSchema
{
    public const FIELDS = [
        ImportBatch::INVENTORY => [
            'sku' => ['sku', 'code', 'item code', 'product code', 'part no', 'part number', 'catalog no', 'catalogue no', 'stock code', 'item no', 'mat code', 'material code', 'sku code', 'sku no'],
            'name' => [
                'product name', 'name', 'item name', 'material name', 'description', 'item description', 'product description',
                'material description', 'materials description', 'description of goods', 'description of items', 'item desc',
                'product desc', 'material desc', 'desc', 'particulars', 'article', 'product', 'item', 'items', 'products', 'material', 'materials',
            ],
            'category' => ['category', 'cat', 'group', 'product category', 'type', 'class', 'product type'],
            'brand' => ['brand', 'brand name', 'manufacturer', 'make', 'mfr', 'maker'],
            'unit' => ['unit', 'uom', 'unit of measure', 'unit of measurement', 'um', 'measure', 'units'],
            'cost_price' => ['cost price', 'cost', 'unit cost', 'purchase price', 'buying price', 'acquisition cost', 'cost per unit'],
            'price' => ['selling price', 'price', 'srp', 'retail price', 'unit price', 'sale price', 'sales price', 'price per unit'],
            'stock' => [
                'stock', 'stocks', 'qty', 'quantity', 'on hand', 'current stock', 'stock on hand', 'soh', 'qoh',
                'stock quantity', 'stock qty', 'available quantity', 'available qty', 'avail qty', 'available stock',
                'qty available', 'quantity available', 'current quantity', 'current qty', 'qty on hand', 'quantity on hand',
                'on hand qty', 'on hand quantity', 'stock level', 'stock count', 'inventory count', 'total qty', 'total quantity',
            ],
        ],
        ImportBatch::SUPPLIER_QUOTATION => [
            'description' => [
                'description', 'item description', 'product description', 'material description', 'materials description',
                'product name', 'material name', 'item name', 'item details', 'particulars', 'description of goods',
                'description of items', 'item desc', 'product desc', 'desc', 'item', 'items', 'product', 'products',
                'material', 'materials', 'name', 'article', 'goods',
            ],
            'item_code' => ['code', 'item code', 'sku', 'part no', 'part number', 'product code', 'model', 'model no', 'catalog no', 'catalogue no', 'ref', 'mat code'],
            'unit' => ['unit', 'uom', 'units', 'unit of measure', 'unit of measurement', 'um', 'measure'],
            'quantity' => ['qty', 'quantity', 'quantities', 'nos', 'order qty', 'qty ordered', 'quantity ordered', 'ordered qty', 'no of units', 'req qty', 'required qty', 'qty requested', 'requested qty', 'total qty', 'total quantity'],
            'unit_price' => ['unit price', 'price', 'rate', 'unit cost', 'cost', 'price per unit', 'u price', 'unit amount', 'unit rate', 'net price', 'quoted price'],
            'amount' => ['amount', 'total', 'total price', 'line total', 'total amount', 'ext price', 'extended price', 'extended amount', 'net amount', 'total cost'],
        ],
    ];

    /** Generic wordings, used only when no column names the field more specifically. */
    public const WEAK = [
        ImportBatch::INVENTORY => [
            'name' => ['product', 'item', 'items', 'products', 'material', 'materials', 'article', 'particulars'],
            'sku' => ['item no', 'code'],
            'category' => ['type', 'class', 'group'],
        ],
        ImportBatch::SUPPLIER_QUOTATION => [
            'description' => ['item', 'items', 'product', 'products', 'material', 'materials', 'name', 'article', 'goods'],
            'item_code' => ['ref', 'model'],
            'unit_price' => ['cost', 'rate'],
            'amount' => ['total'],
            'quantity' => ['nos'],
        ],
    ];

    /** A generic wording that loses its field to a more specific column names this field instead (never a SKU). */
    public const FALLBACK = [
        ImportBatch::SUPPLIER_QUOTATION => ['item' => 'item_code', 'items' => 'item_code'],
    ];

    /** The column a row must have for it to be a header. */
    public const KEY = [
        ImportBatch::INVENTORY => 'name',
        ImportBatch::SUPPLIER_QUOTATION => 'description',
    ];

    /** Field names as staff read them (also used by the column-mapping screen). */
    public const LABELS = [
        ImportBatch::INVENTORY => [
            'sku' => 'SKU', 'name' => 'Product name', 'category' => 'Category', 'brand' => 'Brand', 'unit' => 'Unit',
            'cost_price' => 'Cost price', 'price' => 'Selling price', 'stock' => 'Stock (shown only)',
        ],
        ImportBatch::SUPPLIER_QUOTATION => [
            'item_code' => 'Item code', 'description' => 'Description', 'unit' => 'Unit', 'quantity' => 'Quantity',
            'unit_price' => 'Unit price', 'amount' => 'Amount',
        ],
    ];

    /** Header wordings that name the key column, shown when it is missing. */
    public const KEY_EXAMPLES = [
        ImportBatch::INVENTORY => '"Product Name", "Description", "Item Name" or "Material"',
        ImportBatch::SUPPLIER_QUOTATION => '"Description", "Item Description", "Product Name" or "Material"',
    ];

    /** Header text with currency words and bracketed notes removed: "Unit Price (PHP)" → "unit price". */
    public static function normalise(mixed $header): string
    {
        $h = HeaderMapper::normalise(preg_replace('/\(.*?\)|\[.*?\]/u', ' ', (string) $header) ?? (string) $header);
        $h = preg_replace('/\b(php|usd|peso|pesos|in|vat inc|vat incl|vat ex)\b/u', ' ', $h) ?? $h;

        return trim(preg_replace('/\s+/', ' ', $h) ?? $h);
    }

    public static function fieldFor(string $module, mixed $header): ?string
    {
        return self::rankedField($module, $header)[0] ?? null;
    }

    /** The field a cell names with an exact wording only ("Product Name", not "Product Manual"). */
    public static function exactFieldFor(string $module, mixed $cell): ?string
    {
        $found = self::rankedField($module, $cell);

        return $found !== null && $found[2] ? $found[0] : null;
    }

    /** @return array{0: string, 1: int, 2: bool, 3: string}|null field, rank (0 specific, 1 generic), exact wording, wording */
    public static function rankedField(string $module, mixed $header): ?array
    {
        return HeaderMapper::resolve(self::normalise($header), self::FIELDS[$module], self::WEAK[$module] ?? [], HeaderMapper::NEUTRAL);
    }

    /**
     * Column index per field for a header row, or null when the row is not a header.
     * $wordLevel: the cells are single OCR / PDF words (see HeaderMapper::scan()).
     *
     * @param  array<int, string>  $cells
     * @return array<string, int>|null
     */
    public static function headerMap(string $module, array $cells, bool $wordLevel = false): ?array
    {
        $scan = HeaderMapper::scan($cells, fn (string $cell) => self::rankedField($module, $cell), $wordLevel, self::FALLBACK[$module] ?? []);

        return $scan['header'] && isset($scan['mapping'][self::KEY[$module]]) ? $scan['mapping'] : null;
    }

    /**
     * The fields a row names, header or not (for choosing columns by hand).
     *
     * @param  array<int, string>  $cells
     * @return array<string, int>
     */
    public static function guessMap(string $module, array $cells): array
    {
        return HeaderMapper::scan($cells, fn (string $cell) => self::rankedField($module, $cell), false, self::FALLBACK[$module] ?? [])['mapping'];
    }

    /**
     * Staff-facing explanation when the key column is missing, naming the columns found.
     *
     * @param  array<int, string>  $columns
     */
    public static function describeMissingKey(string $module, array $columns = []): string
    {
        $label = mb_strtolower(self::LABELS[$module][self::KEY[$module]]);
        $found = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $columns), fn ($c) => $c !== ''));
        $seen = $found !== []
            ? ' Columns found: '.implode(', ', array_map(fn ($c) => '"'.mb_substr($c, 0, 40).'"', array_slice($found, 0, 12))).'.'
            : ' No column headers were recognised in the file.';

        return "No {$label} column was found.{$seen} Choose the columns below, or add a column headed ".self::KEY_EXAMPLES[$module].' to the file.';
    }

    /**
     * True when a row consists purely of column header names or standalone field labels
     * (so header rows or field labels are never mistaken for actual record values).
     *
     * @param  array<int, string>  $cells
     */
    public static function isHeaderOrLabelRow(string $module, array $cells): bool
    {
        if (self::headerMap($module, $cells) !== null) {
            return true;
        }

        $nonEmpty = array_values(array_filter(array_map('trim', $cells), fn ($c) => $c !== ''));
        if ($nonEmpty === []) {
            return false;
        }

        // A single cell that is just a standalone field label (e.g. "Product Name:", "Supplier:", "Date:")
        if (count($nonEmpty) === 1) {
            return (bool) preg_match('/^(product\s+name|item\s+description|description|particulars|quantity|qty|unit\s+price|selling\s+price|cost\s+price|sku|item\s+code|category|brand|unit|uom|amount|total|supplier|supplier\s+name|client\s+name|customer\s+name|date|quotation\s+date|invoice\s+no|quotation\s+no)\s*:?\s*$/iu', $nonEmpty[0]);
        }

        // Multiple cells where every cell is a known column header or table index column
        $metaHeaders = ['no', 's n', 'sn', 'sr no', 'line', 'sl no', 'date', 'supplier', 'status', 'reorder', 'reorder level', 'reorder quantity', 'action', 'actions'];
        $matchedFields = 0;
        foreach ($nonEmpty as $cell) {
            $norm = self::normalise($cell);
            if (self::exactFieldFor($module, $cell) !== null || HeaderMapper::exactFieldFor($cell) !== null) {
                $matchedFields++;
            } elseif (! in_array($norm, $metaHeaders, true)) {
                return false;
            }
        }

        return $matchedFields >= 1;
    }
}
