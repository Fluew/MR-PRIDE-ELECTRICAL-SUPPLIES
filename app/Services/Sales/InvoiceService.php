<?php

namespace App\Services\Sales;

use App\Enums\EmailStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\NotificationLog;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\Documents\DocumentPdf;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sales invoices are issued when the order is confirmed (the company's process). Each invoicing
 * event issues one invoice (issue_key is unique). Issued invoices are never edited: a correction
 * cancels the invoice with a reason and issues a replacement that points to it.
 */
class InvoiceService
{
    public function __construct(private DocumentPdf $pdf, private CustomerNotifier $notifier) {}

    public function issue(Sale $sale, string $event, ?User $user = null, ?SalesInvoice $replaces = null): SalesInvoice
    {
        $key = "{$event}:{$sale->id}";
        if ($existing = SalesInvoice::where('team_id', $sale->team_id)->where('issue_key', $key)->first()) {
            return $existing;
        }

        $sale->loadMissing(['items', 'quotation']);
        $invoice = SalesInvoice::create([
            'team_id' => $sale->team_id,
            'sale_id' => $sale->id,
            'invoice_number' => DocumentNumbers::next($sale->team_id, DocumentNumbers::SALES_INVOICE),
            'status' => SalesInvoice::ISSUED,
            'issue_key' => $key,
            'customer' => [
                'name' => $sale->client_name,
                'contact_person' => $sale->contact_person,
                'email' => $sale->email,
                'phone' => $sale->phone,
                'address' => $sale->address,
            ],
            'items' => $sale->items->map(fn ($i) => [
                'sku' => $i->sku,
                'product_name' => $i->product_name,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'subtotal' => (float) $i->subtotal,
            ])->values()->all(),
            'subtotal' => $sale->subtotal,
            'discount' => $sale->discount,
            'tax_rate' => $sale->quotation?->tax_rate ?? 0,
            'tax_amount' => $sale->tax_amount,
            'grand_total' => $sale->grand_total,
            'issued_at' => now(),
            'issued_by_id' => $user?->id,
            'replaces_invoice_id' => $replaces?->id,
        ]);

        $sale->forceFill(['invoice_number' => $invoice->invoice_number])->save();
        AuditLogger::record('invoice.issued', "Sales invoice {$invoice->invoice_number} issued for {$sale->order_number}".($replaces ? " (replaces {$replaces->invoice_number})" : '').'.', $sale, ['invoice_id' => $invoice->id, 'event' => $event], $user);

        DB::afterCommit(fn () => $this->storePdf($invoice));

        return $invoice;
    }

    /** Cancel an issued invoice, optionally issuing a corrected replacement (staff then send it with "Send Commercial Invoice"). */
    public function cancel(SalesInvoice $invoice, string $reason, User $user, bool $reissue): ?SalesInvoice
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can cancel an invoice.');
        }

        return DB::transaction(function () use ($invoice, $reason, $user, $reissue) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== SalesInvoice::ISSUED) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is already cancelled.");
            }
            $sale = Sale::whereKey($invoice->sale_id)->lockForUpdate()->firstOrFail();

            $invoice->update(['status' => SalesInvoice::CANCELLED, 'cancelled_at' => now(), 'cancelled_by_id' => $user->id, 'cancellation_reason' => $reason]);
            AuditLogger::record('invoice.cancelled', "Sales invoice {$invoice->invoice_number} cancelled: {$reason}", $sale, ['invoice_id' => $invoice->id], $user);

            if (! $reissue) {
                $sale->forceFill(['invoice_number' => null])->save();

                return null;
            }

            return $this->issue($sale, "reissue-{$invoice->id}", $user, $invoice);
        });
    }

    /** A send still in progress after this long is treated as interrupted, so it can be tried again. */
    public const SEND_TIMEOUT_MINUTES = 5;

    /**
     * "Send Commercial Invoice": email the issued invoice (PDF attached) to the customer, now.
     * Never automatic. Available once the order is confirmed; an invoice already emailed is sent
     * again only when staff ask for it ($resend). Every attempt is kept in the email log, whose
     * sent_at is the sending date and time; nothing is marked sent unless the email went out.
     */
    public function send(SalesInvoice $invoice, User $user, bool $resend = false): NotificationLog
    {
        $log = DB::transaction(function () use ($invoice, $user, $resend) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($invoice->sale_id)->firstOrFail();

            if ($invoice->status !== SalesInvoice::ISSUED) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is cancelled and cannot be sent.");
            }
            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing, SalesOrderStatus::Completed], true)) {
                throw new WorkflowException("Order {$sale->order_number} is {$sale->status->label()}: the commercial invoice can be sent once the order is confirmed.");
            }
            $email = trim((string) ($sale->email ?: $sale->quotation?->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new WorkflowException("Order {$sale->order_number} has no valid customer email address. Add one to the order first.", 'email');
            }

            $previous = NotificationLog::where('notifiable_type', 'SalesInvoice')->where('notifiable_id', $invoice->id)->where('type', 'invoice_issued');
            if ((clone $previous)->where('status', EmailStatus::Pending->value)->where('created_at', '>', now()->subMinutes(self::SEND_TIMEOUT_MINUTES))->exists()) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is already being sent. Wait for the result before sending it again.");
            }
            $lastSent = (clone $previous)->where('status', EmailStatus::Sent->value)->latest('sent_at')->first();
            if ($lastSent && ! $resend) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} was already emailed on ".$lastSent->sent_at?->format('M d, Y h:i A').'. Use Resend to send it again.');
            }

            $number = $previous->count() + 1;
            $log = $this->notifier->record('invoice_issued', $invoice, "invoice_issued:{$invoice->id}:send-{$number}", ['send_number' => $number]);
            AuditLogger::record($lastSent ? 'invoice.resend_requested' : 'invoice.send_requested',
                "Commercial invoice {$invoice->invoice_number} ".($lastSent ? 'sent again' : 'sent')." to {$email} by {$user->name}.", $sale, ['invoice_id' => $invoice->id, 'to' => $email, 'send_number' => $number], $user);

            return $log;
        });

        $this->storePdf($invoice); // the attached PDF is the stored, issued one

        return $this->notifier->deliverNow($log);
    }

    /** Store the issued PDF once; cancelled invoices are rendered fresh, with the cancellation shown. */
    public function storePdf(SalesInvoice $invoice): void
    {
        $invoice->refresh();
        if ($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            return;
        }
        $invoice->forceFill(['pdf_path' => $this->pdf->store($this->pdf->invoice($invoice), 'invoices', $invoice->invoice_number)])->save();
    }
}
