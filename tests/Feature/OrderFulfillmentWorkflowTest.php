<?php

use App\Enums\DeliveryStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use App\Services\Sales\SalesOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function fulfillmentActor(string $role, Team $team): array
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

function fulfillmentQuotation(Team $team, Product $product, int $qty): Quotation
{
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-FULFILL-001', 'client_name' => 'Metro Power Corp',
        'contact_person' => 'Engr. Roberto Cruz', 'email' => 'roberto@metropower.example', 'phone' => '09181112233',
        'address' => 'Unit 402 Industrial Complex, Taguig City',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(14)->toDateString(),
        'subtotal' => $product->price * $qty, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => $product->price * $qty,
        'status' => QuotationStatus::Accepted,
    ]);
    $quotation->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty, 'unit_price' => $product->price, 'subtotal' => $product->price * $qty]);

    return $quotation;
}

it('creates the delivery record at Pending only once the converted order is paid', function () {
    [$user, $cookies] = fulfillmentActor('admin', $this->team);
    $product = Product::create(['team_id' => $this->team->id, 'name' => 'Heavy Duty Magnetic Contactor', 'sku' => 'HD-MC-TEST', 'price' => 1200, 'cost_price' => 800, 'stock' => 10, 'status' => 'In Stock', 'is_active' => true]);
    $quotation = fulfillmentQuotation($this->team, $product, 2);

    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/quotations/{$quotation->id}/convert-to-sale", [
        'fulfillment_type' => 'Delivery',
        'delivery_address' => 'Substation Project B, Pasig',
        'delivery_instructions' => 'Call 30 mins before arrival',
    ])->assertSessionHasNoErrors();

    $quotation->refresh();
    $sale = Sale::find($quotation->converted_to_sale_id);
    expect($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending)
        ->and($sale->address)->toBe('Substation Project B, Pasig')
        ->and($sale->fulfillment)->toBeNull()          // not confirmed yet: no delivery record
        ->and($product->fresh()->stock)->toBe(10);    // and nothing taken out of stock

    // Payment recorded and verified → confirmed → delivery record starts at Pending.
    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments", [
        'method' => 'Bank Transfer', 'amount' => 2400, 'reference' => 'BDO-777', 'payment_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();
    $payment = Payment::sole();
    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$sale->id}/payments/{$payment->id}/verify", [
        'reference' => 'BDO-777', 'amount' => 2400, 'payment_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $fulfillment = $sale->fresh()->fulfillment;
    expect($fulfillment)->not->toBeNull()
        ->and($fulfillment->status)->toBe(DeliveryStatus::Pending)
        ->and($fulfillment->fulfillment_type)->toBe('delivery')
        ->and($fulfillment->delivery_address)->toBe('Substation Project B, Pasig')
        ->and($fulfillment->instructions)->toBe('Call 30 mins before arrival')
        ->and($fulfillment->attemptLogs()->sole()->new_status)->toBe('pending')
        ->and($product->fresh()->stock)->toBe(10)
        ->and($product->fresh()->availableStock())->toBe(8); // reserved, not taken
});

it('takes stock out once, when the customer receives the goods, not when the release leaves', function () {
    [$user, $cookies] = fulfillmentActor('admin', $this->team);
    $product = Product::create(['team_id' => $this->team->id, 'name' => 'Thermal Overload Relay', 'sku' => 'TOR-TEST', 'price' => 850, 'stock' => 15, 'is_active' => true]);
    $quotation = fulfillmentQuotation($this->team, $product, 1);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $user);
    app(SalesOrderService::class)->setTerms($sale, Sale::TERMS_CREDIT, null, 15, $user);
    $fulfillment = $sale->fresh()->fulfillment;
    $line = $sale->items()->sole();
    $base = "/{$this->team->slug}/deliveries";

    $this->actingAs($user)->withCookies($cookies)->post("{$base}/fulfillments/{$fulfillment->id}/releases", ['quantities' => [$line->id => 1]])->assertSessionHasNoErrors();
    $release = $fulfillment->releases()->sole();
    $this->actingAs($user)->withCookies($cookies)->post("{$base}/releases/{$release->id}/ready")->assertSessionHasNoErrors();
    expect($product->fresh()->stock)->toBe(15);

    $this->actingAs($user)->withCookies($cookies)->post("{$base}/fulfillments/{$fulfillment->id}/start")->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->status)->toBe(DeliveryStatus::InTransit)
        ->and($fulfillment->fresh()->dispatched_at)->not->toBeNull()
        ->and($product->fresh()->stock)->toBe(15)
        ->and($product->fresh()->availableStock())->toBe(14); // on the road: still in stock, reserved

    $this->actingAs($user)->withCookies($cookies)->post("{$base}/fulfillments/{$fulfillment->id}/deliver", [
        'proof_of_delivery' => UploadedFile::fake()->image('signed_waybill.jpg', 300, 300),
        'recipient_name' => 'Site Engr Arthur',
        'remarks' => 'Client signed received copy.',
    ])->assertSessionHasNoErrors();

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe(DeliveryStatus::Delivered)
        ->and($fulfillment->delivered_at)->not->toBeNull()
        ->and($release->fresh()->proof_path)->not->toBeNull()
        ->and($product->fresh()->stock)->toBe(14)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(1);
});
