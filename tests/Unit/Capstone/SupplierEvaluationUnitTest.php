<?php

/*
 * 4.4.1 Unit Testing — Supplier Evaluation (UT-54 to UT-61).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 *
 * Fixed weights (config/evaluation.php): Delivery 40% (on-time 60% + fulfillment 40%),
 * Price 35%, Quality 25%.
 */

use App\Models\Delivery;
use App\Models\Team;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create();
    $this->product = capProduct($this->team, ['stock' => 0]);
    $this->alpha = capSupplier($this->team, 'Alpha Electrical');
    $this->beta = capSupplier($this->team, 'Beta Trading');
});

afterEach(fn () => Carbon::setTestNow());

function capCriterion(array $evaluation, string $key): array
{
    return collect($evaluation['criteria'])->firstWhere('key', $key);
}

function capEvaluate($t): array
{
    return app(SupplierEvaluator::class)->forTeam($t->team->id);
}

it('UT-54 calculates delivery timeliness from on-time deliveries and quantity fulfillment', function () {
    // Promised Sep 10. One delivery on Sep 8 (on time), one on Sep 15 (late): 1 of 2 on time.
    // 57 + 40 = 97 of the 100 ordered were accepted.
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 100],
        [['2026-09-08', 60, 57, 3], ['2026-09-15', 40, 40, 0]]);

    $delivery = capCriterion(capEvaluate($this)[$this->alpha->id], 'delivery');

    // (50.00 × 0.60) + (97.00 × 0.40) = 68.80
    expect($delivery['inputs'])->toMatchArray(['completed_deliveries' => 2, 'on_time_deliveries' => 1, 'ordered_quantity' => 100, 'accepted_quantity' => 97])
        ->and($delivery['inputs']['on_time_rate'])->toEqual(50.0)
        ->and($delivery['inputs']['fulfillment_rate'])->toEqual(97.0)
        ->and($delivery['score'])->toEqual(68.8)
        ->and($delivery['weighted'])->toEqual(27.52); // × 0.40
});

it('UT-55 counts a delivery on the promised date as on time and one day later as late', function () {
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 10], [['2026-09-10', 10, 10, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $this->beta, $this->product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 10], [['2026-09-11', 10, 10, 0]]);

    $result = capEvaluate($this);

    expect(capCriterion($result[$this->alpha->id], 'delivery')['inputs']['on_time_rate'])->toEqual(100.0)
        ->and(capCriterion($result[$this->beta->id], 'delivery')['inputs']['on_time_rate'])->toEqual(0.0)
        ->and(capCriterion($result[$this->beta->id], 'delivery')['score'])->toEqual(40.0); // only the fulfillment part
});

it('UT-56 calculates price competitiveness against the lowest purchase cost of the same product', function () {
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['unit_cost' => 1100, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $this->beta, $this->product, ['unit_cost' => 1000, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);

    $result = capEvaluate($this);

    // Alpha: (1,000 / 1,100) × 100 = 90.91. Beta has the lowest cost: 100.00.
    expect(capCriterion($result[$this->alpha->id], 'price')['score'])->toEqual(90.91)
        ->and(capCriterion($result[$this->beta->id], 'price')['score'])->toEqual(100.0)
        ->and(capCriterion($result[$this->alpha->id], 'price')['weighted'])->toEqual(31.82); // × 0.35
});

it('UT-57 has no price score when no other supplier delivered the same product', function () {
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['unit_cost' => 1100, 'promised_delivery_date' => '2026-09-10'], [['2026-09-08', 100, 100, 0]]);

    $evaluation = capEvaluate($this)[$this->alpha->id];
    $price = capCriterion($evaluation, 'price');

    expect($price['available'])->toBeFalse()
        ->and($price['score'])->toBeNull()                  // insufficient data, not zero
        ->and($evaluation['complete'])->toBeFalse()
        ->and($evaluation['score'])->toBeNull()
        ->and($evaluation['rank'])->toBeNull()
        ->and($evaluation['missing'])->toBe(['Price Competitiveness']);
});

it('UT-58 calculates product quality from accepted and rejected quantities', function () {
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2026-09-10'], [['2026-09-08', 60, 57, 3], ['2026-09-09', 40, 40, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $this->beta, $this->product, ['promised_delivery_date' => '2026-09-10'], [['2026-09-08', 20, 0, 20]]);

    $result = capEvaluate($this);
    $quality = capCriterion($result[$this->alpha->id], 'quality');

    // (97 / 100) × 100 = 97.00; × 0.25 = 24.25. Everything rejected: 0.00 (a real score, not "no data").
    expect($quality['inputs'])->toMatchArray(['inspections' => 2, 'accepted' => 97, 'rejected' => 3])
        ->and($quality['score'])->toEqual(97.0)
        ->and($quality['weighted'])->toEqual(24.25)
        ->and(capCriterion($result[$this->beta->id], 'quality')['score'])->toEqual(0.0)
        ->and(capCriterion($result[$this->beta->id], 'quality')['available'])->toBeTrue();
});

it('UT-59 gives a supplier without records no score and no rank', function () {
    $evaluation = capEvaluate($this)[$this->alpha->id];

    expect($evaluation['complete'])->toBeFalse()
        ->and($evaluation['score'])->toBeNull()
        ->and($evaluation['classification'])->toBeNull()
        ->and($evaluation['rank'])->toBeNull()
        ->and($evaluation['missing'])->toBe(['Inbound Delivery Performance', 'Price Competitiveness', 'Product Quality'])
        ->and(collect($evaluation['criteria'])->pluck('score')->all())->toBe([null, null, null]);
});

it('UT-60 adds the weighted criteria into the final score, classifies it and ranks the suppliers', function () {
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['unit_cost' => 1100, 'promised_delivery_date' => '2026-09-10', 'quantity' => 100],
        [['2026-09-08', 60, 57, 3], ['2026-09-15', 40, 40, 0]]);
    capPurchaseOrderWithDeliveries($this->team, $this->beta, $this->product, ['unit_cost' => 1000, 'promised_delivery_date' => '2026-09-25', 'quantity' => 50],
        [['2026-09-20', 50, 50, 0]]);

    $result = capEvaluate($this);
    $alpha = $result[$this->alpha->id];
    $beta = $result[$this->beta->id];

    // Alpha: 68.80 × 0.40 + 90.91 × 0.35 + 97.00 × 0.25 = 27.52 + 31.82 + 24.25 = 83.59
    expect($alpha['score'])->toEqual(83.59)
        ->and($alpha['final_formula'])->toBe('27.52 + 31.82 + 24.25 = 83.59')
        ->and($alpha['classification'])->toBe('Very Good')
        ->and($alpha['rank'])->toBe(2)
        // Beta: on time, complete, cheapest, nothing rejected.
        ->and($beta['score'])->toEqual(100.0)
        ->and($beta['classification'])->toBe('Excellent')
        ->and($beta['rank'])->toBe(1)
        ->and($beta['ranked_of'])->toBe(2);
});

it('UT-61 classifies a supplier score at the class boundaries', function () {
    $evaluator = app(SupplierEvaluator::class);

    expect($evaluator->classify(100))->toBe('Excellent')
        ->and($evaluator->classify(90))->toBe('Excellent')
        ->and($evaluator->classify(89.99))->toBe('Very Good')
        ->and($evaluator->classify(80))->toBe('Very Good')
        ->and($evaluator->classify(79.99))->toBe('Good')
        ->and($evaluator->classify(70))->toBe('Good')
        ->and($evaluator->classify(69.99))->toBe('Fair')
        ->and($evaluator->classify(60))->toBe('Fair')
        ->and($evaluator->classify(59.99))->toBe('Needs Improvement')
        ->and($evaluator->classify(0))->toBe('Needs Improvement');
});

it('UT-62 leaves out cancelled deliveries, deliveries older than 12 months, and counts a repeated record once', function () {
    $po = capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2026-09-10', 'quantity' => 100], [['2026-09-08', 50, 50, 0]]);
    $recorded = Delivery::where('purchase_order_id', $po->id)->first();

    // The same delivery number recorded twice for the same order.
    Delivery::create($recorded->only(['team_id', 'purchase_order_id', 'purchase_order_number', 'unit', 'description', 'supplier_id', 'status', 'inspection_result',
        'delivery_number', 'quantity', 'accepted_quantity', 'rejected_quantity']) + ['delivery_date' => '2026-09-08', 'inspected_on' => '2026-09-08']);
    // A cancelled delivery, and one from before the 12-month evaluation period, both fully rejected.
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2026-09-10'], [['2026-09-20', 30, 0, 30]]);
    Delivery::latest('id')->first()->update(['status' => 'Cancelled']);
    capPurchaseOrderWithDeliveries($this->team, $this->alpha, $this->product, ['promised_delivery_date' => '2025-06-10'], [['2025-06-01', 30, 0, 30]]);

    $quality = capCriterion(capEvaluate($this)[$this->alpha->id], 'quality');

    expect($quality['inputs'])->toMatchArray(['inspections' => 1, 'accepted' => 50, 'rejected' => 0])
        ->and($quality['score'])->toEqual(100.0);
});
