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
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['team_id', 'sale_date', 'status'], 'sales_forecast_period_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id', 'team_id'], 'movements_forecast_link_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_forecast_period_idx'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropIndex('movements_forecast_link_idx'));
    }
};
