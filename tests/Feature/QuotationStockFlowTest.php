<?php

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Quotation flow when products are short: Draft → stock checked → purchase request for the
 * missing quantity → admin approval → automatic draft PO → sent →
 * inbound delivery inspected → accepted units posted → quotation continued. The quotation stays
 * Draft throughout and is never sent automatically.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'qs-team']);
    $actor = function (string $role): array {
        $user = User::factory()->create(['role' => $role, 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
        $this->team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

        return [$user, createTrustedDeviceFor($user)['cookies']];
    };
    [$this->admin, $this->adminCookies] = $actor('admin');
    [$this->quoter, $this->quoterCookies] = $actor('staff_quotations');
    [$this->buyer, $this->buyerCookies] = $actor('staff_procurement');

    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $this->product = Product::create(['team_id' => $this->team->id, 'sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 20, 'cost_price' => 10,
        'stock' => 5, 'reorder_level' => 2, 'is_active' => true, 'unit' => 'pcs', 'supplier_id' => $this->supplier->id]);
    $this->inStock = Product::create(['team_id' => $this->team->id, 'sku' => 'WR-25', 'name' => 'Wire 2.5mm', 'price' => 5, 'cost_price' => 3,
        'stock' => 500, 'reorder_level' => 10, 'is_active' => true, 'unit' => 'pcs']);

    $this->quotation = Quotation::create([
        'team_id' => $this->team->id, 'quotation_number' => 'QT-STOCK-1', 'client_name' => 'Metro Builders Inc.', 'email' => 'buyer@example.com',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal' => 1650, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 1650,
        'status' => QuotationStatus::Draft, 'prepared_by_id' => $this->quoter->id,
    ]);
    foreach ([[$this->product, 80], [$this->inStock, 10]] as [$p, $qty]) {
        $this->quotation->items()->create(['product_id' => $p->id, 'sku' => $p->sku, 'product_name' => $p->name, 'quantity' => $qty, 'unit_price' => $p->price, 'discount' => 0, 'subtotal' => $p->price * $qty]);
    }
    $this->url = "/{$this->team->slug}";
});

function qsAs($t, string $who)
{
    return $t->actingAs($t->{$who})->withCookies($t->{$who.'Cookies'});
}

function qsQuotationRow($t): array
{
    $rows = qsAs($t, 'quoter')->get("{$t->url}/quotations")->assertOk()->viewData('page')['props']['quotations']['data'];

    return collect($rows)->firstWhere('id', $t->quotation->id);
}

/** The request was refused with a business-rule message containing $text. */
function qsRefused(TestResponse $response, string $text): void
{
    $response->assertSessionHasErrors('error');
    expect(session('errors')->first('error'))->toContain($text);
}

it('takes a quotation with short products through purchasing and back, staying Draft and never sending it', function () {
    // 1–2. Draft; the inventory check shows the short product and offers to request stock.
    $row = qsQuotationRow($this);
    expect($row['allowed_actions'])->toContain('request_stock')
        ->and(collect($row['items'])->firstWhere('sku', 'CB-40')['stock_status'])->toBe('Insufficient Stock')
        ->and($row['waiting_for_stock'])->toBe([]);

    // 3. Purchase request for the missing quantity only (80 needed, 5 available).
    qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock")->assertSessionHasNoErrors();
    $pr = PurchaseRequest::sole();
    expect($pr->quotation_id)->toBe($this->quotation->id)
        ->and($pr->product_id)->toBe($this->product->id)
        ->and($pr->quantity)->toBe(75)
        ->and($pr->status)->toBe(PurchaseRequest::PENDING)
        ->and($pr->justification)->toContain('QT-STOCK-1')
        ->and($this->quotation->fresh()->status)->toBe(QuotationStatus::Draft);

    // Waiting for stock: it cannot go for approval, and stock is not requested twice.
    $row = qsQuotationRow($this);
    expect($row['waiting_for_stock'])->toBe(['Circuit Breaker 40A'])
        ->and($row['allowed_actions'])->not->toContain('request_stock')->not->toContain('status:submit_for_approval');
    qsRefused(qsAs($this, 'quoter')->patch("{$this->url}/quotations/{$this->quotation->id}/status", ['status' => 'submit_for_approval']), 'Waiting for stock');
    qsRefused(qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock"), 'already being purchased');
    expect(PurchaseRequest::count())->toBe(1);

    // 4–5. Admin approves; the draft PO is created automatically; staff confirm the price and send it.
    qsAs($this, 'admin')->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    $po = PurchaseOrder::sole();
    expect($po->purchase_request_id)->toBe($pr->id)->and($po->status)->toBe(PurchaseOrder::DRAFT)->and($po->quantity)->toBe(75);
    qsAs($this, 'buyer')->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 75, 'unit_cost' => 9.5])->assertSessionHasNoErrors();
    qsAs($this, 'buyer')->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect(qsQuotationRow($this)['waiting_for_stock'])->toBe(['Circuit Breaker 40A']);

    // 7–8. Inbound delivery checked: 72 accepted go into stock at once, 3 rejected never do.
    qsAs($this, 'buyer')->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => now()->toDateString(), 'quantity' => 75])->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    qsAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 72, 'rejected_quantity' => 3, 'damaged_quantity' => 3, 'defective_quantity' => 0, 'inspected_on' => now()->toDateString(), 'rejection_reason' => 'Cracked casings'])
        ->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(77);

    // Still 3 short (77 of 80) and the supplier still owes the 3 rejected units: it keeps waiting.
    expect(qsQuotationRow($this)['waiting_for_stock'])->toBe(['Circuit Breaker 40A']);

    // The quotation staff are told the stock arrived.
    $note = $this->quoter->fresh()->notifications()->get()->firstWhere('data.event', 'quotation.stock_received');
    expect($note)->not->toBeNull()->and($note->data['message'])->toContain('QT-STOCK-1')->and($note->data['url'])->toContain('/quotations?search=QT-STOCK-1');

    // The supplier replaces the 3 rejected units: everything is in, the quotation can continue.
    qsAs($this, 'buyer')->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => now()->toDateString(), 'quantity' => 3])->assertSessionHasNoErrors();
    $second = Delivery::latest('id')->first();
    qsAs($this, 'buyer')->post("{$this->url}/deliveries/{$second->id}/inspection", ['accepted_quantity' => 3, 'rejected_quantity' => 0, 'inspected_on' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(80);
    // The 3 rejected units were replaced: recorded, so the order counts as Received.
    qsAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/resolution", ['resolution_status' => 'replaced'])->assertSessionHasNoErrors();

    // 9. Resume: no longer waiting; staff continue the quotation. It is still Draft and not sent.
    $row = qsQuotationRow($this);
    expect($row['waiting_for_stock'])->toBe([])
        ->and($row['allowed_actions'])->toContain('status:submit_for_approval')->not->toContain('request_stock')
        ->and(collect($row['items'])->firstWhere('sku', 'CB-40')['purchase'])->toBe(['number' => $po->po_number, 'status' => PurchaseOrder::FULL]);

    qsAs($this, 'quoter')->patch("{$this->url}/quotations/{$this->quotation->id}/status", ['status' => 'submit_for_approval'])->assertSessionHasNoErrors();
    $quotation = $this->quotation->fresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->submitted_for_approval_at)->not->toBeNull()
        ->and($quotation->sent_at)->toBeNull();
});

it('withdraws an earlier submission when stock is requested, and allows a new request after a rejection', function () {
    $this->quotation->update(['submitted_for_approval_at' => now(), 'approved_at' => now(), 'approved_by_id' => $this->admin->id]);

    qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock")->assertSessionHasNoErrors();
    expect($this->quotation->fresh()->submitted_for_approval_at)->toBeNull()->and($this->quotation->fresh()->approved_at)->toBeNull();

    $pr = PurchaseRequest::sole();
    qsAs($this, 'admin')->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject', 'remarks' => 'Supplier out of stock'])->assertSessionHasNoErrors();
    $row = qsQuotationRow($this);
    expect($row['waiting_for_stock'])->toBe([])->and($row['allowed_actions'])->toContain('request_stock');

    qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock")->assertSessionHasNoErrors();
    expect(PurchaseRequest::where('status', PurchaseRequest::PENDING)->count())->toBe(1);
});

it('does not request stock for a quotation that has enough, or for a product with an open request from elsewhere', function () {
    $this->quotation->items()->where('product_id', $this->product->id)->update(['quantity' => 3]);
    qsRefused(qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock"), 'in stock');

    $this->quotation->items()->where('product_id', $this->product->id)->update(['quantity' => 80]);
    PurchaseRequest::create(['team_id' => $this->team->id, 'request_number' => 'PR-OTHER', 'product_id' => $this->product->id, 'requested_by_id' => $this->buyer->id,
        'quantity' => 100, 'status' => PurchaseRequest::PENDING, 'justification' => 'Reorder', 'requested_at' => now()]);
    qsRefused(qsAs($this, 'quoter')->post("{$this->url}/quotations/{$this->quotation->id}/request-stock"), 'PR-OTHER');
    expect(PurchaseRequest::count())->toBe(1);
});
