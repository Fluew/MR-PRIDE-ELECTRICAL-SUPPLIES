<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttemptLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'order_fulfillment_id',
        'delivery_release_id',
        'attempt_number',
        'previous_status',
        'new_status',
        'staff_id',
        'failure_reason',
        'remarks',
        'proof_image',
        'attempt_date',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'attempt_date' => 'datetime',
    ];

    protected $appends = [
        'proof_url',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function orderFulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * Get secure URL for attempt proof image if available.
     */
    public function getProofUrlAttribute(): ?string
    {
        if (! $this->proof_image) {
            return null;
        }

        $team = $this->team;
        $slug = $team?->slug ?? 'mr-pride';

        return "/{$slug}/deliveries/attempts/{$this->id}/proof";
    }
}
