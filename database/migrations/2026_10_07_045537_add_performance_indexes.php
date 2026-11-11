<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for frequently queried columns.
     * Covers WHERE/ORDER BY clauses in InventoryController and QuotationController.
     */
    public function up(): void
    {
        // Products: composite indexes for base inventory filter
        Schema::table('products', function (Blueprint $table) {
            $table->index(['team_id', 'is_active'], 'products_team_active_idx');
            $table->index(['team_id', 'status'], 'products_team_status_idx');
            $table->index(['team_id', 'name'], 'products_team_name_idx');
            $table->index(['team_id', 'category'], 'products_team_category_idx');
        });

        // Stock movements: covers history/audit listing order
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['team_id', 'movement_date'], 'movements_team_date_idx');
            $table->index(['team_id', 'product_id', 'movement_date'], 'movements_team_product_date_idx');
        });

        // Quotations: covers filter + auto-expire query
        Schema::table('quotations', function (Blueprint $table) {
            $table->index(['team_id', 'quotation_date'], 'quotations_team_date_idx');
            $table->index(['team_id', 'status', 'quotation_date'], 'quotations_team_status_date_idx');
            $table->index(['team_id', 'valid_until'], 'quotations_team_valid_until_idx');
        });

        // Clients: covers firstOrCreate and ordering
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['team_id', 'company_name'], 'clients_team_name_idx');
        });

        // Suppliers: covers Active filter
        Schema::table('suppliers', function (Blueprint $table) {
            $table->index(['team_id', 'status'], 'suppliers_team_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_team_active_idx');
            $table->dropIndex('products_team_status_idx');
            $table->dropIndex('products_team_name_idx');
            $table->dropIndex('products_team_category_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('movements_team_date_idx');
            $table->dropIndex('movements_team_product_date_idx');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_team_date_idx');
            $table->dropIndex('quotations_team_status_date_idx');
            $table->dropIndex('quotations_team_valid_until_idx');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_team_name_idx');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('suppliers_team_status_idx');
        });
    }
};
