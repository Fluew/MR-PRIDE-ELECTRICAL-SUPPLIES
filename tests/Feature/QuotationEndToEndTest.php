<?php

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\ResendService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * End to end: a customer's public quotation request (RFQ) through every staff/admin step to the
 * Sales Order, driven only through the HTTP routes the buttons call. Every transition is checked,
 * the screens are re-rendered between steps (no 500s, no dead ends), and the wrong-order clicks
 * and wrong roles are refused with a message rather than a broken state.
 * The email provider is mocked: nothing leaves the machine.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->emails = collect();
    $this->mock(ResendService::class)
        ->shouldReceive('sendCustomerEmail')
        ->andReturnUsing(function ($to, $subject, $html, $kind, $attachments = []) {
            $this->emails->push(compact('to', 'subject', 'kind', 'attachments'));

            return ['success' => true, 'id' => 'e2e-'.$this->emails->count(), 'error' => null];
        });

    $this->team = Team::factory()->create(['slug' => 'e2e']);
    [$this->admin, $this->adminCookies] = e2eActor('admin', $this->team);
    [$this->staff, $this->staffCookies] = e2eActor('staff_quotations', $this->team);
    [$this->inventory, $this->inventoryCookies] = e2eActor('staff_inventory', $this->team);

    Brand::create(['name' => 'Schneider', 'category' => 'Electrical Materials', 'is_partner' => true, 'is_active' => true]);
    $this->breaker = Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-000001', 'name' => 'Circuit Breaker 2P 40A', 'brand' => 'Schneider', 'unit' => 'pcs', 'price' => 850, 'stock' => 100, 'is_active' => true]);
    $this->wire = Product::create(['team_id' => $this->team->id, 'sku' => 'WIR-000001', 'name' => 'THHN Wire 12 AWG', 'brand' => 'Phelps Dodge', 'unit' => 'roll', 'price' => 3200, 'stock' => 2, 'is_active' => true]);
});

function e2eActor(string $role, Team $team): array
{
    $user = User::factory()->create(['role' => $role, 'status' => 'active', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function e2eAs($t, string $who)
{
    return $t->actingAs($t->{$who})->withCookies($t->{$who.'Cookies'});
}

/** Guest submits the public RFQ form; returns the quotation it created. */
function e2eSubmitRfq($t, string $company, string $email): Quotation
{
    $t->post('/quotation', [
        'company' => $company,
        'contactName' => 'Engr. Ana Reyes',
        'email' => $email,
        'phone' => '0917 555 0101',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
        'brands' => [['brand' => 'Schneider', 'details' => '2-pole 40A breakers', 'quantity' => '10']],
        'products' => [['description' => 'THHN wire 12 AWG', 'quantity' => 3, 'unit' => 'roll']],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    return Quotation::where('client_name', $company)->latest('id')->firstOrFail();
}

/** The staff edit form, priced: [product, qty, price] lines. */
function e2ePricedForm(Quotation $q, array $lines, string $status = 'draft'): array
{
    return [
        'client_name' => $q->client_name, 'contact_person' => $q->contact_person, 'email' => $q->email, 'phone' => $q->phone,
        'address' => '12 Site Rd, Pasig City', 'status' => $status, 'notes' => $q->notes, 'tax_rate' => 12, 'discount' => 0,
        'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'sku' => $l[0]->sku, 'product_name' => $l[0]->name, 'quantity' => $l[1], 'unit_price' => $l[2], 'discount' => 0], $lines),
    ];
}

/** Re-render the quotations screen as the given user and return the row for $q (asserts it renders). */
function e2eRow($t, string $who, Quotation $q): array
{
    $row = null;
    e2eAs($t, $who)->get('/e2e/quotations')->assertOk()->assertInertia(function (Assert $page) use (&$row, $q) {
        $page->component('Quotations/AdminIndex');
        $data = $page->toArray()['props']['quotations'];
        $rows = $data['data'] ?? $data;
        $row = collect($rows)->firstWhere('id', $q->id);
    });
    expect($row)->not->toBeNull("Quotation {$q->quotation_number} is missing from the {$who} quotations screen");

    return $row;
}

/** The buttons the quotations screen offers $who for $q (the server's allowed_actions), sorted. */
function e2eButtons($t, string $who, Quotation $q): array
{
    $actions = e2eRow($t, $who, $q)['allowed_actions'] ?? [];
    sort($actions);

    return $actions;
}

function e2eError($response): string
{
    $errors = session('errors');
    if ($errors instanceof ViewErrorBag) {
        return collect($errors->getBag('default')->all())->implode(' ');
    }

    return collect((array) $errors)->flatten()->implode(' ');
}

it('takes a public RFQ all the way to a Sales Order through every button', function () {
    // 1. Public form renders with the catalog brands.
    $this->get('/quotation')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Quotations/Create')
        ->where('brands', fn ($b) => collect($b)->contains('Schneider') && collect($b)->contains('Phelps Dodge')));

    // 2. Guest submits the RFQ → an unpriced Draft with the request details.
    $q = e2eSubmitRfq($this, 'Metro Builders Inc.', 'buyer@metro.example');
    expect($q->status)->toBe(QuotationStatus::Draft)
        ->and((float) $q->grand_total)->toBe(0.0)
        ->and($q->request_details['source'])->toBe('public_form')
        ->and($q->items()->count())->toBeGreaterThanOrEqual(2)
        ->and($q->quotation_number)->not->toBeEmpty();

    // 3. Staff and admin both see it on the quotations screen, with the Draft buttons.
    expect(e2eButtons($this, 'staff', $q))->toBe(['edit', 'status:rejected', 'status:submit_for_approval']);
    expect(e2eButtons($this, 'admin', $q))->toBe(['edit', 'status:rejected', 'status:submit_for_approval']);

    // 4. Submitting it unpriced is refused with a clear message (no state change).
    $r = e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'submit_for_approval']);
    expect(e2eError($r))->toContain('selling price')->and($q->fresh()->submitted_for_approval_at)->toBeNull();

    // 5. Approving before it was submitted is refused.
    $r = e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve");
    expect(e2eError($r))->toContain('submitted for approval');

    // 6. Staff price it against inventory and submit for approval in the same save.
    e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->breaker, 10, 950], [$this->wire, 2, 3500]], 'submit_for_approval'))
        ->assertSessionHasNoErrors();
    $q->refresh();
    expect($q->status)->toBe(QuotationStatus::Draft)
        ->and($q->submitted_for_approval_at)->not->toBeNull()
        ->and((float) $q->subtotal)->toBe(16500.0)
        ->and((float) $q->grand_total)->toBe(18480.0); // 16,500 + 12% VAT
    // Waiting for approval: only the admin gets Approve.
    expect(e2eButtons($this, 'staff', $q))->toBe(['edit', 'status:rejected']);
    expect(e2eButtons($this, 'admin', $q))->toBe(['approve', 'edit', 'status:rejected']);

    // 7. Sending before approval is refused; staff cannot approve.
    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")))->toContain('approved');
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/approve")->assertForbidden();
    expect($this->emails)->toHaveCount(0);

    // 8. Admin approves → still Draft, Valid Until set, no email yet.
    e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve", ['remarks' => 'OK'])->assertSessionHasNoErrors();
    $q->refresh();
    expect($q->approved_at)->not->toBeNull()->and($q->valid_until)->not->toBeNull()
        ->and($q->status)->toBe(QuotationStatus::Draft)->and($this->emails)->toHaveCount(0);
    expect(e2eError(e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve")))->toContain('already approved');
    expect(e2eButtons($this, 'staff', $q))->toBe(['edit', 'send', 'status:rejected']);

    // 9. PDF preview opens.
    $pdf = e2eAs($this, 'staff')->get("/e2e/quotations/{$q->id}/pdf");
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('pdf');

    // 10. Send Quotation → emailed with the PDF, now Sent.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")->assertSessionHasNoErrors()->assertSessionHas('success');
    $q->refresh();
    expect($q->status)->toBe(QuotationStatus::Sent)
        ->and($q->email_status)->toBe(EmailStatus::Sent)
        ->and($this->emails)->toHaveCount(1)
        ->and($this->emails[0]['to'])->toBe('buyer@metro.example')
        ->and($this->emails[0]['attachments'])->not->toBeEmpty();
    e2eAs($this, 'staff')->get("/e2e/quotations/{$q->id}/pdf?sent=1")->assertOk();

    // 11. A second plain Send is refused; Resend works and is logged.
    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")))->toContain('Resend');
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send", ['resend' => true])->assertSessionHasNoErrors();
    expect($this->emails)->toHaveCount(2)->and($q->fresh()->status)->toBe(QuotationStatus::Sent);

    // Sent: Resend, Customer answer, and Revise (back to Draft). No manual "Rejected": the
    // customer's rejection goes through Customer answer, so offering it would always fail.
    expect(e2eButtons($this, 'staff', $q))->toBe(['decision', 'resend', 'status:draft']);

    // 12. A sent quotation cannot be edited or set to Rejected by hand.
    expect(e2eError(e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->breaker, 1, 1]]))))->toContain('cannot be edited');
    expect(e2eError(e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'rejected'])))->toContain('Customer answer');

    // 13. Converting before the customer accepts is refused.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/convert-to-sale");
    expect(Sale::count())->toBe(0);

    // 14. Record the customer's acceptance.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/decision", ['decision' => 'accepted', 'source' => 'email', 'remarks' => 'PO to follow'])
        ->assertSessionHasNoErrors();
    expect($q->fresh()->status)->toBe(QuotationStatus::Accepted);
    expect(e2eButtons($this, 'staff', $q))->toBe(['convert']);

    // 15. Generate the Sales Order: lines and totals come from the quotation; no stock taken yet.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/convert-to-sale", ['fulfillment_type' => 'Delivery', 'delivery_address' => '12 Site Rd, Pasig City'])
        ->assertSessionHasNoErrors();
    $q->refresh();
    $sale = Sale::firstOrFail();
    expect($q->converted_to_sale_id)->toBe($sale->id)
        ->and($q->status)->toBe(QuotationStatus::Accepted)
        ->and((float) $sale->grand_total)->toBe(18480.0)
        ->and($this->breaker->fresh()->stock)->toBe(100);

    // Converted: finished, nothing left to click.
    expect(e2eButtons($this, 'staff', $q))->toBe([]);

    // 16. No duplicate order, and the accepted quotation is locked.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/convert-to-sale");
    expect(Sale::count())->toBe(1);
    expect(e2eError(e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'draft'])))->not->toBe('');
    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/decision", ['decision' => 'rejected', 'source' => 'phone'])))->toContain('sent');

    // 17. The trail of every step is in the audit log.
    $events = AuditLog::where('subject_id', $q->id)->pluck('event')->all();
    expect($events)->toContain('quotation.submitted', 'quotation.approved', 'quotation.send_requested', 'quotation.resend_requested', 'quotation.accepted');

    // 18. The order shows on the Sales screen for the admin.
    e2eAs($this, 'admin')->get('/e2e/sales')->assertOk();
});

it('revises a rejected quotation and sends it again', function () {
    $q = e2eSubmitRfq($this, 'Apex Builders', 'ana@apex.example');
    e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->breaker, 5, 900]], 'submit_for_approval'))->assertSessionHasNoErrors();
    e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve")->assertSessionHasNoErrors();
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")->assertSessionHasNoErrors();

    // Customer says no.
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/decision", ['decision' => 'rejected', 'source' => 'phone', 'remarks' => 'Too expensive'])->assertSessionHasNoErrors();
    expect($q->fresh()->status)->toBe(QuotationStatus::Rejected);
    expect(e2eButtons($this, 'staff', $q))->toBe(['status:draft']);

    // Revise: back to Draft clears the approval and the answer.
    e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'draft', 'remarks' => 'New price'])->assertSessionHasNoErrors();
    $q->refresh();
    expect($q->status)->toBe(QuotationStatus::Draft)->and($q->approved_at)->toBeNull()->and($q->customer_decision_at)->toBeNull();

    // New price, re-approval, re-send, accepted.
    e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->breaker, 5, 820]], 'submit_for_approval'))->assertSessionHasNoErrors();
    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")))->toContain('approved');
    e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve")->assertSessionHasNoErrors();
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")->assertSessionHasNoErrors();
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/decision", ['decision' => 'accepted', 'source' => 'purchase_order'])->assertSessionHasNoErrors();
    expect($q->fresh()->status)->toBe(QuotationStatus::Accepted)->and($this->emails)->toHaveCount(2);
});

it('holds a quotation for short stock and raises a purchase request for the admin', function () {
    $q = e2eSubmitRfq($this, 'Short Stock Corp', 'buyer@short.example');
    // 10 rolls wanted, 2 in stock.
    e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->wire, 10, 3500]]))->assertSessionHasNoErrors();
    expect(e2eButtons($this, 'staff', $q))->toBe(['edit', 'request_stock', 'status:rejected', 'status:submit_for_approval']);

    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/request-stock")->assertSessionHasNoErrors()->assertSessionHas('success');
    $pr = PurchaseRequest::where('quotation_id', $q->id)->firstOrFail();
    expect((int) $pr->quantity)->toBe(8)->and($pr->product_id)->toBe($this->wire->id);

    // Asking again does not duplicate the request.
    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/request-stock")))->toContain('No purchase request');
    expect(PurchaseRequest::where('quotation_id', $q->id)->count())->toBe(1);

    // It cannot go for approval while waiting for that stock.
    expect(e2eError(e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'submit_for_approval'])))->toContain('Waiting for stock');
    expect($q->fresh()->submitted_for_approval_at)->toBeNull();

    // Waiting for stock: no Submit and no second Request Stock is offered; it can still be edited.
    expect(e2eButtons($this, 'staff', $q))->toBe(['edit', 'status:rejected']);
    // The admin sees the request in procurement.
    e2eAs($this, 'admin')->get('/e2e/procurement')->assertOk();
});

it('expires an approved or sent quotation past its validity and lets staff revise it', function () {
    $q = e2eSubmitRfq($this, 'Late Co', 'late@co.example');
    e2eAs($this, 'staff')->put("/e2e/quotations/{$q->id}", e2ePricedForm($q, [[$this->breaker, 1, 900]], 'submit_for_approval'))->assertSessionHasNoErrors();
    e2eAs($this, 'admin')->post("/e2e/quotations/{$q->id}/approve")->assertSessionHasNoErrors();
    e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/send")->assertSessionHasNoErrors();

    $this->travel(30)->days();
    expect(app(QuotationWorkflow::class)->expireDue($this->team->id))->toBe(1);
    expect($q->fresh()->status)->toBe(QuotationStatus::Expired);
    expect(e2eButtons($this, 'staff', $q))->toBe(['status:draft']);

    expect(e2eError(e2eAs($this, 'staff')->post("/e2e/quotations/{$q->id}/decision", ['decision' => 'accepted', 'source' => 'email'])))->toContain('sent');
    e2eAs($this, 'staff')->patch("/e2e/quotations/{$q->id}/status", ['status' => 'draft'])->assertSessionHasNoErrors();
    expect($q->fresh()->status)->toBe(QuotationStatus::Draft);
});

it('keeps other modules and guests out of quotation processing', function () {
    $q = e2eSubmitRfq($this, 'Guarded Inc', 'g@guarded.example');

    $this->get('/e2e/quotations')->assertRedirect();
    $this->post("/e2e/quotations/{$q->id}/send")->assertRedirect();
    e2eAs($this, 'inventory')->get('/e2e/quotations')->assertForbidden();
    e2eAs($this, 'inventory')->post("/e2e/quotations/{$q->id}/approve")->assertForbidden();
    expect($q->fresh()->status)->toBe(QuotationStatus::Draft);
});

it('refuses bad RFQ input with field messages and saves nothing', function () {
    $this->post('/quotation', ['company' => '', 'contactName' => '', 'email' => 'not-an-email', 'phone' => ''])
        ->assertSessionHasErrors(['company', 'contactName', 'email', 'phone']);
    $this->post('/quotation', ['company' => 'X', 'contactName' => 'Y', 'email' => 'x@y.example', 'phone' => '1', 'brands' => [['brand' => 'NoSuchBrand', 'details' => 'x']]])
        ->assertSessionHasErrors('brands');
    expect(Quotation::count())->toBe(0);
});
