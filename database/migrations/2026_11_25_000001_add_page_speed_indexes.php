<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the slowest queries found by profiling the pages:
 *  - notifications: the bell's unread count and latest-first list (polled on every page);
 *  - stock_movements: first demand date per product (forecast, product prioritization);
 *  - sale_items: sales per product (forecast, top products, reports);
 *  - quotations: the dashboard's most recent quotations.
 * Additive only; no data changes.
 */
return new class extends Migration
{
    private const INDEXES = [
        'notifications' => [
            'notifications_owner_unread_idx' => ['notifiable_type', 'notifiable_id', 'read_at'],
            'notifications_owner_created_idx' => ['notifiable_type', 'notifiable_id', 'created_at'],
        ],
        'stock_movements' => [
            'movements_team_type_product_date_idx' => ['team_id', 'type', 'product_id', 'movement_date'],
        ],
        'sale_items' => [
            'sale_items_product_sale_idx' => ['product_id', 'sale_id'],
        ],
        'quotations' => [
            'quotations_team_created_idx' => ['team_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
