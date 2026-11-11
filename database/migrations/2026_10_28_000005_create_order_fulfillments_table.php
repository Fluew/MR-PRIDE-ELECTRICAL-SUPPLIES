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
        if (! Schema::hasTable('order_fulfillments')) {
            Schema::create('order_fulfillments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
                $table->string('fulfillment_number', 100)->unique();
                $table->string('fulfillment_type', 50)->default('Delivery');
                $table->string('recipient_name', 255);
                $table->string('contact_number', 50);
                $table->text('delivery_address')->nullable();
                $table->dateTime('delivery_schedule')->nullable();
                $table->text('instructions')->nullable();
                $table->string('status', 50)->default('Preparing');
                $table->string('proof_of_delivery', 500)->nullable();
                $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('dispatched_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'status']);
                $table->index(['team_id', 'sale_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_fulfillments');
    }
};
