<?php

namespace App\Services\Quotations;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use Illuminate\Support\Collection;

/**
 * Stock of a quotation's products, for the "products are short" flow: which products are short,
 * and the purchase raised for this quotation for each one. A quotation waits for stock (stays
 * Draft) while one of its products is short and that purchase is still on its way. Nothing here
 * reserves or changes stock.
 */
class QuotationStock
{
    /**
     * Products the quotation asks for more of than is available (lines of the same product
     * added together). Lines not linked to an inventory product are not checked.
     *
     * @return Collection<int, array{product_id: int, name: string, active: bool, requested: int, available: int, short: int, supplier_id: ?int}> keyed by product id
     */
    public function shortages(Quotation $quotation): Collection
    {
        $items = $quotation->relationLoaded('items') ? $quotation->items->loadMissing('product') : $quotation->items()->with('product')->get();

        return $items->filter(fn ($item) => $item->product_id && $item->product !== null)
            ->groupBy('product_id')
            ->map(function (Collection $lines) {
                $product = $lines->first()->product;
                $requested = (int) $lines->sum('quantity');
                $available = $product->availableStock();

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'active' => (bool) $product->is_active,
                    'requested' => $requested,
                    'available' => $available,
                    'short' => max(0, $requested - $available),
                    'supplier_id' => $lines->pluck('supplier_id')->filter()->first(),
                ];
            })
            ->filter(fn ($s) => $s['short'] > 0);
    }

    /**
     * The purchase raised for this quotation, per product: the latest request that was not
     * rejected, with its order. Keyed by product id.
     *
     * @return Collection<int, PurchaseRequest>
     */
    public function purchases(Quotation $quotation): Collection
    {
        $requests = $quotation->relationLoaded('purchaseRequests')
            ? $quotation->purchaseRequests
            : $quotation->purchaseRequests()->with('purchaseOrder')->get();

        return $requests->where('status', '!=', PurchaseRequest::REJECTED)
            ->filter(fn (PurchaseRequest $pr) => $pr->purchaseOrder?->status !== PurchaseOrder::CANCELLED)
            ->sortByDesc('id')
            ->unique('product_id')
            ->keyBy('product_id');
    }

    /**
     * Still on its way to stock: waiting for approval or ordering, ordered and not fully
     * delivered, or delivered but the accepted units are not in stock yet.
     */
    public function inProgress(PurchaseRequest $pr): bool
    {
        if (in_array($pr->status, [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::APPROVED], true)) {
            return true;
        }
        $po = $pr->purchaseOrder;
        if (! $po) {
            return false;
        }
        if (in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::SENT, PurchaseOrder::PARTIAL], true)) {
            return true;
        }

        // Fully delivered: still waiting while a delivery is not inspected or its accepted units not posted.
        return $po->status === PurchaseOrder::FULL && Delivery::where('purchase_order_id', $po->id)->whereNull('posted_at')
            ->where(fn ($q) => $q->whereNull('inspection_result')->orWhere('accepted_quantity', '>', 0))->exists();
    }

    /**
     * Short products whose purchase for this quotation is still on its way.
     *
     * @return Collection<int, array{product_id: int, name: string, requested: int, available: int, short: int, purchase: string}>
     */
    public function waitingFor(Quotation $quotation): Collection
    {
        $purchases = $this->purchases($quotation);

        return $this->shortages($quotation)
            ->filter(fn ($s) => ($pr = $purchases->get($s['product_id'])) && $this->inProgress($pr))
            ->map(fn ($s) => $s + ['purchase' => $this->describe($purchases->get($s['product_id']))]);
    }

    /** "PR-0001 (Pending Approval)" or "PO-0001 (Sent to Supplier)". */
    public function describe(PurchaseRequest $pr): string
    {
        $po = $pr->purchaseOrder;

        return $po ? "{$po->po_number} ({$po->status})" : "{$pr->request_number} ({$pr->status})";
    }
}
