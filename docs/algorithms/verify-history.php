<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Database\Seeders\AlgorithmHistorySeeder;

$db = DB::connection();
if (! app()->environment(['local', 'staging']) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Local development/staging verification only.');
}
$team = DB::table('teams')->where('slug', AlgorithmHistorySeeder::SLUG)->first();
if (! $team) {
    throw new RuntimeException('Seed the fictional history first.');
}
$at = Carbon\Carbon::parse('2026-11-12 23:59:59');
$products = Product::where('team_id', $team->id)->orderBy('sku')->get();
$counts = ['teams' => 1, 'brands' => DB::table('brands')->where('name', AlgorithmHistorySeeder::MARKER)->count()];
foreach (['products', 'suppliers', 'clients', 'quotations', 'sales', 'purchase_requests', 'purchase_orders', 'inbound_deliveries', 'deliveries',
    'supplier_product_costs', 'product_supplier', 'stock_movements', 'payments', 'payment_receipts', 'sales_invoices', 'order_fulfillments', 'delivery_releases', 'evaluation_snapshots'] as $table) {
    $counts[$table] = DB::table($table)->where('team_id', $team->id)->count();
}
foreach (['quotation_items' => ['quotations', 'quotation_id'], 'sale_items' => ['sales', 'sale_id'], 'delivery_release_items' => ['delivery_releases', 'delivery_release_id']] as $table => [$parent, $fk]) {
    $counts[$table] = DB::table($table)->whereIn($fk, DB::table($parent)->where('team_id', $team->id)->select('id'))->count();
}
$errors = [];
foreach ($products as $p) {
    $balance = 0;
    foreach (StockMovement::where('product_id', $p->id)->orderBy('movement_date')->orderBy('id')->get() as $move) {
        if ($move->previous_stock !== $balance || $move->new_stock !== $balance + $move->quantity || $move->new_stock < 0) {
            $errors[] = 'Movement '.$move->id;
        }
        $balance += $move->quantity;
    }
    if ($balance !== $p->stock) {
        $errors[] = 'Balance '.$p->sku;
    }
}
$forecasts = [];
foreach ([3 => [60, 100, 30, 40, 12, 0], 6 => [60, 99, 32, 53, 12, 0], 12 => [60, 96, 35, 73, 9, 0]] as $window => $expected) {
    $result = app(SmaForecaster::class)->forProducts($team->id, $products->modelKeys(), $window, 12, $at);
    $actual = array_column($result, 'forecast');
    $forecasts[$window] = ['expected' => $expected, 'actual' => $actual, 'all_available' => ! in_array(false, array_column($result, 'available'), true), 'pass' => $actual === $expected];
    if ($actual !== $expected) {
        $errors[] = 'SMA '.$window;
    }
}
$foreignKeys = [];
foreach (array_keys($counts) as $table) {
    foreach ($db->getSchemaBuilder()->getForeignKeys($table) as $key) {
        if (count($key['columns']) !== 1) {
            continue;
        }
        $column = $key['columns'][0];
        $parent = $key['foreign_table'];
        $pk = $key['foreign_columns'][0];
        $orphans = DB::table($table.' as child')->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.'.$pk)
            ->whereNotNull('child.'.$column)->whereNull('parent.'.$pk)->count();
        $foreignKeys[$table.'.'.$column] = $orphans;
        if ($orphans) {
            $errors[] = 'Orphans '.$table.'.'.$column;
        }
    }
}
$suppliers = collect(app(SupplierEvaluator::class)->forTeam($team->id, $at))->sortBy('rank')->map(fn ($s) => [
    'name' => $s['supplier']['name'], 'rank' => $s['rank'], 'score' => $s['score'], 'complete' => $s['complete'],
    'criteria' => collect($s['criteria'])->mapWithKeys(fn ($c) => [$c['key'] => $c['score']])->all(),
])->values();
$priority = collect(app(ProductPrioritizer::class)->forTeam($team->id, $at))->map(fn ($r) => ['sku' => $r['product']['sku'],
    'score' => $r['score'], 'forecast' => $r['forecast'], 'reorder' => $r['reorder']])->values();
$report = ['database' => $db->getDatabaseName(), 'host' => $db->getConfig('host'), 'team' => $team->slug,
    'period' => ['2020-01-01', AlgorithmHistorySeeder::END], 'counts' => $counts, 'product_order' => $products->pluck('sku'),
    'forecasts' => $forecasts, 'suppliers' => $suppliers, 'priorities' => $priority, 'foreign_key_checks' => count($foreignKeys),
    'orphan_counts' => array_filter($foreignKeys), 'errors' => $errors];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($errors ? 1 : 0);
