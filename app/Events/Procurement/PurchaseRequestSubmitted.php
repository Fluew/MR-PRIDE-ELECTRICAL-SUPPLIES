<?php

namespace App\Events\Procurement;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A purchase request went to an administrator for approval. Dispatched only after the change is committed. */
class PurchaseRequestSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseRequest $purchaseRequest, public ?User $actor = null) {}
}
