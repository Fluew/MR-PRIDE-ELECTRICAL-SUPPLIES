<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acknowledgment receipt for one verified payment, for the amount actually received. It is not
 * a BIR official receipt and not a sales invoice.
 */
class PaymentReceipt extends Model
{
    protected $fillable = [
        'team_id', 'sale_id', 'payment_id', 'receipt_number', 'amount_received', 'order_total',
        'previously_paid', 'balance_after', 'method', 'reference', 'payment_date', 'pdf_path',
        'generated_by_id', 'generated_at',
    ];

    protected $hidden = ['pdf_path'];

    protected $appends = ['pdf_url'];

    protected function casts(): array
    {
        return [
            'amount_received' => 'decimal:2',
            'order_total' => 'decimal:2',
            'previously_paid' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payment_date' => 'date:Y-m-d',
            'generated_at' => 'datetime',
        ];
    }

    public function getPdfUrlAttribute(): string
    {
        return route('sales.receipts.pdf', ['sale' => $this->sale_id, 'receipt' => $this->id]);
    }

    public function sale(): BelongsTo
    {
        // Documents stay readable when their order is archived.
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }
}
