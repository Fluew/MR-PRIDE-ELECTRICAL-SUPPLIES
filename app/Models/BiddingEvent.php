<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request for quotations (RFQ) on one approved purchase request. Managed entirely by staff. */
class BiddingEvent extends Model
{
    use TracksDeletions;

    public const DRAFT = 'Draft';

    public const OPEN = 'Open for Quotations';

    public const CLOSED = 'Closed';

    public const AWARDED = 'Awarded';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::DRAFT, self::OPEN, self::CLOSED, self::AWARDED, self::CANCELLED];

    protected $fillable = [
        'team_id', 'purchase_request_id', 'reference', 'status', 'submission_deadline', 'rfq_notes', 'rfq_issued_at',
        'closed_at', 'closed_by_id', 'awarded_bid_id', 'awarded_by_id', 'awarded_at', 'award_remarks', 'award_snapshot',
        'purchase_order_id', 'cancel_reason', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'submission_deadline' => 'datetime',
            'rfq_issued_at' => 'datetime',
            'closed_at' => 'datetime',
            'awarded_at' => 'datetime',
            'award_snapshot' => 'array',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BiddingSupplier::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(SupplierBid::class);
    }

    public function awardedBid(): BelongsTo
    {
        return $this->belongsTo(SupplierBid::class, 'awarded_bid_id');
    }

    /** Quotations can still be recorded or revised. */
    public function acceptsBids(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::AWARDED, self::CANCELLED], true);
    }
}
