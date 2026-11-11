<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a supplier charged for one of our products on a received delivery (purchase cost
 * history). Rows are only added; purchase order prices themselves are never changed.
 */
class SupplierProductCost extends Model
{
    protected $fillable = [
        'team_id', 'supplier_id', 'product_id', 'supplier_sku', 'unit_cost', 'unit_of_measure',
        'purchase_order_id', 'delivery_id', 'recorded_on', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4', 'recorded_on' => 'date'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
