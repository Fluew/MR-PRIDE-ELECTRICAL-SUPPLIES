<?php

namespace App\Events\Procurement;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A delivery was inspected (accepted, damaged, defective and other rejected units). Dispatched only after the change is committed. */
class QualityInspectionCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delivery $delivery, public ?User $actor = null) {}
}
