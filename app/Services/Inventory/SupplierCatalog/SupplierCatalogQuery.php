<?php

namespace App\Services\Inventory\SupplierCatalog;

use App\Models\SupplierCatalogPrice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries of the Supplier Product Catalog: one row per catalog product and supplier.
 * The lowest cost is calculated when reading (per product and pricing unit, since costs in
 * different units are not comparable); stored records are never changed.
 */
class SupplierCatalogQuery
{
    public const SORTS = ['product', 'price_asc', 'price_desc'];

    public const REGISTRATION = ['all', 'catalog', 'inventory'];

    /**
     * @param  array{search?: ?string, category?: ?string, supplier_id?: ?int, registration?: ?string, sort?: ?string}  $filters
     */
    public function paginate(int $teamId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $q = $this->base($teamId);

        foreach (preg_split('/\s+/u', trim((string) ($filters['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('scp.name', 'like', $like)->orWhere('scp.brand', 'like', $like)
                ->orWhere('scp.category', 'like', $like)->orWhere('suppliers.name', 'like', $like));
        }
        if (filled($filters['category'] ?? null)) {
            $q->where('scp.category', $filters['category']);
        }
        if (! empty($filters['supplier_id'])) {
            $q->where('suppliers.id', (int) $filters['supplier_id']);
        }
        match ($filters['registration'] ?? 'all') {
            'catalog' => $q->whereNull('products.id'),
            'inventory' => $q->whereNotNull('products.id'),
            default => null,
        };
        match ($filters['sort'] ?? 'product') {
            'price_asc' => $q->orderBy('scp_prices.unit_cost')->orderBy('scp.name'),
            'price_desc' => $q->orderByDesc('scp_prices.unit_cost')->orderBy('scp.name'),
            default => $q->orderBy('scp.name')->orderBy('scp_prices.pricing_unit')->orderBy('scp_prices.unit_cost'),
        };

        return $q->orderBy('scp_prices.id')->paginate($perPage)->through(fn ($r) => $this->row($r));
    }

    /**
     * Every supplier's catalog cost for one catalog product, lowest first.
     *
     * @return list<array>
     */
    public function forCatalogProduct(int $teamId, int $catalogProductId): array
    {
        return $this->base($teamId)->where('scp.id', $catalogProductId)
            ->orderBy('scp_prices.pricing_unit')->orderBy('scp_prices.unit_cost')->orderBy('suppliers.name')
            ->get()->map(fn ($r) => $this->row($r))->all();
    }

    /**
     * Catalog supplier options of registered inventory products, lowest first, keyed by product id.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array>>
     */
    public function forInventoryProducts(int $teamId, array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return $this->base($teamId)->whereIn('products.id', $productIds)
            ->orderBy('scp_prices.pricing_unit')->orderBy('scp_prices.unit_cost')->orderBy('suppliers.name')
            ->get()->map(fn ($r) => $this->row($r))->groupBy('product_id')->map->values()->all();
    }

    /** @return array{products: int, prices: int, suppliers: int, registered: int} */
    public function summary(int $teamId): array
    {
        $prices = $this->base($teamId);

        return [
            'products' => (clone $prices)->distinct()->count('scp.id'),
            'prices' => (clone $prices)->count(),
            'suppliers' => (clone $prices)->distinct()->count('suppliers.id'),
            'registered' => (clone $prices)->whereNotNull('products.id')->distinct()->count('scp.id'),
        ];
    }

    /** @return array{categories: list<string>, suppliers: list<array{id: int, name: string}>} */
    public function filterOptions(int $teamId): array
    {
        $base = $this->base($teamId);

        return [
            'categories' => (clone $base)->select('scp.category')->distinct()->orderBy('scp.category')->pluck('category')->all(),
            'suppliers' => (clone $base)->select('suppliers.id', 'suppliers.name')->distinct()->orderBy('suppliers.name')->get()
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
        ];
    }

    private function base(int $teamId): QueryBuilder
    {
        // Lowest cost and number of offers per catalog product and pricing unit (active supplier records only).
        $lowest = DB::table('supplier_catalog_prices as lp')
            ->join('suppliers as ls', 'ls.id', '=', 'lp.supplier_id')
            ->whereNull('ls.deleted_at')
            ->where('lp.team_id', $teamId)
            ->groupBy('lp.supplier_catalog_product_id', 'lp.pricing_unit')
            ->select('lp.supplier_catalog_product_id', 'lp.pricing_unit', DB::raw('MIN(lp.unit_cost) as lowest_cost'), DB::raw('COUNT(*) as offers'));

        return DB::table((new SupplierCatalogPrice)->getTable().' as scp_prices')
            ->join('supplier_catalog_products as scp', 'scp.id', '=', 'scp_prices.supplier_catalog_product_id')
            ->join('suppliers', 'suppliers.id', '=', 'scp_prices.supplier_id')
            ->leftJoin('products', fn ($j) => $j->on('products.id', '=', 'scp.product_id')->whereNull('products.deleted_at'))
            ->joinSub($lowest, 'lo', fn ($j) => $j->on('lo.supplier_catalog_product_id', '=', 'scp_prices.supplier_catalog_product_id')
                ->on('lo.pricing_unit', '=', 'scp_prices.pricing_unit'))
            ->where('scp_prices.team_id', $teamId)
            ->where('scp.team_id', $teamId)
            ->whereNull('suppliers.deleted_at')
            ->select([
                'scp_prices.id', 'scp_prices.unit_cost', 'scp_prices.currency', 'scp_prices.pricing_unit', 'scp_prices.pricing_unit_label',
                'scp_prices.unit_confirmed', 'scp_prices.is_simulated',
                'scp.id as catalog_product_id', 'scp.name as product_name', 'scp.category', 'scp.brand',
                'suppliers.id as supplier_id', 'suppliers.name as supplier_name', 'suppliers.status as supplier_status',
                'products.id as product_id', 'products.sku as product_sku', 'products.status as product_status',
                'lo.lowest_cost', 'lo.offers',
            ]);
    }

    private function row(object $r): array
    {
        $cost = (float) $r->unit_cost;
        $lowest = (float) $r->lowest_cost;

        return [
            'id' => (int) $r->id,
            'catalog_product_id' => (int) $r->catalog_product_id,
            'product_name' => $r->product_name,
            'category' => $r->category,
            'brand' => $r->brand,
            'supplier_id' => (int) $r->supplier_id,
            'supplier_name' => $r->supplier_name,
            'supplier_active' => $r->supplier_status === 'Active',
            'unit_cost' => $cost,
            'currency' => $r->currency,
            'pricing_unit' => $r->pricing_unit,
            'pricing_unit_label' => $r->pricing_unit_label,
            'unit_confirmed' => (bool) $r->unit_confirmed,
            'is_simulated' => (bool) $r->is_simulated,
            'lowest_cost' => $lowest,
            'is_lowest' => abs($cost - $lowest) < 0.005,
            'difference_from_lowest' => round($cost - $lowest, 2),
            'offers' => (int) $r->offers,
            'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
            'product_sku' => $r->product_sku,
            'product_status' => $r->product_status,
        ];
    }
}
