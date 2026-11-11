<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app (database) notification for a procurement workflow step: a request submitted or
 * reviewed, an order sent, goods received or inspected, an invoice needing verification, stock posted.
 */
class ProcurementActivity extends Notification
{
    public function __construct(
        public string $event,
        public string $title,
        public string $message,
        public ?string $url = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
