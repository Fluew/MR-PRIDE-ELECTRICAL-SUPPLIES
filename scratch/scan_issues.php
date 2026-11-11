<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

// Check all model tables exist and have the expected columns
$checks = [
    'users' => ['id', 'name', 'email', 'role', 'status', 'password', 'current_team_id'],
    'teams' => ['id', 'name', 'slug'],
    'team_members' => ['id', 'user_id', 'team_id'],
    'products' => ['id', 'sku', 'name', 'category', 'brand', 'unit', 'price', 'stock', 'is_active', 'deleted_at'],
    'quotations' => ['id', 'deleted_at'],
    'quotation_items' => ['id', 'quotation_id'],
    'sales' => ['id', 'deleted_at'],
    'sale_items' => ['id', 'sale_id'],
    'suppliers' => ['id', 'deleted_at'],
    'clients' => ['id'],
    'stock_movements' => ['id'],
    'trusted_devices' => ['id', 'device_uuid', 'credential_hash', 'status', 'is_shared', 'authorized_user_ids'],
    'audit_logs' => ['id'],
    'sessions' => ['id'],
    'user_module_permissions' => ['id', 'user_id', 'module'],
    'purchase_requests' => ['id', 'deleted_at'],
    'commercial_invoices' => ['id'],
    'purchase_orders' => ['id'],
    'deliveries' => ['id', 'deleted_at'],
    'inquiries' => ['id', 'deleted_at'],
];

$errors = [];
$ok = 0;

foreach ($checks as $table => $columns) {
    if (! Schema::hasTable($table)) {
        $errors[] = "MISSING TABLE: $table";

        continue;
    }

    foreach ($columns as $col) {
        if (! Schema::hasColumn($table, $col)) {
            $errors[] = "MISSING COLUMN: $table.$col";
        }
    }
    $ok++;
}

echo "=== TABLE/COLUMN CHECK ===\n";
echo 'Tables checked: '.count($checks).", OK: $ok\n";

if (empty($errors)) {
    echo "ALL CHECKS PASSED!\n";
} else {
    echo "\nERRORS FOUND:\n";
    foreach ($errors as $e) {
        echo "  ❌ $e\n";
    }
}

// Check for any referenced models that might reference non-existent tables
echo "\n=== QUICK MODEL TESTS ===\n";

$models = [
    'App\Models\User',
    'App\Models\Product',
    'App\Models\Quotation',
    'App\Models\Sale',
    'App\Models\Supplier',
    'App\Models\TrustedDevice',
    'App\Models\AuditLog',
    'App\Models\UserModulePermission',
    'App\Models\PurchaseRequest',
    'App\Models\Delivery',
    'App\Models\Team',
];

foreach ($models as $model) {
    try {
        $count = $model::count();
        echo "  ✓ $model: $count records\n";
    } catch (Throwable $e) {
        echo "  ❌ $model: ".$e->getMessage()."\n";
        $errors[] = "$model: ".$e->getMessage();
    }
}

// Try to eager load relationships that were failing
echo "\n=== RELATIONSHIP TESTS ===\n";

try {
    $users = User::with('modulePermissions')->get();
    echo "  ✓ User->modulePermissions: loaded for {$users->count()} users\n";
} catch (Throwable $e) {
    echo '  ❌ User->modulePermissions: '.$e->getMessage()."\n";
    $errors[] = 'User->modulePermissions: '.$e->getMessage();
}

try {
    $brands = Product::where('is_active', true)
        ->select('brand')
        ->whereNotNull('brand')
        ->pluck('brand')
        ->unique()
        ->values();
    echo "  ✓ Product brands query: {$brands->count()} brands found\n";
} catch (Throwable $e) {
    echo '  ❌ Product brands query: '.$e->getMessage()."\n";
    $errors[] = 'Product brands: '.$e->getMessage();
}

echo "\n=== SUMMARY ===\n";
if (empty($errors)) {
    echo "ALL CHECKS PASSED - System should be operational!\n";
} else {
    echo count($errors)." issue(s) found that need attention.\n";
}
