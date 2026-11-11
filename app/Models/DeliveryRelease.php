<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery trip or pickup for part (or all) of a Sales Order. Earlier releases are kept, so
 * the order shows every release with the quantities it carried, delivered and returned.
 */
class DeliveryRelease extends Model
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const DISPATCHED = 'dispatched';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /** Statuses in which the goods are still with us or on the road (the release is not finished). */
    public const OPEN = [self::PREPARING, self::READY, self::DISPATCHED];

    protected $fillable = [
        'team_id', 'order_fulfillment_id', 'sale_id', 'release_number', 'type', 'status', 'prepared_by_id',
        'dispatched_at', 'released_by_id', 'delivered_at', 'received_by_name', 'acknowledged_by_id',
        'proof_path', 'returned_at', 'return_reason', 'cancelled_at', 'remarks',
    ];

    protected $hidden = ['proof_path'];

    protected $appends = ['proof_url'];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getProofUrlAttribute(): ?string
    {
        return $this->proof_path ? route('deliveries.releases.proof', ['release' => $this->id]) : null;
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function sale(): BelongsTo
    {
        // Documents stay readable when their order is archived.
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryReleaseItem::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }
}
