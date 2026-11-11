<?php

namespace App\Listeners;

use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Jobs\RecalculateEvaluations;

/**
 * After a record that feeds the analyses changes, queue a recalculation of AHP Product
 * Prioritization (stock) and Supplier Evaluation (delivery, price, quality). The pages always
 * calculate live; the job stores the changed results as snapshots for history.
 */
class RefreshEvaluations
{
    public function handleInboundDeliveryRecorded(InboundDeliveryRecorded $e): void
    {
        RecalculateEvaluations::dispatch($e->delivery->team_id, 'inbound_delivery.recorded', $e->delivery->delivery_number);
    }

    public function handleQualityInspectionCompleted(QualityInspectionCompleted $e): void
    {
        RecalculateEvaluations::dispatch($e->delivery->team_id, 'inspection.completed', $e->delivery->inspection_number);
    }

    public function handleInventoryUpdated(InventoryUpdated $e): void
    {
        RecalculateEvaluations::dispatch($e->delivery->team_id, 'inventory.updated', $e->delivery->delivery_number);
    }
}
