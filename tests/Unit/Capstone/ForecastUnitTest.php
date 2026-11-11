<?php

/*
 * 4.4.1 Unit Testing — Demand Forecasting (UT-80 to UT-88).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 *
 * Simple Moving Average: forecast of a month = average demand of the `window` months before it.
 * "Today" is fixed at 2026-10-15 so the months are the same on every run.
 */

use App\Models\Sale;
use App\Models\Team;
use App\Services\Forecast\SmaForecaster;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create();
    $this->product = capProduct($this->team, ['stock' => 50]);
    $this->forecaster = app(SmaForecaster::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Monthly series of the product from $from to $to. */
function capSeries($t, string $from, string $to, int $window = 3, ?int $productId = null): array
{
    return $t->forecaster->series($t->team->id, Carbon::parse($from), Carbon::parse($to), $window, 'All', $productId ?? $t->product->id);
}

/** A sales order with one line, in the given status. */
function capSaleOf($t, string $date, int $quantity, string $status): Sale
{
    $sale = Sale::create(['team_id' => $t->team->id, 'order_number' => 'SO-CAP-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'sale_date' => $date,
        'subtotal' => $quantity * 100, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => $quantity * 100, 'status' => $status]);
    $sale->items()->create(['product_id' => $t->product->id, 'sku' => $t->product->sku, 'product_name' => $t->product->name,
        'quantity' => $quantity, 'unit_price' => 100, 'discount' => 0, 'subtotal' => $quantity * 100]);

    return $sale;
}

it('UT-80 forecasts a month as the plain average of the previous three months', function () {
    capDemand($this->team, $this->product, '2026-07-10', 10);
    capDemand($this->team, $this->product, '2026-08-10', 20);
    capDemand($this->team, $this->product, '2026-09-10', 30);

    $series = capSeries($this, '2026-07-01', '2026-10-15');

    // (10 + 20 + 30) / 3 = 20
    expect($series['labels'])->toBe(['Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'])
        ->and($series['historical'])->toBe([10, 20, 30, 0])
        ->and($series['forecast'])->toBe([null, null, null, 20])
        ->and($series['steps'][0])->toMatchArray(['month' => 'Oct 2026', 'sum' => 60, 'window' => 3, 'forecast' => 20])
        ->and($series['sourceRecordCount'])->toBe(3);
});

it('UT-81 rounds the average to the nearest whole unit', function () {
    $forecastOf = function (array $monthly): ?int {
        $product = capProduct($this->team);
        foreach (array_combine(['2026-07-10', '2026-08-10', '2026-09-10'], $monthly) as $date => $qty) {
            if ($qty > 0) {
                capDemand($this->team, $product, $date, $qty);
            }
        }

        return capSeries($this, '2026-07-01', '2026-10-15', 3, $product->id)['forecast'][3];
    };

    expect($forecastOf([10, 10, 11]))->toBe(10)   // 10.33
        ->and($forecastOf([1, 2, 2]))->toBe(2)    // 1.67
        ->and($forecastOf([1, 1, 2]))->toBe(1)    // 1.33
        ->and($forecastOf([0, 0, 1]))->toBe(0)    // 0.33
        ->and($forecastOf([1, 2, 0]))->toBe(1);   // 1.00
});

it('UT-82 counts months without demand as zero', function () {
    capDemand($this->team, $this->product, '2026-07-10', 30); // nothing in August or September

    $series = capSeries($this, '2026-07-01', '2026-11-30');

    // Oct: (30 + 0 + 0) / 3 = 10; Nov: (0 + 0 + 0) / 3 = 0.
    expect($series['historical'])->toBe([30, 0, 0, 0, 0])
        ->and($series['forecast'])->toBe([null, null, null, 10, 0])
        ->and($series['hasData'])->toBeTrue();
});

it('UT-83 gives no forecast for a product with no demand history', function () {
    $series = capSeries($this, '2026-01-01', '2026-10-15');
    $perProduct = $this->forecaster->forProducts($this->team->id, [$this->product->id])[$this->product->id];

    expect($series['hasData'])->toBeFalse()
        ->and(array_sum($series['historical']))->toBe(0)
        ->and($series['sourceRecordCount'])->toBe(0)
        ->and($perProduct['available'])->toBeFalse()
        ->and($perProduct['forecast'])->toBeNull()             // not zero: there is no forecast
        ->and($perProduct['period_forecast'])->toBeNull()
        ->and($perProduct['reason'])->toBe('No sales or outgoing stock records exist for this product.');
});

it('UT-84 accepts only the 3, 6 and 12 month forecasting periods', function () {
    expect(SmaForecaster::window(3))->toBe(3)
        ->and(SmaForecaster::window(6))->toBe(6)
        ->and(SmaForecaster::window(12))->toBe(12)
        ->and(SmaForecaster::window('6'))->toBe(6)
        ->and(SmaForecaster::window(5))->toBe(3)      // not a listed period: the default
        ->and(SmaForecaster::window(0))->toBe(3)
        ->and(SmaForecaster::window(-3))->toBe(3)
        ->and(SmaForecaster::window(13))->toBe(3)
        ->and(SmaForecaster::window(null))->toBe(3)
        ->and(SmaForecaster::window('abc'))->toBe(3);
});

it('UT-85 produces a forecast only once a full window of months precedes it', function () {
    foreach (['2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
        capDemand($this->team, $this->product, $date, 12);
    }

    expect(capSeries($this, '2026-08-01', '2026-10-15')['forecast'])->toBe([null, null, null])        // exactly 3 months: none
        ->and(capSeries($this, '2026-07-01', '2026-10-15')['forecast'])->toBe([null, null, null, 12])  // 4 months: one
        ->and(capSeries($this, '2026-10-01', '2026-10-15')['forecast'])->toBe([null])                  // a single month
        ->and(capSeries($this, '2026-07-01', '2026-10-15', 6)['forecast'])->toBe([null, null, null, null]); // 6-month window, 4 months
});

it('UT-86 takes monthly demand as the larger of stock movements and sales orders, and ignores unconfirmed orders', function () {
    capDemand($this->team, $this->product, '2026-09-05', 10);
    capDemand($this->team, $this->product, '2026-09-20', 5, 'sale_release'); // movements: 15
    capDemand($this->team, $this->product, '2026-09-21', 99, 'stock_in');    // not a demand movement
    capSaleOf($this, '2026-09-12', 25, 'confirmed');                         // sales orders: 25
    capSaleOf($this, '2026-09-13', 40, 'pending');                           // not counted
    capSaleOf($this, '2026-09-14', 40, 'cancelled');                         // not counted

    $series = capSeries($this, '2026-09-01', '2026-09-30');

    expect($series['historical'])->toBe([25]) // max(15, 25), not 15 + 25
        ->and($series['sourceRecordCount'])->toBe(3);
});

it('UT-87 forecasts each product for the next period and multiplies by the months of the period', function () {
    foreach (['2026-04-05' => 20, '2026-05-05' => 20, '2026-06-05' => 20, '2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
        capDemand($this->team, $this->product, $date, $qty);
    }
    $new = capProduct($this->team, [], '2026-09-01'); // added six weeks ago
    capDemand($this->team, $new, '2026-09-20', 9);

    $three = $this->forecaster->forProducts($this->team->id, [$this->product->id, $new->id], 3);
    $six = $this->forecaster->forProducts($this->team->id, [$this->product->id], 6)[$this->product->id];

    // 3 months: (40 + 50 + 60) / 3 = 50 a month, 150 for the period. 6 months: 210 / 6 = 35 a month, 210.
    expect($three[$this->product->id])->toMatchArray(['available' => true, 'forecast' => 50, 'period_forecast' => 150, 'window' => 3])
        ->and($six)->toMatchArray(['available' => true, 'forecast' => 35, 'period_forecast' => 210, 'window' => 6])
        ->and($three[$this->product->id]['movement_units'])->toBe(210)
        // The current, unfinished month is never an input.
        ->and(array_column($three[$this->product->id]['inputs'], 'month'))->toBe(['Jul 2026', 'Aug 2026', 'Sep 2026'])
        // Less than three full months of history: no forecast, with the reason.
        ->and($three[$new->id]['available'])->toBeFalse()
        ->and($three[$new->id]['forecast'])->toBeNull()
        ->and($three[$new->id]['reason'])->toContain('fewer than 3 full months');
});

it('UT-88 keeps the demand of other products and other teams out of a forecast', function () {
    $other = capProduct($this->team);
    $otherTeam = Team::factory()->create();
    $foreign = capProduct($otherTeam);
    capDemand($this->team, $this->product, '2026-09-10', 7);
    capDemand($this->team, $other, '2026-09-10', 100);
    capDemand($otherTeam, $foreign, '2026-09-10', 1000);

    $all = $this->forecaster->series($this->team->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 3);

    expect(capSeries($this, '2026-09-01', '2026-09-30')['historical'])->toBe([7])
        ->and($all['historical'])->toBe([107]) // the whole team, without the other team's 1,000
        ->and($this->forecaster->series($otherTeam->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 3)['historical'])->toBe([1000]);
});
