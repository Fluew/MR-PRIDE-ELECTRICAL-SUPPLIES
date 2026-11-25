<?php

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\Suppliers\SupplierFlags;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Capstone/helpers.php';

/** A SupplierEvaluator-shaped result with just the inputs the flags read. */
function flagEval(int $id, ?float $lead, ?float $price, ?float $quality, ?float $overall, string $status = 'Active'): array
{
    return [
        'supplier' => ['id' => $id, 'name' => "S{$id}", 'status' => $status],
        'complete' => $overall !== null,
        'score' => $overall,
        'criteria' => [
            ['key' => 'delivery', 'available' => $lead !== null, 'score' => null, 'inputs' => ['average_lead_days' => $lead, 'lead_time_deliveries' => $lead === null ? 0 : 3]],
            ['key' => 'price', 'available' => $price !== null, 'score' => $price, 'inputs' => ['comparisons' => $price === null ? [] : [[]]]],
            ['key' => 'quality', 'available' => $quality !== null, 'score' => $quality, 'inputs' => ['accepted' => 90, 'rejected' => 10]],
        ],
    ];
}

function flagKeys(array $flags, int $id): array
{
    return array_column($flags[$id] ?? [], 'key');
}

it('flags suppliers by comparing their recorded performance, with shared ties and several flags each', function () {
    $flags = app(SupplierFlags::class)->fromEvaluations([
        1 => flagEval(1, 3.0, 100.0, 99.0, 95.0),
        2 => flagEval(2, 5.0, 90.0, 99.0, 88.0),
        3 => flagEval(3, 8.0, 80.0, 90.0, 80.0),
        4 => flagEval(4, 12.0, 95.0, 85.0, null),
        5 => flagEval(5, 1.0, null, null, null, 'Inactive'), // inactive: never flagged, never compared
    ]);

    expect(flagKeys($flags, 1))->toBe(['best_overall', 'fastest_delivery', 'fast_delivery', 'lowest_price', 'best_quality'])
        ->and(flagKeys($flags, 2))->toBe(['best_quality'])             // tie on quality: shared
        ->and(flagKeys($flags, 3))->toBe(['highest_price'])
        ->and(flagKeys($flags, 4))->toBe(['slow_delivery'])
        ->and($flags)->not->toHaveKey(5)
        ->and($flags[1][1]['reason'])->toContain('Average 3.0 days from order to delivery');
});

it('does not flag without at least two suppliers to compare, or quartiles without four', function () {
    $one = app(SupplierFlags::class)->fromEvaluations([1 => flagEval(1, 3.0, 100.0, 99.0, 95.0)]);
    expect($one)->toBe([]);

    $two = app(SupplierFlags::class)->fromEvaluations([1 => flagEval(1, 3.0, null, null, null), 2 => flagEval(2, 9.0, null, null, null)]);
    expect(flagKeys($two, 1))->toBe(['fastest_delivery'])->and(flagKeys($two, 2))->toBe([]); // no Fast / Slow with two
});

it('shows flags from delivery records on the supplier list and filters by them', function () {
    Carbon::setTestNow('2026-11-17 10:00:00');
    $team = Team::factory()->create();
    [$user, $cookies] = capActor('admin', $team);
    $p = capProduct($team, [], '2020-01-01');
    $make = function (string $name, string $delivered, int $rejected, float $cost) use ($team, $p) {
        $s = Supplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'Active']);
        $po = PurchaseOrder::create(['team_id' => $team->id, 'po_date' => '2026-10-01', 'promised_delivery_date' => '2026-10-20', 'po_number' => "PO-{$name}", 'quantity' => 10, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => $cost, 'total_cost' => 10 * $cost, 'supplier_id' => $s->id]);
        Delivery::create(['team_id' => $team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => $po->po_number, 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s->id, 'status' => 'Delivered',
            'inspection_result' => 'accepted', 'inspected_on' => $delivered, 'delivery_date' => $delivered, 'delivery_number' => "D-{$name}", 'quantity' => 10, 'accepted_quantity' => 10 - $rejected, 'rejected_quantity' => $rejected]);

        return $s;
    };
    $quick = $make('Quick', '2026-10-04', 0, 5);   // 3 days, 100% accepted, pays 5
    $slow = $make('Steady', '2026-10-15', 2, 6);   // 14 days, 80% accepted, pays 6
    $keys = fn ($rows, $id) => collect(collect($rows)->firstWhere('id', $id)['flags'])->pluck('key')->all();

    $this->actingAs($user)->withCookies($cookies)->get('/'.$team->slug.'/suppliers')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('suppliers.data', fn ($rows) => $keys($rows, $quick->id) === ['best_overall', 'fastest_delivery', 'lowest_price', 'best_quality']
                && $keys($rows, $slow->id) === ['highest_price'])
            ->where('flagOptions', fn ($options) => collect($options)->firstWhere('key', 'fastest_delivery')['count'] === 1
                && collect($options)->firstWhere('key', 'slow_delivery')['count'] === 0));

    $this->actingAs($user)->withCookies($cookies)->get('/'.$team->slug.'/suppliers?flag=best_quality')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 1)->where('suppliers.data.0.id', $quick->id)->where('filters.flag', 'best_quality'));

    // An unknown flag is ignored rather than hiding everyone.
    $this->actingAs($user)->withCookies($cookies)->get('/'.$team->slug.'/suppliers?flag=made_up')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 2)->where('filters.flag', null));
    Carbon::setTestNow();
});

it('builds the supplier profile from the same records: products, delivery, price, quality and ranking', function () {
    Carbon::setTestNow('2026-11-17 10:00:00');
    $team = Team::factory()->create();
    [$user, $cookies] = capActor('admin', $team);
    $p = capProduct($team, ['name' => 'THHN Wire', 'sku' => 'WIR-1'], '2020-01-01');
    $make = function (string $name, string $delivered, int $rejected, float $cost) use ($team, $p) {
        $s = Supplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'Active']);
        $po = PurchaseOrder::create(['team_id' => $team->id, 'po_date' => '2026-10-01', 'promised_delivery_date' => '2026-10-10', 'po_number' => "PO-{$name}", 'quantity' => 10, 'product_id' => $p->id, 'description' => 'THHN Wire', 'unit_cost' => $cost, 'total_cost' => 10 * $cost, 'supplier_id' => $s->id]);
        Delivery::create(['team_id' => $team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => $po->po_number, 'unit' => 'pcs', 'description' => 'THHN Wire', 'supplier_id' => $s->id, 'status' => 'Delivered',
            'inspection_result' => 'partially_accepted', 'inspected_on' => $delivered, 'delivery_date' => $delivered, 'delivery_number' => "D-{$name}", 'quantity' => 10, 'accepted_quantity' => 10 - $rejected, 'rejected_quantity' => $rejected]);

        return $s;
    };
    $quick = $make('Quick', '2026-10-04', 0, 5);   // on time, 3 days, cheapest
    $late = $make('Late', '2026-10-15', 2, 6);     // 5 days late, 14 days, dearer, 80% accepted
    $empty = Supplier::create(['team_id' => $team->id, 'name' => 'New', 'status' => 'Active']);
    $get = fn ($s) => $this->actingAs($user)->withCookies($cookies)->get('/'.$team->slug.'/suppliers/'.$s->id.'/records', ['Accept' => 'application/json'])->assertOk()->json('profile');

    $profile = $get($late);
    expect($profile['products'][0])->toMatchArray(['name' => 'THHN Wire', 'sku' => 'WIR-1', 'unit_price' => 6.0, 'quantity_ordered' => 10, 'units_received' => 10, 'units_accepted' => 8, 'counts_in' => ['Delivery', 'Price', 'Quality']])
        ->and($profile['delivery'])->toMatchArray(['average_days' => 14.0, 'on_time' => 0, 'late' => 1, 'on_time_rate' => 0.0])
        ->and($profile['delivery']['rows'][0])->toMatchArray(['po_date' => '2026-10-01', 'expected' => '2026-10-10', 'delivered' => '2026-10-15', 'days' => 14, 'status' => '5 days late'])
        ->and($profile['price']['rows'][0])->toMatchArray(['own_price' => 6.0, 'lowest_price' => 5.0, 'difference' => 1.0, 'difference_pct' => 20.0, 'average_price' => 5.5, 'rank' => 2, 'of' => 2])
        ->and($profile['price']['rows'][0]['others'])->toEqual([['supplier' => 'Quick', 'price' => 5.0]])
        ->and($profile['quality'])->toMatchArray(['accepted' => 8, 'rejected' => 2, 'score' => 80])
        ->and($profile['quality']['history'][0])->toMatchArray(['month' => 'Oct 2026', 'rate' => 80])
        ->and($profile['ranking'])->toMatchArray(['rank' => 2, 'ranked_of' => 2, 'complete' => true])
        ->and(array_column($profile['flags'], 'key'))->toBe(['highest_price']);

    // The weighted parts add up to the final score shown.
    expect(round(array_sum(array_column($get($quick)['ranking']['criteria'], 'weighted')), 2))->toEqual($get($quick)['ranking']['score']);

    // No records: Insufficient Data, never an invented score.
    $none = $get($empty);
    expect($none['products'])->toBe([])->and($none['delivery']['score'])->toBeNull()->and($none['quality']['score'])->toBeNull()
        ->and($none['ranking']['complete'])->toBeFalse()->and($none['ranking']['score'])->toBeNull()->and($none['flags'])->toBe([]);
    Carbon::setTestNow();
});
