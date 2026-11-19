<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Product Catalog: what suppliers offer and at what purchase cost, before a product is
 * registered in inventory.
 *
 * `products` is the inventory master: every row is listed, counted (Out of Stock, valuation),
 * offered in quotations and matched by the material-list reader. Products that are only offered
 * by suppliers therefore cannot be stored there without changing those modules, and
 * `supplier_quotes` / `product_supplier` both require a product. These tables hold the catalog
 * until staff register a product; registration then creates (or reuses) the inventory product
 * and links it here through `product_id`.
 *
 * - supplier_catalog_products: one row per distinct product specification (per team).
 * - supplier_catalog_prices: one row per catalog product and supplier, with that supplier's
 *   purchase cost (USD) and pricing unit. Selling prices are never stored here.
 *
 * Purely additive: no existing table or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Matching key: lowercase, unified dashes/spaces (see SupplierCatalogNormalizer). Specs are kept.
            $table->string('normalized_name');
            $table->string('category', 100);
            $table->string('brand', 100)->nullable();
            // Inventory product once registered (or matched); null = catalog only.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'normalized_name']);
            $table->index(['team_id', 'category']);
            $table->index('product_id');
        });

        Schema::create('supplier_catalog_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_catalog_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_cost', 12, 2);
            $table->char('currency', 3)->default('USD');
            // Normalized pricing unit (pcs, pack, m, spool) and the label as given in the source file.
            $table->string('pricing_unit', 50);
            $table->string('pricing_unit_label', 100)->nullable();
            // false when the source says the unit must still be confirmed (e.g. "pc or set (verify UOM)").
            $table->boolean('unit_confirmed')->default(false);
            // Simulated staging costs, not verified supplier quotations.
            $table->boolean('is_simulated')->default(true);
            $table->string('source_file', 191)->nullable();
            $table->timestamps();

            $table->unique(['supplier_catalog_product_id', 'supplier_id'], 'supplier_catalog_prices_unique_pair');
            $table->index(['team_id', 'supplier_id']);
            $table->index(['team_id', 'unit_cost']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_prices');
        Schema::dropIfExists('supplier_catalog_products');
    }
};
