<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\DeliveryAttemptLog;
use App\Models\DeliveryRelease;
use App\Models\OrderFulfillment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\Inventory\StockLedger;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Delivery and pickup of confirmed Sales Orders.
 *
 * One delivery record per order (enforced by the database), created at Pending when the order is
 * confirmed. Goods leave in releases: a release takes chosen quantities, is prepared (still
 * Pending), made ready (Ready for Delivery), dispatched (In Transit, its units stay reserved) and
 * received (stock is taken out here, for the quantities received only). A failed attempt puts
 * it back to Ready for Delivery with the goods still on the truck (the attempt is in the history). When only part of the order was received the status goes back
 * to Pending for the next release; it becomes Delivered only when nothing is owed, and the order
 * is then completed automatically when its payment terms allow. Every change is kept in the
 * history; the customer is emailed for Ready for Delivery, In Transit, Delivered (each receipt),
 * a failed delivery attempt, and Cancelled.
 */
class FulfillmentService
{
    /** Statuses the customer is emailed about. */
    private const EMAILED = [
        DeliveryStatus::ReadyForDelivery, DeliveryStatus::InTransit, DeliveryStatus::Delivered, DeliveryStatus::Cancelled,
    ];

    public function __construct(private StockLedger $stock, private CustomerNotifier $notifier) {}

    /** Create (or reuse) the order's delivery record at Pending, with its first history entry. Never a second one. */
    public function initialize(Sale $sale, ?User $user = null): OrderFulfillment
    {
        $fulfillment = OrderFulfillment::where('sale_id', $sale->id)->first();
        if ($fulfillment && $fulfillment->status !== null) {
            return $fulfillment;
        }

        $fulfillment ??= new OrderFulfillment;
        $fulfillment->fill([
            'team_id' => $sale->team_id,
            'sale_id' => $sale->id,
            'fulfillment_number' => $fulfillment->fulfillment_number ?: DocumentNumbers::next($sale->team_id, DocumentNumbers::FULFILLMENT),
            'fulfillment_type' => $sale->isPickup() ? 'pickup' : 'delivery',
            'recipient_name' => $fulfillment->recipient_name ?: ($sale->contact_person ?: $sale->client_name),
            'contact_number' => $fulfillment->contact_number ?: $sale->phone,
            'delivery_address' => $fulfillment->delivery_address ?: $sale->address,
            'instructions' => $fulfillment->instructions ?: $sale->delivery_instructions,
            'status' => DeliveryStatus::Pending,
            'attempt_count' => 0,
        ])->save();

        $this->history($fulfillment, null, DeliveryStatus::Pending, $user, 'Order confirmed; delivery record created.');
        AuditLogger::record('delivery.created', "Delivery record {$fulfillment->fulfillment_number} created for {$sale->order_number} (".($fulfillment->isPickup() ? 'pickup' : 'delivery').').', $sale, ['fulfillment_id' => $fulfillment->id], $user);

        return $fulfillment;
    }

    /**
     * Start a release with the chosen quantities (sale_item_id => quantity).
     *
     * @param  array<int|string, int|string>  $quantities
     */
    public function prepareRelease(OrderFulfillment $fulfillment, array $quantities, User $user, ?string $remarks = null): DeliveryRelease
    {
        return DB::transaction(function () use ($fulfillment, $quantities, $user, $remarks) {
            [$sale, $fulfillment] = $this->lock($fulfillment);

            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                throw new WorkflowException("Order {$sale->order_number} is {$sale->status->label()}: items can only be released for confirmed orders.");
            }
            $this->assertNotHeld($sale);
            if ($fulfillment->openRelease()) {
                throw new WorkflowException('A release for this order is already being prepared or is on its way. Finish or cancel it first.');
            }
            $this->guard($fulfillment, DeliveryStatus::Pending);

            $lines = SaleItem::where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
            $chosen = [];
            foreach ($quantities as $lineId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $line = $lines->get((int) $lineId) ?? throw new WorkflowException('One of the items is not part of this order.', 'items');
                if ($qty > $line->releasable_quantity) {
                    throw new WorkflowException("{$line->product_name}: only {$line->releasable_quantity} can still be released (ordered {$line->quantity}, already released or cancelled ".($line->quantity - $line->releasable_quantity).').', 'items');
                }
                $chosen[$line->id] = $qty;
            }
            if ($chosen === []) {
                throw new WorkflowException('Choose at least one item and quantity to release.', 'items');
            }

            $release = DeliveryRelease::create([
                'team_id' => $sale->team_id,
                'order_fulfillment_id' => $fulfillment->id,
                'sale_id' => $sale->id,
                'release_number' => DocumentNumbers::next($sale->team_id, DocumentNumbers::DELIVERY_RELEASE),
                'type' => $fulfillment->isPickup() ? 'pickup' : 'delivery',
                'status' => DeliveryRelease::PREPARING,
                'prepared_by_id' => $user->id,
                'remarks' => $remarks,
            ]);
            foreach ($chosen as $lineId => $qty) {
                $line = $lines[$lineId];
                $release->items()->create(['sale_item_id' => $lineId, 'product_id' => $line->product_id, 'quantity' => $qty]);
                $line->increment('released_quantity', $qty);
            }

            // Next stage for the order: it is being processed.
            if ($sale->status === SalesOrderStatus::Confirmed) {
                $sale->update(['status' => SalesOrderStatus::Processing]);
                AuditLogger::record('sales_order.processing', "Order {$sale->order_number} is being processed.", $sale, [], $user);
            }

            $summary = collect($chosen)->map(fn ($q, $id) => "{$lines[$id]->product_name} × {$q}")->implode(', ');
            $this->transition($fulfillment, DeliveryStatus::Pending, $user, "{$release->release_number} being prepared: {$summary}".($remarks ? ". {$remarks}" : ''), $release);
            AuditLogger::record('delivery.release_created', "Release {$release->release_number} prepared for {$sale->order_number}: {$summary}.", $sale, ['release_id' => $release->id], $user);

            return $release;
        });
    }

    public function cancelRelease(DeliveryRelease $release, User $user, string $reason): void
    {
        DB::transaction(function () use ($release, $user, $reason) {
            [$sale, $fulfillment, $release] = $this->lockRelease($release);
            if (! in_array($release->status, [DeliveryRelease::PREPARING, DeliveryRelease::READY], true)) {
                throw new WorkflowException('Only a release that has not left the warehouse can be cancelled. Record a return instead.');
            }

            foreach ($release->items as $item) {
                SaleItem::whereKey($item->sale_item_id)->decrement('released_quantity', $item->quantity);
            }
            $release->update(['status' => DeliveryRelease::CANCELLED, 'cancelled_at' => now(), 'remarks' => trim(($release->remarks ? $release->remarks.' · ' : '').'Cancelled: '.$reason)]);

            $this->transition($fulfillment, $this->restingStatus($sale), $user, "{$release->release_number} cancelled: {$reason}", $release, notify: false);
            AuditLogger::record('delivery.release_cancelled', "Release {$release->release_number} cancelled before leaving: {$reason}", $sale, ['release_id' => $release->id], $user);
        });
    }

    public function markReady(DeliveryRelease $release, User $user, ?string $remarks = null): void
    {
        DB::transaction(function () use ($release, $user, $remarks) {
            [$sale, $fulfillment, $release] = $this->lockRelease($release);
            if ($release->status !== DeliveryRelease::PREPARING) {
                throw new WorkflowException('Only a release being prepared can be marked ready.');
            }
            $this->assertNotHeld($sale);
            $release->update(['status' => DeliveryRelease::READY]);
            $this->transition($fulfillment, DeliveryStatus::ReadyForDelivery, $user, $remarks ?: "{$release->release_number} is ready".($fulfillment->isPickup() ? ' for pickup.' : ' for dispatch.'), $release, context: ['remarks' => $remarks]);
        });
    }

    /** The release leaves the warehouse. Its units stay reserved; stock is taken out when the customer receives them. */
    public function dispatch(DeliveryRelease $release, User $user): void
    {
        DB::transaction(function () use ($release, $user) {
            [$sale, $fulfillment, $release] = $this->lockRelease($release);
            if ($release->type === 'pickup') {
                throw new WorkflowException('Pickup orders are handed over at the warehouse; record the pickup instead.');
            }
            if ($release->status !== DeliveryRelease::READY) {
                throw new WorkflowException('Mark the release ready before dispatching it.');
            }
            $this->assertNotHeld($sale);

            $this->stock->holdForRelease($release, $user);
            $release->update(['status' => DeliveryRelease::DISPATCHED, 'dispatched_at' => now(), 'released_by_id' => $user->id]);
            $fulfillment->forceFill([
                'handled_by_id' => $fulfillment->handled_by_id ?: $user->id,
                'dispatched_at' => now(),
                'attempt_count' => 0,
            ])->save();

            $this->transition($fulfillment, DeliveryStatus::InTransit, $user, "{$release->release_number} dispatched.", $release);
        });
    }

    /**
     * The customer could not receive the goods. They stay on the truck and the delivery is Ready
     * for Delivery again; the attempt (reason, photo) is in the history and the customer is told.
     */
    public function recordFailedAttempt(OrderFulfillment $fulfillment, string $reason, string $remarks, ?string $photoPath, User $user): int
    {
        return DB::transaction(function () use ($fulfillment, $reason, $remarks, $photoPath, $user) {
            [, $fulfillment] = $this->lock($fulfillment);
            if ($fulfillment->status !== DeliveryStatus::InTransit) {
                throw new WorkflowException('A failed attempt can only be recorded while the order is in transit.');
            }
            if ($fulfillment->attempt_count >= OrderFulfillment::MAX_ATTEMPTS) {
                throw new WorkflowException('The 3-attempt limit has been reached. An administrator has to reschedule this delivery.');
            }

            $attempt = $fulfillment->attempt_count + 1;
            $fulfillment->forceFill(['attempt_count' => $attempt])->save();
            $this->transition($fulfillment, DeliveryStatus::ReadyForDelivery, $user, "Delivery attempt {$attempt} failed: {$remarks}", $fulfillment->openRelease(),
                attempt: $attempt, failureReason: $reason, proofImage: $photoPath, context: ['remarks' => "{$reason}. {$remarks}", 'attempt_failed' => true]);

            return $attempt;
        });
    }

    /** Go again with the goods already on the truck: after a failed attempt, or after a reschedule. */
    public function retryDispatch(OrderFulfillment $fulfillment, User $user): void
    {
        DB::transaction(function () use ($fulfillment, $user) {
            [$sale, $fulfillment] = $this->lock($fulfillment);
            $this->assertNotHeld($sale);
            $release = $fulfillment->openRelease();
            if ($fulfillment->status !== DeliveryStatus::ReadyForDelivery || ! $fulfillment->goodsOnTruck($release)) {
                throw new WorkflowException('Only a failed or rescheduled delivery can be dispatched again.');
            }
            if ($fulfillment->needsReschedule($release)) {
                throw new WorkflowException('The 3-attempt limit has been reached. An administrator has to reschedule this delivery.');
            }
            $fulfillment->forceFill(['dispatched_at' => now()])->save();
            $this->transition($fulfillment, DeliveryStatus::InTransit, $user, 'Dispatched again (attempt '.($fulfillment->attempt_count + 1).' of '.OrderFulfillment::MAX_ATTEMPTS.').', $release);
        });
    }

    /** Admin: a new delivery date after a failed attempt. The customer is told it is ready again. */
    public function reschedule(OrderFulfillment $fulfillment, string $schedule, bool $resetAttempts, ?string $notes, ?int $driverId, User $user): void
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can reschedule a delivery.');
        }

        DB::transaction(function () use ($fulfillment, $schedule, $resetAttempts, $notes, $driverId, $user) {
            [, $fulfillment] = $this->lock($fulfillment);
            if (! $fulfillment->awaitingRedelivery()) {
                throw new WorkflowException('Only a delivery whose attempt failed can be rescheduled.');
            }
            $fulfillment->forceFill(array_filter([
                'delivery_schedule' => $schedule,
                'rescheduled_at' => now(),
                'rescheduled_by_id' => $user->id,
                'attempt_count' => $resetAttempts ? 0 : $fulfillment->attempt_count,
                'handled_by_id' => $driverId ?: $fulfillment->handled_by_id,
            ], fn ($v) => $v !== null))->save();

            $when = Carbon::parse($schedule)->format('M d, Y h:i A');
            $this->transition($fulfillment, DeliveryStatus::ReadyForDelivery, $user,
                "Rescheduled to {$when}".($resetAttempts ? '; attempt counter reset.' : '.').($notes ? " {$notes}" : ''),
                $fulfillment->openRelease(), context: ['remarks' => "New delivery date: {$when}.".($notes ? " {$notes}" : '')],
                emailAs: DeliveryStatus::ReadyForDelivery); // still Ready for Delivery: the customer is told the new date
        });
    }

    /**
     * Record what the customer received for a release (sale_item_id => quantity received).
     * Only the received quantities are taken out of stock; anything not received stays held for the order.
     *
     * @param  array<int|string, int|string>  $received
     */
    public function recordReceipt(DeliveryRelease $release, array $received, string $receivedBy, ?string $proofPath, ?string $remarks, User $user): DeliveryStatus
    {
        $status = DB::transaction(function () use ($release, $received, $receivedBy, $proofPath, $remarks, $user) {
            [$sale, $fulfillment, $release] = $this->lockRelease($release);
            $pickup = $release->type === 'pickup';

            if ($pickup ? $release->status !== DeliveryRelease::READY : $release->status !== DeliveryRelease::DISPATCHED) {
                throw new WorkflowException($pickup ? 'Mark the pickup ready before recording the handover.' : 'Only a dispatched release can be recorded as delivered.');
            }
            if ($pickup) {
                $this->assertNotHeld($sale);
                $this->stock->holdForRelease($release, $user); // refuses a handover the stock cannot cover
                $release->update(['dispatched_at' => now(), 'released_by_id' => $user->id]);
            }

            $quantity = fn ($item) => (int) ($received[$item->sale_item_id] ?? $received[(string) $item->sale_item_id] ?? $item->quantity);
            $total = 0;
            foreach ($release->items as $item) {
                $qty = $quantity($item);
                if ($qty < 0 || $qty > $item->quantity) {
                    throw new WorkflowException("{$item->saleItem?->product_name}: received quantity must be between 0 and {$item->quantity}.", 'items');
                }
                $total += $qty;
            }
            if ($total === 0) {
                throw new WorkflowException('Nothing was received. Record a failed attempt or a return instead.', 'items');
            }

            $lines = [];
            foreach ($release->items as $item) {
                $qty = $quantity($item);
                $back = $item->quantity - $qty;
                $item->update(['delivered_quantity' => $qty, 'returned_quantity' => $back]);
                $line = SaleItem::whereKey($item->sale_item_id)->lockForUpdate()->first();
                $line->forceFill([
                    'delivered_quantity' => $line->delivered_quantity + $qty,
                    'released_quantity' => max(0, $line->released_quantity - $back),
                ])->save();
                if ($item->deducted_quantity > $qty) {
                    // Dispatched before stock-out moved to delivery: put back what was not received.
                    $this->stock->restock($item->fresh('release.sale'), $item->deducted_quantity - $qty, "Not received on {$release->release_number}", $user);
                }
                $lines[] = "{$line->product_name}: {$qty} of {$item->quantity}";
            }
            $this->stock->deductDelivered($release, $user); // only what the customer received leaves stock

            $release->update([
                'status' => DeliveryRelease::DELIVERED,
                'delivered_at' => now(),
                'received_by_name' => $receivedBy,
                'acknowledged_by_id' => $user->id,
                'proof_path' => $proofPath,
                'remarks' => trim(($release->remarks ? $release->remarks.' · ' : '').($remarks ?? '')) ?: null,
            ]);

            $done = SaleItem::where('sale_id', $sale->id)->get()->every(fn ($l) => $l->remaining_quantity === 0);
            $fulfillment->forceFill([
                'proof_of_delivery' => $proofPath ?? $fulfillment->proof_of_delivery,
                'delivered_at' => $done ? now() : $fulfillment->delivered_at,
            ])->save();

            // All received: Delivered. Part received: back to Pending for the next release; the
            // customer still gets a "delivered" email for what arrived and what is still to follow.
            $to = $done ? DeliveryStatus::Delivered : DeliveryStatus::Pending;
            $this->transition($fulfillment, $to, $user, "{$release->release_number} received by {$receivedBy}: ".implode('; ', $lines).($remarks ? ". {$remarks}" : '').($done ? '' : '. More to follow.'), $release,
                emailAs: DeliveryStatus::Delivered, context: ['partial' => ! $done]);
            AuditLogger::record('delivery.acknowledged', "{$release->release_number} received by {$receivedBy}".($done ? '; everything delivered.' : '; part of the order is still to follow.'), $sale, ['release_id' => $release->id], $user);

            return $to;
        });

        if ($status === DeliveryStatus::Delivered) {
            app(SalesOrderService::class)->completeIfDone($release->sale()->first(), $user);
        }

        return $status;
    }

    /**
     * The goods on a dispatched release came back to the warehouse. They never left stock, so
     * nothing moves; they stay held for the order (older releases taken out at dispatch are put back).
     */
    public function returnRelease(DeliveryRelease $release, string $reason, User $user): void
    {
        DB::transaction(function () use ($release, $reason, $user) {
            [, $fulfillment, $release] = $this->lockRelease($release);
            if ($release->status !== DeliveryRelease::DISPATCHED) {
                throw new WorkflowException('Only a release that left the warehouse can be returned.');
            }

            foreach ($release->items as $item) {
                $item->update(['returned_quantity' => $item->quantity]);
                SaleItem::whereKey($item->sale_item_id)->decrement('released_quantity', $item->quantity);
                $this->stock->restock($item->fresh('release.sale'), $item->quantity, "Returned: {$reason}", $user);
            }
            $release->update(['status' => DeliveryRelease::RETURNED, 'returned_at' => now(), 'return_reason' => $reason]);

            $this->transition($fulfillment, DeliveryStatus::Pending, $user, "{$release->release_number} returned to the warehouse: {$reason}", $release, notify: false);
        });
    }

    /**
     * After quantities were cancelled: close the delivery when nothing is owed any more. With
     * something delivered it is Delivered (and the order completes if it can), otherwise
     * Cancelled. The customer is told the remaining delivery was cancelled.
     */
    public function settle(OrderFulfillment $fulfillment, ?User $user = null, ?string $reason = null): void
    {
        $fulfillment->refresh();
        if ($fulfillment->openRelease() || $fulfillment->status->isFinal()) {
            return;
        }
        $lines = SaleItem::where('sale_id', $fulfillment->sale_id)->get();
        if ($lines->contains(fn ($l) => $l->remaining_quantity > 0)) {
            return;
        }

        $delivered = $lines->sum('delivered_quantity') > 0;
        $to = $delivered ? DeliveryStatus::Delivered : DeliveryStatus::Cancelled;
        $fulfillment->forceFill($delivered ? ['delivered_at' => now()] : ['cancelled_at' => now(), 'cancellation_reason' => $reason])->save();
        $this->transition($fulfillment, $to, $user, 'The remaining quantities were cancelled'.($reason ? ": {$reason}" : '.'),
            emailAs: DeliveryStatus::Cancelled, context: ['remarks' => $reason, 'remaining_cancelled' => $delivered]);

        if ($delivered && $user) {
            app(SalesOrderService::class)->completeIfDone($fulfillment->sale()->first(), $user);
        }
    }

    /** The order was completed: the delivery is already Delivered; only the time is recorded. */
    public function complete(OrderFulfillment $fulfillment, User $user): void
    {
        $fulfillment->forceFill(['completed_at' => now()])->save();
    }

    /** Cancelled together with its order: the order cancellation email covers it. */
    public function cancel(OrderFulfillment $fulfillment, string $reason, User $user): void
    {
        if ($fulfillment->status === DeliveryStatus::Cancelled) {
            return;
        }
        $fulfillment->forceFill(['cancelled_at' => now(), 'cancellation_reason' => $reason])->save();
        $this->transition($fulfillment, DeliveryStatus::Cancelled, $user, "Cancelled with the order: {$reason}", notify: false);
    }

    /** Counter sale: the customer takes everything at the counter (stock was taken out when sold). */
    public function counterHandover(Sale $sale, User $user): void
    {
        $fulfillment = $this->initialize($sale, $user);
        $lines = SaleItem::where('sale_id', $sale->id)->get();

        $release = DeliveryRelease::create([
            'team_id' => $sale->team_id,
            'order_fulfillment_id' => $fulfillment->id,
            'sale_id' => $sale->id,
            'release_number' => DocumentNumbers::next($sale->team_id, DocumentNumbers::DELIVERY_RELEASE),
            'type' => 'pickup',
            'status' => DeliveryRelease::DELIVERED,
            'prepared_by_id' => $user->id,
            'released_by_id' => $user->id,
            'dispatched_at' => now(),
            'delivered_at' => now(),
            'received_by_name' => $sale->contact_person ?: $sale->client_name,
            'acknowledged_by_id' => $user->id,
            'remarks' => 'Handed over at the sales counter.',
        ]);
        foreach ($lines as $line) {
            $qty = $line->releasable_quantity;
            if ($qty > 0) {
                $release->items()->create(['sale_item_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => $qty, 'delivered_quantity' => $qty]);
                $line->forceFill(['released_quantity' => $line->released_quantity + $qty, 'delivered_quantity' => $line->delivered_quantity + $qty])->save();
            }
        }

        $fulfillment->forceFill(['delivered_at' => now(), 'handled_by_id' => $user->id])->save();
        $this->transition($fulfillment, DeliveryStatus::Delivered, $user, 'Handed over at the sales counter.', $release, notify: false);
    }

    // ------------------------------------------------------------------------------ internals

    /**
     * Change the status, keep it in the history and, for customer-facing statuses, email the
     * customer once (the key is the history entry). $emailAs sends a different email than the
     * new status would (a partial receipt is emailed as "delivered").
     *
     * @param  array<string, mixed>  $context
     */
    private function transition(OrderFulfillment $fulfillment, DeliveryStatus $to, ?User $user, ?string $remarks, ?DeliveryRelease $release = null, int $attempt = 0, ?string $failureReason = null, ?string $proofImage = null, bool $notify = true, array $context = [], ?DeliveryStatus $emailAs = null): void
    {
        $from = $fulfillment->status;
        if ($from !== null) {
            $this->guard($fulfillment, $to);
        }

        $fulfillment->forceFill(['status' => $to])->save();
        $log = $this->history($fulfillment, $from, $to, $user, $remarks, $release, $attempt, $failureReason, $proofImage);

        $email = $emailAs ?? $to;
        $sale = $fulfillment->sale;
        $changed = $from !== $to || $emailAs !== null;
        if ($notify && $changed && in_array($email, self::EMAILED, true) && $sale?->channel !== Sale::CHANNEL_COUNTER) {
            $this->notifier->notify('delivery_status', $fulfillment, "delivery:{$fulfillment->id}:{$log->id}", array_filter([
                'status' => $email->value,
                'release_id' => $release?->id,
                'remarks' => $context['remarks'] ?? null,
                'partial' => $context['partial'] ?? null,
                'remaining_cancelled' => $context['remaining_cancelled'] ?? null,
                'attempt_failed' => $context['attempt_failed'] ?? null, // emailed as "we could not deliver your order"
            ], fn ($v) => $v !== null && $v !== false));
        }
    }

    private function history(OrderFulfillment $fulfillment, ?DeliveryStatus $from, DeliveryStatus $to, ?User $user, ?string $remarks, ?DeliveryRelease $release = null, int $attempt = 0, ?string $failureReason = null, ?string $proofImage = null): DeliveryAttemptLog
    {
        return DeliveryAttemptLog::create([
            'team_id' => $fulfillment->team_id,
            'order_fulfillment_id' => $fulfillment->id,
            'delivery_release_id' => $release?->id,
            'attempt_number' => $attempt,
            'previous_status' => $from?->value ?? 'none',
            'new_status' => $to->value,
            'staff_id' => $user?->id,
            'failure_reason' => $failureReason,
            'remarks' => $remarks,
            'proof_image' => $proofImage,
            'attempt_date' => now(),
        ]);
    }

    private function guard(OrderFulfillment $fulfillment, DeliveryStatus $to): void
    {
        if (! $fulfillment->status->canMoveTo($to, $fulfillment->isPickup())) {
            throw new WorkflowException("A delivery that is {$fulfillment->status->label()} cannot move to {$to->label()}.");
        }
    }

    /** Where a delivery rests between releases (nothing or only part of it delivered): Pending. */
    private function restingStatus(Sale $sale): DeliveryStatus
    {
        return DeliveryStatus::Pending;
    }

    private function assertNotHeld(Sale $sale): void
    {
        if ($sale->hold_reason) {
            throw new WorkflowException("Order {$sale->order_number} is on hold ({$sale->hold_reason}): nothing can be released until it is resumed.");
        }
        if ($sale->status === SalesOrderStatus::Cancelled) {
            throw new WorkflowException("Order {$sale->order_number} is cancelled.");
        }
    }

    /** @return array{0: Sale, 1: OrderFulfillment} locked, order first */
    private function lock(OrderFulfillment $fulfillment): array
    {
        $sale = Sale::whereKey($fulfillment->sale_id)->lockForUpdate()->firstOrFail();
        $fulfillment = OrderFulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();
        if ($fulfillment->status?->isFinal()) {
            throw new WorkflowException("This delivery is {$fulfillment->status->label()}; nothing more can be changed.");
        }

        return [$sale, $fulfillment];
    }

    /** @return array{0: Sale, 1: OrderFulfillment, 2: DeliveryRelease} */
    private function lockRelease(DeliveryRelease $release): array
    {
        [$sale, $fulfillment] = $this->lock($release->fulfillment);
        $release = DeliveryRelease::with('items.saleItem')->whereKey($release->id)->lockForUpdate()->firstOrFail();

        return [$sale, $fulfillment, $release];
    }
}
