<?php

namespace App\Models;

use App\Enums\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment a customer submitted for a Sales Order, and its verification. Records are never
 * deleted or overwritten: a rejected payment stays rejected, a correction is a new submission.
 */
class Payment extends Model
{
    public const METHODS = ['Bank Transfer', 'GCash'];

    protected $fillable = [
        'team_id', 'sale_id', 'method', 'submitted_amount', 'submitted_reference', 'payment_date',
        'proof_path', 'remarks', 'recorded_by_id', 'status', 'verified_reference', 'verified_reference_key',
        'verified_amount', 'verified_payment_date', 'verified_by_id', 'verified_at', 'verification_remarks',
        'rejected_by_id', 'rejected_at', 'rejection_reason', 'refunded_by_id', 'refunded_at', 'refund_reason',
        'idempotency_key',
    ];

    protected $hidden = ['proof_path', 'verified_reference_key', 'idempotency_key'];

    protected $appends = ['proof_url', 'status_label'];

    protected function casts(): array
    {
        return [
            'status' => PaymentRecordStatus::class,
            'submitted_amount' => 'decimal:2',
            'verified_amount' => 'decimal:2',
            'payment_date' => 'date:Y-m-d',
            'verified_payment_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** Authenticated, team-checked route; proof files are never in public storage. */
    public function getProofUrlAttribute(): ?string
    {
        return $this->proof_path ? route('sales.payments.proof', ['sale' => $this->sale_id, 'payment' => $this->id]) : null;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /** Normalised "method|reference", used to refuse the same verified transaction twice. */
    public static function referenceKey(string $method, string $reference): string
    {
        return mb_strtolower($method).'|'.mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reference));
    }

    public function sale(): BelongsTo
    {
        // Documents stay readable when their order is archived.
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }
}
