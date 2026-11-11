<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->index(['product_id', 'reserved_quantity'], 'sale_items_product_reserved_idx');
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index(['team_id', 'status'], 'payments_team_status_idx');
            });
        }

        if (Schema::hasTable('order_fulfillments')) {
            Schema::table('order_fulfillments', function (Blueprint $table) {
                $table->index(['team_id', 'handled_by_id', 'status'], 'fulfillments_team_handler_status_idx');
            });
        }

        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'subject_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex('sale_items_product_reserved_idx');
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_team_status_idx');
            });
        }

        if (Schema::hasTable('order_fulfillments')) {
            Schema::table('order_fulfillments', function (Blueprint $table) {
                $table->dropIndex('fulfillments_team_handler_status_idx');
            });
        }

        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'subject_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('audit_logs_subject_idx');
            });
        }
    }
};

