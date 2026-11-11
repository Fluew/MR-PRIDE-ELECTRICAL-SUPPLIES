<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional brand to products so the public quotation form can list brands from the
 * inventory catalog. Nullable: existing products simply have no brand until one is entered,
 * and no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'brand')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('category');
            $table->index('brand');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'brand')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['brand']);
            $table->dropColumn('brand');
        });
    }
};
