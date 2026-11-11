<?php

namespace App\Services\Sales;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Delivery\FulfillmentService;
use App\Services\DocumentNumbers;
use App\Services\Inventory\StockLedger;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Sales Orders: conversion from an accepted quotation, payment terms, confirmation, hold,
 * cancellation and completion. The order status, payment status and delivery status are kept
 * separate and each one changes only through its own action.
 */
class SalesOrderService
{
    public function __construct(
        private StockLedger $stock,
        private InvoiceService $invoices,
        private FulfillmentService $fulfillment,
        private CustomerNotifier $notifier,
    ) {}

    /**
     * Create a Sales Order from an accepted quotation, in one transaction. The quotation stays
     * Accepted and links to the order (converted_to_sale_id), so a second order can never be made
     * from it (also enforced by the database). Stock is checked, not taken.
     *
     * @param  array{fulfillment_type?: string, address?: ?string, delivery_instructions?: ?string}  $options
     * @return array{0: Sale, 1: list<string>} the order and stock warnings
     */
    public function createFromQuotation(Quotation $quotation, User $user, array $options = []): array
    {
        return DB::transaction(function () use ($quotation, $user, $options) {
            $quotation = Quotation::with('items')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if ($quotation->converted_to_sale_id || Sale::withTrashed()->where('quotation_id', $quotation->id)->exists()) {
                throw new WorkflowException('This quotation was already converted to a Sales Order.');
            }
            if ($quotation->status !== QuotationStatus::Accepted) {
                throw new WorkflowException("Only quotations the customer accepted can become Sales Orders. This one is {$quotation->status->label()}.");
            }
            if ($quotation->items->isEmpty()) {
                throw new WorkflowException('The quotation has no items.');
            }

            $pickup = ($options['fulfillment_type'] ?? 'delivery') === 'pickup';
            $total = round((float) $quotation->grand_total, 2);

            $sale = Sale::create([
                'team_id' => $quotation->team_id,
                'order_number' => DocumentNumbers::next($quotation->team_id, DocumentNumbers::SALES_ORDER),
                'channel' => Sale::CHANNEL_QUOTATION,
                'fulfillment_type' => $pickup ? 'pickup' : 'delivery',
                'quotation_id' => $quotation->id,
                'client_name' => $quotation->client_name,
                'contact_person' => $quotation->contact_person,
                'email' => $quotation->email,
                'phone' => $quotation->phone,
                'address' => $pickup ? $quotation->address : (($options['address'] ?? null) ?: $quotation->address),
                'delivery_instructions' => $options['delivery_instructions'] ?? null,
                'sale_date' => now(),
                'subtotal' => $quotation->subtotal,
                'discount' => $quotation->discount,
                'tax_amount' => $quotation->tax_amount,
                'grand_total' => $total,
                'status' => SalesOrderStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_terms' => Sale::TERMS_FULL,
                'payment_method' => 'Bank Transfer / GCash',
                'amount_paid' => 0,
                'outstanding_balance' => $total,
                'notes' => "Created from quotation {$quotation->quotation_number}.",
                'processed_by_id' => $user->id,
            ]);

            $warnings = [];
            foreach ($quotation->items as $item) {
                $sale->items()->create([
                    'quotation_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'sku' => $item->sku,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'subtotal' => $item->subtotal,
                ]);
                if ($item->product_id && ($product = Product::find($item->product_id))) {
                    $available = $product->availableStock();
                    if ($available < $item->quantity) {
                        $warnings[] = "{$item->product_name}: {$available} available, {$item->quantity} ordered.";
                    }
                } elseif (! $item->product_id) {
                    $warnings[] = "{$item->product_name} is not linked to an inventory product; it will not be reserved or deducted.";
                }
            }

            $quotation->update([
                'converted_to_sale_id' => $sale->id,
                'converted_at' => now(),
                'converted_by_id' => $user->id,
            ]);

            AuditLogger::record('quotation.converted', "Quotation {$quotation->quotation_number} converted to Sales Order {$sale->order_number} by {$user->name}.", $quotation, ['sale_id' => $sale->id], $user);
            AuditLogger::record('sales_order.created', "Sales Order {$sale->order_number} created from quotation {$quotation->quotation_number} (\$".number_format($total, 2).', pending payment).'.($warnings ? ' Stock warnings: '.implode(' ', $warnings) : ''), $sale, ['quotation_id' => $quotation->id, 'stock_warnings' => $warnings], $user);

            // No "sales order created" email: it was removed at the business's request (2026-11-09).
            // The customer's next email is the payment/order confirmation.

            return [$sale, $warnings];
        });
    }

    /** Admin: set how this order may be paid. Credit terms confirm the order straight away. */
    public function setTerms(Sale $sale, string $terms, ?float $downPayment, ?int $creditDays, User $user): Sale
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can approve payment terms.');
        }

        return DB::transaction(function () use ($sale, $terms, $downPayment, $creditDays, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== SalesOrderStatus::Pending) {
                throw new WorkflowException('Payment terms can only be changed before the order is confirmed.');
            }

            $changes = ['payment_terms' => $terms, 'required_down_payment' => null, 'credit_days' => null, 'credit_due_date' => null,
                'terms_approved_by_id' => $terms === Sale::TERMS_FULL ? null : $user->id, 'terms_approved_at' => $terms === Sale::TERMS_FULL ? null : now()];
            $summary = 'full payment before confirmation';

            if ($terms === Sale::TERMS_DOWN_PAYMENT) {
                if (! $downPayment || $downPayment <= 0 || $downPayment > (float) $sale->grand_total) {
                    throw new WorkflowException('Enter a down payment between $0.01 and the order total.', 'required_down_payment');
                }
                $changes['required_down_payment'] = round($downPayment, 2);
                $summary = 'down payment of $'.number_format($downPayment, 2);
            } elseif ($terms === Sale::TERMS_CREDIT) {
                if (! $creditDays || $creditDays < 1 || $creditDays > 365) {
                    throw new WorkflowException('Enter credit terms between 1 and 365 days.', 'credit_days');
                }
                $changes['credit_days'] = $creditDays;
                $changes['credit_due_date'] = now()->addDays($creditDays)->toDateString();
                $summary = "credit terms of {$creditDays} days";
            }

            $sale->update($changes);
            AuditLogger::record('sales_order.terms_set', "Payment terms for {$sale->order_number}: {$summary}.", $sale, ['terms' => $terms], $user);

            $this->evaluateConfirmation($sale->fresh(), $user);

            return $sale->fresh();
        });
    }

    /** Confirm the order when its payment terms are met (verified payments only). */
    public function evaluateConfirmation(Sale $sale, ?User $user = null): bool
    {
        // An order on hold is not confirmed until it is resumed.
        if ($sale->status !== SalesOrderStatus::Pending || $sale->hold_reason) {
            return false;
        }

        $paid = $sale->verifiedTotal();
        $met = match ($sale->payment_terms) {
            Sale::TERMS_CREDIT => $sale->terms_approved_at !== null,
            Sale::TERMS_DOWN_PAYMENT => $paid + 0.004 >= (float) $sale->required_down_payment,
            default => $paid + 0.004 >= (float) $sale->grand_total,
        };

        if ($met) {
            $this->confirm($sale, $user);
        }

        return $met;
    }

    /**
     * Confirmed: reserve stock, issue the sales invoice, start the delivery record at
     * Pending, and email the customer (after the transaction commits).
     */
    public function confirm(Sale $sale, ?User $user = null): void
    {
        DB::transaction(function () use ($sale, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== SalesOrderStatus::Pending) {
                return;
            }

            $sale->update(['status' => SalesOrderStatus::Confirmed, 'confirmed_at' => now(), 'confirmed_by_id' => $user?->id]);
            $basis = match ($sale->payment_terms) {
                Sale::TERMS_CREDIT => "approved credit terms ({$sale->credit_days} days)",
                Sale::TERMS_DOWN_PAYMENT => 'verified down payment',
                default => 'verified full payment',
            };
            AuditLogger::record('sales_order.confirmed', "Sales Order {$sale->order_number} confirmed on {$basis}.", $sale, ['payment_status' => $sale->payment_status?->value], $user);

            $shortages = $this->stock->reserve($sale, $user);
            $this->invoices->issue($sale, 'confirmation', $user);
            $fulfillment = $this->fulfillment->initialize($sale, $user);

            if ($sale->channel === Sale::CHANNEL_COUNTER && $user) {
                // Counter sale recorded before counter sales were removed (2026-11-10): the goods were
                // handed over when sold, so verifying its payment completes it.
                $this->fulfillment->counterHandover($sale, $user);
                $this->complete($sale->fresh(), $user, automatic: true);

                return;
            }

            $this->notifier->notify('order_confirmed', $sale, "order_confirmed:{$sale->id}", ['shortages' => $shortages, 'fulfillment_id' => $fulfillment->id]);
        });
    }

    public function hold(Sale $sale, string $reason, User $user): void
    {
        $this->adminOnly($user, 'put an order on hold');
        DB::transaction(function () use ($sale, $reason, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $sale->status->isOpen()) {
                throw new WorkflowException("An order that is {$sale->status->label()} cannot be put on hold.");
            }
            if ($sale->hold_reason) {
                throw new WorkflowException('This order is already on hold.');
            }
            // On hold is a flag: the order keeps its status; releases and confirmation wait.
            $sale->update(['hold_reason' => $reason]);
            AuditLogger::record('sales_order.on_hold', "Order {$sale->order_number} put on hold: {$reason}", $sale, [], $user, 'success', 'warning');
        });
    }

    public function resume(Sale $sale, User $user): void
    {
        $this->adminOnly($user, 'resume an order');
        DB::transaction(function () use ($sale, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $sale->hold_reason) {
                throw new WorkflowException('This order is not on hold.');
            }
            $sale->update(['hold_reason' => null]);
            AuditLogger::record('sales_order.resumed', "Order {$sale->order_number} resumed ({$sale->status->label()}).", $sale, [], $user);
            $this->evaluateConfirmation($sale->fresh(), $user);
        });
    }

    /**
     * Cancel an order before anything was delivered. Reservations are freed, an issued invoice
     * is cancelled, and verified payments stay on record (refunds are recorded separately).
     */
    public function cancel(Sale $sale, string $reason, User $user): void
    {
        $this->adminOnly($user, 'cancel an order');
        DB::transaction(function () use ($sale, $reason, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $sale->status->isOpen()) {
                throw new WorkflowException("Order {$sale->order_number} is already {$sale->status->label()}.");
            }
            $lines = SaleItem::where('sale_id', $sale->id)->get();
            if ($lines->sum('delivered_quantity') > 0) {
                throw new WorkflowException('Part of this order was already delivered. Cancel the remaining quantities instead.');
            }
            $fulfillment = $sale->fulfillment;
            if ($fulfillment && $fulfillment->status && ($fulfillment->status === DeliveryStatus::InTransit || $fulfillment->goodsOnTruck())) {
                throw new WorkflowException('Goods for this order are on the road. Record the return to the warehouse first.');
            }
            if ($open = $fulfillment?->openRelease()) {
                $this->fulfillment->cancelRelease($open, $user, 'order cancelled');
            }

            $this->stock->releaseReservations($sale, null, $user);
            $this->stock->returnUndelivered($sale, $reason, $user);
            foreach ($sale->invoices()->where('status', 'issued')->get() as $invoice) {
                $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_id' => $user->id, 'cancellation_reason' => "Order cancelled: {$reason}"]);
                AuditLogger::record('invoice.cancelled', "Sales invoice {$invoice->invoice_number} cancelled with the order: {$reason}", $sale, ['invoice_id' => $invoice->id], $user);
            }
            if ($fulfillment && $fulfillment->status) {
                $this->fulfillment->cancel($fulfillment->fresh(), $reason, $user);
            }

            $sale->update(['status' => SalesOrderStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by_id' => $user->id, 'cancellation_reason' => $reason, 'invoice_number' => null]);
            AuditLogger::record('sales_order.cancelled', "Order {$sale->order_number} cancelled: {$reason}".((float) $sale->amount_paid > 0 ? ' Verified payments of $'.number_format((float) $sale->amount_paid, 2).' remain on record; record any refund separately.' : ''), $sale, [], $user, 'success', 'warning');
            $this->notifier->notify('order_cancelled', $sale, "order_cancelled:{$sale->id}");
        });
    }

    /**
     * Admin: stop owing quantities that will not be delivered (sale_item_id => quantity, or all).
     *
     * @param  array<int|string, int|string>|null  $quantities
     */
    public function cancelRemaining(Sale $sale, ?array $quantities, string $reason, User $user): void
    {
        $this->adminOnly($user, 'cancel remaining quantities');
        DB::transaction(function () use ($sale, $quantities, $reason, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                throw new WorkflowException('Remaining quantities can only be cancelled on a confirmed order.');
            }

            $freed = [];
            $done = [];
            foreach (SaleItem::where('sale_id', $sale->id)->lockForUpdate()->get() as $line) {
                $max = $line->releasable_quantity; // not delivered, not on a release
                $qty = $quantities === null ? $max : min($max, (int) ($quantities[$line->id] ?? 0));
                if ($qty <= 0) {
                    continue;
                }
                $line->increment('cancelled_quantity', $qty);
                $freed[$line->id] = $qty;
                $done[] = "{$line->product_name} × {$qty}";
            }
            if ($done === []) {
                throw new WorkflowException('There is nothing left to cancel (quantities on a release must be returned or delivered first).');
            }

            $this->stock->releaseReservations($sale, $freed, $user, 'remaining quantity cancelled');
            AuditLogger::record('sales_order.quantity_cancelled', "Remaining quantities cancelled on {$sale->order_number}: ".implode(', ', $done).". Reason: {$reason}", $sale, ['quantities' => $freed], $user, 'success', 'warning');

            if ($sale->fulfillment) {
                $this->fulfillment->settle($sale->fulfillment, $user, $reason);
            }
        });
    }

    /**
     * Complete the order once every quantity is delivered or cancelled and no delivery problem is
     * open. Full-payment orders must be fully paid; approved down-payment and credit orders may
     * be completed with a balance.
     */
    public function complete(Sale $sale, User $user, bool $automatic = false): void
    {
        DB::transaction(function () use ($sale, $user, $automatic) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                throw new WorkflowException("An order that is {$sale->status->label()} cannot be completed.");
            }
            $open = SaleItem::where('sale_id', $sale->id)->get()->filter(fn ($l) => $l->remaining_quantity > 0);
            if ($open->isNotEmpty()) {
                throw new WorkflowException('Not everything has been delivered yet: '.$open->map(fn ($l) => "{$l->product_name} ({$l->remaining_quantity} left)")->take(5)->implode(', ').'.');
            }
            $fulfillment = $sale->fulfillment;
            if (! $fulfillment || ! $fulfillment->status || $fulfillment->status !== DeliveryStatus::Delivered) {
                throw new WorkflowException('The delivery is not finished ('.($fulfillment?->status?->label() ?? 'no delivery record').').');
            }
            if ($sale->payment_terms === Sale::TERMS_FULL && (float) $sale->outstanding_balance > 0.004) {
                throw new WorkflowException('This order still has a balance of $'.number_format((float) $sale->outstanding_balance, 2).'. Full-payment orders are completed once fully paid.');
            }

            $this->fulfillment->complete($fulfillment, $user);
            $sale->update(['status' => SalesOrderStatus::Completed, 'completed_at' => now(), 'completed_by_id' => $user->id]);
            AuditLogger::record('sales_order.completed', "Sales Order {$sale->order_number} completed".($automatic ? ' automatically (everything delivered)' : '').'.'.((float) $sale->outstanding_balance > 0.004 ? ' Outstanding balance of $'.number_format((float) $sale->outstanding_balance, 2).' under '.str_replace('_', ' ', $sale->payment_terms).' terms.' : ''), $sale, [], $user);
            if ($sale->channel !== Sale::CHANNEL_COUNTER) {
                $this->notifier->notify('order_completed', $sale, "order_completed:{$sale->id}");
            }
        });
    }

    /**
     * Next stage after the last delivery: complete the order when nothing blocks it (everything
     * delivered, and paid unless approved terms allow a balance). Otherwise it stays open.
     */
    public function completeIfDone(Sale $sale, User $user): bool
    {
        try {
            $this->complete($sale, $user, automatic: true);

            return true;
        } catch (WorkflowException $e) {
            AuditLogger::record('sales_order.not_completed', "Order {$sale->order_number} is fully delivered but stays open: {$e->getMessage()}", $sale, [], $user, 'success', 'info');

            return false;
        }
    }

    /** amount_paid, balance and payment status from the payment records (verified ones count). */
    public function recalculatePayments(Sale $sale): void
    {
        $payments = Payment::where('sale_id', $sale->id)->get();
        $paid = round((float) $payments->where('status', PaymentRecordStatus::Verified)->sum('verified_amount'), 2);
        $total = round((float) $sale->grand_total, 2);
        $latest = $payments->sortByDesc('id')->first();
        $lastVerified = $payments->where('status', PaymentRecordStatus::Verified)->sortByDesc('verified_at')->first();

        $status = match (true) {
            $paid + 0.004 >= $total && $total > 0 => PaymentStatus::Paid,
            $paid > 0 => PaymentStatus::PartiallyPaid,
            $payments->contains(fn ($p) => $p->status === PaymentRecordStatus::UnderVerification) => PaymentStatus::UnderVerification,
            $payments->contains(fn ($p) => $p->status === PaymentRecordStatus::Submitted) => PaymentStatus::PaymentSubmitted,
            $latest?->status === PaymentRecordStatus::Rejected => PaymentStatus::Rejected,
            $payments->contains(fn ($p) => $p->status === PaymentRecordStatus::Refunded) => PaymentStatus::Refunded,
            default => PaymentStatus::Unpaid,
        };

        $sale->forceFill([
            'amount_paid' => $paid,
            'outstanding_balance' => max(0, round($total - $paid, 2)),
            'payment_status' => $status,
            // Older screens and reports read these from the order.
            'payment_method' => $lastVerified?->method ?? $latest?->method ?? $sale->payment_method,
            'payment_reference' => $lastVerified?->verified_reference ?? $latest?->submitted_reference,
            'payment_date' => $lastVerified?->verified_payment_date ?? $latest?->payment_date,
            'verified_by_id' => $lastVerified?->verified_by_id,
            'verified_at' => $lastVerified?->verified_at,
        ])->save();
    }

    private function adminOnly(User $user, string $what): void
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException("Only an administrator can {$what}.");
        }
    }
}
