<?php

namespace App\Enums;

/**
 * Email sending status. "sent" means the email service accepted the message. "delivered" is only
 * set when the provider confirms delivery (not connected yet), never assumed.
 */
enum EmailStatus: string
{
    case NotSent = 'not_sent';
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Not sent',
            self::Pending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'No email address',
        };
    }
}
