<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->default(0.00)->after('price');
            $table->string('unit')->default('pcs')->after('category');
            $table->integer('reorder_level')->default(10)->after('min_stock');
            $table->integer('reorder_quantity')->default(50)->after('reorder_level');
            $table->foreignId('supplier_id')->nullable()->after('description')->constrained('suppliers')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn([
                'cost_price',
                'unit',
                'reorder_level',
                'reorder_quantity',
                'supplier_id',
                'is_active',
            ]);
        });
    }
};
