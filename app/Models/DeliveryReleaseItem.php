<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryReleaseItem extends Model
{
    protected $fillable = [
        'delivery_release_id', 'sale_item_id', 'product_id', 'quantity', 'delivered_quantity',
        'returned_quantity', 'deducted_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'delivered_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'deducted_quantity' => 'integer',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(DeliveryRelease::class, 'delivery_release_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
