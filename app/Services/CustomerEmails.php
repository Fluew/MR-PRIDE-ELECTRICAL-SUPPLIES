<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryRelease;
use App\Models\OrderFulfillment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Services\Documents\DocumentPdf;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Builds customer emails (subject, HTML and PDF attachments) for SendCustomerNotification.
 * Customer emails never contain supplier costs, margins or internal notes. Amounts are USD,
 * the currency they are stored in.
 */
class CustomerEmails
{
    public function __construct(private DocumentPdf $pdf) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{to: ?string, subject: string, html: string, attachments: array<int, array{filename: string, content: string}>}
     */
    public function build(string $type, object $record, array $context = []): array
    {
        return match ($type) {
            'quotation_sent' => $this->quotationSent($this->expect($record, Quotation::class)),
            // Not sent any more (removed 2026-11-09); kept to resend or show earlier emails of this type.
            'quotation_accepted' => $this->quotationAccepted($this->expect($record, Quotation::class)),
            'order_completed' => $this->orderCompleted($this->expect($record, Sale::class)),
            // Not sent any more (removed 2026-11-09); kept to resend or show earlier emails of this type.
            'sales_order_created' => $this->salesOrderCreated($this->expect($record, Sale::class)),
            'order_confirmed' => $this->orderConfirmed($this->expect($record, Sale::class)),
            'order_cancelled' => $this->orderCancelled($this->expect($record, Sale::class)),
            'payment_submitted' => $this->paymentSubmitted($this->expect($record, Payment::class)),
            'payment_verified' => $this->paymentVerified($this->expect($record, Payment::class)),
            'payment_rejected' => $this->paymentRejected($this->expect($record, Payment::class)),
            'invoice_issued' => $this->invoiceIssued($this->expect($record, SalesInvoice::class)),
            'delivery_status' => $this->deliveryStatus($this->expect($record, OrderFulfillment::class), $context),
            default => throw new InvalidArgumentException("Unknown customer email type {$type}."),
        };
    }

    private function quotationSent(Quotation $quotation): array
    {
        $quotation->loadMissing(['items', 'client']);
        $company = config('company.name');

        $html = $this->layout(
            "Quotation {$quotation->quotation_number}",
            $this->greeting($quotation->contact_person ?: $quotation->client_name),
            'Thank you for your interest. Please find our quotation below and attached as a PDF.',
            [
                'Quotation No.' => $quotation->quotation_number,
                'Date' => $quotation->quotation_date?->format('M d, Y'),
                'Valid Until' => $quotation->valid_until?->format('M d, Y'),
                'Customer' => $quotation->client_name,
            ],
            $this->rows($quotation->items),
            $this->totals($quotation),
            ($quotation->terms_and_conditions ? "Terms and conditions:\n{$quotation->terms_and_conditions}\n\n" : '')
                .'To accept this quotation, reply to this email or contact us before it expires. Prices are valid until '.$quotation->valid_until?->format('M d, Y').'.',
        );

        return $this->message(
            $quotation->email ?: $quotation->client?->email,
            "Your Quotation {$quotation->quotation_number} from {$company}",
            $html,
            [$this->quotationAttachment($quotation)],
        );
    }

    private function quotationAccepted(Quotation $quotation): array
    {
        $quotation->loadMissing(['items', 'client']);
        $html = $this->layout(
            'Thank you: your acceptance is recorded',
            $this->greeting($quotation->contact_person ?: $quotation->client_name),
            "We have recorded your acceptance of quotation {$quotation->quotation_number}. Your Sales Order with the payment instructions follows in a separate email.",
            [
                'Quotation No.' => $quotation->quotation_number,
                'Accepted On' => $quotation->customer_decision_at?->format('M d, Y'),
                'Total' => $this->money($quotation->grand_total),
            ],
            $this->rows($quotation->items),
            $this->totals($quotation),
        );

        return $this->message($quotation->email ?: $quotation->client?->email, "Quotation {$quotation->quotation_number} Accepted – ".config('company.name'), $html);
    }

    private function orderCompleted(Sale $sale): array
    {
        $sale->loadMissing(['items', 'invoices']);
        $balance = (float) $sale->outstanding_balance;
        $html = $this->layout(
            "Order {$sale->order_number} completed",
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'All items in your order have been '.($sale->isPickup() ? 'picked up' : 'delivered').' and your order is now complete. Thank you for your business.',
            [
                'Sales Order No.' => $sale->order_number,
                'Sales Invoice No.' => $sale->invoice_number,
                'Completed' => $sale->completed_at?->format('M d, Y'),
                'Amount Paid' => $this->money($sale->amount_paid),
                'Balance' => $balance > 0.004 ? $this->money($balance).($sale->credit_due_date ? ' (due '.$sale->credit_due_date->format('M d, Y').')' : '') : null,
            ],
            $this->rows($sale->items),
            $this->totals($sale),
            $balance > 0.004 ? 'The remaining balance is payable under the agreed payment terms.' : null,
        );

        return $this->message($this->saleEmail($sale), "Order Completed – {$sale->order_number}", $html);
    }

    private function salesOrderCreated(Sale $sale): array
    {
        $sale->loadMissing(['items', 'quotation']);
        $instructions = collect(config('company.payment_instructions'))
            ->map(fn ($details, $method) => $details ? "{$method}: {$details}" : "{$method}: our team will send you the account details.")
            ->implode("\n");

        $next = match ($sale->payment_terms) {
            Sale::TERMS_DOWN_PAYMENT => 'Next step: please pay the down payment of '.$this->money($sale->required_down_payment).'. Your order will be confirmed once we have verified it.',
            Sale::TERMS_CREDIT => 'Your order is on approved credit terms. We will confirm it shortly.',
            default => 'Next step: please pay the total of '.$this->money($sale->grand_total).'. Your order will be confirmed once we have verified your payment.',
        };

        $html = $this->layout(
            "Sales Order {$sale->order_number}",
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'Your Sales Order has been created. It is not confirmed yet: '.lcfirst(rtrim($next, '.')).'.',
            [
                'Sales Order No.' => $sale->order_number,
                'Quotation No.' => $sale->quotation?->quotation_number,
                'Customer' => $sale->client_name,
                'Contact Person' => $sale->contact_person,
                'Contact Number' => $sale->phone,
                'Email' => $sale->email,
                $sale->isPickup() ? 'Pickup' : 'Delivery Address' => $sale->isPickup() ? 'At our warehouse' : $sale->address,
            ],
            $this->rows($sale->items),
            $this->totals($sale),
            "Payment methods:\n{$instructions}\n\nWhen you have paid, reply with your transaction reference and a screenshot or photo of the payment. A screenshot alone is not proof of payment: we confirm every payment against our bank or GCash records.",
        );

        $attachments = [['filename' => "Sales-Order-{$sale->order_number}.pdf", 'content' => $this->pdf->salesOrder($sale)]];
        if ($sale->quotation) {
            array_unshift($attachments, $this->quotationAttachment($sale->quotation));
        }

        return $this->message($this->saleEmail($sale), "Your Sales Order Has Been Created – {$sale->order_number}", $html, $attachments);
    }

    /** The commercial invoice is not attached: staff send it separately ("Send Commercial Invoice"). */
    private function orderConfirmed(Sale $sale): array
    {
        $sale->loadMissing('items');

        $basis = match ($sale->payment_terms) {
            Sale::TERMS_CREDIT => 'on approved credit terms',
            Sale::TERMS_DOWN_PAYMENT => 'after we verified your down payment',
            default => 'after we verified your payment',
        };

        $html = $this->layout(
            "Order {$sale->order_number} confirmed",
            $this->greeting($sale->contact_person ?: $sale->client_name),
            "Your order is officially confirmed {$basis}. We will now prepare your items".($sale->isPickup() ? ' for pickup.' : ' for delivery.'),
            [
                'Sales Order No.' => $sale->order_number,
                'Confirmed' => $sale->confirmed_at?->format('M d, Y h:i A'),
                'Amount Paid' => $this->money($sale->amount_paid),
                'Balance' => $this->money($sale->outstanding_balance),
                'Payment Terms' => match ($sale->payment_terms) {
                    Sale::TERMS_CREDIT => "Credit, {$sale->credit_days} days".($sale->credit_due_date ? ' (due '.$sale->credit_due_date->format('M d, Y').')' : ''),
                    Sale::TERMS_DOWN_PAYMENT => 'Down payment received; balance as agreed',
                    default => 'Paid in full',
                },
            ],
            [],
            [],
            'Your commercial invoice will be sent to you in a separate email.',
        );

        return $this->message($this->saleEmail($sale), "Order Confirmed – {$sale->order_number}", $html);
    }

    private function orderCancelled(Sale $sale): array
    {
        $html = $this->layout(
            "Order {$sale->order_number} cancelled",
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'Your order has been cancelled.',
            [
                'Sales Order No.' => $sale->order_number,
                'Cancelled' => $sale->cancelled_at?->format('M d, Y'),
                'Reason' => $sale->cancellation_reason,
            ],
            [],
            [],
            (float) $sale->amount_paid > 0 ? 'Our team will contact you about the payment you made for this order.' : 'If you have questions, reply to this email.',
        );

        return $this->message($this->saleEmail($sale), "Order Cancelled – {$sale->order_number}", $html);
    }

    private function paymentSubmitted(Payment $payment): array
    {
        $sale = $payment->sale;
        $html = $this->layout(
            'We received your payment details',
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'Thank you. We have recorded your payment details and will verify them against our records. You will receive a receipt once the payment is verified.',
            [
                'Sales Order No.' => $sale->order_number,
                'Payment Method' => $payment->method,
                'Reference' => $payment->submitted_reference,
                'Amount' => $this->money($payment->submitted_amount),
                'Payment Date' => $payment->payment_date?->format('M d, Y'),
            ],
        );

        return $this->message($this->saleEmail($sale), "Payment Details Received – {$sale->order_number}", $html);
    }

    private function paymentVerified(Payment $payment): array
    {
        $payment->loadMissing(['sale', 'receipt']);
        $sale = $payment->sale;
        $receipt = $payment->receipt;

        $html = $this->layout(
            'Payment received',
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'We have verified your payment. Your acknowledgment receipt is attached.',
            [
                'Sales Order No.' => $sale->order_number,
                'Receipt No.' => $receipt?->receipt_number,
                'Amount Received' => $this->money($payment->verified_amount),
                'Reference' => $payment->verified_reference,
                'Remaining Balance' => $this->money($receipt?->balance_after ?? $sale->outstanding_balance),
            ],
        );

        $attachments = [];
        if ($receipt) {
            $content = $receipt->pdf_path && Storage::disk('local')->exists($receipt->pdf_path)
                ? Storage::disk('local')->get($receipt->pdf_path)
                : $this->pdf->receipt($receipt);
            $attachments[] = ['filename' => "Receipt-{$receipt->receipt_number}.pdf", 'content' => $content];
        }

        return $this->message($this->saleEmail($sale), "Payment Received – {$sale->order_number}", $html, $attachments);
    }

    private function paymentRejected(Payment $payment): array
    {
        $sale = $payment->sale;
        $html = $this->layout(
            'We could not verify your payment',
            $this->greeting($sale->contact_person ?: $sale->client_name),
            'We could not match this payment to a transaction in our records, so it has not been counted towards your order.',
            [
                'Sales Order No.' => $sale->order_number,
                'Payment Method' => $payment->method,
                'Reference' => $payment->submitted_reference,
                'Amount' => $this->money($payment->submitted_amount),
                'Reason' => $payment->rejection_reason,
            ],
            [],
            [],
            'Please check the details, or reply to this email with the correct transaction reference.',
        );

        return $this->message($this->saleEmail($sale), "Payment Not Verified – {$sale->order_number}", $html);
    }

    /** "Send Commercial Invoice" (staff-triggered): the issued invoice PDF, attached. */
    private function invoiceIssued(SalesInvoice $invoice): array
    {
        $sale = $invoice->sale;
        $html = $this->layout(
            "Commercial Invoice {$invoice->invoice_number}",
            $this->greeting($sale->contact_person ?: $sale->client_name),
            $invoice->replaces_invoice_id
                ? 'Please find attached the corrected commercial invoice for your order. It replaces the earlier invoice.'
                : 'Thank you for your order. Please find attached the commercial invoice for your confirmed order.',
            [
                'Invoice No.' => $invoice->invoice_number,
                'Sales Order No.' => $sale->order_number,
                'Invoice Date' => $invoice->issued_at?->format('M d, Y'),
                'Total' => $this->money($invoice->grand_total),
                'Amount Paid' => $this->money($sale->amount_paid),
                'Balance' => $this->money($sale->outstanding_balance),
            ],
            [],
            [],
            'If you have any questions about this invoice, simply reply to this email.',
        );

        return $this->message($this->saleEmail($sale), "Commercial Invoice {$invoice->invoice_number} – Order {$sale->order_number}", $html, [$this->invoiceAttachment($invoice)]);
    }

    /** @param  array<string, mixed>  $context  status, release_id, remarks */
    private function deliveryStatus(OrderFulfillment $fulfillment, array $context): array
    {
        $fulfillment->loadMissing('sale.items');
        $sale = $fulfillment->sale;
        $status = DeliveryStatus::from((string) $context['status']);
        $pickup = $fulfillment->isPickup();
        $release = isset($context['release_id']) ? DeliveryRelease::with('items.saleItem')->find($context['release_id']) : null;

        $partial = (bool) ($context['partial'] ?? false);
        $remainingCancelled = (bool) ($context['remaining_cancelled'] ?? false);

        // A failed attempt leaves the delivery Ready for Delivery, but the customer hears what happened.
        $attemptFailed = ! empty($context['attempt_failed']);

        [$title, $intro] = match (true) {
            $attemptFailed => ['We could not deliver your order', 'Our delivery attempt was not successful. Our team will contact you to arrange the next delivery; you can also reply to this email with a better time or address.'],
            $status === DeliveryStatus::ReadyForDelivery => $pickup
                ? ['Your order is ready for pickup', 'Your items are ready. You can pick them up at our warehouse.']
                : ['Your order is ready for delivery', 'Your items are packed and ready to be dispatched to you.'],
            $status === DeliveryStatus::InTransit => ['Your order is on the way', 'Your order has left our warehouse and is in transit. Please make sure someone is available to receive it.'],
            $status === DeliveryStatus::Delivered => $partial
                ? [$pickup ? 'Part of your order was picked up' : 'Part of your order was delivered', 'Part of your order has been received. The remaining items will follow.']
                : [$pickup ? 'Your order was picked up' : 'Your order was delivered', 'All items in your order have been received. Thank you for your business.'],
            $status === DeliveryStatus::Cancelled => $remainingCancelled
                ? ['The rest of your delivery was cancelled', 'The items of your order that were not delivered yet will not be delivered.']
                : ['Your delivery was cancelled', 'The delivery of your order has been cancelled.'],
            default => throw new InvalidArgumentException("No customer email for delivery status {$status->value}."),
        };

        $received = $release && $status === DeliveryStatus::Delivered;
        $rows = [];
        if ($received) {
            foreach ($release->items as $item) {
                $rows[] = [$item->saleItem?->sku, $item->saleItem?->product_name, (int) $item->delivered_quantity, '', ''];
            }
        }
        $remaining = $partial
            ? $sale->items->filter(fn ($i) => $i->remaining_quantity > 0)->map(fn ($i) => "{$i->product_name}: {$i->remaining_quantity}")->implode("\n")
            : null;

        $html = $this->layout(
            $title,
            $this->greeting($fulfillment->recipient_name ?: $sale->client_name),
            $intro,
            [
                'Sales Order No.' => $sale->order_number,
                'Delivery No.' => $release?->release_number ?? $fulfillment->fulfillment_number,
                $pickup ? 'Pickup' : 'Delivery Address' => $pickup ? 'At our warehouse' : $fulfillment->delivery_address,
                'Scheduled' => $status === DeliveryStatus::ReadyForDelivery ? $fulfillment->delivery_schedule?->format('M d, Y h:i A') : null,
                'Received By' => $received ? $release->received_by_name : null,
                'Remarks' => $context['remarks'] ?? null,
            ],
            $rows,
            [],
            $remaining ? "Still to follow:\n{$remaining}" : null,
            $rows ? ['SKU', 'Item', 'Qty received'] : null,
        );

        $attachments = [];
        if ($received) {
            $attachments[] = ['filename' => "Delivery-{$release->release_number}.pdf", 'content' => $this->pdf->deliveryReceipt($release)];
        }

        return $this->message($this->saleEmail($sale), "{$title} – {$sale->order_number}", $html, $attachments);
    }

    // ----------------------------------------------------------------------------- helpers

    /** @return array{filename: string, content: string} */
    private function quotationAttachment(Quotation $quotation): array
    {
        $content = $quotation->sent_pdf_path && Storage::disk('local')->exists($quotation->sent_pdf_path)
            ? Storage::disk('local')->get($quotation->sent_pdf_path)
            : $this->pdf->quotation($quotation);

        return ['filename' => "Quotation-{$quotation->quotation_number}.pdf", 'content' => $content];
    }

    /** @return array{filename: string, content: string} */
    private function invoiceAttachment(SalesInvoice $invoice): array
    {
        $content = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? Storage::disk('local')->get($invoice->pdf_path)
            : $this->pdf->invoice($invoice);

        return ['filename' => "Invoice-{$invoice->invoice_number}.pdf", 'content' => $content];
    }

    private function saleEmail(Sale $sale): ?string
    {
        return $sale->email ?: $sale->quotation?->email;
    }

    /** @return array{to: ?string, subject: string, html: string, attachments: array} */
    private function message(?string $to, string $subject, string $html, array $attachments = []): array
    {
        return ['to' => $to ? trim($to) : null, 'subject' => $subject, 'html' => $html, 'attachments' => $attachments];
    }

    private function greeting(?string $name): string
    {
        return 'Dear '.e($name ?: 'Customer').',';
    }

    private function rows(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [$item->sku, $item->product_name, (int) $item->quantity, $this->money($item->unit_price), $this->money($item->subtotal)];
        }

        return $rows;
    }

    private function totals(Quotation|Sale $record): array
    {
        return array_filter([
            'Subtotal' => $this->money($record->subtotal),
            'Discount' => (float) $record->discount > 0 ? '-'.$this->money($record->discount) : null,
            'Tax' => $this->money($record->tax_amount),
            'Total' => $this->money($record->grand_total),
        ]);
    }

    private function money(mixed $amount): string
    {
        return '$'.number_format((float) $amount, 2).' USD';
    }

    private function expect(object $record, string $class): mixed
    {
        if (! $record instanceof $class) {
            throw new InvalidArgumentException('Expected '.class_basename($class).', got '.class_basename($record).'.');
        }

        return $record;
    }

    /**
     * Simple, email-client-safe HTML (inline styles, tables). Every value is escaped.
     *
     * @param  array<string, ?string>  $details
     * @param  array<int, array<int, mixed>>  $rows  [sku, name, qty, unit price, line total]
     * @param  array<string, string>  $totals
     * @param  list<string>|null  $headings
     */
    private function layout(string $title, string $greeting, string $intro, array $details, array $rows = [], array $totals = [], ?string $footnote = null, ?array $headings = null): string
    {
        $company = (string) config('company.name');
        $detailHtml = collect($details)->filter(fn ($v) => filled($v))->map(fn ($v, $k) => '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;">'.e($k).'</td><td style="padding:4px 0;font-weight:600;color:#111827;">'.e((string) $v).'</td></tr>'
        )->implode('');

        $itemsHtml = '';
        if ($rows !== []) {
            $headings ??= ['SKU', 'Item', 'Qty', 'Unit Price', 'Amount'];
            $head = collect($headings)->map(fn ($h, $i) => '<th align="'.($i >= 2 ? 'right' : 'left').'" style="padding:8px;">'.e($h).'</th>')->implode('');
            $body = collect($rows)->map(fn ($r) => '<tr>'.collect(array_slice($r, 0, count($headings)))->map(fn ($c, $i) => '<td style="padding:8px;border-bottom:1px solid #e5e7eb;'.($i >= 2 ? 'text-align:right;' : '').($i === 0 ? 'color:#6b7280;' : '').'">'.e((string) $c).'</td>')->implode('').'</tr>')->implode('');
            $itemsHtml = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:20px;font-size:13px;">'
                .'<tr style="background:#f3f4f6;">'.$head.'</tr>'.$body.'</table>';
        }

        $totalsHtml = collect($totals)->map(fn ($v, $k) => '<tr><td style="padding:4px 12px;color:#6b7280;text-align:right;">'.e($k).'</td><td style="padding:4px 0;text-align:right;font-weight:'.($k === 'Total' ? '800' : '600').';">'.e($v).'</td></tr>'
        )->implode('');
        $totalsHtml = $totalsHtml ? '<table cellpadding="0" cellspacing="0" style="margin:16px 0 0 auto;font-size:13px;">'.$totalsHtml.'</table>' : '';

        $foot = $footnote ? '<p style="margin-top:20px;font-size:12px;color:#6b7280;">'.nl2br(e($footnote)).'</p>' : '';

        return '<!DOCTYPE html><html><body style="margin:0;background:#f9fafb;font-family:Arial,Helvetica,sans-serif;color:#111827;">'
            .'<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
            .'<table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">'
            .'<tr><td style="background:#4b5320;padding:20px 24px;color:#ffffff;"><div style="font-size:12px;letter-spacing:1px;color:#F8B803;font-weight:700;">'.e(strtoupper($company)).'</div>'
            .'<div style="font-size:20px;font-weight:800;margin-top:4px;">'.e($title).'</div></td></tr>'
            .'<tr><td style="padding:24px;font-size:14px;line-height:1.5;">'
            .'<p style="margin:0 0 8px;">'.$greeting.'</p><p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<table cellpadding="0" cellspacing="0" style="font-size:13px;">'.$detailHtml.'</table>'
            .$itemsHtml.$totalsHtml.$foot
            .'</td></tr>'
            .'<tr><td style="padding:16px 24px;background:#f3f4f6;font-size:11px;color:#6b7280;">This is an automated message from '.e($company).'. Amounts are in US dollars (USD).</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
