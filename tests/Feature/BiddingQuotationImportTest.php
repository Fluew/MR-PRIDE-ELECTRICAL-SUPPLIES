<?php

use App\Enums\TeamRole;
use App\Models\BiddingEvent;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
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
    $this->team = Team::factory()->create(['slug' => 'bq-team']);
    $this->product = capProduct($this->team, ['unit' => 'pcs', 'name' => 'THHN Wire 3.5mm Red', 'sku' => 'WIR-35-RED'], '2020-01-01');
    $user = User::factory()->create(['role' => 'staff_procurement', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $this->buyer = [$user, createTrustedDeviceFor($user)['cookies']];
    $this->suppliers = collect(['Alpha Wire Inc.', 'Bravo Cable', 'Charlie Lighting'])->map(fn ($n) => Supplier::create(['team_id' => $this->team->id, 'name' => $n, 'status' => 'Active']));
    $pr = PurchaseRequest::create([
        'team_id' => $this->team->id, 'request_number' => 'PR-BQ1', 'product_id' => $this->product->id, 'requested_by_id' => $user->id,
        'quantity' => 100, 'unit' => 'pcs', 'estimated_cost' => 500, 'status' => PurchaseRequest::APPROVED, 'requested_at' => now(),
    ]);
    $this->actingAs($user)->withCookies($this->buyer[1])->post('/bq-team/procurement/bidding', [
        'purchase_request_id' => $pr->id, 'supplier_ids' => $this->suppliers->take(2)->pluck('id')->all(),
        'submission_deadline' => now()->addDays(7)->format('Y-m-d H:i:s'),
    ])->assertSessionHasNoErrors();
    $this->event = BiddingEvent::latest('id')->firstOrFail();
    $this->actingAs($user)->withCookies($this->buyer[1])->post("/bq-team/procurement/bidding/{$this->event->id}/issue")->assertSessionHasNoErrors();
});

afterEach(fn () => Carbon::setTestNow());

function bqRead($t, UploadedFile $file)
{
    return $t->actingAs($t->buyer[0])->withCookies($t->buyer[1])
        ->post("/bq-team/procurement/bidding/{$t->event->id}/read-quotation", ['file' => $file], ['Accept' => 'application/json']);
}

function bqCsv(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('quotation.csv', $content);
}

it('reads a CSV quotation with the purchase order columns and suggests the requested product row', function () {
    $csv = "ORDER DATE,PURCHASE ORDER NO.,QTY ORDERED,SUPPLIER,ITEM/SKU Number,Description,UNIT COST,U/M,EXTENDED COST\n"
        ."11/15/2026,Q-7781,50,Alpha Wire,CBL-01,Cable tie 8in,2.00,PCS,100.00\n"
        ."11/15/2026,Q-7781,100,Alpha Wire,WIR-35-RED,THHN wire 3.5mm red,12.75,PCS,1275.00\n";

    $data = bqRead($this, bqCsv($csv))->assertOk()->json();

    expect($data['method'])->toBe('csv')
        ->and($data['header']['quotation_date']['value'])->toBe('2026-11-15')
        ->and($data['header']['reference']['value'])->toBe('Q-7781')
        ->and($data['header']['supplier']['supplier_id'])->toBe($this->suppliers[0]->id) // "Alpha Wire" = "Alpha Wire Inc."
        ->and($data['rows'])->toHaveCount(2)
        ->and($data['suggested_row'])->toBe(1)
        ->and($data['rows'][1]['quantity'])->toBe(100)
        ->and($data['rows'][1]['unit_cost'])->toEqual(12.75);
});

it('flags an extended cost that is not quantity times unit cost', function () {
    $csv = "QTY ORDERED,Description,UNIT COST,U/M,EXTENDED COST\n10,THHN wire 3.5mm red,5.00,PCS,60.00\n";

    $row = bqRead($this, bqCsv($csv))->assertOk()->json('rows.0');

    expect($row['calculated_cost'])->toEqual(50.0)
        ->and($row['issues']['extended_cost'])->toContain('QTY ORDERED × UNIT COST = 50.00');
});

it('does not offer a supplier that was not invited or already has a quotation', function () {
    $csv = "QTY ORDERED,SUPPLIER,Description,UNIT COST,U/M\n100,Charlie Lighting,THHN wire,12.00,PCS\n";
    $supplier = bqRead($this, bqCsv($csv))->assertOk()->json('header.supplier');
    expect($supplier['supplier_id'])->toBeNull()->and($supplier['issue'])->toContain('not one of the invited suppliers');

    $this->actingAs($this->buyer[0])->withCookies($this->buyer[1])->post("/bq-team/procurement/bidding/{$this->event->id}/bids", [
        'supplier_id' => $this->suppliers[1]->id, 'unit_price' => 12.5, 'quantity' => 100, 'lead_time_days' => 5,
        'quotation_date' => '2026-11-16', 'received_at' => '2026-11-16 15:00:00',
    ])->assertSessionHasNoErrors();
    $csv = "QTY ORDERED,SUPPLIER,Description,UNIT COST,U/M\n100,Bravo Cable,THHN wire,12.00,PCS\n";
    $supplier = bqRead($this, bqCsv($csv))->assertOk()->json('header.supplier');
    expect($supplier['supplier_id'])->toBeNull()->and($supplier['issue'])->toContain('already has a recorded quotation');
});

it('rejects a CSV without the required columns and unsupported files', function () {
    bqRead($this, bqCsv("Name,Price\nWire,10\n"))->assertStatus(422)->assertJsonPath('success', false);
    bqRead($this, UploadedFile::fake()->create('quote.docx', 10))->assertStatus(422)->assertJsonPath('message', 'Quotations can be read from PDF, PNG, JPG or CSV files.');
});

it('only reads quotations while the bidding is open', function () {
    $this->event->update(['status' => BiddingEvent::CLOSED]);
    bqRead($this, bqCsv("QTY ORDERED,Description,UNIT COST\n1,Wire,1.00\n"))->assertStatus(422);
});

it('accepts the CSV that was read as the attached quotation document', function () {
    $this->actingAs($this->buyer[0])->withCookies($this->buyer[1])->post("/bq-team/procurement/bidding/{$this->event->id}/bids", [
        'supplier_id' => $this->suppliers[0]->id, 'unit_price' => 12.75, 'quantity' => 100, 'lead_time_days' => 7,
        'quotation_date' => '2026-11-15', 'received_at' => '2026-11-16 15:00:00', 'quotation_reference' => 'Q-7781',
        'document' => bqCsv("QTY ORDERED,Description,UNIT COST\n100,THHN wire,12.75\n"),
    ])->assertSessionHasNoErrors();

    expect($this->event->bids()->first()->document_name)->toBe('quotation.csv');
});
