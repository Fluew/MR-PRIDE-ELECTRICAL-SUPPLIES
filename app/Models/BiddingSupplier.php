<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered supplier invited to quote on a bidding event. */
class BiddingSupplier extends Model
{
    protected $fillable = ['bidding_event_id', 'supplier_id', 'invited_by_id', 'rfq_sent_at'];

    protected function casts(): array
    {
        return ['rfq_sent_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(BiddingEvent::class, 'bidding_event_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
