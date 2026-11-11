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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('category')->default('General');
            $table->decimal('price', 12, 2)->default(0.00);
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(10);
            $table->string('status')->default('In Stock');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
