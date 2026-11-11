<?php

namespace App\Events\Procurement;

use App\Models\Delivery;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Accepted units of a delivery were posted to stock (automatically at inspection). Dispatched only after the change is committed. */
class InventoryUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delivery $delivery, public ?User $actor = null, public ?StockMovement $movement = null) {}
}
