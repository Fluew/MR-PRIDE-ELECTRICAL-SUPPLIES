<?php

namespace App\Enums;

/**
 * Sales Order status. Separate from the payment status and the delivery status. "On hold" is a
 * flag on the order (hold_reason), not a status.
 */
enum SalesOrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-700 border-amber-200',
            self::Confirmed, self::Processing => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Completed => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Cancelled => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }

    /**
     * Orders that count as sales in reports: confirmed or further, not cancelled.
     *
     * @return list<string>
     */
    public static function countedInSales(): array
    {
        return [self::Confirmed->value, self::Processing->value, self::Completed->value];
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }
}
