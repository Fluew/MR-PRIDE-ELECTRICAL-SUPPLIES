<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supplier's quoted price for one product, compared in Price Competitiveness once verified. */
class SupplierQuote extends Model
{
    protected $fillable = [
        'team_id', 'supplier_id', 'product_id', 'reference', 'unit_price', 'currency', 'unit',
        'tax_inclusive', 'quoted_on', 'valid_until', 'notes', 'recorded_by_id', 'verified_at', 'verified_by_id',
        'quantity', 'amount', 'unit_price_source', 'amount_source', 'description_as_quoted', 'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'quoted_on' => 'date',
            'valid_until' => 'date',
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
