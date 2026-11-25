<?php

use App\Models\Sale;
use App\Models\Team;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Capstone/helpers.php';

it('gives the revenue chart weekly and annual totals for finished periods only', function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-11-25 10:00:00'); // November is still running
    $team = Team::factory()->create();
    [$user, $cookies] = capActor('admin', $team);
    $sale = fn (string $date, float $total, string $status = 'completed') => Sale::create(['team_id' => $team->id, 'order_number' => 'REV-'.uniqid(), 'client_name' => 'Rev test', 'sale_date' => $date, 'status' => $status, 'grand_total' => $total]);
    $sale('2026-10-19', 100);   // Mon, the last week that ends in October (19–25 Oct)
    $sale('2026-10-25', 50);    // Sun, same week
    $sale('2026-10-27', 300);   // week 26 Oct – 1 Nov ends in November: not plotted yet
    $sale('2026-11-16', 999);   // November: not plotted until the month is over
    $sale('2026-10-20', 777, 'cancelled'); // not a sale
    $sale('2025-03-01', 400);   // last completed year
    $sale('2026-01-05', 888);   // this year: not plotted in the annual view

    $this->actingAs($user)->withCookies($cookies)->get('/'.$team->slug.'/reports')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('quotationsSalesAnalytics.salesTrendByPeriod.weekly.labels', 12)
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.weekly.labels.11', '19 Oct')
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.weekly.ranges.11', '19 Oct 2026 – 25 Oct 2026')
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.weekly.data.11', 150)
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.weekly.labels', fn ($labels) => ! collect($labels)->contains(fn ($l) => str_contains($l, 'Nov')))
            ->has('quotationsSalesAnalytics.salesTrendByPeriod.annual.labels', 6)
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.annual.labels.5', '2025')
            ->where('quotationsSalesAnalytics.salesTrendByPeriod.annual.data.5', 400));
    Carbon::setTestNow();
});
