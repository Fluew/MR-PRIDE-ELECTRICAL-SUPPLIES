<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quotation a supplier sent externally (email, paper…), as recorded by staff. Never entered by the supplier. */
class SupplierBid extends Model
{
    public const SUBMITTED = 'Submitted';

    public const AWARDED = 'Awarded';

    public const NOT_AWARDED = 'Not Awarded';

    protected $fillable = [
        'bidding_event_id', 'supplier_id', 'unit_price', 'quantity', 'lead_time_days', 'quotation_date', 'received_at',
        'valid_until', 'quotation_reference', 'notes', 'document_path', 'document_name', 'revision', 'status', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quotation_date' => 'date',
            'received_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(BiddingEvent::class, 'bidding_event_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function total(): float
    {
        return round((float) $this->unit_price * $this->quantity, 2);
    }
}
