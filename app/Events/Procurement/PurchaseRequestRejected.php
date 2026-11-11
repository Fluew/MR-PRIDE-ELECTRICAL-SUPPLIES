<?php

namespace App\Events\Procurement;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An administrator rejected a purchase request; it stops here. Dispatched only after the change is committed. */
class PurchaseRequestRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseRequest $purchaseRequest, public ?User $actor = null) {}
}
