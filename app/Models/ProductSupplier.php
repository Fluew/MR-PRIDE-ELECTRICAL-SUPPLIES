<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Whether a supplier can currently supply a product; counted by AHP only once verified. */
class ProductSupplier extends Model
{
    protected $table = 'product_supplier';

    protected $fillable = [
        'team_id', 'product_id', 'supplier_id', 'is_available', 'notes', 'recorded_by_id', 'verified_at', 'verified_by_id',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
