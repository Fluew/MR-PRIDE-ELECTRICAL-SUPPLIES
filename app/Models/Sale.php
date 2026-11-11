<?php

namespace App\Models;

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Sales Order: created from an accepted quotation (channel "quotation") or at the sales
 * counter (channel "counter"). Its order status, payment status and delivery status
 * (on the fulfillment) are kept separately and each changes only through its own action.
 *
 * amount_paid and outstanding_balance are kept up to date from verified payments only.
 * invoice_number holds the number of the current issued sales invoice (null until issued).
 */
class Sale extends Model
{
    use HasFactory, TracksDeletions;

    public const CHANNEL_QUOTATION = 'quotation';

    public const CHANNEL_COUNTER = 'counter';

    public const TERMS_FULL = 'full';

    public const TERMS_DOWN_PAYMENT = 'down_payment';

    public const TERMS_CREDIT = 'credit';

    protected $fillable = [
        'team_id',
        'order_number',
        'channel',
        'fulfillment_type',
        'invoice_number',
        'quotation_id',
        'client_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'delivery_instructions',
        'sale_date',
        'subtotal',
        'discount',
        'tax_amount',
        'grand_total',
        'status',
        'payment_method',
        'payment_reference',
        'amount_paid',
        'outstanding_balance',
        'payment_date',
        'payment_status',
        'payment_terms',
        'required_down_payment',
        'credit_days',
        'credit_due_date',
        'terms_approved_by_id',
        'terms_approved_at',
        'confirmed_at',
        'confirmed_by_id',
        'completed_at',
        'completed_by_id',
        'cancelled_at',
        'cancelled_by_id',
        'cancellation_reason',
        'hold_reason',
        'proof_of_payment',
        'verified_by_id',
        'verified_at',
        'notes',
        'processed_by_id',
        'deleted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'sale_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'required_down_payment' => 'decimal:2',
            'credit_days' => 'integer',
            'credit_due_date' => 'date:Y-m-d',
            'payment_date' => 'datetime',
            'verified_at' => 'datetime',
            'terms_approved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected $appends = [
        'proof_url',
        'status_label',
        'payment_status_label',
        'is_on_hold',
    ];

    public function getProofUrlAttribute(): ?string
    {
        // Authenticated, team-checked route (current_team comes from the URL defaults set per request).
        return $this->proof_of_payment ? route('sales.proof', ['sale' => $this->id]) : null;
    }

    public function getStatusLabelAttribute(): ?string
    {
        return $this->status?->label();
    }

    /** On hold is a flag, not a status: releases and confirmation wait until it is resumed. */
    public function getIsOnHoldAttribute(): bool
    {
        return filled($this->hold_reason);
    }

    public function getPaymentStatusLabelAttribute(): ?string
    {
        return $this->payment_status?->label();
    }

    /** Sum of verified payments (refunded ones excluded). */
    public function verifiedTotal(): float
    {
        return round((float) $this->payments()->where('status', PaymentRecordStatus::Verified->value)->sum('verified_amount'), 2);
    }

    public function isPickup(): bool
    {
        return $this->fulfillment_type === 'pickup';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function fulfillment(): HasOne
    {
        return $this->hasOne(OrderFulfillment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class)->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class)->latest('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(DeliveryRelease::class)->latest('id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function termsApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terms_approved_by_id');
    }
}
