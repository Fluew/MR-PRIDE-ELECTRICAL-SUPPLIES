<?php

namespace App\Jobs;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Models\NotificationLog;
use App\Models\OrderFulfillment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Services\AuditLogger;
use App\Services\CustomerEmails;
use App\Services\ResendService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one logged customer email. Errors are recorded on the log entry and retried a few
 * times; they never reach the request that triggered the email.
 */
class SendCustomerNotification implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    private const MODELS = [
        'Quotation' => Quotation::class,
        'Sale' => Sale::class,
        'Payment' => Payment::class,
        'SalesInvoice' => SalesInvoice::class,
        'OrderFulfillment' => OrderFulfillment::class,
    ];

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public int $logId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CustomerEmails $emails, ResendService $mailer): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || in_array($log->status, [EmailStatus::Sent, EmailStatus::Delivered, EmailStatus::Skipped], true)) {
            return;
        }

        $class = self::MODELS[$log->notifiable_type] ?? null;
        $record = $class ? $class::find($log->notifiable_id) : null;
        if (! $record) {
            $log->update(['status' => EmailStatus::Failed, 'error' => 'The record this email is about no longer exists.']);

            return;
        }

        $log->update(['attempts' => $log->attempts + 1, 'last_attempt_at' => now()]);

        try {
            $message = $emails->build($log->type, $record, $log->context ?? []);
        } catch (Throwable $e) {
            Log::error("Customer email {$log->type} #{$log->id} could not be prepared: ".$e->getMessage(), ['exception' => $e]);
            $this->finish($log, $record, false, 'The email could not be prepared: '.$e->getMessage());

            return;
        }

        $log->update(['recipient' => $message['to'], 'subject' => $message['subject']]);

        if (! $message['to'] || ! filter_var($message['to'], FILTER_VALIDATE_EMAIL)) {
            $log->update(['status' => EmailStatus::Skipped, 'error' => 'No valid customer email address on record.']);
            $this->mirror($log, $record);

            return;
        }

        try {
            $result = $mailer->sendCustomerEmail($message['to'], $message['subject'], $message['html'], str_replace('_', ' ', $log->type), $message['attachments']);
        } catch (Throwable $e) {
            $result = ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }

        $this->finish($log, $record, (bool) $result['success'], $result['error'] ?? null, $result['id'] ?? null);
    }

    private function finish(NotificationLog $log, mixed $record, bool $success, ?string $error, ?string $providerId = null): void
    {
        $log->update($success
            ? ['status' => EmailStatus::Sent, 'sent_at' => now(), 'provider_message_id' => $providerId, 'error' => null]
            : ['status' => EmailStatus::Failed, 'error' => $error]);
        $this->mirror($log, $record);

        AuditLogger::record(
            'email.'.$log->type,
            $success ? "Email \"{$log->subject}\" sent to {$log->recipient}." : "Email \"{$log->subject}\" to {$log->recipient} failed (attempt {$log->attempts}): {$error}",
            $record,
            ['notification_log_id' => $log->id, 'provider_message_id' => $providerId],
            null,
            $success ? 'success' : 'failure',
            $success ? 'info' : 'warning',
        );

        if (! $success && $log->attempts < self::MAX_ATTEMPTS && $this->job) {
            $delay = $this->backoff()[min($log->attempts - 1, 1)];
            // Sent in-process (after the web response): a retry cannot wait in this request, so it
            // goes to the queue. Until a worker sends it, it shows as Failed and staff can resend it.
            if ($this->job instanceof SyncJob && config('queue.default') !== 'sync') {
                self::dispatch($log->id)->delay($delay);
            } else {
                $this->release($delay);
            }
        }
    }

    /**
     * The quotation keeps its own email status for the "Send Quotation" action, and becomes Sent
     * only once its email was actually accepted for delivery (a failed send leaves it approved).
     */
    private function mirror(NotificationLog $log, mixed $record): void
    {
        if ($log->type !== 'quotation_sent' || ! $record instanceof Quotation) {
            return;
        }
        $changes = ['email_status' => $log->status];
        if ($log->status === EmailStatus::Sent) {
            $changes['sent_at'] = $log->sent_at ?? now();
            if ($record->status === QuotationStatus::Draft && $record->approved_at) {
                $changes['status'] = QuotationStatus::Sent;
            }
        }
        $record->forceFill($changes)->saveQuietly();

        if (isset($changes['status'])) {
            AuditLogger::record('quotation.status_changed', "Quotation {$record->quotation_number} is now Sent: emailed to {$log->recipient}.", $record, ['notification_log_id' => $log->id, 'from' => 'draft', 'to' => 'sent']);
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::whereKey($this->logId)->update(['status' => EmailStatus::Failed->value, 'error' => $e->getMessage()]);
    }
}
