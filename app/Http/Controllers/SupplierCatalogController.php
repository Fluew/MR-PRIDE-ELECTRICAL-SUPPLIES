<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SupplierCatalogProduct;
use App\Models\Team;
use App\Services\Inventory\SkuGenerator;
use App\Services\Inventory\SupplierCatalog\SupplierCatalogQuery;
use App\Services\Inventory\SupplierCatalog\SupplierCatalogRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Supplier Product Catalog (Inventory module): products offered by suppliers with each
 * supplier's USD purchase cost, before (and after) registration in inventory. Viewing and
 * comparing change nothing; registering creates or reuses the inventory product only.
 */
class SupplierCatalogController extends Controller
{
    public function __construct(private SupplierCatalogQuery $catalog) {}

    public function index(Request $request): InertiaResponse
    {
        $team = $this->team($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'registration' => ['nullable', Rule::in(SupplierCatalogQuery::REGISTRATION)],
            'sort' => ['nullable', Rule::in(SupplierCatalogQuery::SORTS)],
        ]);

        return Inertia::render('Inventory/SupplierCatalog', [
            'rows' => $this->catalog->paginate($team->id, $filters),
            'summary' => $this->catalog->summary($team->id),
            'options' => $this->catalog->filterOptions($team->id),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category' => $filters['category'] ?? '',
                'supplier_id' => isset($filters['supplier_id']) ? (string) $filters['supplier_id'] : '',
                'registration' => $filters['registration'] ?? 'all',
                'sort' => $filters['sort'] ?? 'product',
            ],
            'unitOptions' => Product::where('team_id', $team->id)->whereNotNull('unit')->distinct()->orderBy('unit')->pluck('unit')
                ->map(fn ($u) => mb_strtolower(trim($u)))->merge(['pcs', 'set', 'pack', 'm', 'spool'])->unique()->sort()->values(),
        ]);
    }

    /** All suppliers of one catalog product with their costs (for the compare / register panel). */
    public function show(Request $request): JsonResponse
    {
        $team = $this->team($request);
        $catalogProduct = $this->catalogProduct($request, $team);
        $product = $catalogProduct->product_id ? Product::where('team_id', $team->id)->find($catalogProduct->product_id, ['id', 'sku', 'name', 'unit', 'stock', 'status', 'is_active']) : null;

        return response()->json([
            'catalog_product' => $catalogProduct->only(['id', 'name', 'category', 'brand', 'registered_at']),
            'inventory_product' => $product,
            'prices' => $this->catalog->forCatalogProduct($team->id, $catalogProduct->id),
            'next_sku' => $product ? null : app(SkuGenerator::class)->peek($team->id, $catalogProduct->category),
        ]);
    }

    /** Register the catalog product in inventory (or link it to the existing inventory product). */
    public function register(Request $request, SupplierCatalogRegistrar $registrar): RedirectResponse
    {
        $team = $this->team($request);
        $catalogProduct = $this->catalogProduct($request, $team);
        $data = $request->validate([
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['unit.required' => 'Confirm the unit of measure the product is stocked in.']);

        return $this->runWorkflow(function () use ($registrar, $catalogProduct, $request, $data) {
            $result = $registrar->register($catalogProduct, $request->user(), $data);
            $p = $result['product'];
            $state = $p->is_active ? 'stock 0' : 'Incomplete until a selling price is entered';

            return ($result['created']
                ? "Registered {$p->name} as {$p->sku} ({$state}). No stock, purchase order or stock movement was created."
                : "{$p->name} is already in inventory as {$p->sku}: linked to the catalog; its stock and reorder values were not changed.")
                ." {$result['suppliers_linked']} supplier link(s) and {$result['quotes_recorded']} unverified catalog cost(s) recorded.";
        });
    }

    /** Catalog supplier options of registered products, for purchase requests and draft purchase orders. */
    public function procurementOptions(Request $request): JsonResponse
    {
        $team = $this->team($request);
        $ids = $request->validate([
            'product_ids' => ['required', 'array', 'max:50'],
            'product_ids.*' => ['integer'],
        ])['product_ids'];

        return response()->json(['options' => (object) $this->catalog->forInventoryProducts($team->id, array_map('intval', $ids))]);
    }

    private function team(Request $request): Team
    {
        $team = $request->user()?->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }

    private function catalogProduct(Request $request, Team $team): SupplierCatalogProduct
    {
        $id = $request->route('catalogProduct');
        abort_unless(is_numeric($id), 404);

        return SupplierCatalogProduct::where('team_id', $team->id)->findOrFail((int) $id);
    }
}
