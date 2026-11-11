<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'sku',
        'product_name',
        'quantity',
        'unit_price',
        'unit_cost',
        'supplier_id',
        'discount',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected $appends = [
        'available_stock',
        'shortage',
    ];

    public function getAvailableStockAttribute(): int
    {
        // Stock not reserved for confirmed orders.
        return $this->product?->availableStock() ?? 0;
    }

    public function getShortageAttribute(): int
    {
        if (! $this->product_id || ! $this->product) {
            return 0;
        }

        return max(0, (int) $this->quantity - $this->product->availableStock());
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
