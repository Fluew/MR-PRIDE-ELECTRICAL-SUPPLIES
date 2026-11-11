<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source records for the transaction-based Supplier Evaluation and the AHP supplier-availability
 * criterion. Every new column is nullable, so existing purchase orders and deliveries keep working;
 * they simply do not count until the missing facts (promised date, inspection) are recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('item_sku')->constrained()->nullOnDelete();
            $table->date('promised_delivery_date')->nullable()->after('po_date');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedInteger('accepted_quantity')->nullable()->after('quantity');
            $table->unsignedInteger('rejected_quantity')->nullable()->after('accepted_quantity');
            $table->string('inspection_result', 30)->nullable()->after('status');
            $table->date('inspected_on')->nullable()->after('inspection_result');
            $table->foreignId('inspected_by_id')->nullable()->after('inspected_on')->constrained('users')->nullOnDelete();
            $table->text('inspection_remarks')->nullable()->after('inspected_by_id');
            $table->text('rejection_reason')->nullable()->after('inspection_remarks');
        });

        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('unit', 50)->default('pcs');
            $table->boolean('tax_inclusive')->default(false);
            $table->date('quoted_on');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id', 'quoted_on', 'unit', 'currency', 'tax_inclusive'], 'supplier_quotes_no_duplicates');
            $table->index(['team_id', 'product_id']);
        });

        Schema::create('product_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'supplier_id']);
            $table->index(['team_id', 'supplier_id']);
        });

        // Link existing purchase orders to their product where the SKU matches exactly.
        DB::table('purchase_orders')->whereNull('product_id')->whereNotNull('item_sku')->orderBy('id')
            ->each(function ($po) {
                $productId = DB::table('products')->where('team_id', $po->team_id)->where('sku', $po->item_sku)->value('id');
                if ($productId) {
                    DB::table('purchase_orders')->where('id', $po->id)->update(['product_id' => $productId]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier');
        Schema::dropIfExists('supplier_quotes');

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspected_by_id');
            $table->dropColumn(['accepted_quantity', 'rejected_quantity', 'inspection_result', 'inspected_on', 'inspection_remarks', 'rejection_reason']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('promised_delivery_date');
        });
    }
};
