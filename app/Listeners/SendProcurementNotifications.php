<?php

namespace App\Listeners;

use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\PurchaseOrderCreated;
use App\Events\Procurement\PurchaseOrderSent;
use App\Events\Procurement\PurchaseRequestApproved;
use App\Events\Procurement\PurchaseRequestRejected;
use App\Events\Procurement\PurchaseRequestSubmitted;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Http\Middleware\EnsureRole;
use App\Models\Delivery;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\ProcurementActivity;
use App\Services\Procurement\WorkflowNotifier;
use App\Services\Quotations\QuotationStock;

/**
 * In-app notifications for the procurement workflow: each step tells the people who act next.
 * Internal recalculations (AHP, supplier evaluation) do not notify anyone.
 */
class SendProcurementNotifications
{
    public function __construct(private WorkflowNotifier $notifier) {}

    public function handlePurchaseRequestSubmitted(PurchaseRequestSubmitted $e): void
    {
        $pr = $e->purchaseRequest;
        $this->notifier->notify($pr->team, ['admin'], 'purchase_request.submitted', 'Purchase request awaiting approval',
            "{$pr->request_number}: {$pr->quantity}".($pr->unit ? " {$pr->unit}" : '').' × '.$pr->itemName().' requested by '.($e->actor?->name ?? 'staff').'.', $e->actor, 'procurement?tab=requests');
    }

    public function handlePurchaseRequestApproved(PurchaseRequestApproved $e): void
    {
        $pr = $e->purchaseRequest;
        $this->notifier->notify($pr->team, ['staff_procurement'], 'purchase_request.approved', 'Purchase request approved',
            "{$pr->request_number} was approved. Its draft purchase order is ready to review and send.", $e->actor, 'procurement?tab=purchase_orders');
        $this->tellRequester($pr, $e->actor, 'approved');
    }

    public function handlePurchaseRequestRejected(PurchaseRequestRejected $e): void
    {
        $pr = $e->purchaseRequest;
        $this->notifier->notify($pr->team, ['staff_procurement'], 'purchase_request.rejected', 'Purchase request rejected',
            "{$pr->request_number} was rejected: {$pr->review_remarks}", $e->actor, 'procurement?tab=requests');
        $this->tellRequester($pr, $e->actor, 'rejected');
    }

    public function handlePurchaseOrderCreated(PurchaseOrderCreated $e): void
    {
        $po = $e->purchaseOrder;
        $missing = array_filter([! $po->supplier_id ? 'supplier' : null, (float) $po->unit_cost <= 0 ? 'agreed price' : null]);
        $this->notifier->notify($po->team, ['staff_procurement'], 'purchase_order.created', 'Draft purchase order created',
            "Purchase order {$po->po_number} was drafted from an approved request.".($missing ? ' Confirm the '.implode(' and ', $missing).' before sending.' : ' Review and send it.'), $e->actor, 'procurement?tab=purchase_orders');
    }

    public function handlePurchaseOrderSent(PurchaseOrderSent $e): void
    {
        $po = $e->purchaseOrder;
        $this->notifier->notify($po->team, ['admin', 'staff_procurement', 'staff_inventory'], 'purchase_order.sent', 'Purchase order sent to supplier',
            "Purchase order {$po->po_number} was sent; record the delivery when goods arrive.", $e->actor, 'procurement?tab=purchase_orders');
    }

    public function handleInboundDeliveryRecorded(InboundDeliveryRecorded $e): void
    {
        $d = $e->delivery;
        $this->notifier->notify($d->team, ['admin', 'staff_inventory', 'staff_procurement'], 'inspection.task', 'Inspection task: delivery received',
            "Delivery {$d->delivery_number} ({$d->quantity} units, PO {$d->purchase_order_number}) is waiting for quality inspection.", $e->actor, 'deliveries?tab=inbound');
        if ($d->quantity !== $e->openBefore) {
            $this->notifier->notify($d->team, ['admin', 'staff_procurement'], 'inbound_delivery.discrepancy', 'Delivery quantity differs from the order',
                "Delivery {$d->delivery_number}: {$d->quantity} units received, {$e->openBefore} were still owed on PO {$d->purchase_order_number}.", $e->actor, 'procurement?tab=purchase_orders');
        }
    }

    public function handleQualityInspectionCompleted(QualityInspectionCompleted $e): void
    {
        $d = $e->delivery;
        $this->notifier->notify($d->team, ['admin', 'staff_procurement'], 'inspection.completed', 'Inspection completed',
            "Delivery {$d->delivery_number}: {$d->accepted_quantity} accepted, {$d->rejected_quantity} rejected. The accepted units were added to stock.", $e->actor, 'deliveries?tab=inbound');
        if ((int) $d->rejected_quantity > 0) {
            $this->notifier->notify($d->team, ['admin', 'staff_procurement'], 'inspection.rejected', 'Units held after inspection',
                "Delivery {$d->delivery_number}: {$d->rejected_quantity} units rejected and held (damaged ".((int) $d->damaged_quantity).', defective '.((int) $d->defective_quantity).'). Record the supplier resolution.', $e->actor, 'deliveries?tab=inbound');
        }
    }

    public function handleInventoryUpdated(InventoryUpdated $e): void
    {
        $d = $e->delivery;
        $this->notifier->notify($d->team, ['admin', 'staff_inventory', 'staff_procurement'], 'inventory.received', 'Stock updated from a supplier delivery',
            "{$d->posted_quantity} accepted units of delivery {$d->delivery_number} were added to stock automatically.", null, 'inventory');
        $this->tellQuotationStaff($d, $e->actor);
    }

    private function tellRequester($pr, $actor, string $outcome): void
    {
        $requester = $pr->requestedBy;
        if ($requester && $requester->role !== 'staff_procurement' && $requester->id !== $actor?->id) {
            $path = match (true) {
                $requester->isAdmin() => "/{$pr->team->slug}/procurement?tab=requests",
                $pr->quotation_id !== null => $this->quotationPath($pr),
                // AHP Product Prioritization lives in Reports and Analytics; staff without Reports open Inventory.
                EnsureRole::allows($requester, ['admin', 'staff_reports']) => "/{$pr->team->slug}/reports?view=priority",
                default => "/{$pr->team->slug}/inventory",
            };
            $requester->notify(new ProcurementActivity("purchase_request.{$outcome}", "Your purchase request was {$outcome}",
                $pr->request_number.($pr->quotation ? " (quotation {$pr->quotation->quotation_number})" : '').($pr->review_remarks ? ": {$pr->review_remarks}" : '.'), $path));
        }
    }

    /**
     * Stock bought for a quotation is in: tell whoever requested it and the quotation's preparer
     * to continue the quotation (it is still a Draft; nothing is sent automatically).
     */
    private function tellQuotationStaff(Delivery $d, ?User $actor): void
    {
        $pr = $d->purchaseOrder?->purchaseRequest;
        $quotation = $pr?->quotation;
        if (! $quotation) {
            return;
        }
        $waiting = app(QuotationStock::class)->waitingFor($quotation)->isNotEmpty();
        $message = "{$d->posted_quantity} × ".($pr->product?->name ?? 'product')." for quotation {$quotation->quotation_number} ({$quotation->client_name}) were added to stock. "
            .($waiting ? 'More stock is still on its way.' : 'Check the stock on the quotation and continue it.');

        collect([$pr->requestedBy, $quotation->preparedBy])->filter()->unique('id')
            ->reject(fn (User $u) => $u->id === $actor?->id)
            ->each(fn (User $u) => $u->notify(new ProcurementActivity('quotation.stock_received', 'Stock received for a quotation', $message, $this->quotationPath($pr))));
    }

    private function quotationPath(PurchaseRequest $pr): string
    {
        return "/{$pr->team->slug}/quotations?search=".rawurlencode((string) $pr->quotation?->quotation_number);
    }
}
