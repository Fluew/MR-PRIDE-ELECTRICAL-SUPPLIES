<?php

namespace App\Services\Sales;

use App\Enums\PaymentRecordStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\Documents\DocumentPdf;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Payments: staff record what the customer submitted, then verify it against the real bank or
 * GCash transaction (or reject it). Uploading proof never verifies anything. Only verified
 * amounts count, each verified payment gets its own acknowledgment receipt, and a verified
 * transaction reference cannot be counted twice.
 */
class PaymentService
{
    public function __construct(
        private SalesOrderService $orders,
        private CustomerNotifier $notifier,
        private DocumentPdf $pdf,
    ) {}

    /**
     * @param  array{method: string, amount: float|string, reference?: ?string, payment_date: string, remarks?: ?string}  $data
     */
    public function record(Sale $sale, array $data, ?UploadedFile $proof, User $user, ?string $idempotencyKey = null, bool $notify = true): Payment
    {
        if ($idempotencyKey && ($existing = Payment::where('team_id', $sale->team_id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing; // the same form was submitted twice
        }

        $path = $proof?->store('proofs/payments', 'local');

        try {
            return DB::transaction(function () use ($sale, $data, $path, $user, $idempotencyKey) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($sale->status === SalesOrderStatus::Cancelled) {
                    throw new WorkflowException("Order {$sale->order_number} is cancelled; payments cannot be recorded.");
                }
                if ((float) $data['amount'] <= 0) {
                    throw new WorkflowException('Enter the amount the customer paid.', 'amount');
                }

                $payment = Payment::create([
                    'team_id' => $sale->team_id,
                    'sale_id' => $sale->id,
                    'method' => $data['method'],
                    'submitted_amount' => round((float) $data['amount'], 2),
                    'submitted_reference' => $data['reference'] ?? null,
                    'payment_date' => $data['payment_date'],
                    'proof_path' => $path,
                    'remarks' => $data['remarks'] ?? null,
                    'recorded_by_id' => $user->id,
                    'status' => PaymentRecordStatus::Submitted,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->orders->recalculatePayments($sale);

                AuditLogger::record('payment.submitted', "Payment of \${$payment->submitted_amount} by {$payment->method} (ref ".($payment->submitted_reference ?: 'none').") recorded for {$sale->order_number}; waiting for verification.", $sale, ['payment_id' => $payment->id], $user);

                return $payment;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($e instanceof UniqueConstraintViolationException && $idempotencyKey) {
                return Payment::where('team_id', $sale->team_id)->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }
    }

    /** Staff started checking the transaction. */
    public function startVerification(Payment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            [$sale, $payment] = $this->lock($payment);
            if ($payment->status !== PaymentRecordStatus::Submitted) {
                return;
            }
            $payment->update(['status' => PaymentRecordStatus::UnderVerification]);
            $this->orders->recalculatePayments($sale);
            AuditLogger::record('payment.under_verification', "{$user->name} is verifying the {$payment->method} payment (ref ".($payment->submitted_reference ?: 'none').") for {$sale->order_number}.", $sale, ['payment_id' => $payment->id], $user);
        });
    }

    /**
     * Confirm the payment after checking the real transaction.
     *
     * @param  array{reference: string, amount: float|string, payment_date: string, remarks?: ?string}  $data
     */
    public function verify(Payment $payment, array $data, User $user, bool $notify = true): PaymentReceipt
    {
        $this->assertCanVerify($user);

        try {
            return DB::transaction(function () use ($payment, $data, $user, $notify) {
                [$sale, $payment] = $this->lock($payment);
                if (! $payment->status->isPending()) {
                    throw new WorkflowException("This payment is already {$payment->status->label()}.");
                }

                $amount = round((float) $data['amount'], 2);
                $before = $sale->verifiedTotal();
                $balance = round((float) $sale->grand_total - $before, 2);
                if ($amount <= 0) {
                    throw new WorkflowException('Enter the amount actually received.', 'amount');
                }
                if ($amount - $balance > 0.004) {
                    throw new WorkflowException('The amount received ($'.number_format($amount, 2).') is more than the remaining balance ($'.number_format(max(0, $balance), 2).'). Record only what applies to this order.', 'amount');
                }
                $reference = trim((string) $data['reference']);
                if ($reference === '') {
                    throw new WorkflowException('Enter the transaction reference you found in the bank or GCash records.', 'reference');
                }
                $key = Payment::referenceKey($payment->method, $reference);
                if (Payment::where('team_id', $sale->team_id)->where('verified_reference_key', $key)->whereKeyNot($payment->id)->exists()) {
                    throw new WorkflowException("The {$payment->method} reference {$reference} was already verified for another payment. The same transaction cannot be counted twice.", 'reference');
                }

                $payment->update([
                    'status' => PaymentRecordStatus::Verified,
                    'verified_reference' => $reference,
                    'verified_reference_key' => $key,
                    'verified_amount' => $amount,
                    'verified_payment_date' => $data['payment_date'],
                    'verified_by_id' => $user->id,
                    'verified_at' => now(),
                    'verification_remarks' => $data['remarks'] ?? null,
                ]);
                $this->orders->recalculatePayments($sale);

                $receipt = PaymentReceipt::create([
                    'team_id' => $sale->team_id,
                    'sale_id' => $sale->id,
                    'payment_id' => $payment->id,
                    'receipt_number' => DocumentNumbers::next($sale->team_id, DocumentNumbers::RECEIPT),
                    'amount_received' => $amount,
                    'order_total' => $sale->grand_total,
                    'previously_paid' => $before,
                    'balance_after' => max(0, round((float) $sale->grand_total - $before - $amount, 2)),
                    'method' => $payment->method,
                    'reference' => $reference,
                    'payment_date' => $data['payment_date'],
                    'generated_by_id' => $user->id,
                    'generated_at' => now(),
                ]);

                $changed = $amount !== round((float) $payment->submitted_amount, 2) || $reference !== (string) $payment->submitted_reference;
                AuditLogger::record('payment.verified', "Payment verified for {$sale->order_number}: \$".number_format($amount, 2)." received by {$payment->method}, ref {$reference}".($changed ? ' (submitted: $'.number_format((float) $payment->submitted_amount, 2).', ref '.($payment->submitted_reference ?: 'none').')' : '').". Receipt {$receipt->receipt_number}.", $sale, ['payment_id' => $payment->id, 'receipt_id' => $receipt->id], $user);

                DB::afterCommit(fn () => $this->storeReceiptPdf($receipt));
                if ($notify) {
                    $this->notifier->notify('payment_verified', $payment, "payment_verified:{$payment->id}");
                }

                $this->orders->evaluateConfirmation($sale->fresh(), $user);

                return $receipt;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WorkflowException('That transaction reference was just verified for another payment. The same transaction cannot be counted twice.', 'reference');
        }
    }

    public function reject(Payment $payment, string $reason, User $user): void
    {
        $this->assertCanVerify($user);

        DB::transaction(function () use ($payment, $reason, $user) {
            [$sale, $payment] = $this->lock($payment);
            if (! $payment->status->isPending()) {
                throw new WorkflowException("This payment is already {$payment->status->label()}.");
            }
            $payment->update(['status' => PaymentRecordStatus::Rejected, 'rejected_by_id' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->orders->recalculatePayments($sale);

            AuditLogger::record('payment.rejected', "Payment of \${$payment->submitted_amount} (ref ".($payment->submitted_reference ?: 'none').") for {$sale->order_number} rejected: {$reason}", $sale, ['payment_id' => $payment->id], $user, 'failure', 'warning');
            $this->notifier->notify('payment_rejected', $payment, "payment_rejected:{$payment->id}");
        });
    }

    /** Admin: money was given back. The verified record and its receipt stay; it no longer counts. */
    public function refund(Payment $payment, string $reason, User $user): void
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can record a refund.');
        }

        DB::transaction(function () use ($payment, $reason, $user) {
            [$sale, $payment] = $this->lock($payment);
            if ($payment->status !== PaymentRecordStatus::Verified) {
                throw new WorkflowException('Only a verified payment can be refunded.');
            }
            $payment->update(['status' => PaymentRecordStatus::Refunded, 'refunded_by_id' => $user->id, 'refunded_at' => now(), 'refund_reason' => $reason]);
            $this->orders->recalculatePayments($sale);

            AuditLogger::record('payment.refunded', "Payment of \${$payment->verified_amount} (ref {$payment->verified_reference}) for {$sale->order_number} refunded: {$reason}", $sale, ['payment_id' => $payment->id], $user, 'success', 'warning');
        });
    }

    public function storeReceiptPdf(PaymentReceipt $receipt): void
    {
        $receipt->refresh();
        if ($receipt->pdf_path && Storage::disk('local')->exists($receipt->pdf_path)) {
            return;
        }
        $receipt->forceFill(['pdf_path' => $this->pdf->store($this->pdf->receipt($receipt), 'receipts', $receipt->receipt_number)])->save();
    }

    private function assertCanVerify(User $user): void
    {
        if (! $user->isAdmin() && ! $user->hasModule('sales')) {
            throw new WorkflowException('Only administrators and sales staff can verify payments.');
        }
    }

    /** @return array{0: Sale, 1: Payment} */
    private function lock(Payment $payment): array
    {
        $sale = Sale::whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();

        return [$sale, Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail()];
    }
}
