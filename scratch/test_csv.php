<?php

require __DIR__ . '/../../../../xampp/htdocs/mr-pride/vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../xampp/htdocs/mr-pride/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Http\Controllers\InventoryController;

$team = Team::first();
$user = User::where('email', 'admin@example.com')->first() ?? User::first();
$user->current_team_id = $team->id;
$user->save();

$csvData = "SKU,Product Name,Category,Unit,Cost Price,Selling Price,Stock,Reorder Level,Reorder Quantity\n" .
"CSV-TEST-001,\"High Voltage Switchgear 630A\",Breakers,pcs,450.00,680.00,15,5,10\n" .
"CSV-TEST-002,\"Fiber Optic Patch Cord 10m\",Wiring,pcs,12.00,25.00,50,20,100\n" .
"CSV-TEST-001,\"Duplicate SKU Test\",Breakers,pcs,100.00,150.00,5,5,10\n" .
",\"Missing SKU Product\",Lighting,units,30.00,50.00,10,5,20\n" .
"CSV-TEST-003,\"Negative Price Item\",Tools,sets,50.00,-10.00,5,5,10\n";

$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_inventory_' . uniqid() . '.csv';
file_put_contents($tmpPath, $csvData);

$controller = new InventoryController();

// 1. Test Preview
$uploadedFile1 = new UploadedFile($tmpPath, 'test_import.csv', 'text/csv', null, true);
$requestPreview = Request::create('/inventory/import-csv', 'POST', [
    'execute' => 0,
    'mode' => 'append',
], [], ['file' => $uploadedFile1]);
$requestPreview->setUserResolver(fn () => $user);

$previewResponse = $controller->importCsv($requestPreview);
$result = json_decode($previewResponse->getContent(), true);

echo "1. CSV Preview Validation:\n";
echo "   - Valid count: {$result['valid_count']} (Expected: 2)\n";
echo "   - Invalid count: {$result['invalid_count']} (Expected: 3)\n";
assert($result['valid_count'] === 2);
assert($result['invalid_count'] === 3);

// 2. Test Execution
$uploadedFile2 = new UploadedFile($tmpPath, 'test_import.csv', 'text/csv', null, true);
$requestExec = Request::create('/inventory/import-csv', 'POST', [
    'execute' => 1,
    'mode' => 'append',
], [], ['file' => $uploadedFile2]);
$requestExec->setUserResolver(fn () => $user);

$execResponse = $controller->importCsv($requestExec);

$p1 = Product::where('team_id', $team->id)->where('sku', 'CSV-TEST-001')->first();
$p2 = Product::where('team_id', $team->id)->where('sku', 'CSV-TEST-002')->first();

echo "\n2. CSV Execution:\n";
echo "   - P1: {$p1?->name} (Stock: {$p1?->stock}, Status: {$p1?->status})\n";
echo "   - P2: {$p2?->name} (Stock: {$p2?->stock}, Status: {$p2?->status})\n";
assert($p1 !== null && $p1->stock === 15);
assert($p2 !== null && $p2->stock === 50);

unlink($tmpPath);
echo "\nALL CSV TESTS COMPLETED AND PASSED!\n";
