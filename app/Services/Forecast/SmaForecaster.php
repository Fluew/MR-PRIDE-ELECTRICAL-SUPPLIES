<?php

namespace App\Services\Forecast;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Demand Forecasting module's Simple Moving Average: the single source of forecasted demand
 * (the Forecast page and AHP Product Prioritization both read it).
 *
 * Monthly demand = the larger of (outgoing stock movements) and (units on confirmed, processing or
 * completed Sales Orders) for that month. Forecast for month i = the plain average of the
 * `window` months before it; the forecasting period (3 Months, 6 Months, 1 Year) is that window,
 * and the demand expected over the period is the SMA × its months. Months without records count
 * as 0; a product with no demand
 * record at all has no forecast (insufficient data), nothing is invented.
 */
class SmaForecaster
{
    public const WINDOWS = [3, 6, 12];

    public const DEFAULT_WINDOW = 3;

    /** How staff see the forecasting periods: the months averaged and the months forecast ahead. */
    public const PERIOD_LABELS = [3 => '3 Months', 6 => '6 Months', 12 => '1 Year'];

    public const DEMAND_MOVEMENT_TYPES = ['sale', 'sale_release', 'out'];

    public static function window(mixed $value): int
    {
        $value = (int) $value;

        return in_array($value, self::WINDOWS, true) ? $value : self::DEFAULT_WINDOW;
    }

    /**
     * Demand series and SMA for the Forecast page (a product, a category, or everything).
     *
     * @return array{labels: list<string>, months: list<string>, historical: list<int>, forecast: list<int|null>, hasData: bool, steps: list<array>, sourceRecords: list<array>, sourceRecordCount: int}
     */
    public function series(int $teamId, CarbonInterface $from, CarbonInterface $to, int $window, string $category = 'All', ?int $productId = null): array
    {
        $from = Carbon::instance($from);
        $to = Carbon::instance($to);
        $periods = $this->months($from, $to);
        $scope = fn (Builder $q, string $productColumn) => $productId
            ? $q->where($productColumn, $productId)
            : ($category !== 'All' && $category !== '' ? $q->whereHas('product', fn ($p) => $p->where('category', $category)) : $q);

        $records = $this->records($teamId, $periods[0]->copy()->startOfMonth(), end($periods)->copy()->endOfMonth(), $scope);
        $byMonth = $this->totalsByMonth($records);

        $historical = [];
        foreach ($periods as $p) {
            $t = $byMonth[$p->format('Y-m')] ?? ['movements' => 0, 'sales' => 0];
            $historical[] = max($t['movements'], $t['sales']);
        }

        $forecast = array_fill(0, count($periods), null);
        $steps = [];
        for ($i = $window; $i < count($periods); $i++) {
            $slice = array_slice($historical, $i - $window, $window);
            $forecast[$i] = (int) round(array_sum($slice) / $window);
            $steps[] = [
                'month' => $periods[$i]->format('M Y'),
                'inputs' => array_map(fn ($k) => ['month' => $periods[$k]->format('M Y'), 'units' => $historical[$k]], range($i - $window, $i - 1)),
                'sum' => array_sum($slice),
                'window' => $window,
                'forecast' => $forecast[$i],
            ];
        }

        usort($records, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return [
            'labels' => array_map(fn ($d) => $d->format('M Y'), $periods),
            'months' => array_map(fn ($d) => $d->format('Y-m'), $periods),
            'historical' => $historical,
            'forecast' => $forecast,
            'hasData' => $records !== [],
            'steps' => $steps,
            'sourceRecords' => array_slice($records, 0, 200),
            'sourceRecordCount' => count($records),
        ];
    }

    /**
     * Next-month forecast per product, from the `window` complete months before the current one.
     * A product is insufficient when it has no demand record ever, or did not exist for the whole window.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{available: bool, forecast: int|null, window: int, inputs: list<array>, reason: string|null, movement_units: int, movement_months: int, movement_record_count: int}>
     */
    public function forProducts(int $teamId, array $productIds, int $window = self::DEFAULT_WINDOW, int $movementMonths = 12, ?CarbonInterface $today = null): array
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();
        $windowStart = $today->copy()->startOfMonth()->subMonths($window);
        $movementStart = $today->copy()->startOfMonth()->subMonths($movementMonths);
        $lastComplete = $today->copy()->startOfMonth()->subDay()->endOfDay();
        $earliest = $windowStart->copy()->min($movementStart);

        $records = $this->records($teamId, $earliest, $lastComplete, fn (Builder $q, string $col) => $q->whereIn($col, $productIds));
        $everHad = $this->productsWithAnyDemand($teamId, $productIds);

        $byProduct = [];
        foreach ($records as $r) {
            $byProduct[$r['product_id']][] = $r;
        }

        $products = Product::whereIn('id', $productIds)->get(['id', 'created_at'])->keyBy('id');
        $result = [];

        foreach ($productIds as $id) {
            $totals = $this->totalsByMonth($byProduct[$id] ?? []);
            $monthUnits = fn (Carbon $m) => max($totals[$m->format('Y-m')]['movements'] ?? 0, $totals[$m->format('Y-m')]['sales'] ?? 0);

            $inputs = [];
            for ($m = $windowStart->copy(); $m->lt($today->copy()->startOfMonth()); $m->addMonth()) {
                $inputs[] = ['month' => $m->format('M Y'), 'units' => $monthUnits($m)];
            }
            $movementUnits = 0;
            for ($m = $movementStart->copy(); $m->lt($today->copy()->startOfMonth()); $m->addMonth()) {
                $movementUnits += $monthUnits($m);
            }

            // History starts at whichever is earlier: the product being added or its first demand record.
            $historyStart = isset($everHad[$id]) ? Carbon::parse($everHad[$id]) : null;
            $created = $products[$id]?->created_at;
            if ($historyStart && $created && $created->lt($historyStart)) {
                $historyStart = $created->copy();
            }
            $reason = null;
            if (! $historyStart) {
                $reason = 'No sales or outgoing stock records exist for this product.';
            } elseif ($historyStart->gt($windowStart)) {
                $reason = "History starts {$historyStart->format('M j, Y')}: fewer than {$window} full months before this month.";
            }

            $average = array_sum(array_column($inputs, 'units')) / $window;
            $result[$id] = [
                'available' => $reason === null,
                'has_records' => isset($everHad[$id]),
                // Monthly SMA, and the demand expected over the next `window` months (SMA × months).
                'forecast' => $reason === null ? (int) round($average) : null,
                'period_forecast' => $reason === null ? (int) round($average * $window) : null,
                'window' => $window,
                'inputs' => $inputs,
                'reason' => $reason,
                'movement_units' => $movementUnits,
                'movement_months' => $movementMonths,
                'movement_record_count' => count(array_filter($byProduct[$id] ?? [], fn ($r) => $r['date'] >= $movementStart->toDateString())),
            ];
        }

        return $result;
    }

    /** @return list<Carbon> */
    private function months(Carbon $from, Carbon $to): array
    {
        $periods = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to->copy()->startOfMonth()); $c->addMonth()) {
            $periods[] = $c->copy();
        }

        return $periods;
    }

    /**
     * Every source record in the range, so each forecast can be traced back.
     *
     * @return list<array{source: string, product_id: int|null, date: string, reference: string|null, units: int}>
     */
    private function records(int $teamId, Carbon $from, Carbon $to, callable $scope): array
    {
        $movements = StockMovement::where('team_id', $teamId)
            ->whereIn('type', self::DEMAND_MOVEMENT_TYPES)
            ->whereBetween('movement_date', [$from, $to]);
        $scope($movements, 'product_id');

        $rows = $movements->get(['id', 'product_id', 'type', 'quantity', 'movement_date', 'reference_number'])
            ->map(fn ($m) => [
                'source' => 'Stock movement ('.$m->type.')',
                'product_id' => $m->product_id,
                'date' => Carbon::parse($m->movement_date)->toDateString(),
                'reference' => $m->reference_number ?: "Movement #{$m->id}",
                'units' => abs((int) $m->quantity),
            ])->all();

        $sales = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.team_id', $teamId)
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereBetween('sales.sale_date', [$from, $to]);
        $scope($sales, 'sale_items.product_id');

        foreach ($sales->get(['sale_items.product_id', 'sale_items.quantity', 'sales.sale_date', 'sales.order_number', 'sales.id as sale_id']) as $s) {
            $rows[] = [
                'source' => 'Sales Order item',
                'product_id' => $s->product_id,
                'date' => Carbon::parse($s->sale_date)->toDateString(),
                'reference' => $s->order_number ?: "Sale #{$s->sale_id}",
                'units' => (int) $s->quantity,
            ];
        }

        return $rows;
    }

    /** @return array<string, array{movements: int, sales: int}> */
    private function totalsByMonth(array $records): array
    {
        $totals = [];
        foreach ($records as $r) {
            $ym = substr($r['date'], 0, 7);
            $key = $r['source'] === 'Sales Order item' ? 'sales' : 'movements';
            $totals[$ym] ??= ['movements' => 0, 'sales' => 0];
            $totals[$ym][$key] += $r['units'];
        }

        return $totals;
    }

    /** Earliest demand record date per product that has any. @return array<int, string> */
    private function productsWithAnyDemand(int $teamId, array $productIds): array
    {
        $first = [];
        $add = function ($rows) use (&$first) {
            foreach ($rows as $id => $date) {
                $date = Carbon::parse($date)->toDateString();
                if ($id && (! isset($first[(int) $id]) || $date < $first[(int) $id])) {
                    $first[(int) $id] = $date;
                }
            }
        };

        $add(StockMovement::where('team_id', $teamId)->whereIn('type', self::DEMAND_MOVEMENT_TYPES)
            ->whereIn('product_id', $productIds)->groupBy('product_id')
            ->selectRaw('product_id, MIN(movement_date) as first_date')->pluck('first_date', 'product_id'));
        $add(SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')->where('sales.team_id', $teamId)
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereIn('sale_items.product_id', $productIds)->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as pid, MIN(sales.sale_date) as first_date')->pluck('first_date', 'pid'));

        return $first;
    }
}
