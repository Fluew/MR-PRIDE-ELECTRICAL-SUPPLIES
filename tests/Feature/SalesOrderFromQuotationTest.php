<?php

use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sales → New Sales Order: a sales order is created only from a quotation the customer accepted.
 * The quotation is the source of the customer, products, quantities and agreed prices; it is not
 * changed (only marked converted). No payment form and no stock movement at this step.
 */
beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'so-team']);
    [$this->sales, $this->cookies] = soActor('staff_sales', $this->team);
    $this->breaker = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'Circuit Breaker 2P 40A', 'unit' => 'pcs', 'price' => 20, 'stock' => 50, 'is_active' => true]);
    $this->wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WIR-000001', 'name' => 'THHN Wire 12 AWG', 'unit' => 'roll', 'price' => 60, 'stock' => 10, 'is_active' => true]);
});

function soActor(string $role, Team $team): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

/** A quotation with [product, quantity, unit price, line discount] lines; 12% VAT and a 5.00 discount. */
function soQuotation(Team $team, QuotationStatus $status, array $lines, string $number, string $client = 'Metro Builders Inc.'): Quotation
{
    $subtotal = collect($lines)->sum(fn ($l) => ($l[2] - ($l[3] ?? 0)) * $l[1]);
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => $number, 'client_name' => $client, 'contact_person' => 'Engr. Ana Reyes', 'email' => 'buyer@example.com',
        'address' => '12 Site Rd, Pasig City', 'quotation_date' => now()->subDays(3)->toDateString(), 'valid_until' => now()->addDays(12)->toDateString(),
        'subtotal' => $subtotal, 'discount' => 5, 'tax_rate' => 12, 'tax_amount' => round(($subtotal - 5) * 0.12, 2), 'grand_total' => round(($subtotal - 5) * 1.12, 2),
        'status' => $status, 'customer_decision_at' => $status === QuotationStatus::Accepted ? now() : null,
    ]);
    foreach ($lines as $l) {
        [$product, $qty, $price] = $l;
        $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty,
            'unit_price' => $price, 'discount' => $l[3] ?? 0, 'subtotal' => ($price - ($l[3] ?? 0)) * $qty]);
    }

    return $quotation->fresh();
}

function soPage($t): array
{
    $props = [];
    $t->actingAs($t->sales)->withCookies($t->cookies)->get('/so-team/sales')->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('Sales/Index')->missing('products');
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('lists only accepted quotations without a sales order, with their agreed lines', function () {
    $accepted = soQuotation($this->team, QuotationStatus::Accepted, [[$this->breaker, 10, 22.5, 2.5], [$this->wire, 3, 75]], 'Q-ACC-1');
    soQuotation($this->team, QuotationStatus::Draft, [[$this->breaker, 1, 20]], 'Q-DRAFT');
    soQuotation($this->team, QuotationStatus::Sent, [[$this->breaker, 1, 20]], 'Q-SENT');
    soQuotation($this->team, QuotationStatus::Rejected, [[$this->breaker, 1, 20]], 'Q-REJ');
    soQuotation($this->team, QuotationStatus::Expired, [[$this->breaker, 1, 20]], 'Q-EXP');
    $converted = soQuotation($this->team, QuotationStatus::Accepted, [[$this->breaker, 1, 20]], 'Q-DONE');
    $this->actingAs($this->sales)->withCookies($this->cookies)->post('/so-team/sales', ['quotation_id' => $converted->id])->assertSessionHasNoErrors();
    $other = Team::factory()->create();
    soQuotation($other, QuotationStatus::Accepted, [[Product::create(['team_id' => $other->id, 'sku' => 'X-1', 'name' => 'X', 'price' => 1, 'stock' => 1, 'is_active' => true]), 1, 1]], 'Q-OTHER');

    $list = soPage($this)['quotations'];

    expect(collect($list)->pluck('quotation_number')->all())->toBe(['Q-ACC-1'])
        ->and($list[0])->toMatchArray(['id' => $accepted->id, 'client_name' => 'Metro Builders Inc.', 'subtotal' => 425.0, 'discount' => 5.0, 'tax_amount' => 50.4, 'grand_total' => 470.4])
        ->and($list[0]['items'])->toEqual([
            ['id' => $accepted->items[0]->id, 'sku' => 'BRK-000001', 'description' => 'Circuit Breaker 2P 40A', 'quantity' => 10, 'unit' => 'pcs', 'unit_price' => 20.0, 'amount' => 200.0],
            ['id' => $accepted->items[1]->id, 'sku' => 'WIR-000001', 'description' => 'THHN Wire 12 AWG', 'quantity' => 3, 'unit' => 'roll', 'unit_price' => 75.0, 'amount' => 225.0],
        ]);
});

it('creates a sales order from the accepted quotation, copying it exactly, without payment or stock movement', function () {
    $quotation = soQuotation($this->team, QuotationStatus::Accepted, [[$this->breaker, 10, 22.5, 2.5], [$this->wire, 3, 75]], 'Q-ACC-2');
    $before = $quotation->only(['quotation_number', 'client_name', 'subtotal', 'discount', 'tax_amount', 'grand_total', 'status']);

    $this->actingAs($this->sales)->withCookies($this->cookies)->post('/so-team/sales', ['quotation_id' => $quotation->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'created from quotation Q-ACC-2'));

    $sale = Sale::with('items')->sole();
    expect($sale->order_number)->toStartWith('SO-')
        ->and($sale->quotation_id)->toBe($quotation->id)
        ->and($sale->channel)->toBe(Sale::CHANNEL_QUOTATION)
        ->and($sale->client_name)->toBe('Metro Builders Inc.')->and($sale->email)->toBe('buyer@example.com')
        ->and($sale->address)->toBe('12 Site Rd, Pasig City')->and($sale->fulfillment_type)->toBe('delivery')
        ->and((float) $sale->subtotal)->toBe(425.0)->and((float) $sale->discount)->toBe(5.0)->and((float) $sale->tax_amount)->toBe(50.4)
        ->and((float) $sale->grand_total)->toBe(470.4)->and((float) $sale->outstanding_balance)->toBe(470.4)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)->and($sale->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($sale->items->map(fn ($i) => [$i->product_id, $i->sku, $i->quantity, (float) $i->unit_price, (float) $i->discount, (float) $i->subtotal])->all())
        ->toBe([[$this->breaker->id, 'BRK-000001', 10, 22.5, 2.5, 200.0], [$this->wire->id, 'WIR-000001', 3, 75.0, 0.0, 225.0]])
        // No payment and no stock movement at this step; stock is reserved on confirmation, taken out on delivery.
        ->and(Payment::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($this->breaker->fresh()->stock)->toBe(50)
        ->and($sale->items->sum('reserved_quantity'))->toBe(0);

    // The quotation keeps its agreed details; it is only marked converted.
    $quotation->refresh();
    expect($quotation->only(array_keys($before)))->toEqual($before)
        ->and($quotation->converted_to_sale_id)->toBe($sale->id)
        ->and($quotation->converted_by_id)->toBe($this->sales->id)
        ->and(AuditLog::where('event', 'sales_order.created')->where('subject_id', $sale->id)->exists())->toBeTrue();

    // It is no longer offered, and a second order for it is refused.
    expect(soPage($this)['quotations'])->toBe([]);
    $this->actingAs($this->sales)->withCookies($this->cookies)->post('/so-team/sales', ['quotation_id' => $quotation->id])
        ->assertSessionHasErrors(['error' => 'This quotation was already converted to a Sales Order.']);
    expect(Sale::count())->toBe(1);
});

it('keeps the chosen fulfillment: customer pickup, or delivery to another address', function () {
    $pickup = soQuotation($this->team, QuotationStatus::Accepted, [[$this->breaker, 1, 20]], 'Q-PICK');
    $delivery = soQuotation($this->team, QuotationStatus::Accepted, [[$this->breaker, 1, 20]], 'Q-DELIV');
    $as = fn () => $this->actingAs($this->sales)->withCookies($this->cookies);

    $as()->post('/so-team/sales', ['quotation_id' => $pickup->id, 'fulfillment_type' => 'pickup'])->assertSessionHasNoErrors();
    $as()->post('/so-team/sales', ['quotation_id' => $delivery->id, 'fulfillment_type' => 'delivery', 'delivery_address' => 'Warehouse 3, Taguig'])->assertSessionHasNoErrors();

    expect(Sale::where('quotation_id', $pickup->id)->sole()->fulfillment_type)->toBe('pickup')
        ->and(Sale::where('quotation_id', $delivery->id)->sole()->address)->toBe('Warehouse 3, Taguig')
        ->and($delivery->fresh()->address)->toBe('12 Site Rd, Pasig City'); // the quotation is not changed
});

it('checks eligibility on the server: only an accepted quotation of this team, and a quotation is required', function (QuotationStatus $status) {
    $quotation = soQuotation($this->team, $status, [[$this->breaker, 1, 20]], 'Q-'.$status->value);
    $as = fn () => $this->actingAs($this->sales)->withCookies($this->cookies);

    $as()->post('/so-team/sales', ['quotation_id' => $quotation->id])->assertSessionHasErrors('error');
    $as()->post('/so-team/sales', [])->assertSessionHasErrors(['quotation_id' => 'Select a quotation.']);
    $other = Team::factory()->create();
    $foreign = soQuotation($other, QuotationStatus::Accepted, [[$this->breaker, 1, 20]], 'Q-FOREIGN');
    $as()->post('/so-team/sales', ['quotation_id' => $foreign->id])->assertSessionHasErrors('quotation_id');

    expect(Sale::count())->toBe(0)->and($quotation->fresh()->converted_to_sale_id)->toBeNull();
})->with([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Rejected, QuotationStatus::Expired]);

it('no longer records counter sales: the old POS payload is refused and nothing is saved', function () {
    $this->actingAs($this->sales)->withCookies($this->cookies)->post('/so-team/sales', [
        'customer' => 'Walk-in Juan', 'payment_method' => 'GCash', 'payment_reference' => 'GC-1', 'amount_paid' => 60,
        'payment_date' => now()->toDateString(), 'items' => [['product_id' => $this->breaker->id, 'qty' => 3]],
    ])->assertSessionHasErrors('quotation_id');

    expect(Sale::count())->toBe(0)->and(Payment::count())->toBe(0)->and($this->breaker->fresh()->stock)->toBe(50);
});

it('recording the customer acceptance does not create the order; the quotation then waits in Sales', function () {
    [$quoter, $quoterCookies] = soActor('staff_quotations', $this->team);
    $quotation = soQuotation($this->team, QuotationStatus::Sent, [[$this->breaker, 2, 20]], 'Q-WAIT');

    $this->actingAs($quoter)->withCookies($quoterCookies)
        ->post("/so-team/quotations/{$quotation->id}/decision", ['decision' => 'accepted', 'source' => 'email'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Sales → New Sales Order'));

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted)->and(Sale::count())->toBe(0)
        ->and(collect(soPage($this)['quotations'])->pluck('quotation_number')->all())->toBe(['Q-WAIT']);
});

it('sales staff cannot delete a sales order; only admins can', function () {
    $sale = Sale::create(['team_id' => $this->team->id, 'invoice_number' => 'INV-TEST-1', 'client_name' => 'Someone', 'sale_date' => now(),
        'subtotal' => 100, 'discount' => 0, 'tax_amount' => 0, 'grand_total' => 100, 'status' => 'completed']);

    $this->actingAs($this->sales)->withCookies($this->cookies)->delete("/so-team/sales/{$sale->id}")->assertForbidden();
    expect(Sale::find($sale->id))->not->toBeNull();
});
