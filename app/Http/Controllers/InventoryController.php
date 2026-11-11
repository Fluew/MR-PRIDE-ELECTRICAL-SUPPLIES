<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryExporter;
use App\Services\Inventory\SkuGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InventoryController extends Controller
{
    private const THRESHOLD_SQL = 'COALESCE(NULLIF(reorder_level, 0), NULLIF(min_stock, 0), 10)';

    /**
     * Active products for the inventory list, with the page's search / category / status filters.
     *
     * Sorted by stock availability so urgent items come first: out of stock, then low stock
     * (0 < stock <= threshold), then in stock; alphabetically within each group.
     */
    private function productListQuery(int $teamId, ?string $search, ?string $category, ?string $status): Builder
    {
        $thresholdSql = self::THRESHOLD_SQL;

        // "Incomplete": products with missing values (imported, or created by an inbound delivery
        // without a selling price). They are not sellable until completed, but they are real stock,
        // so the normal list shows them too. Products an admin deactivated stay hidden.
        $query = Product::where('team_id', $teamId)
            ->when($status === 'Incomplete',
                fn ($q) => $q->where('is_active', false)->whereNotNull('missing_fields'),
                fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->orWhereNotNull('missing_fields')))
            ->with('supplier:id,name');
        if ($status === 'Incomplete') {
            $status = null;
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($category && $category !== 'All Categories') {
            $query->where('category', $category);
        }

        if ($status && $status !== 'All') {
            if ($status === 'Out of Stock') {
                $query->where('stock', '<=', 0);
            } elseif ($status === 'Low Stock') {
                $query->where('stock', '>', 0)->whereRaw("stock <= {$thresholdSql}");
            } elseif ($status === 'In Stock') {
                $query->whereRaw("stock > {$thresholdSql}");
            } elseif ($status === 'Needs Reorder') {
                $query->whereRaw("stock <= {$thresholdSql}");
            } else {
                $query->where('status', $status);
            }
        }

        return $query->orderByRaw("
            CASE
                WHEN stock <= 0 THEN 1
                WHEN stock <= {$thresholdSql} THEN 2
                ELSE 3
            END ASC
        ")->orderBy('name', 'asc');
    }

    /**
     * Display a listing of inventory products with metrics and filters.
     */
    public function index(Request $request): InertiaResponse
    {
        $team = $request->user()->currentTeam;
        if (! $team) {
            abort(403, 'No active team selected.');
        }

        $search = $request->input('search');
        $category = $request->input('category', 'All Categories');
        $status = $request->input('status', 'All');

        $thresholdSql = self::THRESHOLD_SQL;

        $products = $this->productListQuery($team->id, $search, $category, $status)
            ->paginate(10)
            ->withQueryString();

        // Calculate inventory summary counts in a single aggregate query (same products as the list)
        $counts = Product::where('team_id', $team->id)
            ->where(fn ($w) => $w->where('is_active', true)->orWhereNotNull('missing_fields'))
            ->selectRaw("
                COUNT(*) as total_items,
                SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN stock > 0 AND stock <= {$thresholdSql} THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN stock > {$thresholdSql} THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN stock <= {$thresholdSql} THEN 1 ELSE 0 END) as requiring_reorder
            ")
            ->first();

        $totalItems = (int) ($counts->total_items ?? 0);
        $outOfStockCount = (int) ($counts->out_of_stock ?? 0);
        $lowStockCount = (int) ($counts->low_stock ?? 0);
        $inStockCount = (int) ($counts->in_stock ?? 0);
        $requiringReorderCount = (int) ($counts->requiring_reorder ?? 0);

        // Categories list (cached for 5 minutes per team)
        $categories = Cache::remember("team:{$team->id}:categories", 300, function () use ($team) {
            $cats = Product::where('team_id', $team->id)
                ->distinct()
                ->pluck('category')
                ->filter()
                ->values()
                ->toArray();

            return empty($cats) ? ['Breakers', 'Wiring', 'Lighting', 'Controls', 'Tools', 'General'] : $cats;
        });

        // Suppliers list (cached for 5 minutes per team). Cached as plain arrays: the cache does
        // not unserialize objects (cache.serializable_classes), so a cached Collection comes
        // back as __PHP_Incomplete_Class and reaches the page as {} instead of a list.
        $suppliers = Cache::remember("team:{$team->id}:suppliers_list_v2", 300, function () use ($team) {
            return Supplier::where('team_id', $team->id)
                ->where('status', 'Active')
                ->select('id', 'name', 'contact_person', 'phone')
                ->orderBy('name')
                ->get()
                ->toArray();
        });

        // Recent stock movements for audit (filtered by search, category, and status when supplied)
        $movementsQuery = StockMovement::where('team_id', $team->id)
            ->with(['product:id,name,sku,category,status,stock,reorder_level,min_stock', 'user:id,name', 'supplier:id,name'])
            ->latest('movement_date');

        if ($search) {
            $movementsQuery->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
            });
        }

        if ($category && $category !== 'All Categories') {
            $movementsQuery->whereHas('product', function ($q) use ($category) {
                $q->where('category', $category);
            });
        }

        if ($status && $status !== 'All') {
            $movementsQuery->whereHas('product', function ($q) use ($status, $thresholdSql) {
                if ($status === 'Out of Stock') {
                    $q->where('stock', '<=', 0);
                } elseif ($status === 'Low Stock') {
                    $q->where('stock', '>', 0)->whereRaw("stock <= {$thresholdSql}");
                } elseif ($status === 'In Stock') {
                    $q->whereRaw("stock > {$thresholdSql}");
                } elseif ($status === 'Needs Reorder') {
                    $q->whereRaw("stock <= {$thresholdSql}");
                } else {
                    $q->where('status', $status);
                }
            });
        }

        $recentMovements = $movementsQuery->take(50)->get();

        return Inertia::render('Inventory/Index', [
            'products' => $products,
            'summary' => [
                'total' => $totalItems,
                'in_stock' => $inStockCount,
                'low_stock' => $lowStockCount,
                'out_of_stock' => $outOfStockCount,
                'requiring_reorder' => $requiringReorderCount,
                'incomplete' => Product::where('team_id', $team->id)->where('is_active', false)->whereNotNull('missing_fields')->count(),
            ],
            'categories' => array_values(array_unique(array_merge(['Breakers', 'Wiring', 'Lighting', 'Controls', 'Tools', 'General'], $categories))),
            'suppliers' => $suppliers,
            'recent_movements' => $recentMovements,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'status' => $status,
                // Product Catalog ('table') or Product Movement ('history'). AHP Product Prioritization
                // moved to Reports and Analytics; an old ?view=prioritization link opens the catalog.
                'view' => $request->input('view') === 'history' ? 'history' : 'table',
            ],
        ]);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        // The SKU is not taken from the form: it is generated from the category when saving.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
        ]);

        $reorderLevel = (int) ($validated['reorder_level'] ?? 10);
        $reorderQty = (int) ($validated['reorder_quantity'] ?? 50);
        $stock = (int) $validated['stock'];

        $status = 'In Stock';
        if ($stock <= 0) {
            $status = 'Out of Stock';
        } elseif ($stock <= $reorderLevel) {
            $status = 'Low Stock';
        }

        $product = DB::transaction(function () use ($team, $request, $validated, $reorderLevel, $reorderQty, $stock, $status) {
            $product = Product::create([
                'team_id' => $team->id,
                'sku' => app(SkuGenerator::class)->next($team->id, $validated['category']),
                'name' => trim($validated['name']),
                'category' => $validated['category'],
                'brand' => filled($validated['brand'] ?? null) ? trim($validated['brand']) : null,
                'unit' => $validated['unit'] ?? 'pcs',
                'price' => $validated['price'],
                'cost_price' => $validated['cost_price'] ?? 0.00,
                'stock' => $stock,
                'min_stock' => $reorderLevel,
                'reorder_level' => $reorderLevel,
                'reorder_quantity' => $reorderQty,
                'status' => $status,
                'description' => $validated['description'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'is_active' => true,
            ]);

            // If initial stock is greater than 0, create initial movement log
            if ($stock > 0) {
                StockMovement::create([
                    'team_id' => $team->id,
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'supplier_id' => $product->supplier_id,
                    'type' => 'stock_in',
                    'quantity' => $stock,
                    'previous_stock' => 0,
                    'new_stock' => $stock,
                    'reference_number' => 'INIT-'.$product->sku,
                    'reason' => 'Initial Stock Entry',
                    'remarks' => 'Initial stock registered upon product creation.',
                    'movement_date' => now(),
                ]);
            }

            AuditLogger::record(
                'product.created',
                "Created product {$product->name} ({$product->sku}) with initial stock of {$stock}.",
                $product,
                ['sku' => $product->sku, 'name' => $product->name, 'stock' => $stock],
                $request->user()
            );

            return $product;
        });

        return back()->with('success', "Product '{$product->name}' created with SKU {$product->sku}.");
    }

    /** The SKU the next product of a category would get, for the product form (reserved only when saving). */
    public function nextSku(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);
        $category = (string) $request->validate(['category' => ['required', 'string', 'max:100']])['category'];

        return response()->json(['sku' => app(SkuGenerator::class)->peek($team->id, $category)]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, mixed $current_team = null, mixed $product = null): RedirectResponse
    {
        $product = $this->resolveProduct($product, $current_team, $request);
        $team = $request->user()->currentTeam;
        if (! $team || $product->team_id !== $team->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // No SKU: it stays as created, also when the category changes.
            'category' => ['required', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
        ]);

        $reorderLevel = (int) ($validated['reorder_level'] ?? 10);
        $reorderQty = (int) ($validated['reorder_quantity'] ?? 50);

        $product->update([
            'name' => trim($validated['name']),
            'category' => $validated['category'],
            'brand' => filled($validated['brand'] ?? null) ? trim($validated['brand']) : null,
            // An imported product with no unit keeps it empty until staff enter one (never assumed).
            'unit' => $validated['unit'] ?? $product->unit ?? ($product->missing_fields !== null ? null : 'pcs'),
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? $product->cost_price,
            'min_stock' => $reorderLevel,
            'reorder_level' => $reorderLevel,
            'reorder_quantity' => $reorderQty,
            'description' => $validated['description'] ?? null,
            'supplier_id' => $validated['supplier_id'] ?? null,
        ]);

        $wasIncomplete = ! $product->is_active && $product->missing_fields !== null;
        $product->updateCalculatedStatus();
        $product->refreshCompleteness();
        if ($wasIncomplete && $product->is_active) {
            AuditLogger::record('product.completed', "Imported product {$product->sku} completed and activated.", $product, ['sku' => $product->sku], $request->user());

            return back()->with('success', "Product '{$product->name}' completed and is now active.");
        }

        AuditLogger::record(
            'product.updated',
            "Updated product {$product->name} ({$product->sku}).",
            $product,
            ['sku' => $product->sku, 'name' => $product->name],
            $request->user()
        );

        return back()->with('success', "Product '{$product->name}' updated successfully!");
    }

    /**
     * Remove or archive the specified product.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $product = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $product = $this->resolveProduct($product, $current_team, $request);
        $team = $request->user()->currentTeam;
        if (! $team || $product->team_id !== $team->id) {
            abort(403);
        }

        $name = $product->name;

        // If product has linked quotations or sales, soft archive it
        if ($product->quotationItems()->exists() || $product->saleItems()->exists() || $product->stockMovements()->exists()) {
            $product->update(['is_active' => false]);

            return back()->with('info', "Product '{$name}' has been archived from active inventory.");
        }

        $product->delete();

        return back()->with('success', "Product '{$name}' deleted successfully.");
    }

    /**
     * Record a Stock-In operation.
     */
    public function stockIn(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('team_id', $team->id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'movement_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($team, $request, $validated) {
            $product = Product::lockForUpdate()->find($validated['product_id']);
            $prevStock = $product->stock;
            $qty = (int) $validated['quantity'];
            $newStock = $prevStock + $qty;

            $product->stock = $newStock;
            $product->save();
            $product->updateCalculatedStatus();

            $ref = ($validated['reference_number'] ?? null) ?: ('IN-'.strtoupper(uniqid()));

            StockMovement::create([
                'team_id' => $team->id,
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'supplier_id' => $validated['supplier_id'] ?? $product->supplier_id,
                'type' => 'stock_in',
                'quantity' => $qty,
                'previous_stock' => $prevStock,
                'new_stock' => $newStock,
                'reference_number' => $ref,
                'reason' => 'Manual Stock In',
                'remarks' => $validated['remarks'] ?? null,
                'movement_date' => $validated['movement_date'] ?? now(),
            ]);

            AuditLogger::record(
                'stock.in',
                "Stock-In of {$qty} units for {$product->name} ({$product->sku}). Stock: {$prevStock} → {$newStock}.",
                $product,
                ['sku' => $product->sku, 'reference_number' => $ref, 'quantity' => $qty, 'previous_stock' => $prevStock, 'new_stock' => $newStock],
                $request->user()
            );
        });

        return back()->with('success', 'Stock-In recorded successfully! Inventory updated.');
    }

    /**
     * Record a Stock-Out operation.
     */
    public function stockOut(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('team_id', $team->id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:100'],
            'movement_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::find($validated['product_id']);
        $qty = (int) $validated['quantity'];

        DB::transaction(function () use ($team, $request, $validated, $product, $qty) {
            $product = Product::lockForUpdate()->find($product->id);
            $prevStock = $product->stock;

            // Units reserved for confirmed Sales Orders cannot be taken out by hand.
            $reserved = $product->reservedQuantity();
            $available = max(0, $prevStock - $reserved);
            if ($qty > $available) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock. Available quantity: {$available}".($reserved > 0 ? " ({$reserved} of {$prevStock} reserved for sales orders)" : '').'.',
                ]);
            }

            $newStock = $prevStock - $qty;
            $product->stock = $newStock;
            $product->save();
            $product->updateCalculatedStatus();

            $ref = ($validated['reference_number'] ?? null) ?: ('OUT-'.strtoupper(uniqid()));

            StockMovement::create([
                'team_id' => $team->id,
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'stock_out',
                'quantity' => $qty,
                'previous_stock' => $prevStock,
                'new_stock' => $newStock,
                'reference_number' => $ref,
                'reason' => $validated['reason'],
                'remarks' => $validated['remarks'] ?? null,
                'movement_date' => $validated['movement_date'] ?? now(),
            ]);

            AuditLogger::record(
                'stock.out',
                "Stock-Out of {$qty} units for {$product->name} ({$product->sku}). Reason: {$validated['reason']}.",
                $product,
                ['sku' => $product->sku, 'reference_number' => $ref, 'quantity' => $qty, 'previous_stock' => $prevStock, 'new_stock' => $newStock],
                $request->user()
            );
        });

        return back()->with('success', 'Stock-Out recorded successfully! Inventory updated.');
    }

    /**
     * Record an Inventory Adjustment for physical count corrections.
     */
    public function adjustStock(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('team_id', $team->id)],
            'physical_count' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'in:Physical Count Correction,Damaged,Lost,Data Correction'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($team, $request, $validated) {
            $product = Product::lockForUpdate()->find($validated['product_id']);
            $prevStock = $product->stock;
            $newStock = (int) $validated['physical_count'];
            $diff = $newStock - $prevStock;

            $product->stock = $newStock;
            $product->save();
            $product->updateCalculatedStatus();

            $ref = 'ADJ-'.strtoupper(uniqid());

            StockMovement::create([
                'team_id' => $team->id,
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'adjustment',
                'quantity' => abs($diff),
                'previous_stock' => $prevStock,
                'new_stock' => $newStock,
                'reference_number' => $ref,
                'reason' => $validated['reason'],
                'remarks' => $validated['remarks'] ?: "Adjusted from {$prevStock} to {$newStock} ({$diff})",
                'movement_date' => now(),
            ]);

            AuditLogger::record(
                'stock.adjusted',
                "Adjusted stock for {$product->name} ({$product->sku}) from {$prevStock} to {$newStock} ({$validated['reason']}).",
                $product,
                ['sku' => $product->sku, 'reference_number' => $ref, 'previous_stock' => $prevStock, 'new_stock' => $newStock],
                $request->user()
            );
        });

        return back()->with('success', 'Inventory adjustment successfully recorded and audited.');
    }

    /**
     * Get stock movement history for a product or whole inventory.
     */
    public function history(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $productId = $request->input('product_id');

        $query = StockMovement::where('team_id', $team->id)
            ->with(['product:id,name,sku', 'user:id,name', 'supplier:id,name'])
            ->latest('movement_date');

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $movements = $query->paginate(20);

        return response()->json($movements);
    }

    /**
     * Export active inventory records to CSV.
     */
    public function exportCsv(Request $request, InventoryExporter $exporter): SymfonyResponse
    {
        $request->merge(['format' => 'csv']);

        return $this->export($request, $exporter);
    }

    /**
     * Download the inventory list (with the page's current filters) as CSV, Excel, PDF, PNG or JPG.
     */
    public function export(Request $request, InventoryExporter $exporter): SymfonyResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team, 403, 'No active team selected.');

        $validated = $request->validate([
            'format' => ['nullable', Rule::in(InventoryExporter::FORMATS)],
            'search' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $search = $validated['search'] ?? null;
        $category = $validated['category'] ?? 'All Categories';
        $status = $validated['status'] ?? 'All';
        $products = $this->productListQuery($team->id, $search, $category, $status)->get();

        $filters = array_filter([
            $search ? "Search: \"{$search}\"" : null,
            $category !== 'All Categories' ? "Category: {$category}" : null,
            $status !== 'All' ? "Status: {$status}" : null,
        ]);

        return $exporter->download($validated['format'] ?? 'csv', $products, [
            'team' => $team->name,
            'filters' => $filters ? implode(', ', $filters) : 'All active products',
        ]);
    }

    /**
     * Resolve product from route parameters.
     */
    protected function resolveProduct(mixed $product, mixed $fallback = null, ?Request $request = null): Product
    {
        if ($product instanceof Product) {
            return $product;
        }

        $candidates = array_filter([
            $product,
            $fallback,
            $request?->route('product'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof Product) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = Product::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Product not found.');
    }
}
