<?php

namespace App\Services\Notifications;

use App\Enums\EmailStatus;
use App\Jobs\SendCustomerNotification;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues one customer email for one business event.
 *
 * - The key identifies the event (e.g. "order_confirmed:12"): the same key is never
 *   emailed twice, so retried or repeated actions do not send duplicates.
 * - The log row is written in the caller's transaction and the job is dispatched after it
 *   commits: nothing is sent for a change that was rolled back, and a failed email never
 *   undoes the business change.
 */
class CustomerNotifier
{
    /** type => history category */
    public const TYPES = [
        'quotation_sent' => 'quotation',
        // quotation_accepted and sales_order_created are no longer sent (removed 2026-11-09); kept
        // so earlier emails of these types still show in the email history.
        'quotation_accepted' => 'quotation',
        'order_completed' => 'sales_order',
        'sales_order_created' => 'sales_order',
        'order_confirmed' => 'sales_order',
        'order_cancelled' => 'sales_order',
        'payment_submitted' => 'payment',
        'payment_rejected' => 'payment',
        'payment_verified' => 'receipt',
        'invoice_issued' => 'invoice',
        'delivery_status' => 'delivery',
    ];

    /**
     * Automatic emails (e.g. delivery status): logged now, queued, sent after the change commits.
     *
     * @param  array<string, mixed>  $context
     */
    public function notify(string $type, Model $record, string $key, array $context = []): ?NotificationLog
    {
        $log = $this->record($type, $record, $key, $context);
        if ($log) {
            $this->send($log);
        }

        return $log;
    }

    /**
     * Send a logged automatic email once the current transaction has committed.
     *
     * In a web request it is sent by that same request right after its response ("after
     * response"), so customers get their email even when no queue worker is running (a plain
     * `php artisan serve`). Console code (artisan commands, queue workers, tests) uses the queue.
     * CUSTOMER_EMAILS_SEND=queue always uses the queue (servers that run `queue:work`), and
     * =after_response always sends in-process.
     */
    private function send(NotificationLog $log): void
    {
        $mode = (string) config('services.customer_emails.send', 'auto');
        $afterResponse = $mode === 'after_response' || ($mode === 'auto' && ! app()->runningInConsole());

        if (! $afterResponse) {
            SendCustomerNotification::dispatch($log->id)->afterCommit();

            return;
        }
        DB::afterCommit(fn () => SendCustomerNotification::dispatchAfterResponse($log->id));
    }

    /**
     * Staff-triggered emails (Send Quotation, Send Commercial Invoice): send the logged email now,
     * in this request, so staff see whether it worked. Call it only after the business change has
     * committed, so a sent email is never followed by a rollback. Never retried automatically:
     * a failure is recorded and staff can send again.
     */
    public function deliverNow(NotificationLog $log): NotificationLog
    {
        app()->call([new SendCustomerNotification($log->id), 'handle']);

        return $log->fresh();
    }

    /**
     * Log one email for one event without sending it. Null when the key was already used.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $type, Model $record, string $key, array $context = []): ?NotificationLog
    {
        try {
            // A savepoint, so a duplicate key does not abort the caller's transaction.
            $log = DB::transaction(fn () => NotificationLog::create([
                'team_id' => $record->getAttribute('team_id'),
                'category' => self::TYPES[$type] ?? 'other',
                'type' => $type,
                'notifiable_type' => class_basename($record),
                'notifiable_id' => $record->getKey(),
                'status' => EmailStatus::Pending,
                'idempotency_key' => $key,
                'context' => $context ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // this event was already emailed (or is being emailed)
        }

        return $log;
    }

    /** Send a failed email again (same event, same log entry). */
    public function retry(NotificationLog $log): void
    {
        if ($log->status === EmailStatus::Sent || $log->status === EmailStatus::Delivered) {
            return;
        }
        $log->update(['status' => EmailStatus::Pending, 'error' => null]);
        $this->send($log);
    }
}
