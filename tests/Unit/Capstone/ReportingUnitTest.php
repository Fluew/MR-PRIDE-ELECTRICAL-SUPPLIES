<?php

/*
 * 4.4.1 Unit Testing — Reporting and Analytics (UT-89 to UT-98).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 *
 * "Today" is fixed at 2026-10-15.
 */

use App\Enums\QuotationStatus;
use App\Models\Sale;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'rpt-cap']);
    [$this->user, $this->cookies] = capActor('staff_reports', $this->team);
});

afterEach(fn () => Carbon::setTestNow());

/** The props the Reports page receives for a query string. */
function capReport($t, string $query = ''): array
{
    return $t->actingAs($t->user)->withCookies($t->cookies)->get('/rpt-cap/reports'.($query ? "?{$query}" : ''))
        ->assertOk()->viewData('page')['props'];
}

function capReportSale(Team $team, string $date, float $total, string $status = 'completed'): Sale
{
    return Sale::create(['team_id' => $team->id, 'order_number' => 'SO-RPT-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'sale_date' => $date,
        'subtotal' => $total, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => $total, 'status' => $status]);
}

it('UT-89 filters sales by a custom date range, including both end dates', function () {
    capReportSale($this->team, '2026-09-30 12:00:00', 100);
    capReportSale($this->team, '2026-10-01 00:00:00', 200); // first day of the range
    capReportSale($this->team, '2026-10-10 23:59:00', 400); // last day of the range
    capReportSale($this->team, '2026-10-11 08:00:00', 800);

    $props = capReport($this, 'date_preset=custom&from_date=2026-10-01&to_date=2026-10-10');

    expect($props['dateFilter'])->toBe(['preset' => 'custom', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-10'])
        ->and($props['metrics']['salesCount'])->toBe(2)
        ->and((float) $props['metrics']['totalRevenue'])->toBe(600.0)
        ->and(collect($props['quotationsSalesAnalytics']['salesList'])->pluck('grand_total')->sort()->values()->all())->toEqual([200.0, 400.0]);
});

it('UT-90 applies the date presets and falls back to all-time for an unknown one', function () {
    capReportSale($this->team, '2026-10-15 09:00:00', 100); // today
    capReportSale($this->team, '2026-10-09 09:00:00', 200); // 6 days ago: the last day of "last 7 days"
    capReportSale($this->team, '2026-10-08 09:00:00', 400); // 7 days ago
    capReportSale($this->team, '2025-12-31 09:00:00', 800); // last year

    $revenue = fn (string $query) => (float) capReport($this, $query)['metrics']['totalRevenue'];

    expect($revenue('date_preset=today'))->toBe(100.0)
        ->and($revenue('date_preset=last_7_days'))->toBe(300.0)
        ->and($revenue('date_preset=this_month'))->toBe(700.0)
        ->and($revenue('date_preset=this_year'))->toBe(700.0)
        ->and($revenue('date_preset=all_time'))->toBe(1500.0)
        ->and($revenue(''))->toBe(1500.0)
        ->and(capReport($this, 'date_preset=next_century')['dateFilter']['preset'])->toBe('all_time');
});

it('UT-91 returns no sales for a reversed date range instead of failing', function () {
    capReportSale($this->team, '2026-10-05 09:00:00', 100);

    $props = capReport($this, 'date_preset=custom&from_date=2026-10-10&to_date=2026-10-01');

    expect($props['metrics']['salesCount'])->toBe(0)->and((float) $props['metrics']['totalRevenue'])->toBe(0.0);
});

it('UT-92 handles an unreadable date in the report date range without a server error', function () {
    $response = $this->actingAs($this->user)->withCookies($this->cookies)->get('/rpt-cap/reports?date_preset=custom&from_date=not-a-date&to_date=2026-10-10');

    expect($response->status())->toBeLessThan(500);
});

it('UT-93 totals revenue from confirmed, processing and completed orders only', function () {
    capReportSale($this->team, '2026-10-01', 100, 'confirmed');
    capReportSale($this->team, '2026-10-02', 200, 'processing');
    capReportSale($this->team, '2026-10-03', 400, 'completed');
    capReportSale($this->team, '2026-10-04', 800, 'pending');
    capReportSale($this->team, '2026-10-05', 1600, 'cancelled');

    $props = capReport($this);

    expect($props['metrics']['salesCount'])->toBe(3)
        ->and((float) $props['metrics']['totalRevenue'])->toBe(700.0)
        ->and(array_sum($props['quotationsSalesAnalytics']['salesTrend']['data']))->toEqual(700.0);
});

it('UT-94 values inventory as stock times cost and counts stock levels', function () {
    capProduct($this->team, ['category' => 'Breakers', 'stock' => 10, 'cost_price' => 60, 'price' => 100, 'reorder_level' => 2]);  // 600.00, in stock
    capProduct($this->team, ['category' => 'Breakers', 'stock' => 2, 'cost_price' => 25, 'price' => 40, 'reorder_level' => 5]);    //  50.00, low
    capProduct($this->team, ['category' => 'Wiring', 'stock' => 0, 'cost_price' => 10, 'price' => 20, 'reorder_level' => 5]);      //   0.00, out
    capProduct($this->team, ['category' => 'Wiring', 'stock' => 99, 'cost_price' => 10, 'price' => 20, 'is_active' => false]);     // inactive: left out

    $props = capReport($this);
    $byCategory = collect($props['inventoryAnalytics']['byCategory'])->keyBy('category');

    expect($props['metrics']['inventoryCount'])->toBe(3)
        ->and((float) $props['metrics']['totalValuation'])->toBe(650.0)
        ->and($props['inventoryAnalytics']['statusDistribution'])->toBe(['in_stock' => 1, 'low_stock' => 1, 'out_of_stock' => 1])
        ->and($byCategory['Breakers'])->toMatchArray(['count' => 2, 'stock' => 12])
        ->and((float) $byCategory['Breakers']['valuation'])->toBe(650.0)
        ->and((float) $byCategory['Wiring']['valuation'])->toBe(0.0);
});

it('UT-95 reports the same inventory value in the summary figure and in the category table', function () {
    // A product saved without a cost price: the product form stores 0.00 as its cost.
    capProduct($this->team, ['category' => 'Breakers', 'stock' => 5, 'cost_price' => 0, 'price' => 20]);

    $props = capReport($this);

    expect((float) $props['metrics']['totalValuation'])->toBe((float) collect($props['inventoryAnalytics']['byCategory'])->sum('valuation'));
});

it('UT-96 calculates the quotation conversion rate and keeps other teams out of the figures', function () {
    $product = capProduct($this->team);
    $converted = capQuotation($this->team, QuotationStatus::Accepted, [[$product, 1]]);
    $sale = capReportSale($this->team, '2026-10-05', 112);
    $converted->update(['converted_to_sale_id' => $sale->id]);
    capQuotation($this->team, QuotationStatus::Sent, [[$product, 1]]);
    capQuotation($this->team, QuotationStatus::Draft, [[$product, 1]])->update(['submitted_for_approval_at' => null]);
    capQuotation($this->team, QuotationStatus::Rejected, [[$product, 1]]);

    $otherTeam = Team::factory()->create();
    capQuotation($otherTeam, QuotationStatus::Accepted, [[capProduct($otherTeam), 1]]);
    capReportSale($otherTeam, '2026-10-05', 5000);

    $props = capReport($this);

    // 1 converted of 4 quotations = 25.0%.
    expect($props['quotationsSalesAnalytics']['conversion'])->toMatchArray(['total' => 4, 'converted' => 1])
        ->and((float) $props['quotationsSalesAnalytics']['conversion']['rate'])->toBe(25.0)
        ->and($props['metrics']['quotationsCount'])->toBe(4)
        ->and((float) $props['metrics']['totalRevenue'])->toBe(112.0);
});

it('UT-97 counts quotations waiting for approval in the pending-quotations figure', function () {
    $product = capProduct($this->team);
    capQuotation($this->team, QuotationStatus::Draft, [[$product, 2]]); // submitted for approval, not approved yet: 224.00

    $metrics = capReport($this)['metrics'];

    expect($metrics['pendingQuotationsCount'])->toBe(1)
        ->and((float) $metrics['pendingQuotationsValue'])->toBe(224.0);
});

it('UT-100 handles an unreadable date in the forecast date range without a server error', function () {
    [$forecaster, $cookies] = capActor('staff_forecast', $this->team);

    $response = $this->actingAs($forecaster)->withCookies($cookies)->get('/rpt-cap/forecast?from_date=not-a-date');

    expect($response->status())->toBeLessThan(500);
});

it('UT-98 keeps the forecast date range and forecasting period within their limits', function () {
    [$forecaster, $cookies] = capActor('staff_forecast', $this->team);
    $props = fn (string $query) => $this->actingAs($forecaster)->withCookies($cookies)->get("/rpt-cap/forecast?{$query}")->assertOk()->viewData('page')['props'];

    // From after To: back to the start of the year. To in the future: capped at today. Before 2020: capped.
    expect($props('from_date=2026-09-01&to_date=2026-03-01')['fromDate'])->toBe('2026-01-01')
        ->and($props('from_date=2026-03-01&to_date=2027-01-01')['toDate'])->toBe('2026-10-15')
        ->and($props('from_date=2015-01-01&to_date=2026-03-01')['fromDate'])->toBe('2020-01-01')
        ->and($props('sma_window=7')['smaWindow'])->toBe(3)
        ->and($props('sma_window=12')['smaWindow'])->toBe(12);
});
