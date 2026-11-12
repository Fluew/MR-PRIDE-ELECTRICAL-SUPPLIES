<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Demand Forecasting → Forecast Selection: exactly one mode is in effect, and the inputs of the
 * other mode are ignored even when a request carries them.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'forecast-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $device = createTrustedDeviceFor($this->admin);
    $this->page = fn (string $query = '') => $this->actingAs($this->admin)->withCookies($device['cookies'])
        ->get("/{$this->team->slug}/forecast".($query ? "?{$query}" : ''))->assertOk();
});

afterEach(fn () => Carbon::setTestNow());

it('uses the forecasting period and ignores dates in Forecasting Period mode', function () {
    ($this->page)('forecast_mode=period&sma_window=6&from_date=2021-01-01&to_date=2021-03-31')
        ->assertInertia(fn (Assert $page) => $page
            ->where('forecastMode', 'period')
            ->where('smaWindow', 6)
            // History shown: the 6-month window plus the last year, ending today. The 2021 dates are ignored.
            ->where('fromDate', '2025-04-01')
            ->where('toDate', '2026-10-15'));
});

it('uses the dates as history and ignores the period in Custom Date Range mode', function () {
    ($this->page)('forecast_mode=range&from_date=2026-01-01&to_date=2026-06-30&sma_window=12')
        ->assertInertia(fn (Assert $page) => $page
            ->where('forecastMode', 'range')
            ->where('fromDate', '2026-01-01')
            ->where('toDate', '2026-06-30')
            ->where('smaWindow', 3)                                  // the default window; 12 is ignored
            ->where('forecastData.months.0', '2026-01')
            ->where('forecastData.months.5', '2026-06')
            ->missing('forecastData.months.6'));
});

it('never reads a custom date as a future date', function () {
    ($this->page)('forecast_mode=range&from_date=2026-08-01&to_date=2027-12-31')
        ->assertInertia(fn (Assert $page) => $page
            ->where('forecastMode', 'range')
            ->where('fromDate', '2026-08-01')
            ->where('toDate', '2026-10-15')                          // capped at today
            ->where('maxDate', '2026-10-15'));
});

it('picks the mode for requests that do not name one', function () {
    // The menu link: no values at all.
    ($this->page)()->assertInertia(fn (Assert $page) => $page->where('forecastMode', 'period')->where('smaWindow', 3)->where('fromDate', '2025-07-01'));
    // An old link with a period.
    ($this->page)('sma_window=12')->assertInertia(fn (Assert $page) => $page->where('forecastMode', 'period')->where('smaWindow', 12)->where('fromDate', '2024-10-01'));
    // An old link with dates only.
    ($this->page)('from_date=2026-02-01&to_date=2026-05-31')->assertInertia(fn (Assert $page) => $page->where('forecastMode', 'range')->where('fromDate', '2026-02-01')->where('toDate', '2026-05-31'));
    // An unknown mode falls back the same way.
    ($this->page)('forecast_mode=future&sma_window=6')->assertInertia(fn (Assert $page) => $page->where('forecastMode', 'period')->where('smaWindow', 6));
});

it('exports the same selection as the page shows', function () {
    $export = fn (string $query) => $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->get("/{$this->team->slug}/forecast/export-csv?{$query}")->assertOk();

    expect($export('forecast_mode=period&sma_window=6&from_date=2021-01-01')->headers->get('Content-Disposition'))
        ->toContain('demand_forecast_2025-04-01_to_2026-10-15_sma6.csv');
    expect($export('forecast_mode=range&from_date=2026-01-01&to_date=2026-06-30&sma_window=12')->headers->get('Content-Disposition'))
        ->toContain('demand_forecast_2026-01-01_to_2026-06-30_sma3.csv');
});
