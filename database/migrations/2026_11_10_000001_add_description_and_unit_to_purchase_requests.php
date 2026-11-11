<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New Purchase Request form: what staff wrote about the product they need and the unit of the
 * requested quantity, kept on the request itself (the product record is never changed).
 * Existing requests keep null: nothing is invented for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->text('product_description')->nullable()->after('product_id');
            $table->string('unit', 30)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['product_description', 'unit']);
        });
    }
};
