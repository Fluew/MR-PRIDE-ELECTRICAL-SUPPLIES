<?php

namespace App\Enums;

/** One payment submission. Only verified payments count towards the amount paid. */
enum PaymentRecordStatus: string
{
    case Submitted = 'submitted';
    case UnderVerification = 'under_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderVerification => 'Under Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Refunded => 'Refunded',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Submitted, self::UnderVerification], true);
    }
}
