<?php

namespace Database\Seeders;

use App\Enums\QuotationStatus;
use App\Exceptions\WorkflowException;
use App\Models\BiddingEvent;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Team;
use App\Models\User;
use App\Services\Procurement\BiddingWorkflow;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\ResendService;
use App\Services\Sales\SalesOrderService;
use App\Support\QuotationTerms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wipes every transaction and regenerates the history from 2 January 2020 to yesterday with an exact
 * volume: 22,512 sales orders carrying 65,000 order lines. Users, the team, suppliers, products
 * (names, brands, categories, SKUs), supplier catalogue and import history are kept.
 *
 *   RESEED_CONFIRM=wipe-transactions php artisan db:seed --class=ReseedHistorySeeder
 *
 * It is HistoryOnExistingDataSeeder's time-ordered simulation (every quotation, sales order, payment,
 * customer delivery, purchase request, bidding, purchase order, inbound delivery and inspection goes
 * through the system's own workflow services), with:
 *  - a fixed daily sales plan: gradual growth (about 2,800 orders in 2020 to 3,700 a year by 2026),
 *    a mild construction-season swing and small day-to-day noise, so weekly, monthly and yearly
 *    totals move smoothly for the SMA forecast;
 *  - mild per-product demand shapes (gentle trends and seasonality, no on/off products);
 *  - about one purchase request in eight sent to supplier bidding (RFQ, quotations, award);
 *  - prices that rise yearly and end exactly at today's catalogue prices;
 *  - some verified "cannot supply" supplier records so AHP supplier availability differs per product.
 *
 * No email or outside request leaves the machine: ResendService is replaced by a stub, every HTTP
 * request is answered locally, the mailer is in-memory and jobs run at once.
 */
class ReseedHistorySeeder extends HistoryOnExistingDataSeeder
{
    public const TARGET_SALES = 22512;

    public const TARGET_LINES = 65000;

    private const FIRST_SALE_DAY = '2020-01-02';

    private const LAST_SALE_DAY = '2026-11-17';

    /** Mild construction-season swing on the number of orders. */
    private const SEASON = [1 => 0.95, 0.96, 1.05, 1.07, 1.05, 0.98, 0.95, 0.96, 0.99, 1.02, 1.03, 1.00];

    /** Tables holding transactions or activity; everything else (users, catalogue, suppliers) is kept. */
    private const WIPE = [
        'audit_logs', 'notifications', 'notification_logs', 'inquiries', 'clients',
        'quotations', 'quotation_items', 'quotation_files', 'quotation_number_sequences',
        'sales', 'sale_items', 'payments', 'payment_receipts', 'sales_invoices', 'commercial_invoices',
        'order_fulfillments', 'delivery_releases', 'delivery_release_items', 'delivery_attempt_logs',
        'stock_movements', 'purchase_requests', 'purchase_orders', 'deliveries', 'inbound_deliveries',
        'supplier_quotes', 'supplier_product_costs', 'evaluation_snapshots',
        'bidding_events', 'bidding_suppliers', 'supplier_bids',
        'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks',
    ];

    /** @var array<string, int> sales orders per day (Y-m-d) */
    private array $days = [];

    /** @var array<string, float> full-month equivalent orders per month (Y-m) */
    private array $monthRate = [];

    /** @var list<int> order line counts still to hand out, one per planned order */
    private array $linePool = [];

    /** @var array<int, array{0: float, 1: float, 2: float}> trend, seasonal amplitude, phase per product */
    private array $shape = [];

    /** @var array<int, array{price: float, cost: ?float, rises: array<int, float>}> */
    private array $prices = [];

    /** @var array<string, array<int, int>> */
    private array $mildCache = [];

    private int $blockedEmails = 0;

    public function run(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (app()->isProduction() || ! in_array((string) ($connection['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! empty($connection['url'])) {
            $this->command->error('Refused: runs only on a local database.');

            return;
        }
        if (env('RESEED_CONFIRM') !== 'wipe-transactions') {
            $this->command->error('Refused: this deletes every transaction. Set RESEED_CONFIRM=wipe-transactions to run it.');

            return;
        }

        $this->team = Team::where('slug', 'mr-pride')->first() ?? Team::query()->orderBy('id')->firstOrFail();
        $this->admin = User::where('role', 'admin')->where('current_team_id', $this->team->id)->orderBy('id')->first()
            ?? User::where('role', 'admin')->orderBy('id')->firstOrFail();

        mt_srand(22512);
        $this->blockOutsideTraffic();
        $this->wipeTransactions();
        $this->deflatePrices();
        $this->buildPlan();

        parent::run();
    }

    // ───────────────────────────── Safety ─────────────────────────────

    protected function prepareEnvironment(): void
    {
        parent::prepareEnvironment();
        $this->blockOutsideTraffic();
    }

    private function blockOutsideTraffic(): void
    {
        $seeder = $this;
        app()->instance(ResendService::class, new class($seeder) extends ResendService
        {
            public function __construct(private ReseedHistorySeeder $seeder)
            {
                parent::__construct();
                $this->apiKey = '';
            }

            protected function deliver(string $toEmail, string $subject, string $html, string $kind, array $attachments = []): array
            {
                $this->seeder->emailBlocked();

                return ['success' => true, 'id' => 'not-sent-history-'.uniqid(), 'error' => null];
            }
        });
        Http::fake(['*' => Http::response(['message' => 'Blocked while generating history.'], 503)]);
        config(['services.resend.key' => '', 'services.resend.dev_redirect_to' => '', 'mail.default' => 'array', 'queue.default' => 'sync']);
    }

    public function emailBlocked(): void
    {
        $this->blockedEmails++;
    }

    private function wipeTransactions(): void
    {
        $existing = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'))->pluck('t')->all();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::WIPE as $table) {
                if (in_array($table, $existing, true)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        // "Cannot supply" records added by an earlier run of this seeder are re-created below.
        DB::table('product_supplier')->where('team_id', $this->team->id)->where('notes', 'Asked for this item; currently cannot supply it.')->delete();
        // Suppliers deleted earlier stay deleted; products are reset by the simulation's opening stock.
        DB::table('products')->where('team_id', $this->team->id)->update(['stock' => 0]);
        $this->command->info('Transactions wiped ('.count(self::WIPE).' tables); users, team, suppliers, products and catalogue kept.');
    }

    // ───────────────────────────── Prices ─────────────────────────────

    /** Each product starts in 2020 at today's price less six yearly rises of 2.5–5.5%. */
    private function deflatePrices(): void
    {
        foreach (DB::table('products')->where('team_id', $this->team->id)->get(['id', 'price', 'cost_price']) as $p) {
            $rises = [];
            for ($year = 2021; $year <= 2026; $year++) {
                $rises[$year] = 1 + mt_rand(25, 55) / 1000;
            }
            $cost = $p->cost_price !== null && (float) $p->cost_price > 0 ? (float) $p->cost_price : null;
            $this->prices[$p->id] = ['price' => (float) $p->price, 'cost' => $cost, 'rises' => $rises];
            DB::table('products')->where('id', $p->id)->update($this->priceIn(2020, $p->id));
        }
    }

    /** @return array{price: float, cost_price?: float} */
    private function priceIn(int $year, int $productId): array
    {
        $info = $this->prices[$productId];
        $factor = 1.0;
        foreach ($info['rises'] as $y => $rise) {
            if ($y > $year) {
                $factor *= $rise;
            }
        }
        $values = ['price' => round($info['price'] / $factor, 2)];
        if ($info['cost'] !== null) {
            $values['cost_price'] = round($info['cost'] / $factor, 2);
        }

        return $values;
    }

    protected function raisePrices(): void
    {
        $year = $this->local()->year;
        foreach (array_keys($this->catalog) as $productId) {
            if (isset($this->prices[$productId])) {
                Product::whereKey($productId)->first()->forceFill($this->priceIn($year, $productId))->save();
            }
        }
    }

    // ───────────────────────────── Plan ─────────────────────────────

    /** Exact daily plan for 22,512 orders and a pool of 22,512 line counts adding up to 65,000. */
    private function buildPlan(): void
    {
        $first = CarbonImmutable::parse(self::FIRST_SALE_DAY);
        $last = CarbonImmutable::parse(self::LAST_SALE_DAY);
        $monthNoise = [];
        $rates = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            if ($day->isSunday() || $day->format('m-d') === '12-25' || $day->format('m-d') === '01-01') {
                continue;
            }
            $ym = $day->format('Y-m');
            $monthNoise[$ym] ??= 1 + mt_rand(-35, 35) / 1000;
            $years = abs($first->diffInDays($day)) / 365.25;
            $rates[$day->toDateString()] = (1.044 ** $years) * self::SEASON[$day->month] * $monthNoise[$ym]
                * ($day->isSaturday() ? 0.7 : 1.0) * (1 + mt_rand(-15, 15) / 100);
        }
        $scale = self::TARGET_SALES / array_sum($rates);
        $running = 0.0;
        $given = 0;
        foreach ($rates as $date => $rate) {
            $running += $rate * $scale;
            $n = (int) round($running) - $given;
            $given += $n;
            $this->days[$date] = $n;
        }

        // Full-month equivalent orders per month (partial months scaled up), for stock sizing.
        $byMonth = [];
        $planned = [];
        foreach ($this->days as $date => $n) {
            $ym = substr($date, 0, 7);
            $byMonth[$ym] = ($byMonth[$ym] ?? 0) + $n;
            $planned[$ym] = ($planned[$ym] ?? 0) + 1;
        }
        foreach ($byMonth as $ym => $n) {
            $month = CarbonImmutable::parse($ym.'-01');
            $open = 0;
            for ($d = $month; $d->month === $month->month; $d = $d->addDay()) {
                $open += $d->isSunday() ? 0 : 1;
            }
            $this->monthRate[$ym] = $n * $open / max(1, $planned[$ym]);
        }

        // Line counts: 1–6 per order, exactly 65,000 in total.
        $weights = [1 => 20, 2 => 27, 3 => 24, 4 => 15, 5 => 9, 6 => 5];
        for ($i = 0; $i < self::TARGET_SALES; $i++) {
            $this->linePool[] = (int) $this->weighted($weights);
        }
        $sum = array_sum($this->linePool);
        while ($sum !== self::TARGET_LINES) {
            $i = mt_rand(0, self::TARGET_SALES - 1);
            if ($sum < self::TARGET_LINES && $this->linePool[$i] < 6) {
                $this->linePool[$i]++;
                $sum++;
            } elseif ($sum > self::TARGET_LINES && $this->linePool[$i] > 1) {
                $this->linePool[$i]--;
                $sum--;
            }
        }

        $years = [];
        foreach ($this->days as $date => $n) {
            $years[substr($date, 0, 4)] = ($years[substr($date, 0, 4)] ?? 0) + $n;
        }
        $this->command->info('Plan: '.array_sum($this->days).' orders, '.array_sum($this->linePool).' lines; per year '.collect($years)->map(fn ($n, $y) => "{$y}: {$n}")->implode(', '));
    }

    protected function quotationsPerMonth(CarbonImmutable $month): float
    {
        $ym = $month->format('Y-m');
        $rate = $this->monthRate[$ym] ?? ($ym < array_key_first($this->monthRate) ? reset($this->monthRate) : end($this->monthRate));

        // monthlyDemand() multiplies by 0.66 (the share of quotations that become orders).
        return $rate / 0.66;
    }

    protected function linesPerQuotation(): float
    {
        return self::TARGET_LINES / self::TARGET_SALES;
    }

    /** Schedules this month's planned orders, plus the quotations that customers turn down. */
    protected function planMonth(CarbonImmutable $month): void
    {
        $ym = $month->format('Y-m');
        $monthStart = $month->startOfMonth()->setTime(8, 0);
        foreach ($this->days as $date => $n) {
            if (! str_starts_with($date, $ym)) {
                continue;
            }
            $day = CarbonImmutable::parse($date.' 00:00', self::LOCAL_TZ);
            for ($i = 0; $i < $n; $i++) {
                $lines = array_pop($this->linePool) ?? 3;
                [$create, $decide] = $this->orderTimes($day, $monthStart);
                $this->at($create, fn () => $this->createPlannedQuotation($lines, 'accepted', $decide));

                if (mt_rand(1, 100) <= 32) {
                    [$create] = $this->orderTimes($day, $monthStart);
                    $outcome = $this->weighted(['rejected' => 65, 'silent' => 25, 'declined' => 10]);
                    $this->at($create, fn () => $this->createPlannedQuotation((int) $this->weighted($this->lineCountWeights()), $outcome, null));
                }
            }
        }
    }

    /**
     * Quotation on the previous working day (or early the same day), accepted on the planned day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function orderTimes(CarbonImmutable $day, CarbonImmutable $monthStart): array
    {
        $decide = $day->setTime($day->isSaturday() ? mt_rand(11, 13) : mt_rand(11, 16), mt_rand(0, 59));
        $previous = $day->subDay();
        if ($previous->isSunday()) {
            $previous = $previous->subDay();
        }
        $create = mt_rand(1, 100) <= 70 && $previous->gte($monthStart->startOfDay())
            ? $previous->setTime(mt_rand(8, 14), mt_rand(0, 59))
            : $day->setTime(8, mt_rand(0, 50));
        $floor = $monthStart->max(CarbonImmutable::parse(self::START, self::LOCAL_TZ));
        if ($create->lt($floor)) {
            $create = $floor->addMinutes(mt_rand(5, 50));
        }

        return [$create, $decide];
    }

    protected function lineCountWeights(): array
    {
        return [1 => 20, 2 => 27, 3 => 24, 4 => 15, 5 => 9, 6 => 5];
    }

    // ───────────────────────────── Quotations → Sales Orders ─────────────────────────────

    private function createPlannedQuotation(int $lineCount, string $outcome, ?CarbonImmutable $decideAt): void
    {
        $client = $this->pickClient();
        $demand = $this->demandWeights($this->local());
        $lineCount = min($lineCount, count(array_filter($demand)));
        $picked = [];
        while (count($picked) < $lineCount) {
            $picked[$this->weighted($demand)] = true;
        }

        $items = [];
        $subtotal = 0.0;
        foreach (array_keys($picked) as $productId) {
            $product = Product::find($productId);
            $info = $this->catalog[$productId];
            $qty = $this->roundQuantity(mt_rand($info['min'], $info['max']), $info['max']);
            $line = round($qty * (float) $product->price, 2);
            $subtotal += $line;
            $items[] = ['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => $qty,
                'unit_price' => $product->price, 'unit_cost' => $product->cost_price, 'supplier_id' => $product->supplier_id, 'discount' => 0, 'subtotal' => $line];
        }
        $discount = mt_rand(1, 100) <= 15 ? round($subtotal * mt_rand(2, 5) / 100, 2) : 0.0;
        $total = round($subtotal - $discount, 2);

        $quotation = DB::transaction(function () use ($client, $items, $subtotal, $discount, $total) {
            $quotation = Quotation::createWithNumber([
                'team_id' => $this->team->id,
                'client_id' => $client->id,
                'client_name' => $client->company_name,
                'contact_person' => $client->contact_person,
                'email' => $client->email,
                'phone' => $client->phone,
                'address' => $client->address,
                'quotation_date' => now()->toDateString(),
                'valid_until' => null,
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'grand_total' => $total,
                'status' => QuotationStatus::Draft,
                'notes' => $discount > 0 ? 'Project discount applied.' : null,
                'terms_and_conditions' => QuotationTerms::text(),
                'prepared_by_id' => $this->admin->id,
            ], $items[0]['sku'], now()->toDateString());
            foreach ($items as $item) {
                $quotation->items()->create($item);
            }

            return $quotation;
        });
        $this->count('quotations');

        $id = $quotation->id;
        $workflow = fn () => app(QuotationWorkflow::class);
        $now = $this->local();

        if ($outcome === 'declined') { // declined by us before sending
            $this->at($this->businessTime($now->addMinutes(mt_rand(60, 300))), fn () => $workflow()->changeStatus(Quotation::find($id), QuotationStatus::Rejected, $this->admin, 'Requested items or lead time could not be met.'));

            return;
        }

        $submit = $now->addMinutes(mt_rand(10, 30));
        $approve = $submit->addMinutes(mt_rand(20, 90));
        $send = $approve->addMinutes(mt_rand(10, 40));
        $this->at($submit, fn () => $workflow()->submitForApproval(Quotation::find($id), $this->admin));
        $this->at($approve, fn () => $workflow()->approve(Quotation::find($id), $this->admin));
        $this->at($send, fn () => $workflow()->send(Quotation::find($id), $this->admin));

        if ($outcome === 'silent') {
            return; // no answer: it expires after its validity date
        }
        $source = $this->weighted(['email' => 45, 'phone' => 25, 'purchase_order' => 20, 'in_person' => 10]);

        if ($outcome === 'rejected') {
            $decide = $this->businessTime($send->addMinutes(mt_rand(120, 2 * 24 * 60)));
            if ($decide->toDateString() >= $approve->addDays(3)->toDateString()) {
                $decide = $approve->addDays(2)->setTime(15, 0);
            }
            $remarks = $this->pick(['Customer chose another supplier with a lower price.', 'Project postponed by the owner.', 'Budget not approved this quarter.', 'Lead time too long for the project schedule.', 'Customer will source the items from their main contractor.']);
            $this->at($decide, fn () => $workflow()->recordDecision(Quotation::find($id), false, $source, $remarks, $this->admin));

            return;
        }

        $decide = $decideAt && $decideAt->gt($send->addMinutes(20)) ? $decideAt : $send->addMinutes(mt_rand(30, 90));
        $pickup = mt_rand(1, 100) <= 15;
        $this->at($decide, function () use ($workflow, $id, $source, $pickup) {
            $accepted = $workflow()->recordDecision(Quotation::find($id), true, $source, 'Accepted by the customer.', $this->admin);
            try {
                [$sale] = app(SalesOrderService::class)->createFromQuotation($accepted, $this->admin, ['fulfillment_type' => $pickup ? 'pickup' : 'delivery']);
            } catch (WorkflowException $e) {
                $this->failure('Sales Order not created: '.$e->getMessage());

                return;
            }
            $this->count('sales_orders');
            $this->planPayment($sale->id);
        });
    }

    // ───────────────────────────── Demand ─────────────────────────────

    /** @param  array<string, list<\App\Models\Supplier>>  $suppliers */
    protected function createProducts(array $suppliers): void
    {
        parent::createProducts($suppliers);
        $this->balanceDemand();

        foreach (array_keys($this->catalog) as $id) {
            // Gentle trend over the years (−25% … +45%) and a small seasonal swing (0–10%).
            $this->shape[$id] = [mt_rand(-25, 45) / 100, mt_rand(0, 10) / 100, mt_rand(0, 628) / 100];
        }

        // About a third of the products get a verified alternate supplier that cannot supply right now,
        // so the AHP supplier-availability criterion differs between products.
        $active = DB::table('suppliers')->where('team_id', $this->team->id)->where('status', 'Active')->whereNull('deleted_at')->pluck('id')->all();
        foreach ($this->catalog as $id => $info) {
            if (crc32("unavailable-{$id}") % 3 !== 0) {
                continue;
            }
            $linked = DB::table('product_supplier')->where('product_id', $id)->pluck('supplier_id')->all();
            $candidates = array_values(array_diff($active, $linked));
            if ($candidates === []) {
                continue;
            }
            DB::table('product_supplier')->insert([
                'team_id' => $this->team->id, 'product_id' => $id, 'supplier_id' => $candidates[crc32("alt-{$id}") % count($candidates)],
                'is_available' => false, 'notes' => 'Asked for this item; currently cannot supply it.',
                'recorded_by_id' => $this->admin->id, 'verified_at' => now(), 'verified_by_id' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Narrower popularity and order-size ranges by price than the parent's, so the cheapest items do not
     * sell 25× the typical product. The AHP demand and movement criteria are scored against the top
     * seller, and with that gap almost every product scored "Low Priority". The opening stock, reorder
     * level and reorder quantity the parent derived are recalculated from the new ranges.
     */
    protected function balanceDemand(): void
    {
        $tier = fn (float $price) => match (true) {
            $price < 5 => [6, 10, 50],
            $price < 25 => [5, 5, 40],
            $price < 100 => [5, 3, 24],
            $price < 500 => [3, 1, 12],
            $price < 2000 => [2, 1, 5],
            default => [1, 1, 2],
        };
        $products = Product::where('team_id', $this->team->id)->whereIn('id', array_keys($this->catalog))->get();
        foreach ($products as $p) {
            [$weight, $min, $max] = $tier((float) $p->price);
            $this->catalog[$p->id] = ['weight' => max(1, $weight + mt_rand(-1, 1)), 'min' => $min, 'max' => $max] + $this->catalog[$p->id];
        }
        $totalWeight = array_sum(array_column($this->catalog, 'weight'));
        $start = CarbonImmutable::parse(self::START, self::LOCAL_TZ);
        $typical = CarbonImmutable::parse('2023-06-01', self::LOCAL_TZ);

        foreach ($products as $p) {
            $info = $this->catalog[$p->id];
            $opening = $this->monthlyDemand($info['weight'], $info['min'], $info['max'], $totalWeight, $start);
            $reorderQty = $this->lotSize($info['max']);
            $reorderLevel = max(1, (int) round($this->monthlyDemand($info['weight'], $info['min'], $info['max'], $totalWeight, $typical) * 0.8));
            $stock = max($reorderQty, (int) (ceil(($opening * 3 + $info['max']) / $reorderQty) * $reorderQty));

            $p->forceFill(['stock' => $stock, 'min_stock' => $reorderLevel, 'reorder_level' => $reorderLevel, 'reorder_quantity' => $reorderQty])->save();
            $p->updateCalculatedStatus();
            DB::table('stock_movements')->where('product_id', $p->id)->where('reference_number', 'OPENING-'.$p->sku)
                ->update(['quantity' => $stock, 'new_stock' => $stock]);
        }
    }

    protected function demandWeights(CarbonImmutable $month, bool $planning = false): array
    {
        $ym = $month->format('Y-m');
        if (isset($this->mildCache[$ym])) {
            return $this->mildCache[$ym];
        }
        $progress = min(1.0, max(0.0, (($month->year - 2020) * 12 + $month->month - 1) / 82));
        $weights = [];
        foreach ($this->catalog as $id => $info) {
            [$trend, $amp, $phase] = $this->shape[$id] ?? [0.0, 0.0, 0.0];
            $factor = (1 + $trend * ($progress - 0.5)) * (1 + $amp * sin(2 * M_PI * ($month->month - 1) / 12 + $phase));
            $weights[$id] = max(1, (int) round($info['weight'] * 10 * $factor));
        }
        $this->mildCache = array_slice($this->mildCache, -4, null, true);

        return $this->mildCache[$ym] = $weights;
    }

    // ───────────────────────────── Procurement with bidding ─────────────────────────────

    protected function reviewRequest(int $prId): void
    {
        $pr = PurchaseRequest::find($prId);
        if (crc32("bid-{$prId}") % 8 !== 0 || $pr->status !== PurchaseRequest::PENDING) {
            parent::reviewRequest($prId);

            return;
        }

        // Approved for supplier bidding: the product keeps its open request until the award.
        app(ProcurementWorkflow::class)->review($pr, $this->admin, true, 'Approved; sent to supplier bidding to compare prices.', true);
        $this->count('purchase_requests_to_bidding');
        $info = $this->catalog[$pr->product_id];
        $others = array_values(array_diff(array_keys($this->supplierProfile), [$info['primary'], $info['secondary']]));
        shuffle($others);
        $invited = array_values(array_unique(array_merge([$info['primary'], $info['secondary']], array_slice($others, 0, mt_rand(1, 2)))));
        $deadline = $this->businessTime($this->local()->addDays(mt_rand(2, 4))->setTime(16, 0));

        $event = app(BiddingWorkflow::class)->createEvent($this->team, $this->admin, $pr, [
            'supplier_ids' => $invited,
            'submission_deadline' => $deadline->toIso8601String(),
            'rfq_notes' => 'Please quote your best unit price and lead time for the full quantity.',
        ]);
        $this->count('bidding_events');
        $eventId = $event->id;
        $issue = $this->local()->addMinutes(mt_rand(30, 120));
        $this->at($issue, fn () => app(BiddingWorkflow::class)->issue(BiddingEvent::find($eventId), $this->admin));

        foreach ($invited as $i => $supplierId) {
            if ($i > 0 && mt_rand(1, 100) <= 20) {
                continue; // no reply from this supplier
            }
            $span = max(180, abs($issue->diffInMinutes($deadline)) - 90);
            $this->at($issue->addMinutes(mt_rand(60, (int) $span)), fn () => $this->recordBid($eventId, $supplierId, $pr->quantity));
        }
        $this->at($deadline->addMinutes(10), fn () => app(BiddingWorkflow::class)->close(BiddingEvent::find($eventId), $this->admin));
        $this->at($this->businessTime($deadline->addMinutes(mt_rand(60, 20 * 60))), fn () => $this->award($eventId));
    }

    private function recordBid(int $eventId, int $supplierId, int $quantity): void
    {
        $event = BiddingEvent::find($eventId);
        $product = Product::find($event->purchaseRequest->product_id);
        $profile = $this->supplierProfile[$supplierId];
        app(BiddingWorkflow::class)->recordBid($event, $this->admin, [
            'supplier_id' => $supplierId,
            'unit_price' => round($this->supplierPrice($product, $supplierId) * mt_rand(94, 106) / 100, 2),
            'quantity' => $quantity,
            'lead_time_days' => max(2, $profile['lead'] + mt_rand(-1, 2)),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'quotation_reference' => 'SQ-'.$profile['code'].'-'.(20000 + ++$this->reference),
        ]);
        $this->count('supplier_bids');
    }

    private function award(int $eventId): void
    {
        $event = BiddingEvent::with('bids')->find($eventId);
        $pr = $event->purchaseRequest;
        if ($event->bids->isEmpty()) {
            app(BiddingWorkflow::class)->cancel($event, $this->admin, 'No supplier sent a quotation.');
            $this->openRequest[$pr->product_id] = false;
            $this->incoming[$pr->product_id] = max(0, $this->incoming[$pr->product_id] - $pr->quantity);

            return;
        }
        // Lowest price wins; a much faster supplier wins when it is at most 2% dearer.
        $bids = $event->bids->sortBy([['unit_price', 'asc'], ['lead_time_days', 'asc']])->values();
        $winner = $bids->first();
        $fastest = $bids->sortBy('lead_time_days')->first();
        $remarks = null;
        if ($fastest->id !== $winner->id && (float) $fastest->unit_price <= (float) $winner->unit_price * 1.02 && $fastest->lead_time_days + 3 <= $winner->lead_time_days) {
            $winner = $fastest;
            $remarks = 'Within 2% of the lowest price with a much shorter lead time.';
        }
        if ($bids->count() < BiddingWorkflow::MIN_SUPPLIERS) {
            $remarks = 'Only one supplier replied; the price is in line with its earlier quotes.';
        }
        $event = app(BiddingWorkflow::class)->award($event, $this->admin, $winner->id, $remarks);
        $this->count('bidding_awarded');
        $this->openRequest[$pr->product_id] = false;
        $poId = $event->purchase_order_id;
        $this->at($this->businessTime($this->local()->addMinutes(mt_rand(30, 240))), fn () => $this->sendOrder($poId));
    }

    protected function sendOrder(int $poId): void
    {
        $event = BiddingEvent::where('purchase_order_id', $poId)->with('awardedBid')->first();
        if (! $event) {
            parent::sendOrder($poId);

            return;
        }
        // From a bidding: price, quantity and lead time are as quoted.
        $po = PurchaseOrder::find($poId);
        $lead = (int) $event->awardedBid->lead_time_days;
        $workflow = app(ProcurementWorkflow::class);
        $workflow->updateDraftPurchaseOrder($po, $this->admin, [
            'supplier_id' => $po->supplier_id,
            'quantity' => $po->quantity,
            'unit_cost' => (float) $po->unit_cost,
            'po_date' => now()->toDateString(),
            'promised_delivery_date' => $this->local()->addDays($lead)->toDateString(),
            'payment_terms' => $this->pick(['30 days', '30 days', '15 days', 'COD']),
            'notes' => $po->notes,
        ]);
        $workflow->sendPurchaseOrder($po->fresh(), $this->admin);
        $this->count('purchase_orders');

        $profile = $this->supplierProfile[$po->supplier_id];
        $late = mt_rand(1, 1000) <= (int) ($profile['late'] * 1000) ? mt_rand(1, 6) : mt_rand(-1, 0);
        $arrival = $this->businessTime($this->local()->addDays(max(1, $lead + $late))->setTime(mt_rand(8, 15), mt_rand(0, 59)));
        $partial = mt_rand(1, 100) <= 10 && $po->quantity > 1;
        $this->at($arrival, fn () => $this->receiveGoods($poId, $partial ? (int) ceil($po->quantity * 0.6) : null));
    }

    // ───────────────────────────── Finish ─────────────────────────────

    protected function finish(): void
    {
        // Prices end exactly at today's catalogue prices.
        foreach ($this->prices as $id => $info) {
            DB::table('products')->where('id', $id)->update(array_filter(['price' => $info['price'], 'cost_price' => $info['cost']], fn ($v) => $v !== null));
        }
        parent::finish();

        $sales = Sale::where('team_id', $this->team->id);
        $this->command->info(sprintf('Sales orders: %d (target %d) · order lines: %d (target %d) · emails blocked (never sent): %d',
            (clone $sales)->count(), self::TARGET_SALES,
            SaleItem::whereIn('sale_id', (clone $sales)->select('id'))->count(), self::TARGET_LINES, $this->blockedEmails));
        if ((string) config('services.resend.key') !== '') {
            throw new RuntimeException('Resend key was not blank at the end of the run.');
        }
    }
}
