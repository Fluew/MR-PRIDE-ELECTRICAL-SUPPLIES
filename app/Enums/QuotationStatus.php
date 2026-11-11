<?php

namespace App\Enums;

/**
 * Quotation lifecycle: Draft → Sent → Accepted (or Rejected / Expired).
 *
 * - Draft: being prepared. Inside Draft, staff submit it for approval
 *   (submitted_for_approval_at) and an administrator approves it (approved_at/approved_by);
 *   approving sends it. These are steps, not statuses.
 * - Sent: emailed to the customer.
 * - Accepted: the customer approved it; this automatically creates the Sales Order
 *   (converted_to_sale_id). It stays Accepted after the order exists.
 * - Rejected: the customer (or we) declined it.
 * - Expired: its validity period passed before it was accepted.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-700 border-gray-200',
            self::Sent => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Accepted => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Rejected => 'bg-rose-50 text-rose-700 border-rose-200',
            self::Expired => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }

    /**
     * Statuses this one may move to through any action.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Rejected, self::Expired],
            self::Sent => [self::Sent, self::Accepted, self::Rejected, self::Expired, self::Draft],
            self::Accepted => [],
            self::Rejected, self::Expired => [self::Draft],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Statuses staff can pick with the general "change status" action: back to Draft (revise) and
     * Rejected. Sending (after approval), the customer's answer and expiry have their own actions.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Draft, self::Rejected];
    }

    /** Prices and lines can be changed. Sent quotations must be revised (back to Draft) first. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Finished: nothing more happens to its status, and it never expires. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Expired], true);
    }

    /** An accepted quotation becomes a Sales Order (automatically, or with the fallback button). */
    public function canConvertToSale(): bool
    {
        return $this === self::Accepted;
    }
}
