<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Enums\EmailStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentPdf;
use App\Services\Inventory\StockLedger;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales Orders: the list (new orders are created from accepted quotations), the order page, payments and their
 * verification, receipts, invoices and the order actions (terms, hold, cancel, complete).
 * Routes: Admin and Sales staff only (role:admin,staff_sales).
 */
class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'status' => $request->input('status', 'All'),
            'payment_status' => $request->input('payment_status', 'All'),
            'delivery_status' => $request->input('delivery_status', 'All'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort' => in_array($request->input('sort'), ['oldest', 'total_desc', 'balance_desc'], true) ? $request->input('sort') : 'newest',
        ];

        $query = Sale::where('team_id', $team->id)
            ->with(['items', 'fulfillment:id,sale_id,status,fulfillment_type,fulfillment_number', 'quotation:id,quotation_number', 'processedBy:id,name']);

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhereHas('quotation', fn ($qq) => $qq->where('quotation_number', 'like', "%{$search}%"));
            });
        }
        if ($filters['status'] !== 'All' && SalesOrderStatus::tryFrom($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($filters['payment_status'] !== 'All' && PaymentStatus::tryFrom($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }
        if ($filters['delivery_status'] !== 'All' && DeliveryStatus::tryFrom($filters['delivery_status'])) {
            $query->whereHas('fulfillment', fn ($q) => $q->where('status', $filters['delivery_status']));
        }
        if ($filters['date_from']) {
            $query->whereDate('sale_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to']) {
            $query->whereDate('sale_date', '<=', $filters['date_to']);
        }

        match ($filters['sort']) {
            'oldest' => $query->oldest('sale_date')->oldest('id'),
            'total_desc' => $query->orderByDesc('grand_total'),
            'balance_desc' => $query->orderByDesc('outstanding_balance'),
            default => $query->latest('sale_date')->latest('id'),
        };

        $sales = $query->paginate(15)->withQueryString();
        $sales->getCollection()->each(fn (Sale $s) => $s->setAttribute('delivery_status_label', $s->fulfillment?->status_label));

        $counted = fn () => Sale::where('team_id', $team->id)->whereIn('status', SalesOrderStatus::countedInSales());
        $metrics = [
            'totalSales' => Sale::where('team_id', $team->id)->count(),
            'todaySales' => (float) $counted()->whereDate('sale_date', today())->sum('grand_total'),
            'monthSales' => (float) $counted()->whereMonth('sale_date', now()->month)->whereYear('sale_date', now()->year)->sum('grand_total'),
            'pendingPayments' => Sale::where('team_id', $team->id)->where('status', SalesOrderStatus::Pending->value)->count(),
            'toVerify' => Payment::where('team_id', $team->id)->whereIn('status', ['submitted', 'under_verification'])->count(),
            'outstanding' => (float) Sale::where('team_id', $team->id)->whereNotIn('status', [SalesOrderStatus::Cancelled->value])->sum('outstanding_balance'),
        ];

        // "New Sales Order": accepted quotations without a Sales Order yet, with their agreed lines.
        // Draft, sent, rejected and expired quotations, and converted ones, are never listed.
        $quotations = Quotation::where('team_id', $team->id)
            ->where('status', QuotationStatus::Accepted->value)
            ->whereNull('converted_to_sale_id')
            // Also not when an order (even an archived one) already points at it.
            ->whereNotIn('id', Sale::withTrashed()->whereNotNull('quotation_id')->select('quotation_id'))
            ->whereHas('items')
            ->with(['items.product:id,unit'])
            ->latest('customer_decision_at')
            ->latest('id')
            ->get()
            ->map(fn (Quotation $q) => [
                'id' => $q->id,
                'quotation_number' => $q->quotation_number,
                'client_name' => $q->client_name,
                'address' => $q->address,
                'quotation_date' => $q->quotation_date instanceof CarbonInterface ? $q->quotation_date->toDateString() : (string) $q->quotation_date,
                'accepted_on' => $q->customer_decision_at?->toDateString(),
                'subtotal' => (float) $q->subtotal,
                'discount' => (float) $q->discount,
                'tax_rate' => (float) $q->tax_rate,
                'tax_amount' => (float) $q->tax_amount,
                'grand_total' => (float) $q->grand_total,
                'items' => $q->items->map(fn ($item) => [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    // The quotation has no unit column: it is the linked product's unit, when there is one.
                    'unit' => $item->product?->unit,
                    // Agreed price per unit after the quoted line discount; amount = the quoted line subtotal.
                    'unit_price' => max(0, round((float) $item->unit_price - (float) $item->discount, 2)),
                    'amount' => (float) $item->subtotal,
                ])->values()->all(),
            ])
            ->values();

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'metrics' => $metrics,
            'quotations' => $quotations,
            'options' => $this->statusOptions(),
        ]);
    }

    /**
     * New Sales Order from an accepted quotation. The quotation is the only source: customer,
     * products, quantities and agreed prices are copied from it and it is not changed (it is only
     * marked converted). Eligibility is checked again here, in one locked transaction, so an order
     * is never created twice. No stock moves and no payment is recorded: stock is reserved on
     * confirmation and taken out by Outbound Delivery; payments are recorded on the order page.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');
        if ($request->filled('fulfillment_type')) {
            $request->merge(['fulfillment_type' => strtolower((string) $request->input('fulfillment_type'))]);
        }

        $validated = $request->validate([
            'quotation_id' => ['required', 'integer', Rule::exists('quotations', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
            'fulfillment_type' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
        ], [
            'quotation_id.required' => 'Select a quotation.',
            'quotation_id.exists' => 'The selected quotation was not found.',
        ]);

        return $this->runWorkflow(function () use ($team, $validated, $request) {
            $quotation = Quotation::where('team_id', $team->id)->findOrFail($validated['quotation_id']);
            [$sale, $warnings] = app(SalesOrderService::class)->createFromQuotation($quotation, $request->user(), [
                'fulfillment_type' => $validated['fulfillment_type'] ?? 'delivery',
                'address' => $validated['delivery_address'] ?? null,
                'delivery_instructions' => $validated['delivery_instructions'] ?? null,
            ]);
            if ($warnings) {
                session()->flash('warning', 'Stock check: '.implode(' ', $warnings));
            }

            return "Sales Order {$sale->order_number} created from quotation {$quotation->quotation_number} (pending payment). Record the customer's payment on the order page.";
        });
    }

    /** The Sales Order page: lines with quantities, payments, receipts, invoices, deliveries, history. */
    public function show(Request $request, mixed $current_team = null, mixed $sale = null): Response
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $sale->load([
            'items.product:id,name,sku,stock',
            'quotation:id,quotation_number,status,valid_until,sent_at',
            'processedBy:id,name',
            'confirmedBy:id,name',
            'termsApprovedBy:id,name',
            'payments.recordedBy:id,name',
            'payments.verifiedBy:id,name',
            'payments.rejectedBy:id,name',
            'payments.receipt',
            'receipts',
            'invoices.issuedBy:id,name',
            'invoices.cancelledBy:id,name',
            'fulfillment.handledBy:id,name',
            'fulfillment.attemptLogs.staff:id,name',
            'releases.items.saleItem:id,sku,product_name',
            'releases.preparedBy:id,name',
            'releases.acknowledgedBy:id,name',
        ]);

        $paymentIds = $sale->payments->pluck('id');
        $notifications = NotificationLog::where(function ($q) use ($sale, $paymentIds) {
            $q->where(fn ($qq) => $qq->where('notifiable_type', 'Sale')->where('notifiable_id', $sale->id))
                ->orWhere(fn ($qq) => $qq->where('notifiable_type', 'Payment')->whereIn('notifiable_id', $paymentIds))
                ->orWhere(fn ($qq) => $qq->where('notifiable_type', 'SalesInvoice')->whereIn('notifiable_id', $sale->invoices->pluck('id')))
                ->when($sale->fulfillment, fn ($qq) => $qq->orWhere(fn ($q3) => $q3->where('notifiable_type', 'OrderFulfillment')->where('notifiable_id', $sale->fulfillment->id)));
        })->latest('id')->get(['id', 'category', 'type', 'notifiable_type', 'notifiable_id', 'recipient', 'subject', 'status', 'attempts', 'error', 'last_attempt_at', 'sent_at', 'created_at']);

        $activity = AuditLog::where('subject_type', 'Sale')->where('subject_id', $sale->id)
            ->latest('id')->limit(200)
            ->get(['id', 'event', 'description', 'user_email', 'outcome', 'created_at']);

        $user = $request->user();

        return Inertia::render('Sales/Show', [
            'sale' => $sale,
            'deliveryStatusLabel' => $sale->fulfillment?->status_label,
            'notifications' => $notifications,
            'activity' => $activity,
            'paymentMethods' => Payment::METHODS,
            'can' => [
                'verify' => $user->isAdmin() || $user->hasModule('sales'),
                'admin' => $user->isAdmin(),
            ],
            'company' => ['bir_registered' => (bool) config('company.bir_registered')],
        ]);
    }

    // ------------------------------------------------------------------------------ payments

    public function storePayment(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $validated = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->runWorkflow(function () use ($sale, $validated, $request) {
            app(PaymentService::class)->record($sale, $validated, $request->file('proof'), $request->user(), $validated['idempotency_key'] ?? null);

            return 'Payment recorded. It counts towards the order only after it is verified against the actual transaction.';
        });
    }

    public function startVerification(Request $request, mixed $current_team = null, mixed $sale = null, mixed $payment = null): RedirectResponse
    {
        [, $payment] = $this->authorizedPayment($request, $sale, $payment, $current_team);

        return $this->runWorkflow(function () use ($payment, $request) {
            app(PaymentService::class)->startVerification($payment, $request->user());

            return 'Marked as under verification.';
        });
    }

    public function verifyPayment(Request $request, mixed $current_team = null, mixed $sale = null, mixed $payment = null): RedirectResponse
    {
        [$sale, $payment] = $this->authorizedPayment($request, $sale, $payment, $current_team);
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($payment, $validated, $request, $sale) {
            $receipt = app(PaymentService::class)->verify($payment, $validated, $request->user());
            $sale->refresh();

            return "Payment verified. Receipt {$receipt->receipt_number} generated."
                .($sale->status === SalesOrderStatus::Confirmed || $sale->status === SalesOrderStatus::Completed ? " Order {$sale->order_number} is confirmed." : ' Balance: $'.number_format((float) $sale->outstanding_balance, 2).'.');
        });
    }

    public function rejectPayment(Request $request, mixed $current_team = null, mixed $sale = null, mixed $payment = null): RedirectResponse
    {
        [, $payment] = $this->authorizedPayment($request, $sale, $payment, $current_team);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($payment, $reason, $request) {
            app(PaymentService::class)->reject($payment, $reason, $request->user());

            return 'Payment rejected. The customer is being notified.';
        });
    }

    public function refundPayment(Request $request, mixed $current_team = null, mixed $sale = null, mixed $payment = null): RedirectResponse
    {
        [, $payment] = $this->authorizedPayment($request, $sale, $payment, $current_team);
        abort_unless($request->user()->isAdmin(), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($payment, $reason, $request) {
            app(PaymentService::class)->refund($payment, $reason, $request->user());

            return 'Refund recorded.';
        });
    }

    public function paymentProof(Request $request, mixed $current_team = null, mixed $sale = null, mixed $payment = null): StreamedResponse
    {
        [, $payment] = $this->authorizedPayment($request, $sale, $payment, $current_team);
        abort_if(! $payment->proof_path, 404);

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($payment->proof_path)) {
                return Storage::disk($disk)->response($payment->proof_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
            }
        }
        abort(404);
    }

    // -------------------------------------------------------------------------- order actions

    public function setTerms(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can approve payment terms.');
        $validated = $request->validate([
            'payment_terms' => ['required', Rule::in([Sale::TERMS_FULL, Sale::TERMS_DOWN_PAYMENT, Sale::TERMS_CREDIT])],
            'required_down_payment' => ['nullable', 'numeric', 'min:0.01'],
            'credit_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return $this->runWorkflow(function () use ($sale, $validated, $request) {
            $sale = app(SalesOrderService::class)->setTerms($sale, $validated['payment_terms'], isset($validated['required_down_payment']) ? (float) $validated['required_down_payment'] : null, $validated['credit_days'] ?? null, $request->user());

            return 'Payment terms saved.'.($sale->status === SalesOrderStatus::Confirmed ? " Order {$sale->order_number} is confirmed." : '');
        });
    }

    public function hold(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(fn () => tap('Order put on hold.', fn () => app(SalesOrderService::class)->hold($sale, $reason, $request->user())));
    }

    public function resume(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);

        return $this->runWorkflow(fn () => tap('Order resumed.', fn () => app(SalesOrderService::class)->resume($sale, $request->user())));
    }

    public function cancel(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(fn () => tap("Order {$sale->order_number} cancelled. Reserved stock was released.", fn () => app(SalesOrderService::class)->cancel($sale, $reason, $request->user())));
    }

    public function cancelRemaining(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        return $this->runWorkflow(fn () => tap('Remaining quantities cancelled.', fn () => app(SalesOrderService::class)->cancelRemaining($sale, $validated['quantities'] ?? null, $validated['reason'], $request->user())));
    }

    /** Reserve stock that arrived since the order was confirmed. */
    public function reserve(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);

        return $this->runWorkflow(function () use ($sale, $request) {
            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                throw new WorkflowException('Stock is reserved only for confirmed orders.');
            }
            $shortages = DB::transaction(fn () => app(StockLedger::class)->reserve(Sale::whereKey($sale->id)->lockForUpdate()->first(), $request->user()));

            return $shortages ? 'Reserved what is available. Still waiting for: '.collect($shortages)->map(fn ($s) => $s['product'].' ('.($s['needed'] - $s['reserved']).')')->implode(', ').'.' : 'All needed stock is reserved.';
        });
    }

    public function complete(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);

        return $this->runWorkflow(fn () => tap("Order {$sale->order_number} completed.", fn () => app(SalesOrderService::class)->complete($sale, $request->user())));
    }

    // ------------------------------------------------------------------------------ documents

    public function orderPdf(Request $request, mixed $current_team = null, mixed $sale = null): HttpResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);

        return $this->pdfResponse(app(DocumentPdf::class)->salesOrder($sale), "Sales-Order-{$sale->order_number}.pdf");
    }

    public function receiptPdf(Request $request, mixed $current_team = null, mixed $sale = null, mixed $receipt = null): HttpResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $receipt = PaymentReceipt::where('sale_id', $sale->id)->findOrFail((int) ($receipt ?? $request->route('receipt')));
        $content = $receipt->pdf_path && Storage::disk('local')->exists($receipt->pdf_path)
            ? Storage::disk('local')->get($receipt->pdf_path)
            : app(DocumentPdf::class)->receipt($receipt);

        return $this->pdfResponse($content, "Receipt-{$receipt->receipt_number}.pdf");
    }

    public function invoicePdf(Request $request, mixed $current_team = null, mixed $sale = null, mixed $invoice = null): HttpResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $invoice = SalesInvoice::where('sale_id', $sale->id)->findOrFail((int) ($invoice ?? $request->route('invoice')));
        // An issued invoice is served exactly as issued; a cancelled one shows the cancellation.
        $content = $invoice->status === SalesInvoice::ISSUED && $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? Storage::disk('local')->get($invoice->pdf_path)
            : app(DocumentPdf::class)->invoice($invoice);

        return $this->pdfResponse($content, "Invoice-{$invoice->invoice_number}.pdf");
    }

    public function cancelInvoice(Request $request, mixed $current_team = null, mixed $sale = null, mixed $invoice = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        abort_unless($request->user()->isAdmin(), 403);
        $invoice = SalesInvoice::where('sale_id', $sale->id)->findOrFail((int) ($invoice ?? $request->route('invoice')));
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500'], 'reissue' => ['boolean']]);

        return $this->runWorkflow(function () use ($invoice, $validated, $request) {
            $new = app(InvoiceService::class)->cancel($invoice, $validated['reason'], $request->user(), (bool) ($validated['reissue'] ?? false));

            return $new ? "Invoice {$invoice->invoice_number} cancelled and replaced by {$new->invoice_number}. Use Send Commercial Invoice to email it." : "Invoice {$invoice->invoice_number} cancelled.";
        });
    }

    /** "Send Commercial Invoice" / "Resend": email the issued invoice PDF to the customer now (never automatic). */
    public function sendInvoice(Request $request, mixed $current_team = null, mixed $sale = null, mixed $invoice = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $invoice = SalesInvoice::where('sale_id', $sale->id)->findOrFail((int) ($invoice ?? $request->route('invoice')));
        $request->validate(['resend' => ['sometimes', 'boolean']]);

        return $this->runWorkflow(function () use ($invoice, $request) {
            $log = app(InvoiceService::class)->send($invoice, $request->user(), $request->boolean('resend'));
            if ($log->status !== EmailStatus::Sent) {
                throw new WorkflowException("The email to {$log->recipient} could not be sent".($log->error ? ": {$log->error}" : '.').' The invoice was not marked as sent. Try again.');
            }

            return "Commercial invoice {$invoice->invoice_number} was emailed to {$log->recipient}.";
        });
    }

    /** Try a failed customer email again (same event; never a duplicate of a sent one). */
    public function retryNotification(Request $request, mixed $current_team = null, mixed $sale = null, mixed $notification = null): RedirectResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $log = NotificationLog::where('team_id', $sale->team_id)->findOrFail((int) ($notification ?? $request->route('notification')));

        return $this->runWorkflow(function () use ($log) {
            app(CustomerNotifier::class)->retry($log);

            return 'The email is being sent again.';
        });
    }

    /** Legacy: proof attached to sales recorded before payments were kept separately. */
    public function proof(Request $request, mixed $current_team = null, mixed $sale = null): StreamedResponse
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        abort_if(! $sale->proof_of_payment, 404);

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($sale->proof_of_payment)) {
                return Storage::disk($disk)->response($sale->proof_of_payment, null, [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }

    /**
     * Archive a cancelled or completed order into the Recycle Bin.
     *
     * Cancelled orders already had their stock released on cancellation. Completed orders return
     * their line item quantities to inventory when archived so that restoring them from the
     * Recycle Bin can deduct them again cleanly.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $sale = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $sale = $this->authorizedSale($request, $sale, $current_team);
        if (! in_array($sale->status, [SalesOrderStatus::Cancelled, SalesOrderStatus::Completed], true)) {
            return back()->withErrors(['error' => "Cancel order {$sale->order_number} first. Only cancelled or completed orders can be archived."]);
        }

        DB::transaction(function () use ($sale, $request) {
            if ($sale->status === SalesOrderStatus::Completed) {
                $sale->load('items');

                foreach ($sale->items as $item) {
                    $product = $item->product_id
                        ? Product::whereKey($item->product_id)->lockForUpdate()->first()
                        : null;

                    if (! $product) {
                        continue;
                    }

                    $previousStock = (int) $product->stock;
                    $product->increment('stock', (int) $item->quantity);

                    StockMovement::create([
                        'team_id' => $sale->team_id,
                        'product_id' => $product->id,
                        'user_id' => $request->user()?->id,
                        'type' => 'adjustment',
                        'quantity' => (int) $item->quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $previousStock + (int) $item->quantity,
                        'reference_number' => $sale->invoice_number ?: $sale->order_number,
                        'reason' => 'Sale '.($sale->invoice_number ?: $sale->order_number).' voided / deleted',
                        'movement_date' => now(),
                    ]);

                    $product->refresh();
                    $product->updateCalculatedStatus();
                }
            }

            $sale->delete();
        });

        AuditLogger::audit(
            'sale.archived',
            "Moved sale transaction {$sale->order_number} to the Recycle Bin.",
            $request->user(),
            null,
            ['id' => $sale->id, 'order_number' => $sale->order_number, 'invoice_number' => $sale->invoice_number],
            'success',
            $request
        );

        return back()->with('success', "Order {$sale->order_number} moved to the Recycle Bin.");
    }

    // -------------------------------------------------------------------------------- helpers

    /** @return array<string, list<array{value: string, label: string}>> */
    private function statusOptions(): array
    {
        $map = fn (array $cases) => collect($cases)->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values()->all();

        return [
            'status' => $map(SalesOrderStatus::cases()),
            'payment_status' => $map(PaymentStatus::cases()),
            'delivery_status' => $map(DeliveryStatus::cases()),
        ];
    }

    private function pdfResponse(string $content, string $filename): HttpResponse
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedSale(Request $request, mixed $sale, mixed $current_team): Sale
    {
        $sale = $this->resolveSale($sale, $current_team, $request);
        $team = $request->user()->currentTeam;
        abort_if(! $team || $sale->team_id !== $team->id, 403, 'Unauthorized sale access.');

        return $sale;
    }

    /** @return array{0: Sale, 1: Payment} */
    private function authorizedPayment(Request $request, mixed $sale, mixed $payment, mixed $current_team): array
    {
        $sale = $this->authorizedSale($request, $sale, $current_team);
        $payment = Payment::where('sale_id', $sale->id)->findOrFail((int) ($payment ?? $request->route('payment')));

        return [$sale, $payment];
    }

    protected function resolveSale(mixed $sale, mixed $fallback = null, ?Request $request = null): Sale
    {
        if ($sale instanceof Sale) {
            return $sale;
        }

        foreach (array_filter([$sale, $request?->route('sale'), $fallback], fn ($v) => $v !== null && $v !== '') as $cand) {
            if ($cand instanceof Sale) {
                return $cand;
            }
            if (is_numeric($cand) && ($found = Sale::find((int) $cand))) {
                return $found;
            }
        }

        abort(404, 'Sale record not found.');
    }
}
