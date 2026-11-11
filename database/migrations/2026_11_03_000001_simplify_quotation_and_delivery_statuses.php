<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation statuses become Draft, Sent, Accepted, Rejected, Expired; delivery statuses become
 * Pending, Ready for Delivery, In Transit, Delivered, Cancelled (Sales Order statuses are
 * unchanged). Stored values (records and delivery history) are mapped to the new ones, and one
 * Sales Order per quotation and one delivery record per Sales Order are enforced by the database.
 */
return new class extends Migration
{
    /** Old quotation status => new. "For approval" is kept as submitted_for_approval_at. */
    private const QUOTATION = ['pending' => 'draft', 'preparing' => 'draft', 'for_approval' => 'draft', 'converted' => 'accepted'];

    /** Old delivery status => new. A failed attempt leaves the goods on the truck, ready to go again. */
    private const DELIVERY = ['order_confirmed' => 'pending', 'preparing' => 'pending', 'out_for_delivery' => 'in_transit', 'delivery_attempt_failed' => 'ready_for_delivery'];

    public function up(): void
    {
        $this->assertNoDuplicates();

        DB::transaction(function () {
            DB::table('quotations')->where('status', 'for_approval')->whereNull('submitted_for_approval_at')
                ->update(['submitted_for_approval_at' => DB::raw('updated_at')]);
            foreach (self::QUOTATION as $old => $new) {
                DB::table('quotations')->where('status', $old)->update(['status' => $new]);
            }
            foreach (self::DELIVERY as $old => $new) {
                DB::table('order_fulfillments')->where('status', $old)->update(['status' => $new]);
                DB::table('delivery_attempt_logs')->where('new_status', $old)->update(['new_status' => $new]);
                DB::table('delivery_attempt_logs')->where('previous_status', $old)->update(['previous_status' => $new]);
            }
        });

        Schema::table('sales', fn (Blueprint $table) => $table->unique('quotation_id', 'sales_quotation_id_unique'));
        Schema::table('order_fulfillments', fn (Blueprint $table) => $table->unique('sale_id', 'order_fulfillments_sale_id_unique'));
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', fn (Blueprint $table) => $table->dropUnique('order_fulfillments_sale_id_unique'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropUnique('sales_quotation_id_unique'));

        DB::transaction(function () {
            DB::table('quotations')->where('status', 'draft')->whereNotNull('submitted_for_approval_at')->update(['status' => 'for_approval']);
            DB::table('quotations')->where('status', 'draft')->update(['status' => 'preparing']);
            DB::table('quotations')->where('status', 'accepted')->whereNotNull('converted_to_sale_id')->update(['status' => 'converted']);
            foreach (['pending' => 'order_confirmed', 'in_transit' => 'out_for_delivery'] as $new => $old) {
                DB::table('order_fulfillments')->where('status', $new)->update(['status' => $old]);
                DB::table('delivery_attempt_logs')->where('new_status', $new)->update(['new_status' => $old]);
                DB::table('delivery_attempt_logs')->where('previous_status', $new)->update(['previous_status' => $old]);
            }
        });
    }

    /** Never delete business records to make an index fit: stop and say what to resolve. */
    private function assertNoDuplicates(): void
    {
        $sales = DB::table('sales')->whereNotNull('quotation_id')->select('quotation_id')->groupBy('quotation_id')->havingRaw('count(*) > 1')->pluck('quotation_id');
        $deliveries = DB::table('order_fulfillments')->whereNotNull('sale_id')->select('sale_id')->groupBy('sale_id')->havingRaw('count(*) > 1')->pluck('sale_id');
        if ($sales->isNotEmpty() || $deliveries->isNotEmpty()) {
            throw new RuntimeException('Duplicate records must be resolved before this update: '
                .($sales->isNotEmpty() ? 'quotations with more than one Sales Order (quotation ids '.$sales->implode(', ').') ' : '')
                .($deliveries->isNotEmpty() ? 'Sales Orders with more than one delivery record (sale ids '.$deliveries->implode(', ').')' : ''));
        }
    }
};
