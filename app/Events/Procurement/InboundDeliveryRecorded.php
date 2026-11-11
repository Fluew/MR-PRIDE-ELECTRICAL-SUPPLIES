<?php

namespace App\Events\Procurement;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Goods were received against a purchase order; an inspection task is now open. Dispatched only after the change is committed. */
class InboundDeliveryRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delivery $delivery, public ?User $actor = null, public int $openBefore = 0) {}
}
