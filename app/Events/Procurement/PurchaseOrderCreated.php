<?php

namespace App\Events\Procurement;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A draft purchase order was generated from an approved purchase request. Dispatched only after the change is committed. */
class PurchaseOrderCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $purchaseOrder, public ?User $actor = null, public bool $automatic = true) {}
}
