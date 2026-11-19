<?php

use App\Enums\ForecastPeriod;
use App\Models\Sale;
use App\Models\Team;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Capstone/helpers.php';

beforeEach(function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-11-17 10:00:00');
    $this->team = Team::factory()->create();
    $this->product = capProduct($this->team, [], '2020-01-01');
    $this->forecaster = app(SmaForecaster::class);
});

afterEach(fn () => Carbon::setTestNow());

function periodSale($test, string $date, int $quantity, int $cancelled = 0): Sale
{
    $sale = Sale::create(['team_id' => $test->team->id, 'order_number' => 'PERIOD-'.uniqid(), 'client_name' => 'Forecast test', 'sale_date' => $date, 'status' => 'completed']);
    $sale->items()->create(['product_id' => $test->product->id, 'sku' => $test->product->sku, 'product_name' => $test->product->name, 'quantity' => $quantity, 'cancelled_quantity' => $cancelled, 'unit_price' => 1, 'subtotal' => $quantity]);

    return $sale;
}

it('groups weekly sales across years with Monday boundaries and excludes the incomplete week', function () {
    Carbon::setTestNow('2026-01-20');
    periodSale($this, '2025-12-28 23:59:59', 10);
    periodSale($this, '2025-12-29 00:00:00', 20);
    periodSale($this, '2026-01-04 23:59:59', 30);
    periodSale($this, '2026-01-05', 40);
    periodSale($this, '2026-01-19', 999);
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Weekly, 4, 2, 3)[$this->product->id];
    expect(array_column($result['historical'], 'actual'))->toBe([10, 50, 40, 0])
        ->and($result['forecast'])->toEqual(30)
        ->and($result['future'][0])->toMatchArray(['start' => '2026-01-26', 'end' => '2026-02-01'])
        ->and($result['future'][1]['start'])->toBe('2026-02-02');
});

it('uses calendar months including leap day and counts linked sales only once net of cancellations', function () {
    Carbon::setTestNow('2024-04-15');
    periodSale($this, '2024-01-31 23:59:59', 12);
    $sale = periodSale($this, '2024-02-29 23:59:59', 30, 6);
    capDemand($this->team, $this->product, '2024-03-05', 24)->update(['sale_id' => $sale->id]);
    periodSale($this, '2024-03-01', 36);
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly, 3, 2, 3)[$this->product->id];
    expect(array_column($result['historical'], 'actual'))->toBe([12, 24, 36])
        ->and($result['forecast'])->toEqual(24)
        ->and($result['historical'][1]['end'])->toBe('2024-02-29')
        ->and($result['future'][1]['end'])->toBe('2024-06-30')
        ->and($result['accuracy']['mape'])->toBeNull();
});

it('groups calendar years and refuses a twelve year window without twelve complete years', function () {
    periodSale($this, '2023-12-31 23:59:59', 30);
    periodSale($this, '2024-02-29', 60);
    periodSale($this, '2025-12-31', 90);
    periodSale($this, '2026-01-01', 999);
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Annual, 3, 2, 3)[$this->product->id];
    expect(array_column($result['historical'], 'actual'))->toBe([30, 60, 90])
        ->and($result['forecast'])->toEqual(60)
        ->and($result['future'][0]['start'])->toBe('2027-01-01')
        ->and($result['future'][1]['end'])->toBe('2028-12-31');
    $insufficient = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Annual, 12, 1, 12)[$this->product->id];
    expect($insufficient['available'])->toBeFalse()->and($insufficient['forecast'])->toBeNull();
});

it('distinguishes missing history from observed zero demand and excludes partial opening periods', function () {
    $empty = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly)[$this->product->id];
    expect($empty['available'])->toBeFalse()->and($empty['historical'])->toBeEmpty();
    $this->product->forceFill(['created_at' => '2026-08-15'])->saveQuietly();
    capDemand($this->team, $this->product, '2026-08-15', 10, 'stock_in');
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly)[$this->product->id];
    expect($result['history_periods'])->toBe(2)->and($result['available'])->toBeFalse();
    Carbon::setTestNow('2026-12-01');
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly)[$this->product->id];
    expect($result['forecast'])->toEqual(0)->and($result['accuracy']['mape'])->toBeNull();
});

it('calculates exact error metrics without hiding zero actuals or clamping MAPE', function () {
    $metrics = $this->forecaster->errorMetrics([
        ['actual' => 0, 'forecast' => 30], ['actual' => 10, 'forecast' => 30], ['actual' => 20, 'forecast' => 30],
    ]);
    expect($metrics['mae'])->toEqual(20)->and($metrics['mape'])->toEqual(125)
        ->and($metrics['rmse'])->toEqual(sqrt(1400 / 3))->and($metrics['mape_count'])->toBe(2)
        ->and($metrics['zero_actual_count'])->toBe(1)->and($metrics['accuracy_indicator'])->toEqual(0)
        ->and($metrics['target_met'])->toBeFalse();
});

it('selects a window on earlier validation and evaluates a separate chronological holdout', function () {
    $points = [];
    foreach ([10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10] as $i => $actual) {
        $date = Carbon::parse('2025-01-01')->addMonths($i);
        $points[] = ['start' => $date->toDateString(), 'actual' => $actual];
    }
    $before = $this->forecaster->evaluatePeriods($points);
    expect($before['best_window'])->toBe(3)->and($before['configurations'][12]['validation']['count'])->toBe(4)
        ->and($before['accuracy']['count'])->toBe(2)->and($before['accuracy']['mape'])->toEqual(0);
    $points[16]['actual'] = 100;
    $points[17]['actual'] = 200;
    $after = $this->forecaster->evaluatePeriods($points);
    expect($after['best_window'])->toBe(3)->and($after['configurations'][3]['validation'])->toBe($before['configurations'][3]['validation'])
        ->and($after['accuracy']['comparisons'][0]['forecast'])->toEqual(10)
        ->and($after['accuracy']['comparisons'][1]['forecast'])->toEqual(40)
        ->and($after['accuracy']['mape'])->toEqual(85);
});

it('paginates before calculating and validates filters and team isolation', function () {
    [$user, $cookies] = capActor('admin', $this->team);
    for ($i = 0; $i < 26; $i++) {
        capProduct($this->team);
    }
    $this->actingAs($user)->withCookies($cookies)->get('/'.$this->team->slug.'/forecast?period=weekly&page=2')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('periodPlanning.products.data', 2)->where('periodPlanning.products.total', 27)->where('periodPlanning.options.period', 'weekly'));
    $foreign = capProduct(Team::factory()->create());
    $this->get('/'.$this->team->slug.'/forecast?period=daily&horizon=100')
        ->assertRedirect()->assertSessionHasErrors(['period', 'horizon']);
    $this->get('/'.$this->team->slug.'/forecast?period=monthly&product_id='.$foreign->id)->assertRedirect()->assertSessionHasErrors('product_id');
});

it('selects six periods when it outperforms three and ties twelve on shared validation dates', function () {
    $points = [];
    for ($i = 0; $i < 30; $i++) {
        $points[] = ['start' => Carbon::parse('2024-01-01')->addMonths($i)->toDateString(), 'actual' => $i % 6 < 3 ? 0 : 30];
    }
    $result = $this->forecaster->evaluatePeriods($points);
    expect($result['best_window'])->toBe(6)
        ->and($result['configurations'][6]['validation']['mape'])->toEqual(50)
        ->and($result['configurations'][3]['validation']['mape'])->toBeGreaterThan(50)
        ->and($result['configurations'][12]['validation']['mape'])->toEqual(50)
        ->and($result['accuracy']['mape'])->toEqual(50);
});

it('retains all three existing chart metrics for each selected period', function () {
    periodSale($this, '2026-08-01', 12);
    periodSale($this, '2026-09-01', 24);
    periodSale($this, '2026-10-01', 36);
    $analyses = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly, 3, 2, 3);
    $dashboard = $this->forecaster->periodAnalytics($this->team->id, $analyses, ForecastPeriod::Monthly, ['window' => 3, 'horizon' => 2]);
    expect($dashboard['metricSeries']['sales_transactions']['actual'])->toBe([1, 1, 1, null, null, null])
        ->and($dashboard['metricSeries']['products_involved']['actual'])->toBe([1, 1, 1, null, null, null])
        ->and($dashboard['metricSeries']['units_sold']['actual'])->toBe([12, 24, 36, null, null, null])
        ->and($dashboard['metricSeries']['units_sold']['next_month_projection'])->toEqual([null, null, null, null, 24, 24])
        ->and($dashboard['metricSeries']['units_sold']['partial_actual'])->toBe([null, null, null, 0, null, null]);   // the running month is plotted, not modelled
});

it('keeps date display filters separate from evaluation and exports the same results', function () {
    [$user, $cookies] = capActor('admin', $this->team);
    periodSale($this, '2026-08-01', 12);
    periodSale($this, '2026-09-01', 24);
    periodSale($this, '2026-10-01', 36);
    $query = '?period=monthly&window=3&history_periods=3&horizon=2&from_date=2026-10-01&to_date=2026-10-31';
    $this->actingAs($user)->withCookies($cookies)->get('/'.$this->team->slug.'/forecast'.$query)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('periodPlanning.products.data.0.analysis.forecast', 24)->has('periodPlanning.products.data.0.analysis.historical', 3));
    $csv = $this->get('/'.$this->team->slug.'/forecast/export-csv'.$query)->assertOk()->streamedContent();
    expect($csv)->toContain('2026-12-01', '2027-01-31', 'MAPE (%)')->not->toContain('2026-08-01');
});

it('shares automatic monthly demand with AHP without changing the replenishment horizon', function () {
    for ($i = 0; $i < 18; $i++) {
        periodSale($this, Carbon::parse('2025-05-01')->addMonths($i)->toDateString(), 10);
    }
    $expected = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly)[$this->product->id];
    $priority = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id];
    expect($priority['forecast']['selected_window'])->toBe($expected['selected_window'])
        ->and($priority['forecast']['monthly'])->toBe((int) round($expected['forecast']))
        ->and($priority['forecast']['period_total'])->toBe((int) round($expected['total_forecast']))
        ->and($priority['forecast']['months'])->toBe(3)
        ->and($priority['forecast']['accuracy']['mape'])->toEqual($expected['accuracy']['mape']);
});

it('reports WAPE alongside MAPE, defined even when some actuals are zero', function () {
    $m = $this->forecaster->errorMetrics([['actual' => 0, 'forecast' => 2], ['actual' => 10, 'forecast' => 8], ['actual' => 0, 'forecast' => 0]]);
    expect($m['mape'])->toEqual(20)                // only the single non-zero actual counts
        ->and($m['wape'])->toEqual(40)             // (2 + 2 + 0) / 10
        ->and($m['total_actual'])->toEqual(10);
    expect($this->forecaster->errorMetrics([['actual' => 0, 'forecast' => 3]])['wape'])->toBeNull();
});

it('flags intermittent demand and keeps the running period out of the model', function () {
    expect($this->forecaster->demandPattern([0, 0, 4, 0, 0, 0])['intermittent'])->toBeTrue()
        ->and($this->forecaster->demandPattern([5, 6, 4, 7, 0, 5])['label'])->toBe('Regular')
        ->and($this->forecaster->demandPattern([1, 2])['label'])->toBe('Insufficient history');

    Carbon::setTestNow('2026-11-17');
    periodSale($this, '2026-08-10', 10);
    periodSale($this, '2026-09-10', 10);
    periodSale($this, '2026-10-10', 10);
    periodSale($this, '2026-11-05', 500);          // current month, still running
    $result = $this->forecaster->periodProducts($this->team->id, [$this->product->id], ForecastPeriod::Monthly, 3, 2, 3)[$this->product->id];
    expect($result['forecast'])->toEqual(10)
        ->and($result['in_progress']['actual'])->toBe(500)
        ->and($result['in_progress']['start'])->toBe('2026-11-01')
        ->and(array_column($result['historical'], 'actual'))->toBe([10, 10, 10]);
});
