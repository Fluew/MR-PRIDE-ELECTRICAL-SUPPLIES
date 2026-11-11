<?php

namespace App\Events\Procurement;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An administrator approved a purchase request (its draft purchase order is created in the same transaction). Dispatched only after the change is committed. */
class PurchaseRequestApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseRequest $purchaseRequest, public ?User $actor = null) {}
}
