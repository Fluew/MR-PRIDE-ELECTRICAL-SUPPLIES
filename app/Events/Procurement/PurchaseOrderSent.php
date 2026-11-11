<?php

namespace App\Events\Procurement;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff finalised the purchase order and sent it to the supplier. Dispatched only after the change is committed. */
class PurchaseOrderSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $purchaseOrder, public ?User $actor = null) {}
}
