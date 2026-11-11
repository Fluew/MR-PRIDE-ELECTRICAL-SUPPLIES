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
        Schema::create('commercial_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('invoice_date');
            $table->string('invoice_number');
            $table->integer('quantity')->default(1);
            $table->text('description');
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('Received');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'invoice_number']);
            $table->index(['team_id', 'invoice_date']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('po_date');
            $table->string('po_number');
            $table->integer('quantity')->default(1);
            $table->string('item_sku')->nullable();
            $table->text('description');
            $table->string('unit_of_measure', 50)->nullable()->default('PCS');
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('Issued');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'po_number']);
            $table->index(['team_id', 'po_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('commercial_invoices');
    }
};
