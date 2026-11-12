<?php

namespace App\Services\Inventory\SupplierCatalog;

use App\Exceptions\WorkflowException;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\SupplierCatalogPrice;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierQuote;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Import\InventoryRowRules;
use App\Services\Inventory\SkuGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Registers a Supplier Product Catalog product in inventory.
 *
 *  - An inventory product already linked, or with the same description, is reused (never duplicated).
 *  - Otherwise a product is created like a product created from a purchase order line
 *    (InboundReceiving): SKU from the category sequence (SkuGenerator), stock 0, no stock movement.
 *    Without a selling price it is saved Incomplete (not sellable) until one is entered.
 *  - Reorder level / quantity are only what staff enter (0 = not set); none are generated.
 *  - Each supplier's catalog cost is kept in the existing structures as UNVERIFIED records:
 *    product_supplier (supplier offers the product) and supplier_quotes (USD unit price). Unverified
 *    records are not counted by the AHP evaluations and do not pre-fill purchase orders until staff
 *    verify them in Suppliers. Existing records are never changed.
 *  - No purchase order, sales order, delivery or stock movement is created.
 */
class SupplierCatalogRegistrar
{
    public const QUOTE_REFERENCE = 'SUPPLIER-CATALOG';

    public function __construct(private SkuGenerator $skus) {}

    /**
     * @param  array{unit: string, price?: float|string|null, cost_price?: float|string|null, reorder_level?: int|null, reorder_quantity?: int|null, supplier_id?: int|null, description?: string|null}  $data
     * @return array{product: Product, created: bool, suppliers_linked: int, quotes_recorded: int, quotes_kept: list<string>}
     */
    public function register(SupplierCatalogProduct $catalog, User $user, array $data): array
    {
        return DB::transaction(function () use ($catalog, $user, $data) {
            $catalog = SupplierCatalogProduct::whereKey($catalog->id)->lockForUpdate()->firstOrFail();
            $prices = SupplierCatalogPrice::where('supplier_catalog_product_id', $catalog->id)
                ->whereHas('supplier') // archived suppliers are left out
                ->with('supplier:id,name,status')->orderBy('unit_cost')->get();

            if (! empty($data['supplier_id']) && ! $prices->contains('supplier_id', (int) $data['supplier_id'])) {
                throw new WorkflowException('Choose the usual supplier from the suppliers offering this product.', 'supplier_id');
            }

            $product = $catalog->product_id ? Product::where('team_id', $catalog->team_id)->find($catalog->product_id) : null;
            $product ??= $this->sameDescription($catalog);
            $created = false;
            if (! $product) {
                $product = $this->createProduct($catalog, $data);
                $created = true;
            }

            [$linked, $recorded, $kept] = $this->keepSupplierCosts($catalog, $product, $prices, $user);

            $catalog->update([
                'product_id' => $product->id,
                'registered_at' => $catalog->registered_at ?? now(),
                'registered_by_id' => $catalog->registered_by_id ?? $user->id,
            ]);

            AuditLogger::record(
                $created ? 'product.created' : 'product.catalog_linked',
                $created
                    ? "Registered {$product->name} ({$product->sku}) from the Supplier Product Catalog with stock 0; {$linked} supplier link(s) and {$recorded} unverified catalog cost(s) recorded."
                    : "Linked Supplier Product Catalog item to existing product {$product->name} ({$product->sku}); {$linked} supplier link(s) and {$recorded} unverified catalog cost(s) recorded. Stock and reorder values unchanged.",
                $product,
                ['sku' => $product->sku, 'catalog_product_id' => $catalog->id, 'stock' => (int) $product->stock, 'suppliers_linked' => $linked, 'quotes_recorded' => $recorded],
                $user,
            );

            return ['product' => $product, 'created' => $created, 'suppliers_linked' => $linked, 'quotes_recorded' => $recorded, 'quotes_kept' => $kept];
        });
    }

    /** The single inventory product with the same description, if exactly one exists. */
    private function sameDescription(SupplierCatalogProduct $catalog): ?Product
    {
        $matches = Product::where('team_id', $catalog->team_id)->get(['id', 'name'])
            ->filter(fn ($p) => SupplierCatalogNormalizer::productKey($p->name) === $catalog->normalized_name);

        return $matches->count() === 1 ? Product::find($matches->first()->id) : null;
    }

    private function createProduct(SupplierCatalogProduct $catalog, array $data): Product
    {
        $price = isset($data['price']) && $data['price'] !== '' ? round((float) $data['price'], 2) : null;
        $cost = isset($data['cost_price']) && $data['cost_price'] !== '' ? round((float) $data['cost_price'], 2) : null;
        $unit = mb_strtolower(trim((string) $data['unit']));
        $reorderLevel = max(0, (int) ($data['reorder_level'] ?? 0));

        $values = ['unit' => $unit, 'cost_price' => $cost, 'price' => $price, 'category' => $catalog->category];
        $missing = array_values(array_filter(InventoryRowRules::COMPLETION_FIELDS, fn ($f) => $values[$f] === null || $values[$f] === ''));
        $active = ! array_intersect($missing, InventoryRowRules::REQUIRED_TO_ACTIVATE);

        $product = Product::create([
            'team_id' => $catalog->team_id,
            'sku' => $this->skus->next($catalog->team_id, $catalog->category),
            'name' => $catalog->name,
            'category' => $catalog->category,
            'brand' => $catalog->brand,
            'unit' => $unit,
            'price' => $price,
            'cost_price' => $cost,
            'stock' => 0,
            'min_stock' => $reorderLevel,
            'reorder_level' => $reorderLevel,
            'reorder_quantity' => max(0, (int) ($data['reorder_quantity'] ?? 0)),
            'supplier_id' => $data['supplier_id'] ?? null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'is_active' => $active,
            'missing_fields' => $missing ?: null,
        ]);
        // Same status rule as a product added on the Inventory page: the stock level (0 → Out of
        // Stock). Without a selling price it stays inactive and is listed under "Incomplete".
        $product->forceFill(['status' => 'Out of Stock'])->save();
        Cache::forget("team:{$catalog->team_id}:categories");

        return $product;
    }

    /**
     * Unverified supplier links and catalog costs for the inventory product. Only adds records:
     * a link or a quote already recorded (verified or not) is left exactly as it is.
     *
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function keepSupplierCosts(SupplierCatalogProduct $catalog, Product $product, $prices, User $user): array
    {
        $linked = 0;
        $recorded = 0;
        $kept = [];
        foreach ($prices as $price) {
            $link = ProductSupplier::firstOrCreate(
                ['product_id' => $product->id, 'supplier_id' => $price->supplier_id],
                ['team_id' => $product->team_id, 'is_available' => true, 'recorded_by_id' => $user->id,
                    'notes' => 'From the Supplier Product Catalog (simulated staging data). Verify with the supplier.'],
            );
            $linked += $link->wasRecentlyCreated ? 1 : 0;

            // A unit the source asks to confirm is recorded in the unit staff confirmed for the product.
            $unit = $price->unit_confirmed ? $price->pricing_unit : ($product->unit ?: $price->pricing_unit);
            $quotedOn = $price->created_at->toDateString();
            $existing = SupplierQuote::where('supplier_id', $price->supplier_id)->where('product_id', $product->id)
                ->whereDate('quoted_on', $quotedOn)->where('unit', $unit)->where('currency', 'USD')->where('tax_inclusive', false)->first();
            if ($existing) {
                if ((float) $existing->unit_price != (float) $price->unit_cost) {
                    $kept[] = "{$price->supplier->name}: existing quote ".number_format((float) $existing->unit_price, 2).' kept (catalog '.number_format((float) $price->unit_cost, 2).').';
                }

                continue;
            }
            SupplierQuote::create([
                'team_id' => $product->team_id,
                'supplier_id' => $price->supplier_id,
                'product_id' => $product->id,
                'reference' => self::QUOTE_REFERENCE,
                'unit_price' => $price->unit_cost,
                'currency' => 'USD',
                'unit' => $unit,
                'tax_inclusive' => false,
                'quoted_on' => $quotedOn,
                'notes' => 'Simulated staging cost from the Supplier Product Catalog'.($price->source_file ? " ({$price->source_file})" : '').'; not a verified supplier quotation. Verify before use.',
                'recorded_by_id' => $user->id,
                'description_as_quoted' => $catalog->name,
            ]);
            $recorded++;
        }

        return [$linked, $recorded, $kept];
    }
}
