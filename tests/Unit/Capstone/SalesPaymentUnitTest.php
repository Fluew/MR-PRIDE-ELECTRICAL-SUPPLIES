<?php

/*
 * 4.4.1 Unit Testing — Sales and Payment Recording (UT-63 to UT-72).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 *
 * Orders in these tests: 2 units × 100.00 + 12% tax = 224.00.
 */

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use App\Models\Team;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'sales-cap']);
    [$this->admin, $this->adminCookies] = capActor('admin', $this->team);
    [$this->sales, $this->salesCookies] = capActor('staff_sales', $this->team);
    $this->product = capProduct($this->team, ['stock' => 50, 'price' => 100]);
    $this->url = '/sales-cap/sales';
});

function capSales($t)
{
    return $t->actingAs($t->sales)->withCookies($t->salesCookies);
}

function capPaymentForm(array $overrides = []): array
{
    return $overrides + ['method' => 'GCash', 'amount' => 100, 'reference' => 'GC-1001', 'payment_date' => now()->toDateString()];
}

it('UT-63 requires an existing quotation and a valid fulfillment type for a sales order', function () {
    $accepted = capQuotation($this->team, QuotationStatus::Accepted, [[$this->product, 2]]);

    capSales($this)->post($this->url, [])->assertSessionHasErrors(['quotation_id' => 'Select a quotation.']);
    capSales($this)->post($this->url, ['quotation_id' => 999999])->assertSessionHasErrors(['quotation_id' => 'The selected quotation was not found.']);
    capSales($this)->post($this->url, ['quotation_id' => 'abc'])->assertSessionHasErrors('quotation_id');
    capSales($this)->post($this->url, ['quotation_id' => $accepted->id, 'fulfillment_type' => 'courier'])->assertSessionHasErrors('fulfillment_type');

    expect(Sale::count())->toBe(0);
});

it('UT-64 creates a sales order only from an accepted quotation, copying its totals', function () {
    foreach ([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Rejected, QuotationStatus::Expired] as $status) {
        $quotation = capQuotation($this->team, $status, [[$this->product, 2]]);
        capSales($this)->post($this->url, ['quotation_id' => $quotation->id])->assertSessionHasErrors('error');
    }
    expect(Sale::count())->toBe(0);

    $accepted = capQuotation($this->team, QuotationStatus::Accepted, [[$this->product, 2]]);
    capSales($this)->post($this->url, ['quotation_id' => $accepted->id, 'fulfillment_type' => 'delivery'])->assertSessionHasNoErrors();

    $sale = Sale::with('items')->sole();
    expect($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and((float) $sale->grand_total)->toBe(224.0)
        ->and((float) $sale->outstanding_balance)->toBe(224.0)
        ->and($sale->items->sole()->quantity)->toBe(2)
        ->and($this->product->fresh()->stock)->toBe(50); // creating an order moves no stock
});

it('UT-65 does not create a second sales order from the same quotation', function () {
    $accepted = capQuotation($this->team, QuotationStatus::Accepted, [[$this->product, 2]]);

    capSales($this)->post($this->url, ['quotation_id' => $accepted->id])->assertSessionHasNoErrors();
    capSales($this)->post($this->url, ['quotation_id' => $accepted->id])->assertSessionHasErrors(['error' => 'This quotation was already converted to a Sales Order.']);

    expect(Sale::count())->toBe(1);
});

it('UT-66 validates the payment amount, method and date', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    $pay = fn (array $data) => capSales($this)->post("{$this->url}/{$sale->id}/payments", capPaymentForm($data));

    foreach ([0, -50, 'abc', '', 0.001] as $invalid) {
        $pay(['amount' => $invalid])->assertSessionHasErrors('amount');
    }
    $pay(['amount' => 100000000])->assertSessionHasErrors('amount'); // above the maximum
    $pay(['method' => 'Cash'])->assertSessionHasErrors('method');
    $pay(['method' => ''])->assertSessionHasErrors('method');
    $pay(['payment_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('payment_date'); // tomorrow
    $pay(['payment_date' => 'not-a-date'])->assertSessionHasErrors('payment_date');
    expect(Payment::count())->toBe(0);

    $pay(['amount' => 0.01])->assertSessionHasNoErrors(); // the smallest amount
    expect((float) Payment::sole()->submitted_amount)->toBe(0.01);
});

it('UT-67 accepts only an image or PDF of at most 5 MB as proof of payment', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    $pay = fn (UploadedFile $proof) => capSales($this)->post("{$this->url}/{$sale->id}/payments", capPaymentForm(['proof' => $proof]));

    $pay(UploadedFile::fake()->create('proof.exe', 10))->assertSessionHasErrors('proof');
    $pay(UploadedFile::fake()->create('proof.pdf', 5121, 'application/pdf'))->assertSessionHasErrors('proof'); // 1 KB over
    expect(Payment::count())->toBe(0);

    $pay(UploadedFile::fake()->image('proof.png', 300, 600))->assertSessionHasNoErrors();
    expect(Storage::disk('local')->exists(Payment::sole()->proof_path))->toBeTrue();
});

it('UT-68 counts only verified payments towards the amount paid', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    $payments = app(PaymentService::class);
    $record = fn (float $amount, string $ref) => $payments->record($sale, ['method' => 'GCash', 'amount' => $amount, 'reference' => $ref, 'payment_date' => now()->toDateString()], null, $this->sales);

    $submitted = $record(100, 'GC-1');
    expect((float) $sale->fresh()->amount_paid)->toBe(0.0)->and($sale->fresh()->payment_status)->toBe(PaymentStatus::PaymentSubmitted);

    $payments->reject($submitted, 'No matching transaction', $this->sales);
    expect((float) $sale->fresh()->amount_paid)->toBe(0.0)->and($submitted->fresh()->status)->toBe(PaymentRecordStatus::Rejected);

    $verified = $record(100, 'GC-2');
    $payments->verify($verified, ['reference' => 'GC-2', 'amount' => 100, 'payment_date' => now()->toDateString()], $this->sales);
    expect((float) $sale->fresh()->amount_paid)->toBe(100.0);

    $payments->refund($verified->fresh(), 'Customer cancelled', $this->admin);
    expect((float) $sale->fresh()->amount_paid)->toBe(0.0)->and((float) $sale->fresh()->outstanding_balance)->toBe(224.0);
});

it('UT-69 calculates the remaining balance after each verified payment', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]); // 224.00

    $first = capPay($sale, $this->sales, 100, 'BDO-1');
    expect((float) $first->balance_after)->toBe(124.0)
        ->and((float) $sale->fresh()->amount_paid)->toBe(100.0)
        ->and((float) $sale->fresh()->outstanding_balance)->toBe(124.0)
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);

    $second = capPay($sale->fresh(), $this->sales, 124, 'BDO-2');
    expect((float) $second->previously_paid)->toBe(100.0)
        ->and((float) $second->balance_after)->toBe(0.0)
        ->and((float) $sale->fresh()->outstanding_balance)->toBe(0.0)
        ->and($sale->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(PaymentReceipt::count())->toBe(2);
});

it('UT-70 refuses a verified amount above the remaining balance, and accepts exactly the balance', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]); // 224.00
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'Bank Transfer', 'amount' => 224, 'reference' => 'BDO-9', 'payment_date' => now()->toDateString()], null, $this->sales);
    $verify = fn (float $amount) => $payments->verify($payment->fresh(), ['reference' => 'BDO-9', 'amount' => $amount, 'payment_date' => now()->toDateString()], $this->sales);

    expect(fn () => $verify(224.01))->toThrow(WorkflowException::class, 'more than the remaining balance'); // one centavo over
    expect(fn () => $verify(0))->toThrow(WorkflowException::class, 'Enter the amount actually received');
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Submitted)->and(PaymentReceipt::count())->toBe(0);

    $verify(224.00);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Verified)->and((float) $sale->fresh()->outstanding_balance)->toBe(0.0);
});

it('UT-71 prevents duplicate payment transactions', function () {
    $saleA = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    $saleB = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    $payments = app(PaymentService::class);

    // The same payment form sent twice is recorded once.
    $form = capPaymentForm(['idempotency_key' => 'form-123']);
    capSales($this)->post("{$this->url}/{$saleA->id}/payments", $form);
    capSales($this)->post("{$this->url}/{$saleA->id}/payments", $form);
    expect(Payment::count())->toBe(1);

    // The same bank reference cannot be verified for two payments, however it is typed.
    expect(Payment::referenceKey('Bank Transfer', 'bdo 555'))->toBe(Payment::referenceKey('Bank Transfer', 'BDO-555'))
        ->and(Payment::referenceKey('Bank Transfer', 'BDO-555'))->not->toBe(Payment::referenceKey('GCash', 'BDO-555'));
    capPay($saleA, $this->sales, 100, 'BDO-555');
    $second = $payments->record($saleB, ['method' => 'Bank Transfer', 'amount' => 100, 'reference' => 'bdo 555', 'payment_date' => now()->toDateString()], null, $this->sales);
    expect(fn () => $payments->verify($second, ['reference' => 'bdo-555', 'amount' => 100, 'payment_date' => now()->toDateString()], $this->sales))
        ->toThrow(WorkflowException::class, 'was already verified for another payment');

    // A payment that is already verified cannot be verified again.
    $verified = Payment::where('status', PaymentRecordStatus::Verified->value)->sole();
    expect(fn () => $payments->verify($verified, ['reference' => 'BDO-777', 'amount' => 50, 'payment_date' => now()->toDateString()], $this->sales))
        ->toThrow(WorkflowException::class, 'already Verified');
    expect(PaymentReceipt::count())->toBe(1);
});

it('UT-72 refuses a payment for a cancelled order', function () {
    $sale = capOrder($this->team, $this->admin, [[$this->product, 2]]);
    app(SalesOrderService::class)->cancel($sale, 'Customer withdrew the order', $this->admin);

    capSales($this)->post("{$this->url}/{$sale->id}/payments", capPaymentForm())->assertSessionHasErrors();

    expect($sale->fresh()->status)->toBe(SalesOrderStatus::Cancelled)->and(Payment::count())->toBe(0);
});
