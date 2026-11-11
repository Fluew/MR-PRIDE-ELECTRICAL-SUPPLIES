<?php

namespace App\Events\Procurement;

use Illuminate\Foundation\Events\Dispatchable;

/** AHP Product Prioritization was recalculated from current records and changed results were snapshotted. */
class AHPPrioritizationRecalculated
{
    use Dispatchable;

    public function __construct(public int $teamId, public string $trigger, public int $changed, public int $reorderNeeded) {}
}
