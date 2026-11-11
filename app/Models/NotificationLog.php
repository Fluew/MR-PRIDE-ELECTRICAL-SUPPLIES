<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One customer email for one business event. The idempotency key is unique, so retrying the
 * event never sends the same email twice.
 */
class NotificationLog extends Model
{
    protected $fillable = [
        'team_id', 'category', 'type', 'notifiable_type', 'notifiable_id', 'recipient', 'subject', 'status',
        'attempts', 'idempotency_key', 'provider_message_id', 'error', 'context', 'last_attempt_at', 'sent_at',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'attempts' => 'integer',
            'context' => 'array',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
