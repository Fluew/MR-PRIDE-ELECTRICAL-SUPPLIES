<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'product_id',
        'user_id',
        'supplier_id',
        'sale_id',
        'delivery_release_id',
        'movement_key',
        'type',
        'quantity',
        'previous_stock',
        'new_stock',
        'reference_number',
        'reason',
        'remarks',
        'movement_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'previous_stock' => 'integer',
            'new_stock' => 'integer',
            'movement_date' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function getFormattedTypeAttribute(): string
    {
        return match ($this->type) {
            'stock_in' => 'Stock In',
            'stock_out' => 'Stock Out',
            'adjustment' => 'Adjustment',
            'sale' => 'Counter Sale',
            'sale_release' => 'Delivered',
            'return' => 'Returned',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }
}
