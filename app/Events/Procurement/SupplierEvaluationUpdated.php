<?php

namespace App\Events\Procurement;

use Illuminate\Foundation\Events\Dispatchable;

/** Supplier Evaluation was recalculated from procurement records and changed results were snapshotted. */
class SupplierEvaluationUpdated
{
    use Dispatchable;

    public function __construct(public int $teamId, public string $trigger, public int $changed) {}
}
