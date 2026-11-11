<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued sales invoice: a snapshot of the order at the time of issue. Issued invoices are
 * never edited; a correction cancels the invoice (with a reason) and issues a replacement.
 */
class SalesInvoice extends Model
{
    public const ISSUED = 'issued';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'team_id', 'sale_id', 'invoice_number', 'status', 'issue_key', 'customer', 'items', 'subtotal',
        'discount', 'tax_rate', 'tax_amount', 'grand_total', 'issued_at', 'issued_by_id', 'cancelled_at',
        'cancelled_by_id', 'cancellation_reason', 'replaces_invoice_id', 'pdf_path',
    ];

    protected $hidden = ['pdf_path', 'issue_key'];

    protected $appends = ['pdf_url'];

    protected function casts(): array
    {
        return [
            'customer' => 'array',
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getPdfUrlAttribute(): string
    {
        return route('sales.invoices.pdf', ['sale' => $this->sale_id, 'invoice' => $this->id]);
    }

    public function sale(): BelongsTo
    {
        // Documents stay readable when their order is archived.
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id');
    }
}
