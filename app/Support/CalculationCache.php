<?php

namespace App\Support;

use App\Models;
use App\Models\Team;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Caches heavy read-only calculations (product prioritization, supplier evaluation, SMA series)
 * per team, so pages reuse a result instead of recalculating it on every visit.
 *
 * Results stay correct:
 *  - Every key carries the team's data version. Saving or deleting any business record of the
 *    team (sales, stock movements, deliveries, purchase orders, biddings, products, suppliers, …)
 *    bumps the version, so the next request recalculates from the new records.
 *  - Every key also carries today's date (calculations look back from "today"), and entries expire
 *    after a few minutes as a safety net for writes that bypass the models.
 */
class CalculationCache
{
    public const TTL_SECONDS = 600;

    /** Records the calculations read: a change to any of them recalculates the team's results. */
    public const MODELS = [
        Models\Sale::class, Models\SaleItem::class, Models\StockMovement::class,
        Models\Delivery::class, Models\InboundDelivery::class, Models\DeliveryRelease::class, Models\DeliveryReleaseItem::class, Models\OrderFulfillment::class,
        Models\PurchaseOrder::class, Models\PurchaseRequest::class,
        Models\BiddingEvent::class, Models\BiddingSupplier::class, Models\SupplierBid::class,
        Models\Product::class, Models\Supplier::class, Models\ProductSupplier::class, Models\SupplierQuote::class,
        Models\SupplierProductCost::class, Models\SupplierSkuMapping::class,
        Models\Quotation::class, Models\QuotationItem::class,
    ];

    /** Parent relations used to find the team of a record that has no team_id of its own. */
    private const PARENTS = ['sale', 'biddingEvent', 'quotation', 'deliveryRelease', 'purchaseOrder'];

    public static function remember(int $teamId, string $name, array $args, Closure $calculate): mixed
    {
        $key = sprintf('calc:%s:%d:v%d:%s:%s', $name, $teamId, self::version($teamId), today()->toDateString(), md5(serialize($args)));

        return self::store()->remember($key, self::TTL_SECONDS, $calculate);
    }

    public static function flush(int $teamId): void
    {
        self::store()->forever("calc-version:{$teamId}", self::version($teamId) + 1);
    }

    /** Called when a record is saved or deleted. */
    public static function flushFor(Model $model): void
    {
        $teamId = $model->getAttribute('team_id');
        foreach (self::PARENTS as $relation) {
            if ($teamId || ! method_exists($model, $relation)) {
                continue;
            }
            $teamId = $model->{$relation}()->withoutGlobalScopes()->value('team_id');
        }
        // Team unknown: recalculate for every team rather than risk a stale result.
        foreach ($teamId ? [(int) $teamId] : Team::pluck('id')->all() as $id) {
            self::flush($id);
        }
    }

    public static function register(): void
    {
        foreach (self::MODELS as $class) {
            $class::saved(fn (Model $m) => self::flushFor($m));
            $class::deleted(fn (Model $m) => self::flushFor($m));
            if (method_exists($class, 'restored')) {
                $class::restored(fn (Model $m) => self::flushFor($m));
            }
        }
    }

    private static function version(int $teamId): int
    {
        return (int) self::store()->get("calc-version:{$teamId}", 0);
    }

    private static function store(): Repository
    {
        return Cache::store(config('cache.calculations') ?: config('cache.default'));
    }
}
