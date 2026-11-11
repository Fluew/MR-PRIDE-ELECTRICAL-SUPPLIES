<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== DATABASE TABLES ===\n";
$tables = DB::select('SHOW TABLES');
foreach ($tables as $t) {
    $name = array_values((array) $t)[0];
    echo "  $name\n";
}

echo "\n=== PRODUCTS COLUMNS ===\n";
$cols = Schema::getColumnListing('products');
echo implode(', ', $cols)."\n";

echo "\n=== USERS COLUMNS ===\n";
$cols = Schema::getColumnListing('users');
echo implode(', ', $cols)."\n";

echo "\n=== USER_MODULE_PERMISSIONS COLUMNS ===\n";
if (Schema::hasTable('user_module_permissions')) {
    $cols = Schema::getColumnListing('user_module_permissions');
    echo implode(', ', $cols)."\n";
} else {
    echo "TABLE DOES NOT EXIST!\n";
}

echo "\n=== MIGRATION STATUS ===\n";
$migrations = DB::table('migrations')->orderByDesc('id')->limit(10)->get();
foreach ($migrations as $m) {
    echo "  [{$m->batch}] {$m->migration}\n";
}
