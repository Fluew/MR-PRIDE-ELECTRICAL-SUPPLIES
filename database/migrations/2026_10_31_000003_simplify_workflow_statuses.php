<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simpler statuses for quotations, Sales Orders and deliveries. Only values change: no rows are
 * deleted and no columns are added. "On hold" stays as the order's hold_reason; approval stays
 * in approved_by_id / approved_at; partial deliveries, returns and reschedules stay in the
 * delivery history and quantities.
 */
return new class extends Migration
{
    private const QUOTATIONS = [
        'pending_review' => 'pending', 'under_review' => 'pending',
        'under_preparation' => 'preparing', 'awaiting_supplier_pricing' => 'preparing',
        'approved' => 'for_approval', // approval itself stays in approved_by_id / approved_at
        'sent_to_customer' => 'sent',
        'cancelled' => 'rejected',
    ];

    private const ORDERS = ['pending_payment' => 'pending'];

    private const DELIVERIES = [
        'preparing_order' => 'preparing', 'ready_for_dispatch' => 'ready_for_delivery',
        'delivery_failed' => 'delivery_attempt_failed', 'delivery_rescheduled' => 'ready_for_delivery',
        'partially_delivered' => 'preparing', 'returned' => 'preparing', 'completed' => 'delivered',
    ];

    public function up(): void
    {
        foreach (self::QUOTATIONS as $old => $new) {
            DB::table('quotations')->where('status', $old)->update(['status' => $new]);
        }

        // On hold becomes a flag: the order goes back to the status it had.
        foreach (DB::table('sales')->where('status', 'on_hold')->get(['id', 'confirmed_at', 'hold_reason']) as $sale) {
            $hasRelease = DB::table('delivery_releases')->where('sale_id', $sale->id)->exists();
            DB::table('sales')->where('id', $sale->id)->update([
                'status' => $sale->confirmed_at === null ? 'pending' : ($hasRelease ? 'processing' : 'confirmed'),
                'hold_reason' => $sale->hold_reason ?: 'On hold',
            ]);
        }
        foreach (self::ORDERS as $old => $new) {
            DB::table('sales')->where('status', $old)->update(['status' => $new]);
        }

        foreach (self::DELIVERIES as $old => $new) {
            DB::table('order_fulfillments')->where('status', $old)->update(['status' => $new]);
            DB::table('delivery_attempt_logs')->where('new_status', $old)->update(['new_status' => $new]);
            DB::table('delivery_attempt_logs')->where('previous_status', $old)->update(['previous_status' => $new]);
        }

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('status')->default('preparing')->change();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('status')->default('under_preparation')->change();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status')->default('pending_payment')->change();
        });

        $back = ['pending' => 'pending_review', 'preparing' => 'under_preparation', 'sent' => 'sent_to_customer'];
        foreach ($back as $new => $old) {
            DB::table('quotations')->where('status', $new)->update(['status' => $old]);
        }
        DB::table('quotations')->where('status', 'for_approval')->whereNotNull('approved_at')->update(['status' => 'approved']);
        DB::table('sales')->where('status', 'pending')->update(['status' => 'pending_payment']);

        $deliveries = ['preparing' => 'preparing_order', 'ready_for_delivery' => 'ready_for_dispatch', 'delivery_attempt_failed' => 'delivery_failed'];
        foreach ($deliveries as $new => $old) {
            DB::table('order_fulfillments')->where('status', $new)->update(['status' => $old]);
            DB::table('delivery_attempt_logs')->where('new_status', $new)->update(['new_status' => $old]);
            DB::table('delivery_attempt_logs')->where('previous_status', $new)->update(['previous_status' => $old]);
        }
    }
};
