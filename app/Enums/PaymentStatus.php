<?php

namespace App\Enums;

/** A Sales Order's payment status, worked out from its payment records. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PaymentSubmitted = 'payment_submitted';
    case UnderVerification = 'under_verification';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PaymentSubmitted => 'Payment Submitted',
            self::UnderVerification => 'Under Verification',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Rejected => 'Payment Rejected',
            self::Refunded => 'Refunded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Unpaid, self::Rejected => 'bg-rose-50 text-rose-700 border-rose-200',
            self::PaymentSubmitted, self::UnderVerification => 'bg-amber-50 text-amber-700 border-amber-200',
            self::PartiallyPaid => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Paid => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Refunded => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }
}
