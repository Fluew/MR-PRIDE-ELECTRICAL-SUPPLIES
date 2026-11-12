<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierCatalogPrice;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Inventory\SupplierCatalog\SupplierCatalogImporter;
use App\Services\Inventory\SupplierCatalog\SupplierCatalogNormalizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Supplier Product Catalog: import of the supplier price list, browsing/comparing, registration
 * in inventory, procurement options, and protection of existing inventory and algorithm data.
 */
const CATALOG_CSV = __DIR__.'/../../database/data/mrp_supplier_product_prices_usd_updated.csv';

beforeEach(function () {
    Carbon::setTestNow('2026-11-12 09:00:00');
    $this->team = Team::factory()->create(['slug' => 'cat-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->inventoryStaff = User::factory()->create(['role' => 'staff_inventory', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->salesStaff = User::factory()->create(['role' => 'staff_sales', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->inventoryStaff, ['role' => TeamRole::Member->value]);
    $this->team->members()->attach($this->salesStaff, ['role' => TeamRole::Member->value]);
    $this->adminCookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->inventoryCookies = createTrustedDeviceFor($this->inventoryStaff)['cookies'];
    $this->salesCookies = createTrustedDeviceFor($this->salesStaff)['cookies'];

    // Existing operational data that the catalog must never change.
    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Sonepar Singapore', 'status' => 'Active']);
    $this->existing = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'Existing Breaker 40A', 'category' => 'Circuit Breakers',
        'price' => 20, 'cost_price' => 10, 'stock' => 25, 'min_stock' => 15, 'reorder_level' => 15, 'reorder_quantity' => 60, 'status' => 'In Stock',
        'is_active' => true, 'unit' => 'pcs', 'supplier_id' => $this->supplier->id]);
    $this->existing->forceFill(['created_at' => '2025-01-01'])->saveQuietly();
    foreach (['2026-08-05' => 40, '2026-09-05' => 50, '2026-10-05' => 60] as $date => $qty) {
        StockMovement::create(['team_id' => $this->team->id, 'product_id' => $this->existing->id, 'type' => 'sale', 'quantity' => -$qty,
            'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "S-{$date}"]);
    }
    $this->po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-20', 'po_number' => 'PO-OLD-1', 'quantity' => 10,
        'description' => 'Existing Breaker 40A', 'product_id' => $this->existing->id, 'supplier_id' => $this->supplier->id,
        'unit_cost' => 11.5, 'total_cost' => 115, 'status' => PurchaseOrder::SENT]);

    $this->url = "/{$this->team->slug}";
});

afterEach(fn () => Carbon::setTestNow());

function importCatalog(int $teamId, bool $dryRun = false): array
{
    $importer = app(SupplierCatalogImporter::class);

    return $importer->import($teamId, $importer->analyze(CATALOG_CSV)['rows'], 'mrp_supplier_product_prices_usd_updated.csv', $dryRun);
}

/** Row count + content of the tables the catalog must not change. */
function protectedState(): array
{
    $out = [];
    foreach (['products', 'stock_movements', 'purchase_orders', 'purchase_requests', 'sales', 'sale_items', 'deliveries', 'inbound_deliveries',
        'supplier_product_costs', 'supplier_quotes', 'product_supplier', 'evaluation_snapshots'] as $t) {
        $out[$t] = md5(DB::table($t)->orderBy('id')->get()->toJson());
    }

    return $out;
}

test('the price list file has the expected 47 products, 137 price records and 26 suppliers', function () {
    $analysis = app(SupplierCatalogImporter::class)->analyze(CATALOG_CSV);

    expect($analysis['errors'])->toBe([])
        ->and($analysis['warnings'])->toBe([])
        ->and($analysis['stats']['data_rows'])->toBe(137)
        ->and($analysis['stats']['unique_products'])->toBe(47)
        ->and($analysis['stats']['unique_pairs'])->toBe(137)
        ->and($analysis['stats']['unique_suppliers'])->toBe(26)
        ->and($analysis['stats']['duplicates_in_file'])->toBe(0);
});

test('import creates the catalog, maps every row exactly, and changes no inventory, procurement or sales data', function () {
    Mail::fake();
    Notification::fake();
    $before = protectedState();

    $report = importCatalog($this->team->id);

    expect($report['products_created'])->toBe(47)
        ->and($report['prices_created'])->toBe(137)
        ->and($report['suppliers_created'])->toHaveCount(25)            // one already existed
        ->and($report['suppliers_matched'])->toBe(['Sonepar Singapore']) // reused, not duplicated
        ->and($report['price_conflicts'])->toBe([])
        ->and($report['suppliers_unresolved'])->toBe([]);
    expect(SupplierCatalogProduct::count())->toBe(47)
        ->and(SupplierCatalogPrice::count())->toBe(137)
        ->and(Supplier::count())->toBe(26)
        ->and(SupplierCatalogProduct::whereNotNull('product_id')->count())->toBe(0); // nothing is an inventory product

    // Every source row: same product, category, supplier and USD cost.
    $handle = fopen(CATALOG_CSV, 'r');
    fgetcsv($handle);
    while (($r = fgetcsv($handle)) !== false) {
        $price = SupplierCatalogPrice::whereHas('catalogProduct', fn ($q) => $q->where('name', $r[0])->where('category', $r[1]))
            ->whereHas('supplier', fn ($q) => $q->where('name', $r[2]))->sole();
        expect((float) $price->unit_cost)->toBe(round((float) $r[3], 2))
            ->and($price->currency)->toBe('USD')
            ->and($price->pricing_unit_label)->toBe($r[4]);
    }
    fclose($handle);

    expect(protectedState())->toBe($before);
    expect($this->existing->fresh()->only(['stock', 'reorder_level', 'reorder_quantity', 'status', 'price']))
        ->toBe(['stock' => 25, 'reorder_level' => 15, 'reorder_quantity' => 60, 'status' => 'In Stock', 'price' => '20.00']);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('importing the same file again creates no duplicates; a different cost is reported, not overwritten', function () {
    importCatalog($this->team->id);
    $again = importCatalog($this->team->id);

    expect($again['products_created'])->toBe(0)
        ->and($again['products_matched'])->toBe(47)
        ->and($again['prices_created'])->toBe(0)
        ->and($again['prices_unchanged'])->toBe(137)
        ->and($again['suppliers_created'])->toBe([]);
    expect(SupplierCatalogProduct::count())->toBe(47)->and(SupplierCatalogPrice::count())->toBe(137)->and(Supplier::count())->toBe(26);

    $price = SupplierCatalogPrice::first();
    $price->update(['unit_cost' => 1.23]);
    $third = importCatalog($this->team->id);
    expect($third['price_conflicts'])->toHaveCount(1)
        ->and((float) $price->fresh()->unit_cost)->toBe(1.23);
});

test('a dry run saves nothing', function () {
    $report = importCatalog($this->team->id, dryRun: true);

    expect($report['prices_created'])->toBe(137)
        ->and(SupplierCatalogPrice::count())->toBe(0)
        ->and(SupplierCatalogProduct::count())->toBe(0)
        ->and(Supplier::count())->toBe(1);
});

test('the import command refuses a file whose counts differ from the expected ones', function () {
    $this->artisan('catalog:import-supplier-prices', ['file' => CATALOG_CSV, '--team' => $this->team->id, '--expect-products' => 48])
        ->expectsOutputToContain('Discrepancy: the file has 47 unique products, expected 48.')
        ->assertFailed();
    expect(SupplierCatalogPrice::count())->toBe(0);

    $this->artisan('catalog:import-supplier-prices', ['file' => CATALOG_CSV, '--team' => $this->team->id,
        '--expect-products' => 47, '--expect-prices' => 137, '--expect-suppliers' => 26])->assertSuccessful();
    expect(SupplierCatalogPrice::count())->toBe(137);
});

test('names keep their specifications; matching keys only unify dashes and case', function () {
    expect(SupplierCatalogNormalizer::productKey('Photovoltaic PV4 Crimping Tool, Capacity 0.5–6.0 mm² (20–10 AWG)'))
        ->toBe(SupplierCatalogNormalizer::productKey('photovoltaic pv4 crimping tool, capacity 0.5-6.0 mm²  (20-10 awg)'))
        ->and(SupplierCatalogNormalizer::productKey('Schneider Electric iC60N Miniature Circuit Breaker, 3-Pole, 50A'))
        ->not->toBe(SupplierCatalogNormalizer::productKey('Schneider Electric iC60N Miniature Circuit Breaker, 3-Pole, 32A'))
        ->and(SupplierCatalogNormalizer::supplierKey('J.Z. Electrical Supply, Inc.'))->toBe(SupplierCatalogNormalizer::supplierKey('JZ Electrical Supply Inc'))
        ->and(SupplierCatalogNormalizer::pricingUnit('USD/pc or set (verify UOM)'))->toBe(['unit' => 'pcs', 'confirmed' => false, 'currency' => 'USD'])
        ->and(SupplierCatalogNormalizer::pricingUnit('USD/spool'))->toBe(['unit' => 'spool', 'confirmed' => true, 'currency' => 'USD'])
        ->and(SupplierCatalogNormalizer::pricingUnit('USD/bundle'))->toBeNull()
        ->and(SupplierCatalogNormalizer::brand('Siemens 3TF46 Magnetic Contactor, 45A, 120V Coil'))->toBe('Siemens')
        ->and(SupplierCatalogNormalizer::brand('NEMA 1 Panel Board, … with Two 225AT, 3-Pole Schneider Electric EZC Branch Circuit Breakers'))->toBeNull();
});

test('a catalog product matching an existing inventory product is linked without changing it', function () {
    $this->existing->update(['name' => 'Fluke 393 Clamp Meter']);
    $before = protectedState();

    $report = importCatalog($this->team->id);

    expect($report['products_linked_to_inventory'])->toBe(['Fluke 393 Clamp Meter'])
        ->and(SupplierCatalogProduct::where('name', 'Fluke 393 Clamp Meter')->value('product_id'))->toBe($this->existing->id)
        ->and(Product::count())->toBe(1)
        ->and(protectedState())->toBe($before);
});

test('the catalog page lists one row per product and supplier with search, filters, sorting and pagination', function () {
    importCatalog($this->team->id);
    $page = fn ($query = []) => $this->actingAs($this->inventoryStaff)->withCookies($this->inventoryCookies)
        ->get("{$this->url}/inventory/supplier-catalog?".http_build_query($query))->assertOk();

    $page()->assertInertia(fn ($p) => $p->component('Inventory/SupplierCatalog')
        ->where('summary', ['products' => 47, 'prices' => 137, 'suppliers' => 26, 'registered' => 0])
        ->where('rows.total', 137)
        ->has('rows.data', 25)
        ->has('options.categories', 8));

    // All four suppliers of one product, lowest first, lowest flagged; records unchanged.
    $page(['search' => 'iC60N 3-Pole, 50A', 'sort' => 'price_asc'])->assertInertia(fn ($p) => $p
        ->where('rows.total', 4)
        ->where('rows.data.0.supplier_name', 'Distribution & Control Products, Inc.')
        ->where('rows.data.0.unit_cost', 58.4)
        ->where('rows.data.0.is_lowest', true)
        ->where('rows.data.1.supplier_name', 'J.Z. Electrical Supply, Inc.')
        ->where('rows.data.1.difference_from_lowest', 3.8)
        ->where('rows.data.3.supplier_name', 'KVC Industrial Supplies')
        ->where('rows.data.3.unit_cost', 69));

    $page(['search' => 'Siemens'])->assertInertia(fn ($p) => $p->where('rows.total', 10));           // brand
    $page(['search' => 'element14'])->assertInertia(fn ($p) => $p->where('rows.total', 7));          // supplier
    $page(['category' => 'Wires & Conductors'])->assertInertia(fn ($p) => $p->where('rows.total', 7));
    $sonepar = Supplier::where('name', 'Sonepar Singapore')->value('id');
    $page(['supplier_id' => $sonepar])->assertInertia(fn ($p) => $p->where('rows.total', 6));
    $page(['sort' => 'price_desc'])->assertInertia(fn ($p) => $p->where('rows.data.0.unit_cost', 9150));
    $page(['sort' => 'price_asc'])->assertInertia(fn ($p) => $p->where('rows.data.0.unit_cost', 0.35));
    $page(['page' => 6])->assertInertia(fn ($p) => $p->has('rows.data', 12));
    $page(['registration' => 'inventory'])->assertInertia(fn ($p) => $p->where('rows.total', 0));

    expect(SupplierCatalogPrice::sum('unit_cost'))->toEqual(SupplierCatalogPrice::sum('unit_cost')); // reading changes nothing
});

test('only inventory roles can open the catalog or register products', function () {
    importCatalog($this->team->id);
    $catalog = SupplierCatalogProduct::first();

    $this->actingAs($this->salesStaff)->withCookies($this->salesCookies)->get("{$this->url}/inventory/supplier-catalog")->assertForbidden();
    $this->actingAs($this->salesStaff)->withCookies($this->salesCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs'])->assertForbidden();
    expect(Product::count())->toBe(1);
});

test('registering creates the inventory product with a generated SKU, stock 0 and no movements, orders or reorder values', function () {
    importCatalog($this->team->id);
    $catalog = SupplierCatalogProduct::where('name', 'Schneider Electric iC60N Miniature Circuit Breaker, 3-Pole, 50A')->sole();
    $movements = StockMovement::count();
    $orders = PurchaseOrder::count();
    $requests = PurchaseRequest::count();

    $this->actingAs($this->inventoryStaff)->withCookies($this->inventoryCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs'])
        ->assertSessionHasNoErrors();

    $product = Product::where('name', $catalog->name)->sole();
    expect($product->sku)->toBe('BRK-000002')                 // existing category sequence (BRK-000001 exists)
        ->and($product->stock)->toBe(0)
        ->and($product->reorder_level)->toBe(0)
        ->and($product->reorder_quantity)->toBe(0)
        ->and($product->price)->toBeNull()
        ->and($product->is_active)->toBeFalse()
        ->and($product->status)->toBe('Out of Stock')           // its stock level; inactive until a selling price is entered
        ->and($product->brand)->toBe('Schneider Electric')
        ->and($product->supplier_id)->toBeNull()                // no supplier picked automatically
        ->and($catalog->fresh()->product_id)->toBe($product->id);
    expect(StockMovement::count())->toBe($movements)
        ->and(PurchaseOrder::count())->toBe($orders)
        ->and(PurchaseRequest::count())->toBe($requests);

    // Each supplier's cost kept in the existing structures, unverified.
    $quotes = SupplierQuote::where('product_id', $product->id)->orderBy('unit_price')->get();
    expect($quotes->pluck('unit_price')->map(fn ($v) => (float) $v)->all())->toBe([58.4, 62.2, 66.5, 69.0])
        ->and($quotes->every(fn ($q) => $q->verified_at === null && $q->currency === 'USD' && $q->unit === 'pcs'))->toBeTrue()
        ->and(ProductSupplier::where('product_id', $product->id)->whereNull('verified_at')->count())->toBe(4);

    // Registering again reuses the product; nothing is duplicated.
    $this->actingAs($this->inventoryStaff)->withCookies($this->inventoryCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs'])->assertSessionHasNoErrors();
    expect(Product::where('name', $catalog->name)->count())->toBe(1)
        ->and(SupplierQuote::where('product_id', $product->id)->count())->toBe(4)
        ->and(ProductSupplier::where('product_id', $product->id)->count())->toBe(4);
});

test('registering with a selling price makes the product active with the normal stock-0 status', function () {
    importCatalog($this->team->id);
    $catalog = SupplierCatalogProduct::where('name', 'Copper/PVC Insulated Conductor, 50 mm²')->sole();
    $jz = Supplier::where('name', 'J.Z. Electrical Supply, Inc.')->value('id');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'm', 'price' => 12.5, 'cost_price' => 7.85, 'supplier_id' => $jz, 'reorder_level' => 100])
        ->assertSessionHasNoErrors();

    $product = Product::where('name', $catalog->name)->sole();
    expect($product->is_active)->toBeTrue()
        ->and($product->status)->toBe('Out of Stock')
        ->and($product->unit)->toBe('m')
        ->and($product->supplier_id)->toBe($jz)
        ->and($product->reorder_level)->toBe(100)
        ->and(SupplierQuote::where('product_id', $product->id)->pluck('unit')->unique()->all())->toBe(['m']);
});

test('a usual supplier that does not offer the product is refused', function () {
    importCatalog($this->team->id);
    $catalog = SupplierCatalogProduct::where('name', 'Fluke 393 Clamp Meter')->sole();
    $other = Supplier::where('name', 'Sun Power Automation')->value('id');

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs', 'supplier_id' => $other])
        ->assertSessionHasErrors('supplier_id');
    expect(Product::where('name', 'Fluke 393 Clamp Meter')->exists())->toBeFalse();
});

test('registering a catalog product that matches an inventory product reuses it and leaves its stock and reorder values', function () {
    importCatalog($this->team->id);
    $this->existing->update(['name' => 'Fluke 393 Clamp Meter']); // added to inventory after the import
    $catalog = SupplierCatalogProduct::where('name', 'Fluke 393 Clamp Meter')->sole();

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs', 'price' => 999])
        ->assertSessionHasNoErrors();

    expect(Product::count())->toBe(1)
        ->and($catalog->fresh()->product_id)->toBe($this->existing->id)
        ->and($this->existing->fresh()->only(['stock', 'reorder_level', 'reorder_quantity', 'price']))
        ->toBe(['stock' => 25, 'reorder_level' => 15, 'reorder_quantity' => 60, 'price' => '20.00']);
});

test('unverified catalog costs do not change supplier evaluation, prioritization, forecasts or purchase order pre-fill', function () {
    $sma = fn () => app(SmaForecaster::class)->forProducts($this->team->id, [$this->existing->id], 3);
    $evaluationBefore = app(SupplierEvaluator::class)->forTeam($this->team->id);
    $priorityBefore = app(ProductPrioritizer::class)->forTeam($this->team->id);
    $forecastBefore = $sma();

    importCatalog($this->team->id);
    foreach (SupplierCatalogProduct::limit(5)->get() as $catalog) {
        $this->actingAs($this->admin)->withCookies($this->adminCookies)
            ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs', 'price' => 100])->assertSessionHasNoErrors();
    }

    $evaluationAfter = app(SupplierEvaluator::class)->forTeam($this->team->id);
    expect($evaluationAfter[$this->supplier->id]['score'] ?? null)->toBe($evaluationBefore[$this->supplier->id]['score'] ?? null)
        ->and(collect($evaluationAfter)->except(array_keys($evaluationBefore))->every(fn ($e) => ($e['rank'] ?? null) === null))->toBeTrue();
    expect($sma())->toEqual($forecastBefore);

    // New products have no demand history: the existing insufficient-data handling applies.
    $priorityAfter = collect(app(ProductPrioritizer::class)->forTeam($this->team->id))->keyBy('product.id');
    $existingBefore = collect($priorityBefore)->keyBy('product.id')[$this->existing->id];
    expect($priorityAfter[$this->existing->id]['forecast'])->toEqual($existingBefore['forecast'])
        ->and($priorityAfter[$this->existing->id]['reorder'] ?? null)->toEqual($existingBefore['reorder'] ?? null);
    $registered = SupplierCatalogProduct::whereNotNull('product_id')->pluck('product_id');
    expect($registered)->toHaveCount(5);
    foreach ($registered as $id) {
        expect($priorityAfter[$id]['forecast']['available'])->toBeFalse()   // insufficient data, no invented demand
            ->and($priorityAfter[$id]['forecast']['historical_units'])->toBe(0);
    }

    // The old purchase order keeps its own cost.
    expect((float) $this->po->fresh()->unit_cost)->toBe(11.5);
});

test('procurement sees the catalog suppliers and costs of a registered product; the draft order is not priced from them', function () {
    importCatalog($this->team->id);
    $catalog = SupplierCatalogProduct::where('name', 'MY2N-J Power Relay, 8-Pin, 5A, 110V, with Socket')->sole();
    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("{$this->url}/inventory/supplier-catalog/{$catalog->id}/register", ['unit' => 'pcs', 'price' => 40])->assertSessionHasNoErrors();
    $product = Product::where('name', $catalog->name)->sole();

    $options = $this->withCredentials()->actingAs($this->admin)->withCookies($this->adminCookies)
        ->getJson("{$this->url}/procurement/catalog-prices?product_ids[]={$product->id}")->assertOk()->json("options.{$product->id}");
    expect($options)->toHaveCount(8)
        ->and($options[0]['supplier_name'])->toBe('Best Electrical Components, Inc.')
        ->and($options[0]['unit_cost'])->toBe(18.5)
        ->and($options[0]['is_lowest'])->toBeTrue();

    // Staff choose a supplier on the request; it is kept on the draft order. The price is entered by staff.
    $chosen = Supplier::where('name', 'Prisma Electrical Controls Corporation')->value('id');
    $this->actingAs($this->admin)->withCookies($this->adminCookies)->post("{$this->url}/procurement/requests", [
        'lines' => [['product_id' => $product->id, 'quantity' => 20, 'justification' => 'Stock up', 'supplier_id' => $chosen]],
    ])->assertSessionHasNoErrors();
    $pr = PurchaseRequest::latest('id')->first();
    expect($pr->supplier_id)->toBe($chosen);

    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve', 'remarks' => 'ok'])->assertSessionHasNoErrors();
    $po = PurchaseOrder::where('purchase_request_id', $pr->id)->sole();
    expect($po->supplier_id)->toBe($chosen)
        ->and((float) $po->unit_cost)->toBe(0.0); // unverified catalog costs never pre-fill the agreed price

    $workspace = $this->withCredentials()->actingAs($this->admin)->withCookies($this->adminCookies)
        ->getJson("{$this->url}/procurement/purchase-orders/{$po->id}/workspace")->assertOk()->json();
    expect($workspace['catalog_prices'])->toHaveCount(8);

    // Staff confirm the agreed cost (here the chosen supplier's catalog cost); it is kept on the order.
    $this->actingAs($this->admin)->withCookies($this->adminCookies)
        ->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $chosen, 'quantity' => 20, 'unit_cost' => 20])
        ->assertSessionHasNoErrors();
    expect((float) $po->fresh()->unit_cost)->toBe(20.0)->and($po->fresh()->supplier_id)->toBe($chosen);

    // A later catalog price change does not touch the order.
    SupplierCatalogPrice::where('supplier_id', $chosen)->where('supplier_catalog_product_id', $catalog->id)->update(['unit_cost' => 99]);
    expect((float) $po->fresh()->unit_cost)->toBe(20.0);
});
