<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product offered by suppliers (Supplier Product Catalog). It is not an inventory product
 * until it is registered: then `product_id` points to the inventory master record.
 */
class SupplierCatalogProduct extends Model
{
    protected $fillable = [
        'team_id', 'name', 'normalized_name', 'category', 'brand', 'product_id', 'registered_at', 'registered_by_id',
    ];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupplierCatalogPrice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    public function isRegistered(): bool
    {
        return $this->product_id !== null;
    }
}
