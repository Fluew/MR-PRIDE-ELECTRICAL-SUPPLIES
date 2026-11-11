<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound P.O. deliveries with several products, supplier SKU mappings and purchase cost history.
 *
 * - inbound_deliveries: one record per delivery (Delivery #, date, purchase order, supplier, who
 *   confirmed it, confirmed/cancelled). Its products are the existing `deliveries` rows (one per
 *   purchase order line), which keep their inspection and stock-posting columns.
 * - supplier_sku_mappings: a supplier's own product code → our inventory product, so a product is
 *   matched by an explicit link, never by its description.
 * - supplier_product_costs: what each supplier charged for a product on each delivery (history;
 *   purchase order prices are never overwritten).
 *
 * Existing deliveries get a record each (rows sharing a delivery number share one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_number', 60);
            $table->date('delivery_date');
            $table->string('purchase_order_number')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('confirmed'); // confirmed | cancelled
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('supplier_document_number', 100)->nullable();
            $table->text('notes')->nullable();
            // Sent by the form once per confirmation: a double click or a resubmission cannot record it twice.
            $table->string('submission_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->unique(['team_id', 'delivery_number']);
            $table->index(['team_id', 'delivery_date']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('inbound_delivery_id')->nullable()->after('team_id')->constrained('inbound_deliveries')->nullOnDelete();
        });

        Schema::create('supplier_sku_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_sku', 100);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'supplier_id', 'supplier_sku']);
        });

        Schema::create('supplier_product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_sku', 100)->nullable();
            $table->decimal('unit_cost', 14, 4);
            $table->string('unit_of_measure', 50)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->date('recorded_on');
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'product_id', 'supplier_id', 'recorded_on'], 'supplier_costs_lookup_index');
        });

        // One record per existing delivery number (deliveries sharing a number become its products).
        $groups = DB::table('deliveries')
            ->whereNull('deleted_at')
            ->selectRaw('team_id, delivery_number, MIN(delivery_date) as delivery_date, MIN(purchase_order_number) as po_number,
                MIN(supplier_id) as supplier_id, MIN(received_by_id) as received_by_id, MIN(created_at) as created_at,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled, COUNT(*) as items', ['Cancelled'])
            ->groupBy('team_id', 'delivery_number')
            ->get();
        foreach ($groups as $g) {
            $id = DB::table('inbound_deliveries')->insertGetId([
                'team_id' => $g->team_id,
                'delivery_number' => mb_substr((string) $g->delivery_number, 0, 60),
                'delivery_date' => $g->delivery_date,
                'purchase_order_number' => $g->po_number,
                'supplier_id' => $g->supplier_id,
                'status' => (int) $g->cancelled === (int) $g->items ? 'cancelled' : 'confirmed',
                'confirmed_by_id' => $g->received_by_id,
                'confirmed_at' => $g->created_at,
                'created_at' => $g->created_at ?? now(),
                'updated_at' => now(),
            ]);
            DB::table('deliveries')->where('team_id', $g->team_id)->where('delivery_number', $g->delivery_number)->whereNull('deleted_at')
                ->update(['inbound_delivery_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_costs');
        Schema::dropIfExists('supplier_sku_mappings');
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inbound_delivery_id');
        });
        Schema::dropIfExists('inbound_deliveries');
    }
};
