<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\BiddingEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBid;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Capstone/helpers.php';

beforeEach(function () {
    capAssertIsolatedDatabase();
    Carbon::setTestNow('2026-11-17 09:00:00');
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'bid-team']);
    $this->product = capProduct($this->team, ['unit' => 'pcs'], '2020-01-01');
    $this->owner = biddingActor($this->team, 'admin');
    $this->buyer = biddingActor($this->team, 'staff_procurement');
    $this->sales = biddingActor($this->team, 'staff_sales');
    $this->suppliers = collect(['Alpha Wire', 'Bravo Cable', 'Charlie Lighting'])->map(fn ($n) => Supplier::create(['team_id' => $this->team->id, 'name' => $n, 'email' => strtolower(str_replace(' ', '', $n)).'@example.test', 'status' => 'Active']));
    $this->pr = biddingRequest($this, PurchaseRequest::APPROVED, 100);
});

afterEach(fn () => Carbon::setTestNow());

function biddingActor(Team $team, string $role): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function biddingRequest($t, string $status, int $quantity): PurchaseRequest
{
    return PurchaseRequest::create([
        'team_id' => $t->team->id, 'request_number' => 'PR-'.uniqid(), 'product_id' => $t->product->id, 'requested_by_id' => $t->buyer[0]->id,
        'quantity' => $quantity, 'unit' => 'pcs', 'estimated_cost' => 500, 'status' => $status, 'requested_at' => now(),
    ]);
}

function as_($t, string $who)
{
    return $t->actingAs($t->{$who}[0])->withCookies($t->{$who}[1]);
}

/** Create an event for $this->pr inviting the first $count suppliers, optionally issued. */
function biddingEvent($t, int $count = 3, bool $issue = true, ?PurchaseRequest $pr = null): BiddingEvent
{
    as_($t, 'buyer')->post('/bid-team/procurement/bidding', [
        'purchase_request_id' => ($pr ?? $t->pr)->id,
        'supplier_ids' => $t->suppliers->take($count)->pluck('id')->all(),
        'submission_deadline' => now()->addDays(7)->format('Y-m-d H:i:s'),
        'rfq_notes' => 'Deliver to the Tondo warehouse.',
    ])->assertSessionHasNoErrors();
    $event = BiddingEvent::latest('id')->firstOrFail();
    if ($issue) {
        as_($t, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/issue")->assertSessionHasNoErrors();
    }

    return $event->fresh();
}

function bidPayload(int $supplierId, array $over = []): array
{
    return $over + ['supplier_id' => $supplierId, 'unit_price' => 12.50, 'quantity' => 100, 'lead_time_days' => 10, 'quotation_date' => '2026-11-16', 'received_at' => '2026-11-16 15:00:00', 'valid_until' => '2026-12-31', 'quotation_reference' => 'Q-100'];
}

function recordBid($t, BiddingEvent $event, int $idx, array $over = [])
{
    return as_($t, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/bids", bidPayload($t->suppliers[$idx]->id, $over));
}

it('keeps the current approval behaviour but lets the owner approve a request for bidding with no PO', function () {
    $direct = biddingRequest($this, PurchaseRequest::PENDING, 5);
    as_($this, 'owner')->post("/bid-team/procurement/requests/{$direct->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($direct->fresh()->status)->toBe(PurchaseRequest::CONVERTED)->and(PurchaseOrder::where('purchase_request_id', $direct->id)->exists())->toBeTrue();

    $forBidding = biddingRequest($this, PurchaseRequest::PENDING, 5);
    as_($this, 'owner')->post("/bid-team/procurement/requests/{$forBidding->id}/review", ['decision' => 'approve', 'for_bidding' => true])->assertSessionHasNoErrors();
    expect($forBidding->fresh()->status)->toBe(PurchaseRequest::APPROVED)->and(PurchaseOrder::where('purchase_request_id', $forBidding->id)->exists())->toBeFalse();
});

it('only lets approved requests without a purchase order go to bidding, once', function () {
    $pending = biddingRequest($this, PurchaseRequest::PENDING, 10);
    as_($this, 'buyer')->post('/bid-team/procurement/bidding', ['purchase_request_id' => $pending->id, 'supplier_ids' => [$this->suppliers[0]->id], 'submission_deadline' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('purchase_request_id');
    expect(BiddingEvent::count())->toBe(0);

    $event = biddingEvent($this, 3, false);
    as_($this, 'buyer')->post('/bid-team/procurement/bidding', ['purchase_request_id' => $this->pr->id, 'supplier_ids' => [$this->suppliers[0]->id], 'submission_deadline' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('purchase_request_id');
    expect(BiddingEvent::count())->toBe(1)->and($event->reference)->toBe('BID-2026-0001');
});

it('requires future deadlines, registered active suppliers and at least two invited before the RFQ is issued', function () {
    $r = fn (array $o) => as_($this, 'buyer')->post('/bid-team/procurement/bidding', $o + ['purchase_request_id' => $this->pr->id, 'supplier_ids' => [$this->suppliers[0]->id], 'submission_deadline' => now()->addDay()->toDateTimeString()]);
    $r(['submission_deadline' => now()->subDay()->toDateTimeString()])->assertSessionHasErrors('submission_deadline');
    $this->suppliers[1]->update(['status' => 'Inactive']);
    $r(['supplier_ids' => [$this->suppliers[1]->id]])->assertSessionHasErrors('supplier_ids');
    $r(['supplier_ids' => [999999]])->assertSessionHasErrors('supplier_ids');
    expect(BiddingEvent::count())->toBe(0);

    $this->suppliers[1]->update(['status' => 'Active']);
    $event = biddingEvent($this, 1, false);
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/issue")->assertSessionHasErrors('suppliers');
    expect($event->fresh()->status)->toBe(BiddingEvent::DRAFT);
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/suppliers", ['supplier_id' => $this->suppliers[1]->id])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/issue")->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(BiddingEvent::OPEN);
});

it('records quotations only from invited suppliers, once each, only before the deadline, with valid data', function () {
    $event = biddingEvent($this, 2);
    $outsider = $this->suppliers[2];

    recordBid($this, $event, 2)->assertSessionHasErrors('supplier_id');                              // not invited
    recordBid($this, $event, 0)->assertSessionHasNoErrors();
    recordBid($this, $event, 0, ['unit_price' => 9])->assertSessionHasErrors('supplier_id');         // duplicate
    recordBid($this, $event, 1, ['unit_price' => 0])->assertSessionHasErrors('unit_price');
    recordBid($this, $event, 1, ['quantity' => 0])->assertSessionHasErrors('quantity');
    recordBid($this, $event, 1, ['lead_time_days' => -1])->assertSessionHasErrors('lead_time_days');
    recordBid($this, $event, 1, ['quotation_date' => '2026-12-01'])->assertSessionHasErrors('quotation_date');   // future
    recordBid($this, $event, 1, ['valid_until' => '2026-11-01'])->assertSessionHasErrors('valid_until');        // before quotation date
    recordBid($this, $event, 1, ['received_at' => now()->addDays(8)->toDateTimeString()])->assertSessionHasErrors('received_at');
    expect(SupplierBid::count())->toBe(1);

    // A deadline that has passed refuses a quotation received after it, but accepts one that arrived in time.
    Carbon::setTestNow('2026-11-25 10:00:00');
    recordBid($this, $event, 1, ['received_at' => '2026-11-26 09:00:00'])->assertSessionHasErrors('received_at');
    recordBid($this, $event, 1, ['received_at' => '2026-11-23 16:30:00'])->assertSessionHasNoErrors();
    expect(SupplierBid::count())->toBe(2)->and($outsider->fresh()->bids ?? null)->toBeNull();
});

it('keeps a revision history: a reason is required, the revision count rises and old values stay in the audit trail', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0)->assertSessionHasNoErrors();
    $bid = SupplierBid::firstOrFail();

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/bids/{$bid->id}", bidPayload($this->suppliers[0]->id, ['unit_price' => 11.00]))->assertSessionHasErrors('reason');
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/bids/{$bid->id}", bidPayload($this->suppliers[0]->id, ['unit_price' => 11.00]) + ['reason' => 'Supplier emailed a lower price'])->assertSessionHasNoErrors();

    expect($bid->fresh()->revision)->toBe(2)->and((float) $bid->fresh()->unit_price)->toBe(11.0);
    $log = AuditLog::where('event', 'bidding.bid_revised')->firstOrFail();
    expect($log->metadata['before']['unit_price'])->toEqual('12.50')->and($log->metadata['reason'])->toBe('Supplier emailed a lower price');
    expect(AuditLog::where('event', 'bidding.bid_recorded')->count())->toBe(1);
});

it('stores an attached quotation document and lets staff download it', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0, ['document' => UploadedFile::fake()->create('alpha-quote.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();
    $bid = SupplierBid::firstOrFail();
    Storage::disk('local')->assertExists($bid->document_path);
    expect($bid->document_name)->toBe('alpha-quote.pdf');
    as_($this, 'buyer')->get("/bid-team/procurement/bidding/{$event->id}/bids/{$bid->id}/document")->assertOk();

    recordBid($this, $event, 1, ['document' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('document');
});

it('compares the recorded quotations accurately without awarding anything', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0, ['unit_price' => 12.00, 'lead_time_days' => 14])->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['unit_price' => 10.00, 'lead_time_days' => 21, 'quantity' => 80])->assertSessionHasNoErrors();
    recordBid($this, $event, 2, ['unit_price' => 11.00, 'lead_time_days' => 7])->assertSessionHasNoErrors();

    $comparison = app(\App\Services\Procurement\BiddingWorkflow::class)->comparison($event->fresh());
    $rows = collect($comparison['rows']);
    expect($rows->pluck('supplier.name')->all())->toBe(['Bravo Cable', 'Charlie Lighting', 'Alpha Wire'])      // by price
        ->and($rows[0])->toMatchArray(['unit_price' => 10.0, 'total' => 800.0, 'is_lowest_price' => true, 'quantity_short' => 20, 'is_shortest_lead_time' => false])
        ->and($rows[1])->toMatchArray(['is_shortest_lead_time' => true, 'price_above_lowest_pct' => 10.0, 'total' => 1100.0])
        ->and($comparison['lowest_price'])->toBe(10.0)->and($comparison['shortest_lead_time'])->toBe(7)
        ->and($event->fresh()->status)->toBe(BiddingEvent::OPEN)
        ->and(SupplierBid::where('status', SupplierBid::AWARDED)->count())->toBe(0)
        ->and(PurchaseOrder::where('purchase_request_id', $this->pr->id)->exists())->toBeFalse();
    as_($this, 'buyer')->get("/bid-team/procurement/bidding/{$event->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Procurement/Bidding/Show')->has('comparison.rows', 3));
});

it('lets only the owner award, only after closing, and never lets staff bypass approval', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0)->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['unit_price' => 11])->assertSessionHasNoErrors();
    $winner = SupplierBid::where('supplier_id', $this->suppliers[1]->id)->firstOrFail();

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id])->assertForbidden();
    as_($this, 'sales')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id])->assertForbidden();
    as_($this, 'sales')->get('/bid-team/procurement/bidding')->assertForbidden();
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id])->assertSessionHasErrors('bid_id');   // still open
    expect(PurchaseOrder::where('purchase_request_id', $this->pr->id)->exists())->toBeFalse()->and($event->fresh()->status)->toBe(BiddingEvent::OPEN);

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id])->assertForbidden();
    expect($event->fresh()->status)->toBe(BiddingEvent::CLOSED)->and(PurchaseOrder::count())->toBe(0);
});

it('awards to the chosen supplier and drafts a purchase order that inherits the awarded quotation', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0, ['unit_price' => 12.00, 'lead_time_days' => 14])->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['unit_price' => 10.50, 'lead_time_days' => 9, 'quotation_reference' => 'BRV-7'])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    $winner = SupplierBid::where('supplier_id', $this->suppliers[1]->id)->firstOrFail();

    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id, 'remarks' => 'Best price and fast delivery'])->assertSessionHasNoErrors();

    $event->refresh();
    $po = PurchaseOrder::where('purchase_request_id', $this->pr->id)->firstOrFail();
    expect($event->status)->toBe(BiddingEvent::AWARDED)->and($event->awarded_by_id)->toBe($this->owner[0]->id)->and($event->awarded_at)->not->toBeNull()
        ->and($event->purchase_order_id)->toBe($po->id)->and($event->award_remarks)->toBe('Best price and fast delivery')
        ->and($event->award_snapshot)->toHaveCount(2)
        ->and($po->supplier_id)->toBe($this->suppliers[1]->id)->and((float) $po->unit_cost)->toBe(10.5)->and($po->quantity)->toBe(100)
        ->and((float) $po->total_cost)->toBe(1050.0)->and($po->status)->toBe(PurchaseOrder::DRAFT)->and($po->product_id)->toBe($this->product->id)
        ->and($po->promised_delivery_date->toDateString())->toBe('2026-11-26')->and($po->notes)->toContain('BID-2026-0001')->toContain('BRV-7')
        ->and($this->pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED)
        ->and($winner->fresh()->status)->toBe(SupplierBid::AWARDED)
        ->and(SupplierBid::where('supplier_id', $this->suppliers[0]->id)->value('status'))->toBe(SupplierBid::NOT_AWARDED);
    expect(AuditLog::where('event', 'bidding.awarded')->count())->toBe(1);

    // Final: no second award, no more quotations, and the request cannot return to bidding.
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $winner->id])->assertSessionHasErrors();
    recordBid($this, $event, 2)->assertSessionHasErrors();
    expect(PurchaseOrder::where('purchase_request_id', $this->pr->id)->count())->toBe(1);
});

it('asks for remarks when awarding a sole quotation, a partial quantity, and refuses an expired quotation', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0, ['quantity' => 60, 'valid_until' => '2026-11-18'])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    $bid = SupplierBid::firstOrFail();

    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $bid->id])->assertSessionHasErrors('remarks');
    Carbon::setTestNow('2026-11-20 09:00:00');
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $bid->id, 'remarks' => 'Only supplier available'])->assertSessionHasErrors('bid_id');
    expect(PurchaseOrder::count())->toBe(0);

    Carbon::setTestNow('2026-11-17 12:00:00');
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => $bid->id, 'remarks' => 'Only supplier available; balance of 40 to be re-requested'])->assertSessionHasNoErrors();
    expect(PurchaseOrder::firstOrFail()->quantity)->toBe(60)->and(PurchaseOrder::first()->notes)->toContain('60 of 100');
});

it('can cancel a bidding with a reason and then send the same request to bidding again', function () {
    $event = biddingEvent($this);
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/cancel", ['reason' => 'Specification changed'])->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(BiddingEvent::CANCELLED)->and($this->pr->fresh()->status)->toBe(PurchaseRequest::APPROVED);

    $second = biddingEvent($this, 2, true);
    expect($second->reference)->toBe('BID-2026-0002')->and($second->status)->toBe(BiddingEvent::OPEN);
});

it('produces an RFQ PDF once issued, with no internal cost data', function () {
    $draft = biddingEvent($this, 3, false);
    as_($this, 'buyer')->get("/bid-team/procurement/bidding/{$draft->id}/rfq")->assertStatus(422);
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$draft->id}/issue")->assertSessionHasNoErrors();
    $response = as_($this, 'buyer')->get("/bid-team/procurement/bidding/{$draft->id}/rfq");
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

it('lists events with their status, invited and quotation counts', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0)->assertSessionHasNoErrors();
    as_($this, 'buyer')->get('/bid-team/procurement/bidding')->assertOk()->assertInertia(fn ($p) => $p
        ->component('Procurement/Bidding/Index')->where('events.data.0.reference', 'BID-2026-0001')->where('events.data.0.invited_count', 3)->where('events.data.0.bid_count', 1)
        ->where('events.data.0.status', BiddingEvent::OPEN)->has('eligibleRequests', 0));
    $history = as_($this, 'buyer')->get("/bid-team/procurement/bidding/{$event->id}")->viewData('page')['props']['history'];
    expect(collect($history)->pluck('event')->all())->toBe(['bidding.created', 'bidding.issued', 'bidding.bid_recorded']);
});

it('carries an awarded order through the existing receiving flow: only accepted units reach inventory', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0, ['unit_price' => 9.75])->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['unit_price' => 11])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => SupplierBid::where('supplier_id', $this->suppliers[0]->id)->value('id')])->assertSessionHasNoErrors();
    $po = PurchaseOrder::where('purchase_request_id', $this->pr->id)->firstOrFail();

    // Staff review and send the drafted order: the awarded supplier and price are already on it.
    as_($this, 'buyer')->post("/bid-team/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::SENT)->and((float) $po->fresh()->unit_cost)->toBe(9.75)->and($po->fresh()->supplier_id)->toBe($this->suppliers[0]->id);

    $stockBefore = $this->product->fresh()->stock;
    $workflow = app(\App\Services\Procurement\ProcurementWorkflow::class);
    $delivery = $workflow->recordDelivery($po->fresh(), $this->buyer[0], ['delivery_date' => '2026-11-17', 'quantity' => 100]);
    expect($this->product->fresh()->stock)->toBe($stockBefore);                       // received but not yet inspected

    $workflow->inspect($delivery, $this->buyer[0], ['accepted_quantity' => 90, 'rejected_quantity' => 10, 'damaged_quantity' => 10, 'inspected_on' => '2026-11-17', 'rejection_reason' => 'Crushed in transit', 'rejection_category' => 'damaged']);
    expect($this->product->fresh()->stock)->toBe($stockBefore + 90)                   // the 10 rejected units never reach stock
        ->and($po->fresh()->supplier_id)->toBe($this->suppliers[0]->id);
});

it('submits several products in one purchase request submission, one request per product', function () {
    $second = capProduct($this->team, ['unit' => 'box'], '2020-01-01');
    $this->pr->update(['status' => PurchaseRequest::REJECTED]);            // free the first product from its open request
    $lines = [
        ['product_id' => $this->product->id, 'quantity' => 12, 'justification' => 'Low stock'],
        ['product_id' => $second->id, 'quantity' => 5, 'justification' => 'Project order'],
        ['product_id' => null, 'quantity' => 3, 'unit' => 'pcs', 'product_description' => 'Special 40A breaker', 'justification' => 'Customer request'],
    ];

    as_($this, 'buyer')->post('/bid-team/procurement/requests', ['lines' => $lines, 'draft' => false])->assertSessionHasNoErrors();
    $created = PurchaseRequest::where('status', PurchaseRequest::PENDING)->orderBy('id')->get();
    expect($created)->toHaveCount(3)
        ->and($created->pluck('quantity')->all())->toBe([12, 5, 3])
        ->and($created->pluck('product_id')->all())->toBe([$this->product->id, $second->id, null])
        ->and($created->pluck('request_number')->unique())->toHaveCount(3);

    // The same product twice in one submission, or one that already has an open request, is refused as a whole.
    $before = PurchaseRequest::count();
    $third = capProduct($this->team, ['unit' => 'pcs'], '2020-01-01');
    as_($this, 'buyer')->post('/bid-team/procurement/requests', ['lines' => [
        ['product_id' => $third->id, 'quantity' => 1, 'justification' => 'x'], ['product_id' => $third->id, 'quantity' => 2, 'justification' => 'y'],
    ]])->assertSessionHasErrors('lines');
    as_($this, 'buyer')->post('/bid-team/procurement/requests', ['lines' => [
        ['product_id' => $third->id, 'quantity' => 1, 'justification' => 'x'], ['product_id' => $second->id, 'quantity' => 2, 'justification' => 'y'],
    ]])->assertSessionHasErrors();
    expect(PurchaseRequest::count())->toBe($before);                    // nothing partial was saved
});

it('lets only administrators delete a bidding event, and the delete control is only offered to them', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0)->assertSessionHasNoErrors();

    // Staff are refused (403), the event stays, and the page tells the UI not to show Delete.
    as_($this, 'buyer')->delete("/bid-team/procurement/bidding/{$event->id}", ['confirmation' => 'YES'])->assertForbidden();

    // The owner must type YES, like every other delete in the system: no confirmation, or the wrong word, deletes nothing.
    as_($this, 'owner')->delete("/bid-team/procurement/bidding/{$event->id}")->assertSessionHasErrors('confirmation');
    as_($this, 'owner')->delete("/bid-team/procurement/bidding/{$event->id}", ['confirmation' => 'yes'])->assertSessionHasErrors('confirmation');
    expect(BiddingEvent::find($event->id))->not->toBeNull();

    expect(BiddingEvent::find($event->id))->not->toBeNull();
    as_($this, 'buyer')->get('/bid-team/procurement/bidding')->assertInertia(fn ($p) => $p->where('auth.can_delete', false));
    as_($this, 'owner')->get('/bid-team/procurement/bidding')->assertInertia(fn ($p) => $p->where('auth.can_delete', true));

    as_($this, 'owner')->delete("/bid-team/procurement/bidding/{$event->id}", ['confirmation' => 'YES'])->assertRedirect('/bid-team/procurement/bidding')->assertSessionHasNoErrors();
    $archived = BiddingEvent::withTrashed()->find($event->id);
    expect(BiddingEvent::find($event->id))->toBeNull()->and($archived->deleted_by_id)->toBe($this->owner[0]->id)
        ->and(SupplierBid::where('bidding_event_id', $event->id)->count())->toBe(1)                 // quotations are archived with it, not destroyed
        ->and(AuditLog::where('event', 'bidding.deleted')->count())->toBe(1);

    // The purchase request is waiting for bidding again and a new event can be created.
    as_($this, 'buyer')->get('/bid-team/procurement/bidding')->assertInertia(fn ($p) => $p->has('eligibleRequests', 1)->has('events.data', 0));
    expect(biddingEvent($this, 2, false)->reference)->toBe('BID-2026-0002');
});

it('keeps an awarded bidding event: it cannot be deleted', function () {
    $event = biddingEvent($this);
    recordBid($this, $event, 0)->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['unit_price' => 11])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    as_($this, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => SupplierBid::where('supplier_id', $this->suppliers[0]->id)->value('id')])->assertSessionHasNoErrors();

    as_($this, 'owner')->delete("/bid-team/procurement/bidding/{$event->id}", ['confirmation' => 'YES'])->assertSessionHasErrors('error');
    expect(BiddingEvent::find($event->id))->not->toBeNull()->and($event->fresh()->purchase_order_id)->not->toBeNull();
});

it('lists a deleted bidding event in the Recycle Bin and restores it unless the request moved on', function () {
    $event = biddingEvent($this);
    as_($this, 'owner')->delete("/bid-team/procurement/bidding/{$event->id}", ['confirmation' => 'YES'])->assertSessionHasNoErrors();
    as_($this, 'owner')->get('/bid-team/recycle-bin')->assertOk();

    // Another bidding was raised for the same request meanwhile: restoring would create two live ones.
    $second = biddingEvent($this, 2, false);
    $response = as_($this, 'owner')->post("/bid-team/recycle-bin/bidding_event/{$event->id}/restore");
    expect(BiddingEvent::find($event->id))->toBeNull();
    $response->assertSessionHas('error');

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$second->id}/cancel", ['reason' => 'Changed my mind'])->assertSessionHasNoErrors();
    as_($this, 'owner')->post("/bid-team/recycle-bin/bidding_event/{$event->id}/restore")->assertSessionHas('success');
    expect(BiddingEvent::find($event->id))->not->toBeNull();
});

it('treats times as exact instants, so a user ahead of the server time zone can record a quotation received just now', function () {
    // The server clock is 2026-11-17 09:00 UTC, which is 17:00 in Manila (UTC+8).
    $event = biddingEvent($this);

    // "Now" and half an hour ago on a Manila clock, sent as the browser does (an exact UTC instant).
    recordBid($this, $event, 0, ['received_at' => now()->toIso8601ZuluString()])->assertSessionHasNoErrors();
    recordBid($this, $event, 1, ['received_at' => '2026-11-17T16:30:00+08:00'])->assertSessionHasNoErrors();
    expect(SupplierBid::where('supplier_id', $this->suppliers[1]->id)->first()->received_at->utc()->format('H:i'))->toBe('08:30');

    // A moment that really is in the future is still refused.
    $event2 = biddingEvent($this, 2, true, biddingRequest($this, PurchaseRequest::APPROVED, 10));
    recordBid($this, $event2, 0, ['received_at' => '2026-11-17T18:30:00+08:00'])->assertSessionHasErrors('received_at');

    // A quotation dated today in Manila is accepted even when it is still yesterday in UTC.
    Carbon::setTestNow('2026-11-17 00:30:00');
    recordBid($this, $event2, 1, ['quotation_date' => '2026-11-17', 'received_at' => '2026-11-17T08:00:00+08:00'])->assertSessionHasNoErrors();
});

/** A closed bidding awarded to the first supplier (lead time $lead days), with the drafted purchase order. */
function awardedOrder($t, int $lead = 10, ?PurchaseRequest $pr = null): array
{
    $pr ??= $t->pr;
    $event = biddingEvent($t, 3, true, $pr);
    recordBid($t, $event, 0, ['unit_price' => 10.00, 'lead_time_days' => $lead])->assertSessionHasNoErrors();
    recordBid($t, $event, 1, ['unit_price' => 12.50, 'lead_time_days' => 5])->assertSessionHasNoErrors();
    recordBid($t, $event, 2, ['unit_price' => 15.00, 'lead_time_days' => 20])->assertSessionHasNoErrors();
    as_($t, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/close")->assertSessionHasNoErrors();
    as_($t, 'owner')->post("/bid-team/procurement/bidding/{$event->id}/award", ['bid_id' => SupplierBid::where('bidding_event_id', $event->id)->where('supplier_id', $t->suppliers[0]->id)->value('id')])->assertSessionHasNoErrors();

    return [$event->fresh(), PurchaseOrder::where('purchase_request_id', $pr->id)->firstOrFail()];
}

function biddingReceivedDelivery($t, PurchaseOrder $po, string $number, string $date, int $quantity, int $accepted)
{
    return \App\Models\Delivery::create(['team_id' => $t->team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => $po->po_number, 'unit' => 'pcs', 'description' => 'P',
        'supplier_id' => $po->supplier_id, 'status' => 'Delivered', 'delivery_number' => $number, 'delivery_date' => $date, 'quantity' => $quantity,
        'accepted_quantity' => $accepted, 'rejected_quantity' => $quantity - $accepted, 'rejection_reason' => $accepted < $quantity ? 'Damaged in transit' : null,
        'inspection_result' => $accepted === $quantity ? 'accepted' : 'partially_accepted', 'inspected_on' => $date]);
}

it('scores price competitiveness from the quotations in finished biddings: own price against the lowest bid', function () {
    [$event] = awardedOrder($this);                                   // Alpha 10.00, Bravo 12.50, Charlie 15.00
    $evaluator = app(\App\Services\Suppliers\SupplierEvaluator::class);
    $price = fn (int $i) => collect($evaluator->forTeam($this->team->id)[$this->suppliers[$i]->id]['criteria'])->firstWhere('key', 'price');

    expect($price(0)['score'])->toEqual(100.0)
        ->and($price(1)['score'])->toEqual(80.0)                      // 10 / 12.5
        ->and($price(2)['score'])->toEqual(66.67)                     // 10 / 15
        ->and($price(1)['inputs']['comparisons'][0])->toMatchArray(['source' => 'bidding', 'basis' => 'bidding '.$event->reference, 'own_price' => 12.5, 'lowest_price' => 10.0]);

    // Not finished comparisons: a bidding still open, one cancelled, and one with a single quotation.
    $open = biddingEvent($this, 2, true, biddingRequest($this, PurchaseRequest::APPROVED, 5));
    recordBid($this, $open, 0, ['unit_price' => 1])->assertSessionHasNoErrors();
    recordBid($this, $open, 1, ['unit_price' => 100])->assertSessionHasNoErrors();
    $cancelled = biddingEvent($this, 2, true, biddingRequest($this, PurchaseRequest::APPROVED, 5));
    recordBid($this, $cancelled, 0, ['unit_price' => 1])->assertSessionHasNoErrors();
    recordBid($this, $cancelled, 1, ['unit_price' => 100])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$cancelled->id}/cancel", ['reason' => 'Spec changed'])->assertSessionHasNoErrors();
    $single = biddingEvent($this, 2, true, biddingRequest($this, PurchaseRequest::APPROVED, 5));
    recordBid($this, $single, 0, ['unit_price' => 1])->assertSessionHasNoErrors();
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$single->id}/close")->assertSessionHasNoErrors();

    expect($price(1)['inputs']['comparisons'])->toHaveCount(1)->and($price(1)['score'])->toEqual(80.0);
});

it('averages bidding prices with the product purchase prices of accepted deliveries', function () {
    awardedOrder($this);
    // Product prices: Alpha was paid 1100 per unit, Bravo 1000, for the same product and unit (accepted inbound deliveries).
    foreach ([[0, 1100, 'PO-X1', 'D-X1'], [1, 1000, 'PO-X2', 'D-X2']] as [$i, $cost, $poNo, $dNo]) {
        $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-10-01', 'po_number' => $poNo, 'quantity' => 10, 'product_id' => $this->product->id, 'description' => 'P', 'unit_cost' => $cost, 'total_cost' => 10 * $cost, 'supplier_id' => $this->suppliers[$i]->id, 'status' => PurchaseOrder::FULL]);
        biddingReceivedDelivery($this, $po, $dNo, '2026-10-10', 10, 10);
    }
    $price = collect(app(\App\Services\Suppliers\SupplierEvaluator::class)->forTeam($this->team->id)[$this->suppliers[0]->id]['criteria'])->firstWhere('key', 'price');

    // Alpha: bidding 100.00 and product price 1000/1100 = 90.91 → average 95.46 (two comparisons, one of each kind).
    expect($price['inputs']['comparisons'])->toHaveCount(2)
        ->and(collect($price['inputs']['comparisons'])->pluck('source')->sort()->values()->all())->toBe(['bidding', 'purchase'])
        ->and($price['score'])->toEqual(95.46);
});

it('measures delivery time against the lead time quoted in the bid, counted from the day the order was sent', function () {
    [$event, $po] = awardedOrder($this, 10);                                   // awarded 2026-11-17, quoted 10 days
    expect($po->promised_delivery_date->toDateString())->toBe('2026-11-27');   // the award's own default

    Carbon::setTestNow('2026-11-20 09:00:00');                                 // the order is sent three days later
    as_($this, 'buyer')->post("/bid-team/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->promised_delivery_date->toDateString())->toBe('2026-11-30')   // send date + 10 quoted days
        ->and(AuditLog::where('event', 'purchase_order.promised_date_aligned')->count())->toBe(1);

    // Two inbound deliveries: 8 days after sending (on time) and 12 days after (late), both fully accepted.
    biddingReceivedDelivery($this, $po->fresh(), 'D-A', '2026-11-28', 60, 60);
    biddingReceivedDelivery($this, $po->fresh(), 'D-B', '2026-12-02', 40, 40);
    Carbon::setTestNow('2026-12-05 09:00:00');

    $evaluation = app(\App\Services\Suppliers\SupplierEvaluator::class)->forTeam($this->team->id)[$this->suppliers[0]->id];
    $delivery = collect($evaluation['criteria'])->firstWhere('key', 'delivery');
    $records = collect($delivery['inputs']['records'])->keyBy('delivery_number');

    expect($records['D-A'])->toMatchArray(['promised' => '2026-11-30', 'delivered' => '2026-11-28', 'on_time' => true, 'bidding' => $event->reference, 'quoted_lead_days' => 10, 'actual_lead_days' => 8])
        ->and($records['D-B'])->toMatchArray(['delivered' => '2026-12-02', 'on_time' => false, 'quoted_lead_days' => 10, 'actual_lead_days' => 12])
        ->and($delivery['inputs']['bidding_deliveries'])->toBe(2)
        ->and($delivery['inputs']['on_time_rate'])->toEqual(50.0)
        ->and($delivery['score'])->toEqual(70.0)                                // 50 × 0.6 + 100 × 0.4
        ->and(collect($evaluation['criteria'])->firstWhere('key', 'quality')['score'])->toEqual(100.0);
});

it('scores quality from the inbound inspection and keeps a promised date staff changed on purpose', function () {
    [$event, $po] = awardedOrder($this, 10);
    $po->update(['promised_delivery_date' => '2026-12-15']);                    // staff agreed a different date with the supplier
    Carbon::setTestNow('2026-11-20 09:00:00');
    as_($this, 'buyer')->post("/bid-team/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->promised_delivery_date->toDateString())->toBe('2026-12-15');

    Carbon::setTestNow('2026-12-01 09:00:00');
    biddingReceivedDelivery($this, $po->fresh(), 'D-Q', '2026-11-25', 100, 90);        // 90 accepted, 10 rejected at inspection
    $quality = collect(app(\App\Services\Suppliers\SupplierEvaluator::class)->forTeam($this->team->id)[$this->suppliers[0]->id]['criteria'])->firstWhere('key', 'quality');
    expect($quality['inputs'])->toMatchArray(['accepted' => 90, 'rejected' => 10])->and($quality['score'])->toEqual(90.0);
});

it('invites several suppliers at once from the checklist, all or nothing', function () {
    $more = collect(['Delta Electric', 'Echo Supply'])->map(fn ($n) => Supplier::create(['team_id' => $this->team->id, 'name' => $n, 'status' => 'Active']));
    $event = biddingEvent($this, 1, false);

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/suppliers", ['supplier_ids' => [$this->suppliers[1]->id, $more[0]->id, $more[1]->id]])
        ->assertSessionHasNoErrors()->assertSessionHas('success', '3 suppliers invited.');
    expect($event->invitations()->count())->toBe(4);

    // One already invited: nothing from this batch is added.
    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/suppliers", ['supplier_ids' => [$this->suppliers[2]->id, $more[0]->id]])
        ->assertSessionHasErrors('supplier_ids');
    expect($event->invitations()->count())->toBe(4);

    as_($this, 'buyer')->post("/bid-team/procurement/bidding/{$event->id}/suppliers", ['supplier_ids' => []])->assertSessionHasErrors('supplier_ids');
});
