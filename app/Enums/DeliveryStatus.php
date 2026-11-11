<?php

namespace App\Enums;

/**
 * Customer delivery status of a Sales Order, separate from the order and payment status.
 *
 * Pending → Ready for Delivery → In Transit → Delivered, or Cancelled.
 *
 * - Pending: awaiting (or under) preparation. A partial receipt or goods returned to the warehouse
 *   go back to Pending until everything is received.
 * - Ready for Delivery: prepared and ready to dispatch. After a failed delivery attempt the goods
 *   stay on the truck and the delivery is Ready for Delivery again (the attempt is recorded in
 *   the history); it can be dispatched again up to the attempt limit.
 * - In Transit: on the way to the customer. Pickup orders skip it.
 * - Delivered: the customer has received everything.
 * - Cancelled: delivery stopped.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case ReadyForDelivery = 'ready_for_delivery';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::ReadyForDelivery => 'Ready for Delivery',
            self::InTransit => 'In Transit',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses this one may move to. Staying on the same status (e.g. the next release starting
     * while Pending) is always allowed.
     *
     * @return list<self>
     */
    public function allowedNext(bool $pickup = false): array
    {
        return match ($this) {
            self::Pending => [self::ReadyForDelivery, self::Delivered, self::Cancelled],
            self::ReadyForDelivery => $pickup
                ? [self::Delivered, self::Pending, self::Cancelled]
                : [self::InTransit, self::Pending, self::Cancelled],
            self::InTransit => [self::Delivered, self::Pending, self::ReadyForDelivery],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next, bool $pickup = false): bool
    {
        return $next === $this || in_array($next, $this->allowedNext($pickup), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-700 border-amber-200',
            self::ReadyForDelivery, self::InTransit => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Delivered => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Cancelled => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }
}
