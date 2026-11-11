<?php

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function paymentActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    $deviceData = createTrustedDeviceFor($user);

    return [$user, $deviceData['cookies']];
}

/** An accepted quotation for one product, turned into a Sales Order from the Sales page. */
function paymentOrder(Team $team, User $user, array $cookies, Product $product, int $qty): Sale
{
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-'.uniqid(), 'client_name' => 'Mega Builders Inc', 'address' => 'Lot 4, Pasig',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
        'subtotal' => $product->price * $qty, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => $product->price * $qty,
        'status' => QuotationStatus::Accepted,
    ]);
    $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty,
        'unit_price' => $product->price, 'discount' => 0, 'subtotal' => $product->price * $qty]);

    test()->actingAs($user)->withCookies($cookies)->post("/{$team->slug}/sales", ['quotation_id' => $quotation->id])->assertSessionHasNoErrors();

    return Sale::where('quotation_id', $quotation->id)->sole();
}

it('flags a payment with proof for staff verification and tracks the balance', function () {
    [$user, $cookies] = paymentActor('admin', $this->team);
    $product = Product::create(['team_id' => $this->team->id, 'name' => 'Schneider Circuit Breaker 30A', 'sku' => 'SCB-30A-TEST', 'category' => 'Breakers',
        'price' => 500.00, 'cost_price' => 350.00, 'stock' => 20, 'status' => 'In Stock', 'is_active' => true]);
    $sale = paymentOrder($this->team, $user, $cookies, $product, 2);

    // Payments are recorded on the order page (Payment Recording), not when the order is created.
    expect(Payment::count())->toBe(0);
    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", [
        'method' => 'GCash', 'reference' => 'GCASH-99887766', 'amount' => 1000.00, 'payment_date' => now()->toDateString(),
        'proof' => UploadedFile::fake()->image('gcash_receipt.png', 400, 400),
    ])->assertSessionHasNoErrors();

    // Attached proof means the payment waits for verification: nothing counts as paid yet.
    $payment = Payment::where('submitted_reference', 'GCASH-99887766')->sole();
    $sale->refresh();
    expect($payment->sale_id)->toBe($sale->id)
        ->and($payment->status)->toBe(PaymentRecordStatus::Submitted)
        ->and($sale->payment_status)->toBe(PaymentStatus::PaymentSubmitted)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and((float) $sale->amount_paid)->toBe(0.00)
        ->and((float) $sale->outstanding_balance)->toBe(1000.00);
    Storage::disk('local')->assertExists($payment->proof_path);
});

it('allows authorized staff to verify customer payment', function () {
    [$user, $cookies] = paymentActor('admin', $this->team);
    $product = Product::create(['team_id' => $this->team->id, 'name' => 'Panel Board', 'sku' => 'PB-1', 'price' => 1000, 'stock' => 5, 'is_active' => true]);
    $sale = paymentOrder($this->team, $user, $cookies, $product, 2);

    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", [
        'method' => 'Bank Transfer', 'reference' => 'BDO-55443322', 'amount' => 2000, 'payment_date' => now()->toDateString(),
        'proof' => UploadedFile::fake()->image('deposit.png'),
    ])->assertSessionHasNoErrors();
    $payment = Payment::sole();

    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments/{$payment->id}/verify", [
        'reference' => 'BDO-55443322', 'amount' => 2000, 'payment_date' => now()->toDateString(),
        'remarks' => 'Confirmed matching deposit on BDO online portal.',
    ])->assertSessionHasNoErrors();

    $sale->refresh();
    expect($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->status)->toBe(SalesOrderStatus::Confirmed) // paid: confirmed, then delivered through Outbound Delivery
        ->and($sale->verified_by_id)->toBe($user->id)
        ->and($sale->verified_at)->not->toBeNull()
        ->and($payment->fresh()->receipt)->not->toBeNull();
});
