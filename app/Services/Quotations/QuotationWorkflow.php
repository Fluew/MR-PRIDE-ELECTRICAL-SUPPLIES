<?php

namespace App\Services\Quotations;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\WorkflowException;
use App\Models\NotificationLog;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentPdf;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Procurement\ProcurementWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quotation lifecycle: Draft → Sent → Accepted (or Rejected / Expired). Within Draft, staff
 * submit it for approval and an administrator approves it (steps recorded on the quotation).
 *
 * The decisions stay with people (Admin approval, the customer's answer recorded by staff);
 * what follows them is automatic: approving sends the quotation to the customer, and recording
 * the customer's acceptance creates the Sales Order. If an automatic step cannot run, the
 * decision is still saved and the manual button (Send / Generate Sales Order) stays available.
 */
class QuotationWorkflow
{
    public const DECISION_SOURCES = ['email' => 'Email reply', 'phone' => 'Phone call', 'in_person' => 'In person', 'purchase_order' => 'Purchase order', 'other' => 'Other'];

    public function __construct(private CustomerNotifier $notifier, private DocumentPdf $pdf) {}

    /** Change status by hand: back to Draft (revise) or Rejected (declined by us before sending). */
    public function changeStatus(Quotation $quotation, QuotationStatus $to, User $user, ?string $remarks = null): Quotation
    {
        if (! in_array($to, QuotationStatus::manual(), true)) {
            throw new WorkflowException("\"{$to->label()}\" is set with its own action, not by changing the status.");
        }

        return DB::transaction(function () use ($quotation, $to, $user, $remarks) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $from = $quotation->status;
            if ($from === $to) {
                return $quotation; // nothing changes, nothing is recorded
            }
            $this->guard($quotation, $to);
            if ($to === QuotationStatus::Rejected && $from === QuotationStatus::Sent) {
                throw new WorkflowException('Record the customer\'s answer for a sent quotation with "Customer answer".');
            }

            $changes = ['status' => $to];
            // Back to Draft: the earlier submission, approval and customer answer no longer apply.
            if ($to === QuotationStatus::Draft) {
                $changes += ['submitted_for_approval_at' => null, 'approved_by_id' => null, 'approved_at' => null, 'customer_decision_at' => null, 'customer_decision_by_id' => null, 'customer_decision_source' => null, 'customer_decision_remarks' => null];
            }
            $quotation->update($changes);

            AuditLogger::record('quotation.status_changed', "Quotation {$quotation->quotation_number}: {$from->label()} → {$to->label()}.".($remarks ? " Remarks: {$remarks}" : ''), $quotation, ['from' => $from->value, 'to' => $to->value, 'remarks' => $remarks], $user);

            return $quotation;
        });
    }

    /** A priced Draft goes to an administrator for approval (it stays Draft until it is sent). */
    public function submitForApproval(Quotation $quotation, User $user, ?string $remarks = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $user, $remarks) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::Draft) {
                throw new WorkflowException("Only Draft quotations can be submitted for approval. This one is {$quotation->status->label()}.");
            }
            if ($quotation->submitted_for_approval_at) {
                return $quotation; // already waiting for approval
            }
            $this->assertPriced($quotation);
            $this->assertNotWaitingForStock($quotation);

            $quotation->update(['submitted_for_approval_at' => now()]);
            AuditLogger::record('quotation.submitted', "Quotation {$quotation->quotation_number} submitted for approval.".($remarks ? " Remarks: {$remarks}" : ''), $quotation, ['remarks' => $remarks], $user);

            return $quotation;
        });
    }

    /** Admin approval. The quotation stays Draft (approved) until staff use "Send Quotation". */
    public function approve(Quotation $quotation, User $user, ?string $remarks = null): Quotation
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can approve quotations.');
        }

        // Approval never emails the customer: staff send it with "Send Quotation".
        return DB::transaction(function () use ($quotation, $user, $remarks) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::Draft || ! $quotation->submitted_for_approval_at || $quotation->approved_at) {
                throw new WorkflowException($quotation->approved_at
                    ? 'This quotation is already approved.'
                    : 'Only Draft quotations submitted for approval can be approved.');
            }
            $this->assertPriced($quotation);

            // Valid Until runs from the approval: approval date + the configured validity days (3).
            $quotation->update(['approved_by_id' => $user->id, 'approved_at' => now(), 'valid_until' => now()->addDays((int) config('company.quotation_validity_days'))->toDateString()]);
            AuditLogger::record('quotation.approved', "Quotation {$quotation->quotation_number} approved by {$user->name}.".($remarks ? " Remarks: {$remarks}" : ''), $quotation, ['grand_total' => (float) $quotation->grand_total, 'remarks' => $remarks], $user);

            return $quotation;
        });
    }

    /** A send still in progress after this long is treated as interrupted, so it can be tried again. */
    public const SEND_TIMEOUT_MINUTES = 5;

    /**
     * "Send Quotation": email the approved quotation (with its PDF) to the customer, now. It
     * becomes Sent only if the email is accepted for delivery (see SendCustomerNotification); a
     * failure is recorded and it stays approved. A quotation already emailed is sent again only
     * when staff ask for it ($resend). Every attempt is kept in the email log.
     *
     * @return array{0: Quotation, 1: NotificationLog}
     */
    public function send(Quotation $quotation, User $user, bool $resend = false): array
    {
        $log = DB::transaction(function () use ($quotation, $user, $resend) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            $approved = $quotation->status === QuotationStatus::Draft && $quotation->approved_at;
            if (! $approved && $quotation->status !== QuotationStatus::Sent) {
                throw new WorkflowException('Only approved quotations can be sent to the customer.');
            }
            if ($quotation->status === QuotationStatus::Sent && ! $resend) {
                throw new WorkflowException("Quotation {$quotation->quotation_number} was already emailed".($quotation->sent_at ? ' on '.$quotation->sent_at->format('M d, Y h:i A') : '').'. Use Resend to send it again.');
            }
            if ($quotation->valid_until && $quotation->valid_until->toDateString() < now()->toDateString()) {
                throw new WorkflowException('This quotation has passed its validity date. Revise it before sending.');
            }
            $email = trim((string) ($quotation->email ?: $quotation->client?->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new WorkflowException('The quotation has no valid customer email address. Add one, then use Send.', 'email');
            }
            $this->assertPriced($quotation);

            $previous = NotificationLog::where('notifiable_type', 'Quotation')->where('notifiable_id', $quotation->id)->where('type', 'quotation_sent');
            $inProgress = (clone $previous)->where('status', EmailStatus::Pending->value)
                ->where('created_at', '>', now()->subMinutes(self::SEND_TIMEOUT_MINUTES))->exists();
            if ($inProgress) {
                throw new WorkflowException('This quotation is already being sent. Wait for the result before sending it again.');
            }

            // The PDF that was sent is kept, so later changes never alter what the customer received.
            $path = $this->pdf->store($this->pdf->quotation($quotation), 'quotations', $quotation->quotation_number);
            $number = $previous->count() + 1;

            $quotation->update([
                'sent_by_id' => $user->id,
                'sent_pdf_path' => $path,
                'email_status' => EmailStatus::Pending,
            ]);

            $log = $this->notifier->record('quotation_sent', $quotation, "quotation_sent:{$quotation->id}:{$number}", ['send_number' => $number]);
            AuditLogger::record($quotation->status === QuotationStatus::Sent ? 'quotation.resend_requested' : 'quotation.send_requested',
                "Quotation {$quotation->quotation_number} ".($quotation->status === QuotationStatus::Sent ? 'sent again' : 'sent')." to {$email} by {$user->name}.", $quotation, ['to' => $email, 'send_number' => $number], $user);

            return $log;
        });

        // Sent after the commit, in this request: the result is known before the page reloads.
        $log = $this->notifier->deliverNow($log);

        return [$quotation->fresh(), $log];
    }

    /**
     * Record the customer's answer, as received by staff (customers have no accounts). The
     * customer is not emailed at this step. An acceptance does not create the Sales Order: staff
     * create it from the accepted quotation in Sales → New Sales Order (or Generate Sales Order).
     */
    public function recordDecision(Quotation $quotation, bool $accepted, string $source, ?string $remarks, User $user, ?string $decidedOn = null): Quotation
    {
        if (! array_key_exists($source, self::DECISION_SOURCES)) {
            throw new WorkflowException('Choose how the customer gave their answer.', 'source');
        }

        $quotation = DB::transaction(function () use ($quotation, $accepted, $source, $remarks, $user, $decidedOn) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::Sent) {
                throw new WorkflowException('The customer\'s answer can only be recorded for a quotation that was sent to them.');
            }
            if ($accepted && $quotation->valid_until && $quotation->valid_until->toDateString() < now()->toDateString()) {
                throw new WorkflowException('This quotation expired on '.$quotation->valid_until->format('M d, Y').'. Revise and send it again before accepting.');
            }

            $to = $accepted ? QuotationStatus::Accepted : QuotationStatus::Rejected;
            $quotation->update([
                'status' => $to,
                'customer_decision_at' => $decidedOn ? Carbon::parse($decidedOn) : now(),
                'customer_decision_by_id' => $user->id,
                'customer_decision_source' => $source,
                'customer_decision_remarks' => $remarks,
            ]);
            AuditLogger::record($accepted ? 'quotation.accepted' : 'quotation.rejected', "Customer {$to->label()} quotation {$quotation->quotation_number} (".self::DECISION_SOURCES[$source].'), recorded by '.$user->name.'.', $quotation, ['source' => $source, 'remarks' => $remarks], $user);
            // No "quotation accepted" email: it was removed at the business's request (2026-11-09).

            return $quotation;
        });

        return $quotation->fresh();
    }

    /**
     * Products are short: raise one Purchase Request (pending admin approval) per short product,
     * for the missing quantity only. The quotation stays Draft and waits for the stock; a
     * submission or approval it had is withdrawn, so it cannot be sent while waiting. Products
     * that already have a purchase for this quotation, or an open request from elsewhere, are
     * not requested again.
     *
     * @return array{0: Collection<int, PurchaseRequest>, 1: list<string>} created requests, and why products were skipped
     */
    public function requestStock(Quotation $quotation, User $user): array
    {
        return DB::transaction(function () use ($quotation, $user) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::Draft) {
                throw new WorkflowException("Stock can only be requested for a Draft quotation. This one is {$quotation->status->label()}.");
            }

            $stock = app(QuotationStock::class);
            $shortages = $stock->shortages($quotation);
            if ($shortages->isEmpty()) {
                throw new WorkflowException('Every product on this quotation is in stock. Nothing needs to be purchased.');
            }

            $purchases = $stock->purchases($quotation);
            $openElsewhere = PurchaseRequest::where('team_id', $quotation->team_id)->whereIn('product_id', $shortages->keys())
                ->whereIn('status', [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::APPROVED])
                ->pluck('request_number', 'product_id');

            $lines = [];
            $skipped = [];
            foreach ($shortages as $s) {
                $pr = $purchases->get($s['product_id']);
                if ($pr && $stock->inProgress($pr)) {
                    $skipped[] = "{$s['name']}: already being purchased ({$stock->describe($pr)}).";
                } elseif ($open = $openElsewhere->get($s['product_id'])) {
                    $skipped[] = "{$s['name']}: already has an open purchase request ({$open}).";
                } elseif (! $s['active']) {
                    $skipped[] = "{$s['name']}: the product is inactive in inventory.";
                } else {
                    $lines[] = [
                        'product_id' => $s['product_id'],
                        'quantity' => $s['short'],
                        'supplier_id' => $s['supplier_id'],
                        'quotation_id' => $quotation->id,
                        'justification' => "Quotation {$quotation->quotation_number} ({$quotation->client_name}): {$s['requested']} requested, {$s['available']} available, {$s['short']} short.",
                    ];
                }
            }
            if ($lines === []) {
                throw new WorkflowException('No purchase request was created. '.implode(' ', $skipped));
            }

            $created = app(ProcurementWorkflow::class)->submitRequests($quotation->team, $user, $lines);

            // Waiting for stock: it must be checked (and approved) again once the stock is in.
            if ($quotation->submitted_for_approval_at || $quotation->approved_at) {
                $quotation->update(['submitted_for_approval_at' => null, 'approved_by_id' => null, 'approved_at' => null, 'valid_until' => null]);
            }
            AuditLogger::record('quotation.stock_requested', "Stock requested for quotation {$quotation->quotation_number}: ".$created->map(fn ($pr) => "{$pr->request_number} ({$pr->quantity} × {$pr->product?->name})")->implode(', ').'. The quotation stays Draft until the stock arrives.', $quotation, ['purchase_requests' => $created->pluck('id')->all(), 'skipped' => $skipped], $user);

            return [$created, $skipped];
        });
    }

    /** Approved (not yet sent) or sent quotations past their validity date become Expired. */
    public function expireDue(int $teamId): int
    {
        $due = Quotation::where('team_id', $teamId)
            ->where(fn ($q) => $q->where('status', QuotationStatus::Sent->value)
                ->orWhere(fn ($qq) => $qq->where('status', QuotationStatus::Draft->value)->whereNotNull('approved_at')))
            ->whereDate('valid_until', '<', now()->toDateString())
            ->get();

        foreach ($due as $quotation) {
            $quotation->update(['status' => QuotationStatus::Expired]);
            AuditLogger::record('quotation.expired', "Quotation {$quotation->quotation_number} expired (valid until {$quotation->valid_until?->format('M d, Y')}).", $quotation);
        }

        return $due->count();
    }

    private function guard(Quotation $quotation, QuotationStatus $to): void
    {
        if (! $quotation->status->canMoveTo($to)) {
            throw new WorkflowException("A quotation that is {$quotation->status->label()} cannot be changed to {$to->label()}.");
        }
    }

    /** A Draft whose short products are still being purchased stays Draft until the stock is in. */
    private function assertNotWaitingForStock(Quotation $quotation): void
    {
        $waiting = app(QuotationStock::class)->waitingFor($quotation);
        if ($waiting->isNotEmpty()) {
            throw new WorkflowException('Waiting for stock: '.$waiting->map(fn ($w) => "{$w['name']} ({$w['available']} of {$w['requested']} available, {$w['purchase']})")->implode('; ').'. Continue the quotation once the stock has been received.');
        }
    }

    private function assertPriced(Quotation $quotation): void
    {
        $items = $quotation->items()->get();
        if ($items->isEmpty()) {
            throw new WorkflowException('Add at least one product before sending the quotation for approval.');
        }
        $unpriced = $items->filter(fn ($i) => (float) $i->unit_price <= 0)->pluck('product_name');
        if ($unpriced->isNotEmpty()) {
            throw new WorkflowException('Set a selling price for every line first. Missing: '.$unpriced->take(5)->implode(', ').'.');
        }
    }
}
