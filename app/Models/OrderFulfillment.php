<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Services\DocumentNumbers;
use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The delivery (or pickup) record of one Sales Order. Created when the order is confirmed, with
 * status Pending. Each trip or pickup is a DeliveryRelease; every status change is kept
 * in delivery_attempt_logs (the status history).
 */
class OrderFulfillment extends Model
{
    use HasFactory, SoftDeletes, TracksDeletions;

    protected $table = 'order_fulfillments';

    /** Failed attempts allowed per trip before an administrator has to reschedule. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'team_id',
        'sale_id',
        'commercial_invoice_id',
        'fulfillment_number',
        'fulfillment_type',
        'recipient_name',
        'contact_number',
        'delivery_address',
        'delivery_schedule',
        'instructions',
        'status',
        'attempt_count',
        'proof_of_delivery',
        'handled_by_id',
        'dispatched_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
        'rescheduled_at',
        'rescheduled_by_id',
        'notes',
        'cancellation_reason',
        'deleted_by_id',
    ];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'attempt_count' => 'integer',
        'delivery_schedule' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rescheduled_at' => 'datetime',
    ];

    protected $appends = [
        'proof_url',
        'remaining_attempts',
        'attempts_display',
        'status_label',
    ];

    public function getProofUrlAttribute(): ?string
    {
        if (! $this->proof_of_delivery) {
            return null;
        }

        $slug = $this->team?->slug ?? 'mr-pride';

        return "/{$slug}/deliveries/fulfillments/{$this->id}/proof";
    }

    public function getRemainingAttemptsAttribute(): int
    {
        return max(0, self::MAX_ATTEMPTS - (int) $this->attempt_count);
    }

    public function getAttemptsDisplayAttribute(): string
    {
        return sprintf('%d/%d', min(self::MAX_ATTEMPTS, (int) $this->attempt_count), self::MAX_ATTEMPTS);
    }

    public function getStatusLabelAttribute(): ?string
    {
        return $this->status?->label();
    }

    public function isPickup(): bool
    {
        return $this->fulfillment_type === 'pickup';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function commercialInvoice(): BelongsTo
    {
        return $this->belongsTo(CommercialInvoice::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_id');
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by_id');
    }

    /** Status history, newest first (attempt_number 0 = a status change, not a failed attempt). */
    public function attemptLogs(): HasMany
    {
        return $this->hasMany(DeliveryAttemptLog::class, 'order_fulfillment_id')->latest('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(DeliveryRelease::class, 'order_fulfillment_id')->latest('id');
    }

    /** The release being prepared or on the road, if any. */
    public function openRelease(): ?DeliveryRelease
    {
        return $this->releases()->whereIn('status', DeliveryRelease::OPEN)->first();
    }

    public function canAttempt(): bool
    {
        return $this->attempt_count < self::MAX_ATTEMPTS && $this->status === DeliveryStatus::InTransit;
    }

    /** The release being delivered has left the warehouse and is not received yet (goods on the truck). */
    public function goodsOnTruck(?DeliveryRelease $open = null): bool
    {
        return ($open ?? $this->openRelease())?->status === DeliveryRelease::DISPATCHED;
    }

    /** A delivery attempt failed: the goods are still on the truck, waiting to go out again. */
    public function awaitingRedelivery(?DeliveryRelease $open = null): bool
    {
        return $this->status === DeliveryStatus::ReadyForDelivery && $this->attempt_count > 0 && $this->goodsOnTruck($open);
    }

    /**
     * The attempt limit is reached and no administrator has rescheduled since the last failed
     * attempt: it cannot go out again until it is rescheduled.
     */
    public function needsReschedule(?DeliveryRelease $open = null): bool
    {
        if (! $this->awaitingRedelivery($open) || $this->attempt_count < self::MAX_ATTEMPTS) {
            return false;
        }
        $lastFailure = $this->attemptLogs()->where('attempt_number', '>', 0)->max('created_at');

        return ! $this->rescheduled_at || ($lastFailure && $this->rescheduled_at->lt($lastFailure));
    }

    public static function generateFulfillmentNumber(int $teamId, mixed $date = null): string
    {
        return DocumentNumbers::next($teamId, DocumentNumbers::FULFILLMENT);
    }
}
