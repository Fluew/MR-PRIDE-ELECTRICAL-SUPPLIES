<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's own product code (the ITEM/SKU Number on its purchase orders) linked to our
 * inventory product. Our internal SKU is never replaced by the supplier's code.
 */
class SupplierSkuMapping extends Model
{
    protected $fillable = ['team_id', 'supplier_id', 'supplier_sku', 'product_id', 'created_by_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The product linked to a supplier's code, if any. */
    public static function productFor(int $teamId, ?int $supplierId, ?string $supplierSku): ?Product
    {
        $sku = trim((string) $supplierSku);
        if (! $supplierId || $sku === '') {
            return null;
        }

        return static::where('team_id', $teamId)->where('supplier_id', $supplierId)->where('supplier_sku', $sku)->first()?->product;
    }
}
