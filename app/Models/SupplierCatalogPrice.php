<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supplier's purchase cost (USD) for one catalog product, in its pricing unit. Simulated
 * staging costs, not verified quotations: they never change purchase orders, AHP results or
 * selling prices.
 */
class SupplierCatalogPrice extends Model
{
    protected $fillable = [
        'team_id', 'supplier_catalog_product_id', 'supplier_id', 'unit_cost', 'currency', 'pricing_unit',
        'pricing_unit_label', 'unit_confirmed', 'is_simulated', 'source_file',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'unit_confirmed' => 'boolean',
            'is_simulated' => 'boolean',
        ];
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogProduct::class, 'supplier_catalog_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
