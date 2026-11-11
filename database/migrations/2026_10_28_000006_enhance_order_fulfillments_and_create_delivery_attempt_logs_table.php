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
        Schema::table('order_fulfillments', function (Blueprint $table) {
            if (! Schema::hasColumn('order_fulfillments', 'attempt_count')) {
                $table->unsignedTinyInteger('attempt_count')->default(0)->after('status');
            }
            if (! Schema::hasColumn('order_fulfillments', 'commercial_invoice_id')) {
                $table->foreignId('commercial_invoice_id')->nullable()->after('sale_id')->constrained('commercial_invoices')->nullOnDelete();
            }
            if (! Schema::hasColumn('order_fulfillments', 'rescheduled_at')) {
                $table->timestamp('rescheduled_at')->nullable()->after('delivered_at');
            }
            if (! Schema::hasColumn('order_fulfillments', 'rescheduled_by_id')) {
                $table->foreignId('rescheduled_by_id')->nullable()->after('rescheduled_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('order_fulfillments', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()->after('notes');
            }
        });

        if (! Schema::hasTable('delivery_attempt_logs')) {
            Schema::create('delivery_attempt_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
                $table->unsignedTinyInteger('attempt_number');
                $table->string('previous_status', 50);
                $table->string('new_status', 50);
                $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
                $table->string('failure_reason', 255)->nullable();
                $table->text('remarks')->nullable();
                $table->string('proof_image', 500)->nullable();
                $table->timestamp('attempt_date')->useCurrent();
                $table->timestamps();

                $table->index(['team_id', 'order_fulfillment_id']);
                $table->index('staff_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_attempt_logs');

        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->dropForeign(['commercial_invoice_id']);
            $table->dropForeign(['rescheduled_by_id']);
            $table->dropColumn([
                'attempt_count',
                'commercial_invoice_id',
                'rescheduled_at',
                'rescheduled_by_id',
                'cancellation_reason',
            ]);
        });
    }
};
