<?php

/*
 * 4.4.2 Integration Testing — supply-side workflows (IT-15 to IT-24):
 *   5. Inventory → Purchase Request
 *   6. Purchase Order → Inbound Delivery
 *   7. Inbound Delivery → Inventory Update
 *   8. Inbound Delivery → Supplier Evaluation
 *  13. Procurement → Exchange Rate Integration
 *  14. OCR Import → Review → Record Creation
 *
 * Test-only file. Every step goes through the application's own routes and services; the
 * database is the dedicated MariaDB testing database `mr_pride_testing` (run with: php vendor/bin/pest -c phpunit.mysql.xml) and external services are faked.
 */

use App\Models\Delivery;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\InboundDelivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Models\Team;
use App\Services\ExchangeRates;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Capstone\MysqlTestCase;

require_once __DIR__.'/../../Capstone/mysql_helpers.php';

uses(MysqlTestCase::class); // RefreshDatabase, after the connection is confirmed to be mr_pride_testing

const CAP_RATE_URL = 'https://api.frankfurter.dev/v2/rate/usd/php';

beforeEach(function () {
    capAssertMysqlTestingDatabase();
    Http::preventStrayRequests(); // any real call to Frankfurter, Resend or another service fails the test
    Storage::fake('local');
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'supply-cap']);
    [$this->admin, $this->adminCookies] = capActor('admin', $this->team);
    [$this->buyer, $this->buyerCookies] = capActor('staff_procurement', $this->team);
    [$this->warehouse, $this->warehouseCookies] = capActor('staff_inventory', $this->team);
    $this->supplier = capSupplier($this->team, 'Volt Supply');
    // 5 in stock, reorder level 10, and 40 / 50 / 60 sold in the last three full months.
    $this->product = capProduct($this->team, ['sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 20, 'cost_price' => 10, 'stock' => 5,
        'reorder_level' => 10, 'reorder_quantity' => 100, 'supplier_id' => $this->supplier->id]);
    foreach (['2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
        capDemand($this->team, $this->product, $date, $qty);
    }
    $this->url = '/supply-cap';
});

afterEach(fn () => Carbon::setTestNow());

function capSupplyAs($t, string $who)
{
    return $t->actingAs($t->{$who})->withCookies($t->{$who.'Cookies'});
}

/** Request → approval → draft order confirmed and sent to the supplier. */
function capSentPurchaseOrder($t, int $quantity = 100, array $terms = []): PurchaseOrder
{
    capSupplyAs($t, 'buyer')->post("{$t->url}/procurement/requests", ['lines' => [['product_id' => $t->product->id, 'quantity' => $quantity, 'justification' => 'Below reorder level.']]])->assertSessionHasNoErrors();
    $pr = PurchaseRequest::latest('id')->firstOrFail();
    capSupplyAs($t, 'admin')->post("{$t->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
    $po = PurchaseOrder::where('purchase_request_id', $pr->id)->firstOrFail();
    capSupplyAs($t, 'buyer')->patch("{$t->url}/procurement/purchase-orders/{$po->id}/draft", $terms + ['supplier_id' => $t->supplier->id, 'quantity' => $quantity, 'unit_cost' => 9.5])->assertSessionHasNoErrors();
    capSupplyAs($t, 'buyer')->post("{$t->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();

    return $po->fresh();
}

function capImportCsv(string $csv, string $name = 'list.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cap').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

// ------------------------------------------------------------- 5. Inventory → Purchase Request

it('IT-15 turns a low-stock reorder recommendation into a purchase request that Procurement receives', function () {
    $advice = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id]['reorder'];
    expect($advice)->toMatchArray(['needed' => true, 'suggested_quantity' => 145]) // forecast 150 for 3 months, 5 available
        ->and(PurchaseRequest::count())->toBe(0);                                  // advice alone creates nothing

    // Inventory staff raise the request from the recommendation.
    capSupplyAs($this, 'warehouse')->post("{$this->url}/procurement/requests", ['forecast_months' => 3,
        'lines' => [['product_id' => $this->product->id, 'quantity' => 145, 'justification' => 'Below reorder level; forecast 50 a month.']]])->assertSessionHasNoErrors();

    $pr = PurchaseRequest::sole();
    expect($pr->only(['product_id', 'quantity', 'unit', 'status', 'requested_by_id', 'current_stock', 'forecast_quantity', 'recommended_quantity']))->toBe([
        'product_id' => $this->product->id, 'quantity' => 145, 'unit' => 'pcs', 'status' => PurchaseRequest::PENDING, 'requested_by_id' => $this->warehouse->id,
        'current_stock' => 5, 'forecast_quantity' => 150, 'recommended_quantity' => 145,
    ])->and($this->product->fresh()->stock)->toBe(5);

    // Procurement sees it; inventory staff cannot approve it, and cannot open Procurement.
    capSupplyAs($this, 'buyer')->get("{$this->url}/procurement?tab=requests")->assertOk()
        ->assertInertia(fn ($page) => $page->where('requests.data.0.id', $pr->id)->where('requests.data.0.status', PurchaseRequest::PENDING));
    capSupplyAs($this, 'warehouse')->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertForbidden();
    capSupplyAs($this, 'buyer')->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertForbidden(); // administrators only
    expect($pr->fresh()->status)->toBe(PurchaseRequest::PENDING);
});

// --------------------------------------------------------- 6. Purchase Order → Inbound Delivery

it('IT-16 creates the purchase order on approval and accepts deliveries only once it is sent', function () {
    capSupplyAs($this, 'buyer')->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 100, 'justification' => 'Below reorder level.']]]);
    $pr = PurchaseRequest::sole();
    capSupplyAs($this, 'admin')->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();

    $po = PurchaseOrder::sole();
    expect($po->only(['purchase_request_id', 'product_id', 'quantity', 'status']))->toBe(['purchase_request_id' => $pr->id, 'product_id' => $this->product->id, 'quantity' => 100, 'status' => PurchaseOrder::DRAFT])
        ->and($pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED)
        ->and($pr->fresh()->reviewed_by_id)->toBe($this->admin->id);

    // A draft order cannot receive goods.
    $receive = fn (array $data) => capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", $data + ['delivery_date' => '2026-10-15']);
    $receive(['quantity' => 10])->assertSessionHasErrors();
    expect(Delivery::count())->toBe(0);

    capSupplyAs($this, 'buyer')->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9.5, 'promised_delivery_date' => '2026-10-20'])->assertSessionHasNoErrors();
    capSupplyAs($this, 'buyer')->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::SENT);

    $receive(['quantity' => 60, 'supplier_document_number' => 'DR-7781'])->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    expect($delivery->only(['purchase_order_id', 'quantity', 'supplier_id']))->toBe(['purchase_order_id' => $po->id, 'quantity' => 60, 'supplier_id' => $this->supplier->id])
        ->and($delivery->inspectionStatus())->toBe('Pending Inspection')
        ->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL);

    $receive(['quantity' => 41])->assertSessionHasErrors('quantity'); // only 40 are still owed
    expect(Delivery::count())->toBe(1);
});

// -------------------------------------------------------- 7. Inbound Delivery → Inventory Update

it('IT-17 adds the accepted units of an inspected delivery to inventory exactly once', function () {
    $po = capSentPurchaseOrder($this, 100);
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 60])->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    expect($this->product->fresh()->stock)->toBe(5); // received, not inspected: nothing added yet

    $inspection = ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 2, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'Burnt contacts'];
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/inspection", $inspection)->assertSessionHasNoErrors();

    $movement = StockMovement::where('movement_key', "inbound-delivery:{$delivery->id}")->sole();
    expect($this->product->fresh()->stock)->toBe(59) // 5 + 54 accepted; the 6 rejected are not added
        ->and($this->product->fresh()->status)->toBe('In Stock')
        ->and($movement->only(['type', 'quantity', 'previous_stock', 'new_stock', 'product_id']))->toBe(['type' => 'stock_in', 'quantity' => 54, 'previous_stock' => 5, 'new_stock' => 59, 'product_id' => $this->product->id])
        ->and($delivery->fresh()->posted_quantity)->toBe(54)
        ->and($delivery->fresh()->stock_movement_id)->toBe($movement->id);

    // Posting again, or saving the same inspection again, adds nothing.
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/post-inventory")->assertSessionHasErrors();
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/inspection", $inspection)->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(59)
        ->and(StockMovement::where('movement_key', 'like', "inbound-delivery:{$delivery->id}%")->count())->toBe(1);

    // The reorder advice now reads the new stock.
    expect(app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id]['available_stock'])->toBe(59);
});

it('IT-18 saves nothing of an inbound delivery when one of its lines is invalid, and ignores a repeated confirmation', function () {
    $cable = capProduct($this->team, ['sku' => 'WR-12', 'name' => 'THHN Wire 12 AWG', 'stock' => 3, 'unit' => 'roll']);
    $line = fn (array $a) => PurchaseOrder::create($a + ['team_id' => $this->team->id, 'po_date' => '2026-10-02', 'po_number' => 'PO-7001', 'supplier_id' => $this->supplier->id,
        'status' => PurchaseOrder::SENT, 'unit_of_measure' => 'PCS']);
    $poBreaker = $line(['quantity' => 20, 'product_id' => $this->product->id, 'description' => 'Circuit Breaker 40A', 'unit_cost' => 12.5, 'total_cost' => 250]);
    $poCable = $line(['quantity' => 10, 'product_id' => $cable->id, 'description' => 'THHN Wire 12 AWG', 'unit_cost' => 40, 'total_cost' => 400]);
    $confirm = fn (array $breaker, array $wire, string $key) => capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/inbound", [
        'po_number' => 'PO-7001', 'delivery_date' => '2026-10-15', 'submission_key' => $key,
        'lines' => [['purchase_order_id' => $poBreaker->id, 'inspected' => true] + $breaker, ['purchase_order_id' => $poCable->id, 'inspected' => true] + $wire],
    ]);
    $good = ['received_quantity' => 15, 'accepted_quantity' => 15, 'rejected_quantity' => 0];

    // The first line is valid, the second is not (6 received ≠ 4 accepted + 1 rejected): all or nothing.
    $confirm($good, ['received_quantity' => 6, 'accepted_quantity' => 4, 'rejected_quantity' => 1], (string) Str::uuid())->assertSessionHasErrors('lines.1.accepted_quantity');
    expect(InboundDelivery::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(StockMovement::where('type', 'stock_in')->count())->toBe(0)
        ->and($this->product->fresh()->stock)->toBe(5)
        ->and($cable->fresh()->stock)->toBe(3);

    // Valid: both products are updated together. The same form sent again changes nothing.
    $confirm($good, ['received_quantity' => 6, 'accepted_quantity' => 6, 'rejected_quantity' => 0], 'same-key')->assertSessionHasNoErrors();
    $confirm($good, ['received_quantity' => 6, 'accepted_quantity' => 6, 'rejected_quantity' => 0], 'same-key')->assertSessionHasErrors('error');

    $header = InboundDelivery::sole();
    expect($header->items)->toHaveCount(2)
        ->and($this->product->fresh()->stock)->toBe(20)
        ->and($cable->fresh()->stock)->toBe(9)
        ->and(StockMovement::where('type', 'stock_in')->where('reference_number', $header->delivery_number)->count())->toBe(2)
        ->and($poBreaker->fresh()->status)->toBe(PurchaseOrder::PARTIAL);
});

// ---------------------------------------------------- 8. Inbound Delivery → Supplier Evaluation

it('IT-19 feeds recorded deliveries and inspections into the supplier evaluation', function () {
    $evaluate = fn () => collect(app(SupplierEvaluator::class)->forTeam($this->team->id)[$this->supplier->id]['criteria'])->keyBy('key');
    expect($evaluate()['delivery']['score'])->toBeNull()->and($evaluate()['quality']['score'])->toBeNull(); // no records yet

    $po = capSentPurchaseOrder($this, 100, ['promised_delivery_date' => '2026-10-20']);
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 100])->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    capSupplyAs($this, 'buyer')->post("{$this->url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 94, 'rejected_quantity' => 6, 'damaged_quantity' => 6,
        'inspected_on' => '2026-10-15', 'rejection_reason' => 'Cracked casings'])->assertSessionHasNoErrors();

    // Evaluated on the delivery day itself. (The SQLite version of this case had to evaluate on the
    // following day; on MariaDB the DATE column is compared as a date, so no workaround is used.)
    $criteria = $evaluate();
    $evaluation = app(SupplierEvaluator::class)->forSupplier($this->supplier);

    // Delivered before the promised date (100% on time); 94 of 100 accepted.
    expect($criteria['delivery']['inputs'])->toMatchArray(['completed_deliveries' => 1, 'on_time_deliveries' => 1, 'ordered_quantity' => 100, 'accepted_quantity' => 94])
        ->and($criteria['delivery']['score'])->toEqual(97.6)   // (100 × 0.60) + (94 × 0.40)
        ->and($criteria['quality']['inputs'])->toMatchArray(['inspections' => 1, 'accepted' => 94, 'rejected' => 6])
        ->and($criteria['quality']['score'])->toEqual(94.0)
        ->and($criteria['quality']['inputs']['records'][0]['delivery_number'])->toBe($delivery->delivery_number)
        // No other supplier delivered this product, so there is no price comparison and no final score.
        ->and($criteria['price']['score'])->toBeNull()
        ->and($evaluation['complete'])->toBeFalse()
        ->and($evaluation['score'])->toBeNull();

    // The Suppliers page shows the same evaluation.
    [$supplierStaff, $cookies] = capActor('staff_suppliers', $this->team);
    $this->actingAs($supplierStaff)->withCookies($cookies)->get("{$this->url}/suppliers")->assertOk();
});

// ------------------------------------------------ 13. Procurement → Exchange Rate Integration

it('IT-20 shows Procurement the USD to PHP rate from the exchange rate service without changing stored amounts', function () {
    Http::fake([CAP_RATE_URL => Http::response(['date' => '2026-10-15', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 58.25])]);
    $po = capSentPurchaseOrder($this, 100); // 100 × 9.50 USD
    $as = fn () => $this->actingAs($this->buyer->fresh())->withCookies($this->buyerCookies)->withCredentials();

    $as()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk()->assertJson(['preferred' => 'PHP']);
    $as()->getJson('/settings/currency/rate')->assertOk()->assertJson(['status' => 'ok', 'rate' => 58.25, 'date' => '2026-10-15']);
    $as()->getJson('/settings/currency/rate')->assertOk();
    Http::assertSentCount(1); // the rate is cached, not fetched for every page

    $as()->get("{$this->url}/procurement")->assertOk()->assertInertia(fn ($page) => $page
        ->where('currency.preferred', 'PHP')
        ->where('currency.base', 'USD')
        ->where('currency.rate.rate', 58.25)
        ->where('purchaseOrders.data.0.id', $po->id));

    // Conversion is display only: the purchase order keeps its USD amounts.
    expect((float) $po->fresh()->unit_cost)->toBe(9.5)->and((float) $po->fresh()->total_cost)->toBe(950.0);
});

it('IT-21 keeps Procurement working, with no converted amounts, when the exchange rate service is down', function () {
    Http::fake([CAP_RATE_URL => Http::response('Service Unavailable', 503)]);
    $po = capSentPurchaseOrder($this, 100);
    $as = fn () => $this->actingAs($this->buyer->fresh())->withCookies($this->buyerCookies)->withCredentials();

    $as()->putJson('/settings/currency', ['currency' => 'PHP'])->assertOk();
    $as()->getJson('/settings/currency/rate')->assertStatus(503)
        ->assertJson(['status' => 'unavailable', 'message' => 'The exchange rate service returned an error (HTTP 503).', 'last_success' => null]);

    $as()->get("{$this->url}/procurement")->assertOk()->assertInertia(fn ($page) => $page->where('currency.rate', null)->where('purchaseOrders.data.0.id', $po->id));
    expect(app(ExchangeRates::class)->cached())->toBeNull()
        ->and((float) $po->fresh()->total_cost)->toBe(950.0);
});

// --------------------------------------------- 14. OCR Import → Review → Record Creation

it('IT-22 keeps the rows read by OCR from a customer product list for staff to review with the request', function () {
    fakeImageOcr(); // the OCR engine is mocked: it "reads" three list lines from the picture
    Team::factory()->create(['slug' => 'mr-pride']);
    [$quoter, $cookies] = capActor('staff_quotations', $this->team);
    $img = imagecreatetruecolor(300, 200);
    ob_start();
    imagepng($img);
    $picture = UploadedFile::fake()->createWithContent('site-list.png', (string) ob_get_clean());

    $this->post('/quotation', ['company' => 'Acme Builders', 'contactName' => 'Juan Dela Cruz', 'email' => 'juan@acme.test', 'phone' => '09171234567', 'productFiles' => [$picture]])
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    $read = $quotation->request_details['extracted_products'][0];
    expect($quotation->files->sole()->original_name)->toBe('site-list.png')
        ->and($read)->toMatchArray(['file' => 'site-list.png', 'status' => 'read', 'method' => 'ocr'])
        ->and($read['rows'])->toHaveCount(3)
        ->and($read['rows'][0])->toMatchArray(['description' => 'Circuit breaker 2P 30A', 'quantity' => 20, 'unit' => 'pcs'])
        ->and($read['rows'][1])->toMatchArray(['description' => 'THHN wire 3.5mm', 'quantity' => 100, 'unit' => 'meters'])
        ->and($quotation->notes)->toContain('automatic, not checked by the client')
        // Read rows wait for staff: unpriced lines, nothing matched to inventory, nothing sent.
        ->and($quotation->items->pluck('sku')->unique()->values()->all())->toBe(['CLIENT-LIST'])
        ->and($quotation->items)->toHaveCount(3)
        ->and((float) $quotation->grand_total)->toBe(0.0)
        ->and($quotation->status->value)->toBe('draft');
    Storage::disk('local')->assertExists($quotation->files->sole()->path);
});

it('IT-23 imports a document through review into inventory records, once', function () {
    $csv = "MR PRIDE PRICE LIST\nItem Code,Description,Category,UOM,Cost,Selling Price,Qty\n"
        ."WR-35,THHN Wire 3.5mm,Wires,roll,900,1250,12\n"
        ."CB-40,Circuit Breaker 40A,,pcs,11,25,\n"   // already in inventory
        ."LB-9,LED Bulb 9W,Lighting,pcs,abc,120,\n"; // unreadable cost
    $as = fn () => capSupplyAs($this, 'warehouse')->withCredentials();

    // 1. Upload: the file is read into a batch of rows; nothing is created yet.
    $batchId = $as()->post("{$this->url}/imports/inventory", ['file' => capImportCsv($csv)])->assertCreated()->json('id');
    $batch = ImportBatch::findOrFail($batchId);
    $rows = $batch->rows()->get()->keyBy(fn ($r) => $r->values['name'] ?? $r->raw);
    expect(Product::count())->toBe(1)
        ->and($rows['THHN Wire 3.5mm']->status)->toBe(ImportRow::READY)
        ->and($rows['Circuit Breaker 40A']->status)->toBe(ImportRow::EXISTING)
        ->and($rows['LED Bulb 9W']->status)->toBe(ImportRow::NEEDS_REVIEW);

    // 2. Review: it cannot be confirmed while a row needs review; staff correct the value.
    $as()->post("{$this->url}/imports/batches/{$batch->id}/confirm")->assertStatus(422);
    expect(Product::count())->toBe(1);
    $as()->patchJson("{$this->url}/imports/batches/{$batch->id}/rows/{$rows['LED Bulb 9W']->id}", ['values' => ['cost_price' => '80']])->assertOk();

    // 3. Record creation: new products only; the existing product and its stock are untouched.
    $as()->post("{$this->url}/imports/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 2]);
    $wire = Product::where('sku', 'WR-35')->sole();
    $bulb = Product::where('name', 'LED Bulb 9W')->sole();
    expect((float) $wire->price)->toBe(1250.0)
        ->and($wire->stock)->toBe(0)                       // an import never adds stock
        ->and($wire->import_batch_id)->toBe($batch->id)
        ->and((float) $bulb->cost_price)->toBe(80.0)
        ->and((float) $this->product->fresh()->price)->toBe(20.0)
        ->and($this->product->fresh()->stock)->toBe(5)
        ->and($batch->fresh()->status)->toBe(ImportBatch::COMPLETED);

    // A completed batch cannot be imported a second time.
    $as()->post("{$this->url}/imports/batches/{$batch->id}/confirm")->assertStatus(422);
    expect(Product::count())->toBe(3);
});

it('IT-24 creates no record from an import that staff cancel during review', function () {
    $as = fn () => capSupplyAs($this, 'warehouse')->withCredentials();
    $batchId = $as()->post("{$this->url}/imports/inventory", ['file' => capImportCsv("Item Code,Description,UOM,Cost,Selling Price\nWR-35,THHN Wire 3.5mm,roll,900,1250\n")])->assertCreated()->json('id');

    $as()->post("{$this->url}/imports/batches/{$batchId}/cancel")->assertSuccessful();
    $as()->post("{$this->url}/imports/batches/{$batchId}/confirm")->assertStatus(422);

    expect(Product::count())->toBe(1)->and(Product::where('sku', 'WR-35')->exists())->toBeFalse();

    // Other modules cannot open the inventory import.
    capSupplyAs($this, 'buyer')->withCredentials()->get("{$this->url}/imports/batches/{$batchId}")->assertForbidden();
});
