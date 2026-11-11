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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('delivery_date');
            $table->string('delivery_number');
            $table->string('purchase_order_number')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('unit', 50)->default('PCS');
            $table->text('description');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('Delivered');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'delivery_number']);
            $table->index(['team_id', 'delivery_date']);
            $table->index(['team_id', 'purchase_order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
