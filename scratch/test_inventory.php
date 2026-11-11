<?php

require __DIR__ . '/../../../../xampp/htdocs/mr-pride/vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../xampp/htdocs/mr-pride/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== TESTING INVENTORY MANAGEMENT SYSTEM ===\n\n";

$team = Team::first();
$user = User::where('email', 'admin@example.com')->first() ?? User::first();

if (! $team || ! $user) {
    echo "ERROR: Team or User not found.\n";
    exit(1);
}

echo "Active Team: {$team->name} (ID: {$team->id})\n";
echo "Active User: {$user->name} (Email: {$user->email})\n\n";

// 1. Check Suppliers
$suppliersCount = Supplier::where('team_id', $team->id)->count();
echo "1. Suppliers in Database: {$suppliersCount}\n";
$supplier = Supplier::where('team_id', $team->id)->first();
if ($supplier) {
    echo "   Sample Supplier: {$supplier->name} (AHP Score: {$supplier->ahp_score})\n";
}

// 2. Test Product Creation & Status Calculation
$testSku = 'TEST-PROD-' . time();
echo "\n2. Creating Test Product ($testSku)...\n";
$product = Product::create([
    'team_id' => $team->id,
    'sku' => $testSku,
    'name' => 'Automated Test Industrial Contactor',
    'category' => 'Controls',
    'unit' => 'pcs',
    'cost_price' => 50.00,
    'price' => 75.00,
    'stock' => 15,
    'min_stock' => 10,
    'reorder_level' => 10,
    'reorder_quantity' => 40,
    'supplier_id' => $supplier?->id,
    'description' => 'Test Contactor for validation test suite.',
    'is_active' => true,
]);

$product->updateCalculatedStatus();
echo "   Initial Stock: {$product->stock}, Reorder Level: {$product->reorder_level}\n";
echo "   Status: {$product->status} (Expected: In Stock)\n";
assert($product->status === 'In Stock', "Status should be 'In Stock'");

// 3. Test Stock-In
echo "\n3. Testing Stock-In (+25 units)...\n";
$prevStock = $product->stock;
$inQty = 25;
$product->stock = $prevStock + $inQty;
$product->save();
$product->updateCalculatedStatus();

$movementIn = StockMovement::create([
    'team_id' => $team->id,
    'product_id' => $product->id,
    'user_id' => $user->id,
    'supplier_id' => $supplier?->id,
    'type' => 'stock_in',
    'quantity' => $inQty,
    'previous_stock' => $prevStock,
    'new_stock' => $product->stock,
    'reference_number' => 'PO-TEST-001',
    'reason' => 'Supplier Restock',
    'remarks' => 'Automated test stock inflow.',
    'movement_date' => now(),
]);

echo "   New Stock: {$product->stock} (Expected: 40)\n";
echo "   StockMovement Logged: ID #{$movementIn->id}, Type: {$movementIn->type}, Qty: +{$movementIn->quantity}\n";
assert($product->stock === 40, "Stock should be 40");

// 4. Test Stock-Out
echo "\n4. Testing Stock-Out (-35 units -> Low Stock)...\n";
$prevStock = $product->stock;
$outQty = 35;
$product->stock = $prevStock - $outQty;
$product->save();
$product->updateCalculatedStatus();

$movementOut = StockMovement::create([
    'team_id' => $team->id,
    'product_id' => $product->id,
    'user_id' => $user->id,
    'type' => 'stock_out',
    'quantity' => $outQty,
    'previous_stock' => $prevStock,
    'new_stock' => $product->stock,
    'reference_number' => 'SO-TEST-001',
    'reason' => 'Client Project Deployment',
    'remarks' => 'Automated test stock outflow.',
    'movement_date' => now(),
]);

echo "   New Stock: {$product->stock} (Expected: 5)\n";
echo "   Status: {$product->status} (Expected: Low Stock since 5 <= 10)\n";
assert($product->status === 'Low Stock', "Status should be 'Low Stock'");

// 5. Test Stock Adjustment (Physical count to 0 -> Out of Stock)
echo "\n5. Testing Stock Adjustment (Physical Count = 0 -> Out of Stock)...\n";
$prevStock = $product->stock;
$physicalCount = 0;
$product->stock = $physicalCount;
$product->save();
$product->updateCalculatedStatus();

$movementAdj = StockMovement::create([
    'team_id' => $team->id,
    'product_id' => $product->id,
    'user_id' => $user->id,
    'type' => 'adjustment',
    'quantity' => abs($physicalCount - $prevStock),
    'previous_stock' => $prevStock,
    'new_stock' => $physicalCount,
    'reference_number' => 'ADJ-TEST-001',
    'reason' => 'Physical Count Correction',
    'remarks' => 'Adjusted to zero for testing.',
    'movement_date' => now(),
]);

echo "   New Stock: {$product->stock} (Expected: 0)\n";
echo "   Status: {$product->status} (Expected: Out of Stock)\n";
assert($product->status === 'Out of Stock', "Status should be 'Out of Stock'");

// 6. Test Movement History Query
echo "\n6. Verifying Movement History Trail for Product...\n";
$movements = $product->stockMovements()->get();
echo "   Total Movements Logged for Product: " . $movements->count() . "\n";
foreach ($movements as $m) {
    echo "   - [{$m->type}] {$m->previous_stock} -> {$m->new_stock} (Qty: {$m->quantity}) Ref: {$m->reference_number}\n";
}
assert($movements->count() >= 3, "Should have at least 3 movement records");

// 7. Test Archiving
echo "\n7. Testing Product Soft Archival...\n";
$product->update(['is_active' => false]);
echo "   Product is_active = " . ($product->is_active ? 'true' : 'false') . "\n";

echo "\nALL INVENTORY BACKEND TESTS PASSED SUCCESSFULLY! \n";
