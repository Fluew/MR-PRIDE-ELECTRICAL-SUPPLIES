<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\Forecast\SmaForecaster;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Suppliers\SupplierEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HTTP side of the Inventory-to-Procurement workflow. Validation and team scoping only;
 * every business rule lives in ProcurementWorkflow.
 */
class ProcurementWorkflowController extends Controller
{
    public function __construct(private ProcurementWorkflow $workflow) {}

    /** Staff submit (or draft) Purchase Requests from the AHP reorder recommendations. */
    public function submitRequests(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            // A catalog product (its own unit is used), or "Product Not Listed": no product, a
            // description and one of the system's units. Nothing is added to inventory.
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.product_description' => ['nullable', 'required_without:lines.*.product_id', 'string', 'max:1000'],
            'lines.*.unit' => ['nullable', 'required_without:lines.*.product_id', 'string', Rule::in(PurchaseRequest::unitOptions($team->id))],
            'lines.*.justification' => ['required', 'string', 'max:1000'],
            'lines.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'draft' => ['nullable', 'boolean'],
            'forecast_months' => ['nullable', 'integer', Rule::in(SmaForecaster::WINDOWS)],
        ], [
            'lines.*.product_description.required_without' => 'Describe the product you need to purchase.',
            'lines.*.unit.required_without' => 'Select the unit.',
            'lines.*.unit.in' => 'Select one of the listed units.',
        ]);

        return $this->runWorkflow(function () use ($team, $request, $data) {
            $prs = $this->workflow->submitRequests($team, $request->user(), $data['lines'], (bool) ($data['draft'] ?? false), $data['forecast_months'] ?? null);

            return ($data['draft'] ?? false)
                ? 'Saved as draft: '.$prs->pluck('request_number')->implode(', ').'.'
                : 'Submitted for admin approval: '.$prs->pluck('request_number')->implode(', ').'.';
        });
    }

    public function submitDraft(Request $request): RedirectResponse
    {
        $pr = $this->find($request, PurchaseRequest::class, 'purchaseRequest');

        return $this->runWorkflow(function () use ($pr, $request) {
            $this->workflow->submitDraft($pr, $request->user());

            return "{$pr->request_number} submitted for approval.";
        });
    }

    public function review(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator can approve or reject purchase requests.');
        $pr = $this->find($request, PurchaseRequest::class, 'purchaseRequest');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($pr, $request, $data) {
            $this->workflow->review($pr, $request->user(), $data['decision'] === 'approve', $data['remarks'] ?? null);

            return "{$pr->request_number} ".($data['decision'] === 'approve' ? 'approved.' : 'rejected.');
        });
    }

    /** Staff confirm the draft order created on approval: supplier, quantity, agreed price, terms. */
    public function updateDraftPurchaseOrder(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            // The order date is not changed here (only an administrator correction can change it).
            'promised_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'quantity_adjustment_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(fn () => 'Draft purchase order '.$this->workflow->updateDraftPurchaseOrder($po, $request->user(), $data)->po_number.' saved. Send it when the supplier terms are final.');
    }

    public function sendPurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');

        return $this->runWorkflow(function () use ($po, $request) {
            $this->workflow->sendPurchaseOrder($po, $request->user());

            return "Purchase order {$po->po_number} marked as sent to the supplier.";
        });
    }

    public function cancelPurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($po, $request, $data) {
            $this->workflow->cancelPurchaseOrder($po, $request->user(), $data['reason']);

            return "Purchase order {$po->po_number} cancelled.";
        });
    }

    public function closePurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($po, $request, $data) {
            $this->workflow->closePurchaseOrder($po, $request->user(), $data['reason'] ?? null);

            return "Purchase order {$po->po_number} closed.";
        });
    }

    /** Everything about one order, for the Purchase Order workspace panel. */
    public function workspace(Request $request): JsonResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $po->load(['supplier:id,name', 'product:id,name,sku,unit,stock', 'preparedBy:id,name',
            'purchaseRequest.requestedBy:id,name', 'purchaseRequest.reviewedBy:id,name', 'purchaseRequest.quotation:id,quotation_number,client_name']);

        $deliveries = Delivery::where('purchase_order_id', $po->id)->with(['inspectedBy:id,name', 'receivedBy:id,name', 'postedBy:id,name'])
            ->orderBy('delivery_date')->orderBy('id')->get()
            ->map(fn (Delivery $d) => $d->only(['id', 'delivery_number', 'supplier_document_number', 'delivery_date', 'quantity', 'accepted_quantity', 'rejected_quantity',
                'inspection_number', 'inspected_on', 'inspection_remarks', 'rejection_reason', 'rejection_category', 'damaged_quantity', 'defective_quantity', 'resolution_status', 'resolution_remarks', 'over_receipt_reason',
                'posted_quantity', 'posted_at', 'notes']) + [
                    'inspection_status' => $d->inspectionStatus(),
                    'inspected_by' => $d->inspectedBy?->name,
                    'received_by' => $d->receivedBy?->name,
                    'posted_by' => $d->postedBy?->name,
                ]);

        // All products on this purchase order number (one purchase_orders row each), read-only.
        $lines = PurchaseOrder::where('team_id', $po->team_id)->where('po_number', $po->po_number)->with('supplier:id,name')
            ->orderBy('id')->get()
            ->map(fn (PurchaseOrder $line) => [
                'id' => $line->id,
                'po_date' => $line->po_date?->toDateString(),
                'po_number' => $line->po_number,
                'supplier' => $line->supplier?->name,
                'quantity' => (int) $line->quantity,
                'item_sku' => $line->item_sku,
                'description' => $line->description,
                'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                'unit_of_measure' => $line->unit_of_measure,
                // EXTENDED COST = QUANTITY × UNIT COST.
                'extended_cost' => round((int) $line->quantity * (float) $line->unit_cost, 2),
                'product_id' => $line->product_id,
            ]);

        return response()->json([
            'purchase_order' => $po,
            'lines' => $lines,
            'summary' => $this->workflow->summary($po),
            'deliveries' => $deliveries,
            'rejection_categories' => Delivery::REJECTION_CATEGORIES,
            'suppliers' => $po->status === PurchaseOrder::DRAFT ? $this->rankedSuppliers($po->team_id) : [],
            'resolutions' => Delivery::RESOLUTIONS,
        ]);
    }

    /**
     * Active suppliers for the draft order's supplier choice, best AHP rank first, with their
     * score. Information for staff only: the choice stays theirs.
     *
     * @return list<array{id: int, name: string, rank: ?int, score: ?float, classification: ?string}>
     */
    private function rankedSuppliers(int $teamId): array
    {
        $evaluations = app(SupplierEvaluator::class)->forTeam($teamId);

        return Supplier::where('team_id', $teamId)->where('status', 'Active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'rank' => $evaluations[$s->id]['rank'] ?? null,
                'score' => $evaluations[$s->id]['score'] ?? null, 'classification' => $evaluations[$s->id]['classification'] ?? null])
            ->sortBy(fn ($s) => [$s['rank'] === null ? 1 : 0, $s['rank'] ?? 0, $s['name']])
            ->values()->all();
    }

    public function recordDelivery(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate([
            'delivery_date' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1'],
            'supplier_document_number' => ['nullable', 'string', 'max:100'],
            'delivery_number' => ['nullable', 'string', 'max:100'],
            'over_receipt_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(fn () => 'Delivery '.$this->workflow->recordDelivery($po, $request->user(), $data)->delivery_number.' recorded. Inspect it next.');
    }

    public function inspect(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');
        $data = $request->validate([
            'accepted_quantity' => ['required', 'integer', 'min:0'],
            'rejected_quantity' => ['required', 'integer', 'min:0'],
            'inspected_on' => ['required', 'date', 'after_or_equal:'.$delivery->delivery_date->toDateString(), 'before_or_equal:today'],
            'inspection_remarks' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => ['nullable', 'required_unless:rejected_quantity,0', 'string', 'max:1000'],
            'rejection_category' => ['nullable', Rule::in(Delivery::REJECTION_CATEGORIES)],
            'damaged_quantity' => ['nullable', 'integer', 'min:0'],
            'defective_quantity' => ['nullable', 'integer', 'min:0'],
        ], [
            'rejection_reason.required_unless' => 'Give the defect or rejection reason for the rejected units.',
        ]);

        return $this->runWorkflow(function () use ($delivery, $request, $data) {
            $this->workflow->inspect($delivery, $request->user(), $data);

            return "Inspection recorded for delivery {$delivery->delivery_number}.";
        });
    }

    public function resolveRejection(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');
        $data = $request->validate([
            'resolution_status' => ['required', Rule::in(Delivery::RESOLUTIONS)],
            'resolution_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($delivery, $request, $data) {
            $this->workflow->resolveRejection($delivery, $request->user(), $data['resolution_status'], $data['resolution_remarks'] ?? null);

            return "Resolution recorded for delivery {$delivery->delivery_number}.";
        });
    }

    public function postToInventory(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');

        return $this->runWorkflow(fn () => $this->workflow->postToInventory($delivery, $request->user())->quantity." accepted units of {$delivery->delivery_number} added to inventory.");
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : (is_string($param) && $param !== '' ? Team::where('slug', $param)->first() : null);
        $team ??= $request->user()?->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function find(Request $request, string $class, string $param): Model
    {
        $value = $request->route($param);
        $id = $value instanceof Model ? $value->getKey() : $value;
        abort_unless(is_numeric($id), 404);

        return $class::where('team_id', $this->team($request)->id)->findOrFail((int) $id);
    }
}
