<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered line. Quantities, all in the line's unit:
 *  - quantity            ordered
 *  - reserved_quantity   held in stock for this order, not yet taken out
 *  - deducted_quantity   taken out of inventory (net of returns put back)
 *  - released_quantity   sent out in delivery releases (net of returns)
 *  - delivered_quantity  received by the customer
 *  - cancelled_quantity  no longer owed (cancelled through an authorised action)
 */
class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'quotation_item_id',
        'product_id',
        'sku',
        'product_name',
        'quantity',
        'unit_price',
        'discount',
        'subtotal',
        'reserved_quantity',
        'deducted_quantity',
        'released_quantity',
        'delivered_quantity',
        'cancelled_quantity',
    ];

    protected $appends = ['remaining_quantity', 'releasable_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'reserved_quantity' => 'integer',
            'deducted_quantity' => 'integer',
            'released_quantity' => 'integer',
            'delivered_quantity' => 'integer',
            'cancelled_quantity' => 'integer',
        ];
    }

    /** Still owed to the customer. */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->delivered_quantity - (int) $this->cancelled_quantity);
    }

    /** Can still go into a new release (not delivered, not cancelled, not already on the way). */
    public function getReleasableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->cancelled_quantity - (int) $this->released_quantity);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
