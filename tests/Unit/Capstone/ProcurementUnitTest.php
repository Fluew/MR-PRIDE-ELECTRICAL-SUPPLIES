<?php

/*
 * 4.4.1 Unit Testing — Procurement (UT-44 to UT-53).
 * Test-only file: it exercises the existing code and changes nothing in the application.
 */

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Capstone/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    capAssertIsolatedDatabase();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'proc-cap']);
    [$this->admin, $this->adminCookies] = capActor('admin', $this->team);
    [$this->staff, $this->staffCookies] = capActor('staff_procurement', $this->team);
    $this->supplier = capSupplier($this->team);
    $this->product = capProduct($this->team, ['sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'stock' => 5, 'reorder_level' => 10, 'supplier_id' => $this->supplier->id]);
    $this->url = '/proc-cap';
});

afterEach(fn () => Carbon::setTestNow());

function capProcStaff($t)
{
    return $t->actingAs($t->staff)->withCookies($t->staffCookies);
}

function capProcAdmin($t)
{
    return $t->actingAs($t->admin)->withCookies($t->adminCookies);
}

function capPoCsv(array $rows, string $header = 'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('purchase_orders.csv', implode("\n", [$header, ...$rows]));
}

function capImportPoCsv($t, UploadedFile $file, bool $execute = false)
{
    return capProcAdmin($t)->post("{$t->url}/procurement/purchase-orders/import-csv", ['file' => $file, 'execute' => $execute], ['Accept' => 'application/json']);
}

/** An approved request, so its draft purchase order exists. */
function capDraftPo($t, int $quantity = 100): PurchaseOrder
{
    capProcStaff($t)->post("{$t->url}/procurement/requests", ['lines' => [['product_id' => $t->product->id, 'quantity' => $quantity, 'justification' => 'Below reorder level.']]]);
    capProcAdmin($t)->post("{$t->url}/procurement/requests/".PurchaseRequest::latest('id')->value('id').'/review', ['decision' => 'approve']);

    return PurchaseOrder::latest('id')->firstOrFail();
}

it('UT-44 requires at least one line and a justification on a purchase request', function () {
    capProcStaff($this)->post("{$this->url}/procurement/requests", [])->assertSessionHasErrors('lines');
    capProcStaff($this)->post("{$this->url}/procurement/requests", ['lines' => []])->assertSessionHasErrors('lines');
    capProcStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 10]]])
        ->assertSessionHasErrors('lines.0.justification');

    expect(PurchaseRequest::count())->toBe(0);
});

it('UT-45 accepts only whole purchase request quantities from 1 to 1,000,000', function () {
    $post = fn (mixed $qty) => capProcStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => $qty, 'justification' => 'Restock.']]]);

    foreach ([0, -3, 2.5, '', 'ten', 1000001] as $invalid) {
        $post($invalid)->assertSessionHasErrors('lines.0.quantity');
    }
    expect(PurchaseRequest::count())->toBe(0);

    $post(1)->assertSessionHasNoErrors();        // lower boundary
    // The upper boundary, for another product (a product can have only one open request: see UT-52).
    $other = capProduct($this->team, ['sku' => 'CB-63', 'name' => 'Circuit Breaker 63A']);
    capProcStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $other->id, 'quantity' => 1000000, 'justification' => 'Restock.']]])
        ->assertSessionHasNoErrors();
    expect(PurchaseRequest::orderBy('id')->pluck('quantity')->all())->toBe([1, 1000000])
        ->and(PurchaseRequest::first()->status)->toBe(PurchaseRequest::PENDING)
        ->and($this->product->fresh()->stock)->toBe(5); // a request never changes stock
});

it('UT-46 requires a description and a listed unit for a product that is not in the catalog', function () {
    $post = fn (array $line) => capProcStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [$line + ['product_id' => null, 'quantity' => 6, 'justification' => 'Project need.']]]);

    $post(['unit' => 'roll'])->assertSessionHasErrors('lines.0.product_description');
    $post(['product_description' => 'THHN 14 AWG'])->assertSessionHasErrors('lines.0.unit');
    $post(['product_description' => 'THHN 14 AWG', 'unit' => 'bucket'])->assertSessionHasErrors('lines.0.unit');
    expect(PurchaseRequest::count())->toBe(0);

    $post(['product_description' => 'THHN 14 AWG', 'unit' => 'roll'])->assertSessionHasNoErrors();
    expect(PurchaseRequest::sole()->isUnlisted())->toBeTrue();
});

it('UT-47 validates the supplier, quantity, unit cost and promised date of a purchase order', function () {
    $po = capDraftPo($this);
    $valid = ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9.5];
    $patch = fn (array $data) => capProcStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $data);

    $patch(['quantity' => 100, 'unit_cost' => 9.5])->assertSessionHasErrors('supplier_id');
    $patch(['supplier_id' => 999999] + $valid)->assertSessionHasErrors('supplier_id');
    $patch(['quantity' => 0] + $valid)->assertSessionHasErrors('quantity');
    $patch(['quantity' => -5] + $valid)->assertSessionHasErrors('quantity');
    $patch(['unit_cost' => 0] + $valid)->assertSessionHasErrors('unit_cost');      // must be greater than zero
    $patch(['unit_cost' => -1] + $valid)->assertSessionHasErrors('unit_cost');
    $patch(['unit_cost' => 'free'] + $valid)->assertSessionHasErrors('unit_cost');
    $patch(['promised_delivery_date' => '2026-10-14'] + $valid)->assertSessionHasErrors('promised_delivery_date'); // yesterday
    expect((float) $po->fresh()->unit_cost)->not->toBe(9.5);

    $patch(['unit_cost' => 0.01, 'promised_delivery_date' => '2026-10-15'] + $valid)->assertSessionHasNoErrors(); // smallest cost, today
    expect((float) $po->fresh()->unit_cost)->toBe(0.01)->and($po->fresh()->supplier_id)->toBe($this->supplier->id);
});

it('UT-48 calculates the extended cost of a purchase order line as quantity times unit cost', function () {
    $json = capImportPoCsv($this, capPoCsv([
        '10-25-2026,4000005472,100,068SSCR2,"Stainless Steel Conduit Rod 2m",PCS,450.00',
        '10-25-2026,4000005473,3,3P32A,"MCB 3P 32A",PC,29.50',
        '10-25-2026,4000005474,1,NEMA1,"Panel Board NEMA 1",PC,"2,200.00"',
    ]))->assertSuccessful()->json();

    expect(collect($json['valid_rows'])->pluck('total_cost')->map(fn ($v) => (float) $v)->all())->toBe([45000.0, 88.5, 2200.0]);

    // A different EXTENDED COST written in the file is reported, not silently replaced.
    $json = capImportPoCsv($this, capPoCsv(['10-25-2026,4000005475,5,X1,"Breaker",PCS,350,2000'], 'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost,Extended Cost'))->json();
    expect($json['summary'])->toMatchArray(['valid_count' => 0, 'invalid_count' => 1])
        ->and(implode(' ', $json['invalid_rows'][0]['errors']))->toContain('1,750.00');
});

it('UT-49 maps the columns of an imported purchase order file to the purchase order fields', function () {
    // Abbreviated headers in a different order.
    $file = capPoCsv(['PCS,12.50,Volt Supply,"Cable Tie 8in",CT-8,40,PO-9001,2026-10-01'], 'UOM,Price,Supplier,Desc,SKU,Qty,PO No.,Order Date');

    $row = capImportPoCsv($this, $file)->assertSuccessful()->json('valid_rows.0');
    expect($row)->toMatchArray(['date' => '2026-10-01', 'po_number' => 'PO-9001', 'supplier_id' => $this->supplier->id, 'quantity' => 40,
        'item_sku' => 'CT-8', 'description' => 'Cable Tie 8in', 'unit_of_measure' => 'PCS']);
    expect((float) $row['unit_cost'])->toBe(12.5)->and((float) $row['total_cost'])->toBe(500.0);

    capImportPoCsv($this, $file, execute: true)->assertSuccessful();
    $po = PurchaseOrder::sole();
    expect($po->only(['po_number', 'quantity', 'item_sku', 'description', 'unit_of_measure', 'supplier_id']))
        ->toBe(['po_number' => 'PO-9001', 'quantity' => 40, 'item_sku' => 'CT-8', 'description' => 'Cable Tie 8in', 'unit_of_measure' => 'PCS', 'supplier_id' => $this->supplier->id])
        ->and($po->po_date->toDateString())->toBe('2026-10-01');

    // A file without the required columns is refused.
    capImportPoCsv($this, capPoCsv(['PO-1,5'], 'PO No.,Qty'))->assertStatus(422)->assertJsonPath('success', false);
});

it('UT-50 reports invalid rows of an imported purchase order file and saves none of them', function () {
    $json = capImportPoCsv($this, capPoCsv([
        '10-25-2026,PO-1,0,A1,"Zero quantity",PCS,10',
        '10-25-2026,PO-2,5,A2,,PCS,10',
        '31-31-2026,PO-3,5,A3,"Impossible date",PCS,10',
        ',PO-4,5,A4,"No date",PCS,10',
        '10-25-2026,PO-5,5,A5,"Negative cost",PCS,-10',
    ]), execute: true)->json();

    expect($json['summary'])->toMatchArray(['total_rows' => 5, 'valid_count' => 0, 'invalid_count' => 5])
        ->and($json['invalid_rows'][0]['errors'])->toContain('Quantity must be at least 1.')
        ->and($json['invalid_rows'][1]['errors'])->toContain('Description is required.')
        ->and($json['invalid_rows'][3]['errors'])->toContain('Date is required.')
        ->and($json['invalid_rows'][4]['errors'])->toContain('Unit cost cannot be negative.')
        ->and(PurchaseOrder::count())->toBe(0);
});

it('UT-51 refuses negative and decimal quantities in an imported purchase order file', function () {
    $json = capImportPoCsv($this, capPoCsv([
        '10-25-2026,PO-NEG,-5,A1,"Negative quantity",PCS,10',
        '10-25-2026,PO-DEC,2.5,A2,"Decimal quantity",PCS,10',
    ]))->json();

    // Neither row is a valid whole quantity of at least 1, so neither may be offered for import.
    expect($json['summary']['valid_count'])->toBe(0)
        ->and($json['summary']['invalid_count'])->toBe(2);
});

it('UT-52 refuses a duplicate purchase request and creates one purchase order per approved request', function () {
    $line = ['lines' => [['product_id' => $this->product->id, 'quantity' => 100, 'justification' => 'Restock.']]];
    capProcStaff($this)->post("{$this->url}/procurement/requests", $line)->assertSessionHasNoErrors();
    // A second request for a product that already has an open one is refused.
    capProcStaff($this)->post("{$this->url}/procurement/requests", $line)->assertSessionHasErrors();
    expect(capSessionError(array_key_first(session('errors')->getMessages())))->toContain('already has an open purchase request');
    $pr = PurchaseRequest::sole();

    capProcAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    capProcAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasErrors();

    expect(PurchaseOrder::count())->toBe(1)
        ->and(PurchaseOrder::sole()->purchase_request_id)->toBe($pr->id)
        ->and($pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED);
});

it('UT-53 does not import the same purchase order file twice', function () {
    $file = fn () => capPoCsv(['10-25-2026,4000005472,100,068SSCR2,"Stainless Steel Conduit Rod 2m",PCS,450.00']);

    capImportPoCsv($this, $file(), execute: true)->assertSuccessful();
    capImportPoCsv($this, $file(), execute: true);

    // The second upload repeats a purchase order line that is already recorded.
    expect(PurchaseOrder::where('po_number', '4000005472')->count())->toBe(1);
});
