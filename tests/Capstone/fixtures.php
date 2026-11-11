<?php

/*
 * Fixture builders shared by the capstone test suites. No database guard here: each suite loads
 * its own guard first (helpers.php for in-memory SQLite, mysql_helpers.php for mr_pride_testing).
 * Test-only file. It changes nothing in the application.
 */

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;

/** A team member with a trusted device: [User, device cookies]. */
function capActor(string $role, Team $team, array $attributes = []): array
{
    $user = User::factory()->create($attributes + ['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function capSupplier(Team $team, string $name = 'Volt Supply'): Supplier
{
    return Supplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'Active']);
}

/** An active product. $createdAt backdates it so it has history for the forecast window. */
function capProduct(Team $team, array $attributes = [], ?string $createdAt = '2025-01-01'): Product
{
    static $n = 0;
    $n++;
    $product = Product::create($attributes + [
        'team_id' => $team->id, 'sku' => sprintf('CAP-%04d', $n), 'name' => "Capstone Product {$n}", 'category' => 'Breakers',
        'price' => 100, 'cost_price' => 60, 'stock' => 10, 'reorder_level' => 2, 'status' => 'In Stock', 'is_active' => true, 'unit' => 'pcs',
    ]);
    if ($createdAt) {
        $product->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $product;
}

/** One outgoing stock movement (a demand record for the forecast). */
function capDemand(Team $team, Product $product, string $date, int $quantity, string $type = 'sale'): StockMovement
{
    return StockMovement::create([
        'team_id' => $team->id, 'product_id' => $product->id, 'type' => $type, 'quantity' => -$quantity,
        'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "CAP-{$product->sku}-{$date}",
    ]);
}

/** A quotation with one line per [product, quantity]. 12% tax, as in the application's own fixtures. */
function capQuotation(Team $team, QuotationStatus $status, array $lines, ?string $email = 'buyer@example.com'): Quotation
{
    $subtotal = collect($lines)->sum(fn ($l) => $l[0]->price * $l[1]);
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => 'Q-'.uniqid(), 'client_name' => 'Metro Builders Inc.', 'contact_person' => 'Engr. Ana Reyes',
        'email' => $email, 'phone' => '09171234567', 'address' => '12 Site Rd, Pasig City',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
        'subtotal' => $subtotal, 'discount' => 0, 'tax_rate' => 12, 'tax_amount' => round($subtotal * 0.12, 2),
        'grand_total' => round($subtotal * 1.12, 2), 'status' => $status, 'terms_and_conditions' => 'Net 30.',
        'submitted_for_approval_at' => $status === QuotationStatus::Draft ? now() : null,
    ]);
    foreach ($lines as [$product, $qty]) {
        $quotation->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty,
            'unit_price' => $product->price, 'unit_cost' => $product->cost_price, 'supplier_id' => $product->supplier_id,
            'discount' => 0, 'subtotal' => $product->price * $qty,
        ]);
    }

    return $quotation;
}

/** Accepted quotation â†’ Sales Order (pending payment), through the application's own service. */
function capOrder(Team $team, User $user, array $lines, string $type = 'delivery'): Sale
{
    $quotation = capQuotation($team, QuotationStatus::Accepted, $lines);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $user, ['fulfillment_type' => $type]);

    return $sale->fresh();
}

/** Record and verify a payment through the application's own service. */
function capPay(Sale $sale, User $user, float $amount, string $reference): PaymentReceipt
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'Bank Transfer', 'amount' => $amount, 'reference' => $reference, 'payment_date' => now()->toDateString()], null, $user);

    return $payments->verify($payment, ['reference' => $reference, 'amount' => $amount, 'payment_date' => now()->toDateString()], $user);
}

/** A confirmed (fully paid) order. */
function capConfirmed(Team $team, User $admin, array $lines, string $type = 'delivery'): Sale
{
    $sale = capOrder($team, $admin, $lines, $type);
    capPay($sale, $admin, (float) $sale->grand_total, 'REF-'.uniqid());

    return $sale->fresh();
}

/**
 * A purchase order with its inspected inbound deliveries, written straight to the tables the
 * Supplier Evaluation reads. Each delivery: [date, received, accepted, rejected].
 */
function capPurchaseOrderWithDeliveries(Team $team, Supplier $supplier, Product $product, array $order, array $deliveries): PurchaseOrder
{
    static $n = 0;
    $n++;
    $po = PurchaseOrder::create($order + [
        'team_id' => $team->id, 'po_date' => '2026-09-01', 'po_number' => "CAP-PO-{$n}", 'quantity' => 100, 'product_id' => $product->id,
        'description' => $product->name, 'unit_cost' => 10, 'total_cost' => 1000, 'supplier_id' => $supplier->id, 'unit_of_measure' => 'PCS',
    ]);
    foreach ($deliveries as $i => [$date, $received, $accepted, $rejected]) {
        Delivery::create([
            'team_id' => $team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => $po->po_number, 'unit' => 'pcs',
            'description' => $product->name, 'supplier_id' => $supplier->id, 'status' => 'Delivered',
            'inspection_result' => $rejected > 0 ? 'partially_accepted' : 'accepted', 'inspected_on' => $date,
            'delivery_date' => $date, 'delivery_number' => "CAP-D-{$n}-{$i}", 'quantity' => $received,
            'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'rejection_reason' => $rejected > 0 ? 'Damaged' : null,
        ]);
    }

    return $po;
}

/** The message of the `error` (or any given) session validation key after a refused request. */
function capSessionError(string $key = 'error'): string
{
    return (string) session('errors')?->first($key);
}
