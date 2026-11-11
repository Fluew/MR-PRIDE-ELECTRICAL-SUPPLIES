<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryRelease;
use App\Models\OrderFulfillment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\Documents\DocumentPdf;
use App\Services\Inventory\SkuGenerator;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use App\Support\QuotationTerms;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SplMinHeap;

/**
 * Demo / test data from January 2020 to today for Inventory, Quotations, Sales, Deliveries,
 * Procurement and (through the sales history) Demand Forecasting.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * It is a time-ordered simulation that runs every step through the system's own workflow
 * services with the clock set to that step's date. Stock levels, reservations, document numbers,
 * invoices, receipts, delivery history and audit logs are therefore consistent with each other.
 * Work dated after today is left unfinished, so the current month shows items in progress.
 *
 * - All actions are done as the existing administrator; no login accounts are created.
 * - Customers and suppliers are fictional (example.com addresses). Emails go to an in-memory
 *   mailer and nothing is sent. PDFs are not stored; they are rendered when downloaded.
 * - It runs only on a team without products or orders, so it never mixes with real data.
 */
class DemoDataSeeder extends Seeder
{
    private const START = '2020-01-02 08:00';

    private const LOCAL_TZ = 'Asia/Manila';

    private Team $team;

    private User $admin;

    private CarbonImmutable $end;

    private SplMinHeap $queue;

    private int $seq = 0;

    /** @var array<int, array{weight: int, min: int, max: int, primary: int, secondary: int}> */
    private array $catalog = [];

    /** @var list<array{client: Client, weight: int}> */
    private array $clients = [];

    /** @var array<int, array{lead: int, late: float, reject: float, price: float, code: string}> */
    private array $supplierProfile = [];

    /** @var array<int, int> units on order (requested, not yet received) per product */
    private array $incoming = [];

    /** @var array<int, bool> products with a purchase request waiting for approval */
    private array $openRequest = [];

    private int $reference = 0;

    private ?string $lastUrgentCheck = null;

    /** @var array<string, int> */
    private array $stats = [];

    /** @var array<string, int> */
    private array $failures = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Demo data is not seeded in production.');

            return;
        }

        $this->team = Team::where('slug', 'mr-pride')->first() ?? Team::query()->orderBy('id')->firstOrFail();
        $this->admin = User::where('role', 'admin')->where('current_team_id', $this->team->id)->orderBy('id')->first()
            ?? User::where('role', 'admin')->orderBy('id')->firstOrFail();

        $hasData = Product::withTrashed()->where('team_id', $this->team->id)->exists()
            || Sale::withTrashed()->where('team_id', $this->team->id)->exists()
            || Quotation::withTrashed()->where('team_id', $this->team->id)->exists();
        if ($hasData) {
            $this->command->error("Team \"{$this->team->name}\" already has products, quotations or orders. Demo data is only seeded on an empty team.");

            return;
        }

        $this->prepareEnvironment();
        // DEMO_DATA_UNTIL (e.g. 2020-06-30) stops the simulation earlier, for a quick trial run.
        $this->end = env('DEMO_DATA_UNTIL') ? CarbonImmutable::parse(env('DEMO_DATA_UNTIL').' 23:59', self::LOCAL_TZ)->utc() : CarbonImmutable::now('UTC');
        $this->queue = new class extends SplMinHeap
        {
            protected function compare(mixed $value1, mixed $value2): int
            {
                return [$value2[0], $value2[1]] <=> [$value1[0], $value1[1]];
            }
        };

        $start = CarbonImmutable::parse(self::START, self::LOCAL_TZ);
        $this->travel($start);
        $this->command->info("Seeding demo data for {$this->team->name} from {$start->toDateString()} to {$this->end->toDateString()} as {$this->admin->email}…");

        $suppliers = $this->createSuppliers();
        $this->createProducts($suppliers);
        $this->createClients();
        $this->recordSupplierQuotes($start);
        $this->schedule($start);

        $this->runQueue();

        $this->travel(null);
        $this->finish();
    }

    // ───────────────────────────── Setup ─────────────────────────────

    private function prepareEnvironment(): void
    {
        mt_srand(20200102);
        // Customer emails: in-memory mailer, jobs run at once (never queued for the live worker).
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        // PDFs are rendered on download, so none are generated or stored while seeding.
        app()->instance(DocumentPdf::class, new class extends DocumentPdf
        {
            public function quotation(Quotation $quotation): string
            {
                return '';
            }

            public function salesOrder(Sale $sale): string
            {
                return '';
            }

            public function receipt(PaymentReceipt $receipt): string
            {
                return '';
            }

            public function invoice(SalesInvoice $invoice): string
            {
                return '';
            }

            public function deliveryReceipt(DeliveryRelease $release): string
            {
                return '';
            }

            public function store(string $pdf, string $folder, string $number): string
            {
                return '';
            }
        });
        Model::preventLazyLoading(false);
    }

    /** @return array<string, list<Supplier>> suppliers by category */
    private function createSuppliers(): array
    {
        $rows = [
            // name, contact, city, categories, lead days, late chance, reject chance, price factor, code
            ['Metro Manila Electrical Supply Corp.', 'Ramon dela Cruz', 'Quezon City', ['Breakers', 'Controls'], 4, 0.10, 0.04, 1.00, 'MMES'],
            ['Prime Switchgear Distributors Inc.', 'Liza Manalo', 'Pasig City', ['Breakers', 'Controls'], 6, 0.25, 0.08, 0.96, 'PSD'],
            ['Luzon Wire & Cable Trading', 'Arnel Bautista', 'Valenzuela City', ['Wiring', 'Pipes'], 5, 0.15, 0.05, 0.98, 'LWC'],
            ['Cebu Power Components Trading', 'Grace Uy', 'Cebu City', ['Wiring', 'Lighting'], 9, 0.30, 0.10, 0.94, 'CPC'],
            ['Visayas Lighting Solutions Inc.', 'Paolo Lim', 'Mandaue City', ['Lighting', 'General'], 7, 0.20, 0.06, 0.99, 'VLS'],
            ['Pacific Industrial Hardware Co.', 'Teresa Gonzales', 'Caloocan City', ['Tools', 'General'], 5, 0.12, 0.05, 1.02, 'PIH'],
            ['Northpoint Tools & Equipment', 'Jerome Santos', 'San Fernando, Pampanga', ['Tools', 'Safety'], 8, 0.22, 0.07, 0.97, 'NTE'],
            ['Bayview Pipes and Fittings', 'Marites Reyes', 'Parañaque City', ['Pipes', 'General'], 4, 0.08, 0.03, 1.03, 'BPF'],
            ['Guardian Safety Supply PH', 'Dennis Aquino', 'Makati City', ['Safety', 'Lighting'], 6, 0.14, 0.04, 1.01, 'GSS'],
            ['Allied General Merchandise', 'Carmela Tan', 'Manila', ['General', 'Controls'], 10, 0.35, 0.12, 0.93, 'AGM'],
        ];

        $byCategory = [];
        foreach ($rows as $i => [$name, $contact, $city, $categories, $lead, $late, $reject, $price, $code]) {
            $supplier = Supplier::create([
                'team_id' => $this->team->id,
                'name' => $name,
                'contact_person' => $contact,
                'phone' => sprintf('+63 917 %03d %04d', 200 + $i * 7, 1000 + $i * 311),
                'email' => 'sales.'.strtolower($code).'@example.com',
                'address' => 'Unit '.(100 + $i * 12).", Industrial Avenue, {$city}, Philippines",
                'status' => 'Active',
            ]);
            $this->supplierProfile[$supplier->id] = compact('lead', 'late', 'reject', 'price', 'code');
            foreach ($categories as $category) {
                $byCategory[$category][] = $supplier;
            }
        }
        $this->count('suppliers', count($rows));

        return $byCategory;
    }

    /** @param  array<string, list<Supplier>>  $suppliers */
    private function createProducts(array $suppliers): void
    {
        // name, category, brand, unit, price (USD), popularity weight, quantity per order line [min, max]
        $rows = [
            ['Miniature Circuit Breaker 1P 20A', 'Breakers', 'Royu', 'pcs', 4.80, 8, 10, 60],
            ['Miniature Circuit Breaker 2P 30A', 'Breakers', 'Royu', 'pcs', 9.50, 6, 5, 40],
            ['Miniature Circuit Breaker 2P 60A', 'Breakers', 'Schneider Electric', 'pcs', 14.20, 4, 4, 24],
            ['Molded Case Circuit Breaker 3P 100A', 'Breakers', 'Schneider Electric', 'pcs', 118.00, 2, 1, 6],
            ['Plug-in Circuit Breaker 1P 15A', 'Breakers', 'Panasonic', 'pcs', 5.60, 5, 10, 50],
            ['RCBO 2P 32A 30mA', 'Breakers', 'Himel', 'pcs', 27.50, 2, 2, 12],
            ['Panel Board 12 Branches Bolt-on', 'Breakers', 'Himel', 'pcs', 64.00, 2, 1, 6],
            ['THHN Stranded Wire 2.0mm² (150 m)', 'Wiring', 'Phelps Dodge', 'roll', 68.00, 7, 2, 20],
            ['THHN Stranded Wire 3.5mm² (150 m)', 'Wiring', 'Phelps Dodge', 'roll', 104.00, 6, 2, 15],
            ['THHN Stranded Wire 5.5mm² (150 m)', 'Wiring', 'Philflex', 'roll', 158.00, 4, 1, 10],
            ['Flat Cord Wire #18 AWG (100 m)', 'Wiring', 'Philflex', 'roll', 22.00, 4, 2, 15],
            ['Flexible Conduit 1/2" (50 m)', 'Wiring', 'Paciflex', 'roll', 15.50, 4, 2, 20],
            ['Royal Cord 3C 2.0mm² (100 m)', 'Wiring', 'Duraflex', 'roll', 96.00, 2, 1, 6],
            ['LED Bulb 9W Daylight E27', 'Lighting', 'Koten', 'pcs', 1.40, 8, 20, 200],
            ['LED Bulb 13W Daylight E27', 'Lighting', 'Koten', 'pcs', 1.95, 6, 20, 150],
            ['LED Tube T8 18W 4 ft', 'Lighting', 'Panasonic', 'pcs', 3.20, 6, 10, 120],
            ['LED Panel Light 600x600 40W', 'Lighting', 'Hypertech', 'pcs', 18.50, 3, 4, 40],
            ['LED Floodlight 100W IP65', 'Lighting', 'Hypertech', 'pcs', 24.00, 3, 2, 20],
            ['Emergency Light Twin Head', 'Lighting', 'Koten', 'pcs', 21.00, 2, 2, 15],
            ['Wall Switch 1-Gang Wide Series', 'Controls', 'Panasonic', 'pcs', 1.10, 7, 20, 150],
            ['Duplex Universal Outlet with Ground', 'Controls', 'Royu', 'pcs', 1.90, 7, 20, 150],
            ['Magnetic Contactor 3P 32A 220V', 'Controls', 'Schneider Electric', 'pcs', 34.00, 2, 1, 10],
            ['Thermal Overload Relay 9-13A', 'Controls', 'Schneider Electric', 'pcs', 22.50, 2, 1, 10],
            ['Digital Timer Switch 16A', 'Controls', 'Himel', 'pcs', 12.80, 2, 2, 12],
            ['Weatherproof Outlet Box with Cover', 'Controls', 'Royu', 'pcs', 3.90, 3, 5, 40],
            ['Cordless Drill Driver 18V', 'Tools', 'Makita', 'pcs', 98.00, 2, 1, 4],
            ['Angle Grinder 4" 720W', 'Tools', 'DeWalt', 'pcs', 58.00, 2, 1, 5],
            ['Digital Multimeter Auto-Range', 'Tools', 'Ingco', 'pcs', 19.50, 3, 1, 8],
            ['Insulated Screwdriver Set (6 pcs)', 'Tools', 'Stanley', 'set', 14.00, 3, 2, 12],
            ['Wire Stripper and Cutter 8"', 'Tools', 'Stanley', 'pcs', 8.50, 3, 2, 15],
            ['Fish Tape 30 m Steel', 'Tools', 'Ingco', 'pcs', 16.00, 2, 1, 6],
            ['PVC Electrical Conduit 20mm x 3 m', 'Pipes', 'Neltex', 'pcs', 1.75, 6, 20, 200],
            ['PVC Electrical Conduit 25mm x 3 m', 'Pipes', 'Neltex', 'pcs', 2.40, 5, 20, 150],
            ['PVC Junction Box 4x4', 'Pipes', 'Emerald', 'pcs', 0.85, 5, 20, 200],
            ['uPVC Sanitary Pipe 2" x 3 m', 'Pipes', 'Crown', 'pcs', 6.80, 2, 5, 40],
            ['Safety Helmet with Ratchet Suspension', 'Safety', 'MSA', 'pcs', 9.80, 3, 5, 40],
            ['Electrical Insulating Gloves Class 0', 'Safety', 'Honeywell', 'pair', 42.00, 2, 1, 8],
            ['Safety Glasses Clear Anti-fog', 'Safety', '3M', 'pcs', 4.20, 3, 10, 60],
            ['Reflective Safety Vest', 'Safety', 'Delta Plus', 'pcs', 3.60, 3, 10, 60],
            ['Smoke Detector Photoelectric', 'Safety', 'Kidde', 'pcs', 15.80, 2, 2, 20],
            ['Electrical Tape 19mm x 16 m Black', 'General', '3M', 'pcs', 0.95, 6, 20, 200],
            ['Cable Tie 200mm (100 pcs)', 'General', null, 'pack', 2.10, 5, 5, 50],
            ['Terminal Lug 22mm² (50 pcs)', 'General', null, 'pack', 6.40, 2, 2, 15],
            ['Expansion Bolt 3/8" (50 pcs)', 'General', null, 'pack', 7.20, 2, 2, 12],
        ];
        $totalWeight = array_sum(array_column($rows, 5));
        $skus = app(SkuGenerator::class);

        foreach ($rows as [$name, $category, $brand, $unit, $price, $weight, $min, $max]) {
            $pool = $suppliers[$category];
            $primary = $pool[mt_rand(0, count($pool) - 1)];
            $others = array_values(array_filter($pool, fn ($s) => $s->id !== $primary->id));
            $secondary = $others ? $others[mt_rand(0, count($others) - 1)] : $primary;

            $monthly = $this->monthlyDemand($weight, $min, $max, $totalWeight, CarbonImmutable::parse(self::START, self::LOCAL_TZ));
            $reorderQty = $this->lotSize($max);
            $reorderLevel = max(3, (int) round($monthly * 0.8));
            $stock = max($reorderQty, (int) (ceil(($monthly * 3 + $max) / $reorderQty) * $reorderQty));

            $product = Product::create([
                'team_id' => $this->team->id,
                'sku' => $skus->next($this->team->id, $category),
                'name' => $name,
                'category' => $category,
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'cost_price' => round($price * (0.70 + mt_rand(0, 6) / 100), 2),
                'stock' => $stock,
                'min_stock' => $reorderLevel,
                'reorder_level' => $reorderLevel,
                'reorder_quantity' => $reorderQty,
                'description' => trim(($brand ? "{$brand} " : '').$name),
                'supplier_id' => $primary->id,
                'is_active' => true,
            ]);
            $product->updateCalculatedStatus();
            StockMovement::create([
                'team_id' => $this->team->id,
                'product_id' => $product->id,
                'user_id' => $this->admin->id,
                'supplier_id' => $primary->id,
                'type' => 'stock_in',
                'quantity' => $stock,
                'previous_stock' => 0,
                'new_stock' => $stock,
                'reference_number' => 'OPENING-'.$product->sku,
                'reason' => 'Opening balance',
                'remarks' => 'Initial stock registered upon product creation.',
                'movement_date' => now(),
            ]);
            foreach (array_unique([$primary->id, $secondary->id]) as $supplierId) {
                DB::table('product_supplier')->insert([
                    'team_id' => $this->team->id, 'product_id' => $product->id, 'supplier_id' => $supplierId, 'is_available' => true,
                    'notes' => $supplierId === $primary->id ? 'Usual supplier.' : 'Alternate supplier.',
                    'recorded_by_id' => $this->admin->id, 'verified_at' => now(), 'verified_by_id' => $this->admin->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->catalog[$product->id] = ['weight' => $weight, 'min' => $min, 'max' => $max, 'primary' => $primary->id, 'secondary' => $secondary->id];
            $this->incoming[$product->id] = 0;
        }
        $this->count('products', count($rows));
    }

    private function createClients(): void
    {
        $rows = [
            ['Apex Builders Corp.', 'Ana Villareal', 'Quezon City', 9], ['Silverline Construction Inc.', 'Mark Dizon', 'Pasig City', 8],
            ['Northgate Realty Development', 'Joy Mendoza', 'Caloocan City', 5], ['Bluewave Electrical Contractors', 'Rico Fernandez', 'Makati City', 9],
            ['Summit Engineering Services', 'Karen Lopez', 'Taguig City', 6], ['Greenfield Homes Inc.', 'Paul Navarro', 'Cavite', 5],
            ['Harbor Point Logistics', 'Ella Castillo', 'Manila', 3], ['Ironclad Fabrication Works', 'Vic Domingo', 'Valenzuela City', 4],
            ['Lakeside Resort & Spa', 'Bianca Cruz', 'Laguna', 2], ['Metrocore Facilities Management', 'Jun Ramirez', 'Mandaluyong City', 6],
            ['Nova Tech Industrial Park', 'Sheila Garcia', 'Batangas', 4], ['Orchid Medical Center', 'Dr. Leo Torres', 'San Juan City', 3],
            ['Pinnacle Mall Holdings', 'Iris Santiago', 'Pasay City', 3], ['Quantum Data Center PH', 'Neil Aquino', 'Taguig City', 2],
            ['Riverside School of Arts', 'Maricel Robles', 'Marikina City', 2], ['Sunrise Condominium Corp.', 'Ben Salazar', 'Makati City', 4],
            ['Titan Steel Works', 'Oscar Pineda', 'Bulacan', 3], ['Vista Verde Homeowners Association', 'Linda Ocampo', 'Antipolo City', 2],
            ['Westbridge Warehouse Co.', 'Eric Mercado', 'Parañaque City', 3], ['Zenith Power Contractors', 'Gino Valdez', 'Las Piñas City', 7],
            ['Crescent Bay Hotel', 'Trina Morales', 'Pasay City', 2], ['Golden Grain Rice Mill', 'Rodel Agustin', 'Nueva Ecija', 2],
            ['Coastal Cold Storage Inc.', 'Faye Ilagan', 'Navotas City', 2], ['Del Rosario Electrical Works', 'Jojo del Rosario', 'Quezon City', 7],
            ['Santos Home Improvement Center', 'Cathy Santos', 'Muntinlupa City', 5], ['Villanueva Engineering Consultancy', 'Noel Villanueva', 'Pasig City', 4],
            ['Ramos Builders & Supply', 'Edwin Ramos', 'Rizal', 5], ['Evergreen Agri Supply', 'Tess Javier', 'Pampanga', 2],
            ['Brightway Signage & Lights', 'Allan Co', 'Manila', 3], ['Keystone Property Ventures', 'Mia Reyes', 'Taguig City', 3],
        ];
        foreach ($rows as $i => [$company, $contact, $city, $weight]) {
            $slug = preg_replace('/[^a-z0-9]+/', '', strtolower(strtok($company, ' ')).strtolower((string) strtok(' ')));
            $client = Client::create([
                'team_id' => $this->team->id,
                'company_name' => $company,
                'contact_person' => $contact,
                'email' => "purchasing.{$slug}@example.com",
                'phone' => sprintf('+63 918 %03d %04d', 300 + $i * 11, 2000 + $i * 173),
                'address' => (10 + $i * 7)." Rizal Street, {$city}, Philippines",
            ]);
            $this->clients[] = ['client' => $client, 'weight' => $weight];
        }
        $this->count('clients', count($rows));
    }

    /** Verified supplier price quotes for the year (used for PO price prefill and supplier evaluation). */
    private function recordSupplierQuotes(CarbonImmutable $on): void
    {
        foreach ($this->catalog as $productId => $info) {
            $product = Product::find($productId);
            foreach (array_unique([$info['primary'], $info['secondary']]) as $supplierId) {
                SupplierQuote::create([
                    'team_id' => $this->team->id,
                    'supplier_id' => $supplierId,
                    'product_id' => $productId,
                    'reference' => 'QT-'.$on->format('Y').'-'.$this->supplierProfile[$supplierId]['code'].'-'.$product->sku,
                    'unit_price' => $this->supplierPrice($product, $supplierId),
                    'currency' => 'USD',
                    'unit' => $product->unit,
                    'tax_inclusive' => false,
                    'quoted_on' => $on->toDateString(),
                    'valid_until' => $on->endOfYear()->toDateString(),
                    'notes' => 'Annual price list.',
                    'recorded_by_id' => $this->admin->id,
                    'verified_at' => $on->addDays(2),
                    'verified_by_id' => $this->admin->id,
                ]);
                $this->count('supplier_quotes');
            }
        }
    }

    // ───────────────────────────── Schedule ─────────────────────────────

    private function schedule(CarbonImmutable $start): void
    {
        $endLocal = $this->end->setTimezone(self::LOCAL_TZ);
        for ($month = $start->startOfMonth(); $month->lte($endLocal); $month = $month->addMonth()) {
            $this->at($month->setTime(6, 0), fn () => $this->planMonth($month));
            foreach ([1, 15] as $day) {
                if (! ($month->year === 2020 && $month->month === 1 && $day === 1)) {
                    $this->at($month->setDate($month->year, $month->month, $day)->setTime(7, 30), fn () => $this->stockCheck());
                }
            }
            if ($month->month === 1 && $month->year > 2020) {
                $this->at($month->setDate($month->year, 1, 2)->setTime(7, 0), function () use ($month) {
                    $this->raisePrices();
                    $this->recordSupplierQuotes($month->setDate($month->year, 1, 2)->setTime(9, 0));
                });
            }
        }
        // Unanswered quotations expire after their validity date (as the Quotations page does).
        for ($day = $start; $day->lte($endLocal); $day = $day->addDay()) {
            $this->at($day->setTime(0, 10), fn () => app(QuotationWorkflow::class)->expireDue($this->team->id));
        }
    }

    private function planMonth(CarbonImmutable $month): void
    {
        $count = max(1, (int) round($this->quotationsPerMonth($month) * (0.8 + mt_rand(0, 40) / 100)));
        for ($i = 0; $i < $count; $i++) {
            $day = $this->businessTime($month->setDate($month->year, $month->month, mt_rand(1, $month->daysInMonth))->setTime(mt_rand(8, 15), mt_rand(0, 59)));
            if ($day->month === $month->month) {
                $this->at($day, fn () => $this->createQuotation());
            }
        }
    }

    // ───────────────────────────── Quotations → Sales Orders ─────────────────────────────

    private function createQuotation(): void
    {
        $client = $this->pickClient();
        $lineCount = $this->weighted([1 => 30, 2 => 35, 3 => 22, 4 => 13]);
        $picked = [];
        while (count($picked) < $lineCount) {
            $picked[$this->weighted(array_map(fn ($c) => $c['weight'], $this->catalog))] = true;
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

        if (mt_rand(1, 100) <= 3) { // declined by us before sending
            $this->at($this->businessTime($now->addMinutes(mt_rand(60, 600))), fn () => $workflow()->changeStatus(Quotation::find($id), QuotationStatus::Rejected, $this->admin, 'Requested items or lead time could not be met.'));

            return;
        }

        $submit = $now->addMinutes(mt_rand(10, 90));
        $approve = $this->businessTime($submit->addMinutes(mt_rand(60, 1200)));
        $send = $this->businessTime($approve->addMinutes(mt_rand(15, 240)));
        $this->at($submit, fn () => $workflow()->submitForApproval(Quotation::find($id), $this->admin));
        $this->at($approve, fn () => $workflow()->approve(Quotation::find($id), $this->admin));
        $this->at($send, fn () => $workflow()->send(Quotation::find($id), $this->admin));

        $outcome = $this->weighted(['accepted' => 69, 'rejected' => 22, 'silent' => 9]);
        if ($outcome === 'silent') {
            return; // no answer: it expires after its validity date
        }
        // Answered within the 3-day validity.
        $decide = $this->businessTime($send->addMinutes(mt_rand(60, 2 * 24 * 60)));
        if ($decide->toDateString() > $approve->addDays(3)->toDateString()) {
            $decide = $approve->addDays(3)->setTime(15, 0);
        }
        $source = $this->weighted(['email' => 45, 'phone' => 25, 'purchase_order' => 20, 'in_person' => 10]);

        if ($outcome === 'rejected') {
            $remarks = $this->pick(['Customer chose another supplier with a lower price.', 'Project postponed by the owner.', 'Budget not approved this quarter.', 'Lead time too long for the project schedule.', 'Customer will source the items from their main contractor.']);
            $this->at($decide, fn () => $workflow()->recordDecision(Quotation::find($id), false, $source, $remarks, $this->admin));

            return;
        }

        $pickup = mt_rand(1, 100) <= 15;
        $this->at($decide, function () use ($workflow, $id, $source, $pickup) {
            // Acceptance, then the Sales Order created from it (as staff do in Sales → New Sales Order).
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

    private function planPayment(int $saleId): void
    {
        $sale = Sale::find($saleId);
        $now = $this->local();
        $terms = $this->weighted(['full' => 60, 'down_payment' => 15, 'credit' => 25]);

        if ($terms === 'full') {
            if (mt_rand(1, 100) <= 4) { // cancelled before paying
                $this->at($this->businessTime($now->addDays(mt_rand(1, 4))), function () use ($saleId) {
                    app(SalesOrderService::class)->cancel(Sale::find($saleId), $this->pick(['Customer cancelled the order before payment.', 'Customer project was cancelled.', 'Duplicate order from the customer.']), $this->admin);
                    $this->count('cancelled_orders');
                });

                return;
            }
            $this->payAndVerify($saleId, $this->businessTime($now->addMinutes(mt_rand(60, 2 * 24 * 60))), (float) $sale->grand_total, startDelivery: true);

            return;
        }

        $setTerms = $this->businessTime($now->addMinutes(mt_rand(30, 300)));
        if ($terms === 'down_payment') {
            $down = round((float) $sale->grand_total * 0.5, 2);
            $this->at($setTerms, fn () => app(SalesOrderService::class)->setTerms(Sale::find($saleId), Sale::TERMS_DOWN_PAYMENT, $down, null, $this->admin));
            $this->payAndVerify($saleId, $this->businessTime($setTerms->addMinutes(mt_rand(60, 3 * 24 * 60))), $down, startDelivery: true);

            return;
        }

        $days = $this->pick([30, 30, 30, 45, 60]);
        $this->at($setTerms, function () use ($saleId, $days) {
            app(SalesOrderService::class)->setTerms(Sale::find($saleId), Sale::TERMS_CREDIT, null, $days, $this->admin);
            $this->startDelivery($saleId, $this->businessTime($this->local()->addDays(mt_rand(1, 3))), mt_rand(1, 100) <= 10);
            // Paid around the due date, sometimes in two instalments.
            $due = $this->local()->addDays($days + mt_rand(-12, 18));
            if (mt_rand(1, 100) <= 25) {
                $this->payBalance($saleId, $this->businessTime($due->subDays(mt_rand(10, 20))), 0.4);
            }
            $this->payBalance($saleId, $this->businessTime($due));
        });
    }

    /** Record a payment, then verify it (verification confirms the order when its terms are met). */
    private function payAndVerify(int $saleId, CarbonImmutable $when, float $amount, bool $startDelivery = false): void
    {
        $this->at($when, function () use ($saleId, $amount, $startDelivery) {
            $sale = Sale::find($saleId);
            if ($sale->status === SalesOrderStatus::Cancelled || $amount <= 0.004) {
                return;
            }
            $method = mt_rand(1, 100) <= 60 ? 'Bank Transfer' : 'GCash';
            $reference = $this->paymentReference($method);
            $payment = app(PaymentService::class)->record($sale, ['method' => $method, 'amount' => $amount, 'reference' => $reference, 'payment_date' => now()->toDateString()], null, $this->admin);
            $this->count('payments');
            $paymentId = $payment->id;

            $this->at($this->businessTime($this->local()->addMinutes(mt_rand(30, 26 * 60))), function () use ($paymentId, $saleId, $startDelivery) {
                $payment = Payment::find($paymentId);
                app(PaymentService::class)->verify($payment, ['amount' => (float) $payment->submitted_amount, 'reference' => $payment->submitted_reference, 'payment_date' => $payment->payment_date->toDateString()], $this->admin);
                if ($startDelivery && in_array(Sale::find($saleId)->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                    $this->startDelivery($saleId, $this->businessTime($this->local()->addDays(mt_rand(1, 3))), mt_rand(1, 100) <= 10);
                }
            });
        });
    }

    /** Pay what is still owed (or a share of it). */
    private function payBalance(int $saleId, CarbonImmutable $when, float $share = 1.0): void
    {
        $this->at($when, function () use ($saleId, $share) {
            $sale = Sale::find($saleId);
            $owed = round((float) $sale->grand_total - $sale->verifiedTotal(), 2);
            $pending = Payment::where('sale_id', $saleId)->whereIn('status', ['submitted', 'under_verification'])->sum('submitted_amount');
            $amount = round(($owed - (float) $pending) * $share, 2);
            if ($sale->status !== SalesOrderStatus::Cancelled && $amount > 0.004) {
                $this->payAndVerify($saleId, $this->local(), $amount);
            }
        });
    }

    // ───────────────────────────── Deliveries ─────────────────────────────

    private function startDelivery(int $saleId, CarbonImmutable $when, bool $split = false, int $tries = 0): void
    {
        $this->at($when, function () use ($saleId, $split) {
            $sale = Sale::find($saleId);
            if (! in_array($sale->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true)) {
                return;
            }
            $fulfillment = OrderFulfillment::where('sale_id', $saleId)->first();
            if (! $fulfillment || $fulfillment->openRelease()) {
                return;
            }
            $quantities = [];
            foreach (SaleItem::where('sale_id', $saleId)->get() as $line) {
                $open = $line->releasable_quantity;
                if ($open > 0) {
                    $quantities[$line->id] = $split && $open > 1 ? (int) ceil($open * 0.6) : $open;
                }
            }
            if ($quantities === []) {
                return;
            }
            $release = app(FulfillmentService::class)->prepareRelease($fulfillment, $quantities, $this->admin, $split ? 'First batch; the rest follows.' : null);
            $this->count('delivery_releases');
            $releaseId = $release->id;

            $ready = $this->businessTime($this->local()->addMinutes(mt_rand(60, 300)));
            $this->at($ready, fn () => app(FulfillmentService::class)->markReady(DeliveryRelease::find($releaseId), $this->admin));
            if ($release->type === 'pickup') {
                $this->retrying($this->businessTime($ready->addDays(mt_rand(1, 3))), fn () => $this->receive($releaseId, 'Picked up at the warehouse.'));
            } else {
                $this->retrying($this->businessTime($ready->addDay()->setTime(mt_rand(8, 9), mt_rand(0, 59))), fn () => $this->dispatchRelease($releaseId));
            }
        });
    }

    private function dispatchRelease(int $releaseId): void
    {
        $release = DeliveryRelease::find($releaseId);
        app(FulfillmentService::class)->dispatch($release, $this->admin);
        $fulfillmentId = $release->order_fulfillment_id;
        $arrive = $this->local()->addMinutes(mt_rand(120, 420));

        if (mt_rand(1, 100) <= 7) { // nobody to receive the goods
            $this->at($arrive, function () use ($fulfillmentId, $releaseId) {
                app(FulfillmentService::class)->recordFailedAttempt(OrderFulfillment::find($fulfillmentId),
                    $this->pick(['Customer unavailable', 'Site closed', 'Wrong or incomplete address']), 'Driver returned with the goods; delivery to be retried.', null, $this->admin);
                $retry = $this->businessTime($this->local()->addDay()->setTime(8, mt_rand(0, 59)));
                $this->at($retry, function () use ($fulfillmentId, $releaseId) {
                    app(FulfillmentService::class)->retryDispatch(OrderFulfillment::find($fulfillmentId), $this->admin);
                    $this->at($this->local()->addMinutes(mt_rand(120, 360)), fn () => $this->receive($releaseId));
                });
            });

            return;
        }
        $this->at($arrive, fn () => $this->receive($releaseId));
    }

    private function receive(int $releaseId, ?string $remarks = null): void
    {
        $release = DeliveryRelease::find($releaseId);
        $sale = Sale::find($release->sale_id);
        $status = app(FulfillmentService::class)->recordReceipt($release, [], $sale->contact_person ?: $sale->client_name, null, $remarks, $this->admin);
        $this->count('deliveries_received');

        if ($status === DeliveryStatus::Pending) { // part of the order still to follow
            $this->startDelivery($sale->id, $this->businessTime($this->local()->addDays(mt_rand(2, 6))));
        } elseif ($sale->payment_terms === Sale::TERMS_DOWN_PAYMENT) {
            $this->payBalance($sale->id, $this->businessTime($this->local()->addDays(mt_rand(2, 20))));
        }
    }

    // ───────────────────────────── Procurement ─────────────────────────────

    /** Twice a month: request stock for products whose stock (plus what is on order) runs low. */
    private function stockCheck(): void
    {
        $lines = [];
        $totalWeight = array_sum(array_column($this->catalog, 'weight'));
        // Units still owed to customers on confirmed orders (they are in stock until delivered).
        $committed = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::Processing->value])->whereNull('sales.deleted_at')
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity - sale_items.cancelled_quantity - sale_items.delivered_quantity) as owed')
            ->pluck('owed', 'product_id');
        foreach ($this->catalog as $productId => $info) {
            if ($this->openRequest[$productId] ?? false) {
                continue;
            }
            $product = Product::find($productId);
            $monthly = $this->monthlyDemand($info['weight'], $info['min'], $info['max'], $totalWeight, $this->local());
            $position = (int) $product->stock - (int) ($committed[$productId] ?? 0) + $this->incoming[$productId];
            // Enough for six weeks of demand and one large order.
            if ($position >= max((int) $product->reorder_level, $monthly * 1.5, $info['max'])) {
                continue;
            }
            $lot = max(1, (int) $product->reorder_quantity);
            $qty = (int) (ceil(max($lot, $monthly * 3.2 + $info['max'] - $position) / $lot) * $lot);
            $lines[] = [
                'product_id' => $productId,
                'quantity' => $qty,
                'supplier_id' => mt_rand(1, 100) <= 75 ? $info['primary'] : $info['secondary'],
                'justification' => 'Stock is below the reorder point; this covers about three months of forecast demand.',
            ];
        }
        if ($lines === []) {
            return;
        }

        $requests = app(ProcurementWorkflow::class)->submitRequests($this->team, $this->admin, $lines, false, 3);
        foreach ($requests as $pr) {
            $this->count('purchase_requests');
            $this->incoming[$pr->product_id] += $pr->quantity;
            $this->openRequest[$pr->product_id] = true;
            $prId = $pr->id;
            $this->at($this->businessTime($this->local()->addMinutes(mt_rand(90, 1500))), fn () => $this->reviewRequest($prId));
        }
    }

    private function reviewRequest(int $prId): void
    {
        $pr = PurchaseRequest::find($prId);
        $this->openRequest[$pr->product_id] = false;
        if (mt_rand(1, 100) <= 5) {
            app(ProcurementWorkflow::class)->review($pr, $this->admin, false, 'On hold: stock from the previous order is still enough for now.');
            $this->incoming[$pr->product_id] -= $pr->quantity;
            $this->count('purchase_requests_rejected');

            return;
        }
        app(ProcurementWorkflow::class)->review($pr, $this->admin, true, 'Approved per reorder policy.');
        $po = PurchaseOrder::where('purchase_request_id', $prId)->firstOrFail();
        $poId = $po->id;
        $this->at($this->businessTime($this->local()->addMinutes(mt_rand(30, 240))), fn () => $this->sendOrder($poId));
    }

    private function sendOrder(int $poId): void
    {
        $po = PurchaseOrder::find($poId);
        $product = Product::find($po->product_id);
        $supplierId = $po->supplier_id ?: $this->catalog[$product->id]['primary'];
        $profile = $this->supplierProfile[$supplierId];
        $lead = max(2, $profile['lead'] + mt_rand(-1, 2));

        $workflow = app(ProcurementWorkflow::class);
        $workflow->updateDraftPurchaseOrder($po, $this->admin, [
            'supplier_id' => $supplierId,
            'quantity' => $po->quantity,
            'unit_cost' => $this->supplierPrice($product, $supplierId),
            'po_date' => now()->toDateString(),
            'promised_delivery_date' => $this->local()->addDays($lead)->toDateString(),
            'payment_terms' => $this->pick(['30 days', '30 days', '15 days', 'COD']),
            'notes' => $po->notes,
        ]);
        $workflow->sendPurchaseOrder($po->fresh(), $this->admin);
        $this->count('purchase_orders');

        $late = mt_rand(1, 1000) <= (int) ($profile['late'] * 1000) ? mt_rand(1, 6) : mt_rand(-1, 0);
        $arrival = $this->businessTime($this->local()->addDays(max(1, $lead + $late))->setTime(mt_rand(8, 15), mt_rand(0, 59)));
        $partial = mt_rand(1, 100) <= 10 && $po->quantity > 1;
        $this->at($arrival, fn () => $this->receiveGoods($poId, $partial ? (int) ceil($po->quantity * 0.6) : null));
    }

    /** Goods arrive against the purchase order: delivery, then inspection (stock in). */
    private function receiveGoods(int $poId, ?int $quantity = null): void
    {
        $po = PurchaseOrder::find($poId);
        $workflow = app(ProcurementWorkflow::class);
        $open = $workflow->summary($po)['remaining'];
        if ($open <= 0) {
            return;
        }
        $qty = min($open, $quantity ?? $open);
        $profile = $this->supplierProfile[$po->supplier_id];
        $delivery = $workflow->recordDelivery($po, $this->admin, [
            'delivery_date' => now()->toDateString(),
            'quantity' => $qty,
            'supplier_document_number' => 'DR-'.$profile['code'].'-'.(10000 + ++$this->reference),
        ]);
        $this->count('inbound_deliveries');
        $deliveryId = $delivery->id;
        $more = $qty < $open; // the rest of the order (and any rejected units) comes in a later shipment
        if ($more) {
            $this->at($this->businessTime($this->local()->addDays(mt_rand(3, 8))), fn () => $this->receiveGoods($poId));
        }

        $this->at($this->local()->addMinutes(mt_rand(30, 180)), function () use ($deliveryId, $poId, $qty, $profile, $more) {
            $rejected = mt_rand(1, 1000) <= (int) ($profile['reject'] * 1000) ? max(1, (int) round($qty * mt_rand(2, 8) / 100)) : 0;
            $rejected = min($rejected, $qty - 1);
            $damaged = $rejected > 0 ? (int) ceil($rejected / 2) : 0;
            $data = ['accepted_quantity' => $qty - $rejected, 'rejected_quantity' => $rejected, 'inspected_on' => now()->toDateString(),
                'inspection_remarks' => $rejected ? 'Some units failed the inspection.' : 'Quantity and condition match the order.'];
            if ($rejected > 0) {
                $data += ['damaged_quantity' => $damaged, 'defective_quantity' => $rejected - $damaged, 'rejection_category' => $damaged >= $rejected - $damaged ? 'damaged' : 'defective',
                    'rejection_reason' => $this->pick(['Crushed boxes and dented units on arrival.', 'Units failed the function test.', 'Water damage on part of the shipment.'])];
            }
            $delivery = app(ProcurementWorkflow::class)->inspect(Delivery::find($deliveryId), $this->admin, $data);
            $po = PurchaseOrder::find($poId);
            $this->incoming[$po->product_id] = max(0, $this->incoming[$po->product_id] - $qty);
            if ($rejected > 0) {
                $this->at($this->businessTime($this->local()->addDays(mt_rand(2, 6))), function () use ($deliveryId, $poId, $more) {
                    $replace = $more || mt_rand(1, 100) <= 50;
                    app(ProcurementWorkflow::class)->resolveRejection(Delivery::find($deliveryId), $this->admin, $replace ? 'replaced' : 'credited',
                        $replace ? 'Supplier will replace the rejected units.' : 'Supplier credited the rejected units.');
                    if ($more) {
                        return; // the next shipment includes the replacements
                    }
                    if ($replace) {
                        $this->at($this->businessTime($this->local()->addDays(mt_rand(3, 7))), fn () => $this->receiveGoods($poId));
                    } elseif (in_array(PurchaseOrder::find($poId)->status, PurchaseOrder::RECEIVABLE, true)) {
                        app(ProcurementWorkflow::class)->closePurchaseOrder(PurchaseOrder::find($poId), $this->admin, 'Rejected units were credited by the supplier; no replacement needed.');
                    }
                });
            }
        });
    }

    // ───────────────────────────── Yearly price changes ─────────────────────────────

    private function raisePrices(): void
    {
        foreach (array_keys($this->catalog) as $productId) {
            $product = Product::find($productId);
            $rise = 1 + mt_rand(2, 6) / 100;
            $product->forceFill(['price' => round((float) $product->price * $rise, 2), 'cost_price' => round((float) $product->cost_price * $rise, 2)])->save();
        }
    }

    // ───────────────────────────── Demand model ─────────────────────────────

    /** Quotations per month: growth over the years, a construction-season pattern and the 2020 lockdown dip. */
    private function quotationsPerMonth(CarbonImmutable $month): float
    {
        $season = [1 => 0.85, 0.90, 1.15, 1.20, 1.15, 0.95, 0.85, 0.85, 0.95, 1.05, 1.10, 1.00][$month->month];
        $lockdown = $month->year === 2020 ? ([3 => 0.7, 4 => 0.35, 5 => 0.5, 6 => 0.75][$month->month] ?? 1.0) : 1.0;
        $years = ($month->year - 2020) + ($month->month - 1) / 12;

        return 7.0 * (1.09 ** $years) * $season * $lockdown;
    }

    private function monthlyDemand(int $weight, int $min, int $max, int $totalWeight, CarbonImmutable $month): float
    {
        return $this->quotationsPerMonth($month) * 2.2 * ($weight / $totalWeight) * 0.66 * (($min + $max) / 2);
    }

    private function lotSize(int $max): int
    {
        return match (true) {
            $max >= 100 => 50,
            $max >= 40 => 20,
            $max >= 15 => 10,
            $max >= 8 => 5,
            default => 2,
        };
    }

    private function roundQuantity(int $qty, int $max): int
    {
        $step = $max >= 100 ? 10 : ($max >= 30 ? 5 : 1);

        return max($step, (int) (round($qty / $step) * $step));
    }

    private function supplierPrice(Product $product, int $supplierId): float
    {
        return round((float) $product->cost_price * $this->supplierProfile[$supplierId]['price'], 2);
    }

    // ───────────────────────────── Engine ─────────────────────────────

    private function at(CarbonImmutable $when, callable $task): void
    {
        $this->queue->insert([$when->getTimestamp(), ++$this->seq, $task]);
    }

    /** A step that needs stock (dispatch, pickup): wait for the purchase to arrive and try again. */
    private function retrying(CarbonImmutable $when, callable $task, int $tries = 25): void
    {
        $this->at($when, function () use ($task, $tries) {
            try {
                $task();
            } catch (WorkflowException $e) {
                if ($tries <= 1) {
                    throw $e;
                }
                $this->count('waits_for_stock');
                // Short of stock: request it now instead of waiting for the next routine check.
                $today = now()->toDateString();
                if ($this->lastUrgentCheck !== $today) {
                    $this->lastUrgentCheck = $today;
                    $this->stockCheck();
                }
                $this->retrying($this->businessTime($this->local()->addDays(2)), $task, $tries - 1);
            }
        });
    }

    private function runQueue(): void
    {
        $limit = $this->end->getTimestamp();
        $done = 0;
        $lastMonth = '';
        while (! $this->queue->isEmpty()) {
            [$timestamp, , $task] = $this->queue->extract();
            if ($timestamp > $limit) {
                $this->stats['steps_left_for_later'] = $this->queue->count() + 1;
                break; // after today: left in progress
            }
            $this->travel(CarbonImmutable::createFromTimestamp($timestamp, 'UTC'));
            try {
                $task();
            } catch (WorkflowException $e) {
                $this->failure($e->getMessage());
            }
            $done++;
            if ($done % 200 === 0) {
                // The in-memory mailer keeps every message; drop them so memory stays flat.
                $transport = app('mail.manager')->mailer()->getSymfonyTransport();
                if (method_exists($transport, 'flush')) {
                    $transport->flush();
                }
                gc_collect_cycles();
            }
            $month = now()->format('Y-m');
            if ($month !== $lastMonth && str_ends_with($month, '-01')) {
                $this->command->line("  … {$month} ({$done} steps)");
            }
            $lastMonth = $month;
        }
        $this->stats['steps'] = $done;
    }

    private function finish(): void
    {
        // PDFs were not stored while seeding; downloads render them on demand.
        DB::table('quotations')->where('team_id', $this->team->id)->where('sent_pdf_path', '')->update(['sent_pdf_path' => null]);
        DB::table('sales_invoices')->where('team_id', $this->team->id)->where('pdf_path', '')->update(['pdf_path' => null]);
        DB::table('payment_receipts')->where('team_id', $this->team->id)->where('pdf_path', '')->update(['pdf_path' => null]);
        // In-app notifications older than two weeks are marked read, so the bell shows only recent ones.
        DB::table('notifications')->whereNull('read_at')->where('created_at', '<', now()->subDays(14))->update(['read_at' => DB::raw('created_at')]);
        Cache::flush();

        $this->command->info('Demo data created:');
        foreach ([
            'products' => Product::where('team_id', $this->team->id)->count(),
            'suppliers' => Supplier::where('team_id', $this->team->id)->count(),
            'clients' => Client::where('team_id', $this->team->id)->count(),
            'quotations' => Quotation::where('team_id', $this->team->id)->count(),
            'sales orders' => Sale::where('team_id', $this->team->id)->count(),
            'payments' => Payment::where('team_id', $this->team->id)->count(),
            'customer deliveries' => OrderFulfillment::where('team_id', $this->team->id)->count(),
            'delivery releases' => DeliveryRelease::where('team_id', $this->team->id)->count(),
            'purchase requests' => PurchaseRequest::where('team_id', $this->team->id)->count(),
            'purchase orders' => PurchaseOrder::where('team_id', $this->team->id)->count(),
            'inbound deliveries' => Delivery::where('team_id', $this->team->id)->count(),
            'stock movements' => StockMovement::where('team_id', $this->team->id)->count(),
        ] as $label => $n) {
            $this->command->line(sprintf('  %-20s %6d', $label, $n));
        }
        $this->command->line(sprintf('  %-20s %6d', 'waits for stock', $this->stats['waits_for_stock'] ?? 0));
        foreach (['quotations' => 'status', 'sales' => 'status', 'order_fulfillments' => 'status', 'purchase_orders' => 'status'] as $table => $column) {
            $counts = DB::table($table)->where('team_id', $this->team->id)->groupBy($column)->selectRaw("{$column} as s, COUNT(*) as n")->pluck('n', 's')->all();
            $this->command->line(sprintf('  %-20s %s', $table, collect($counts)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ')));
        }
        if ($this->failures) {
            arsort($this->failures);
            $this->command->warn('Steps the workflow refused (left as they were):');
            foreach (array_slice($this->failures, 0, 15, true) as $message => $n) {
                $this->command->line("  {$n} × {$message}");
            }
        }
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function travel(?CarbonImmutable $to): void
    {
        $utc = $to?->setTimezone('UTC');
        Carbon::setTestNow($utc ? Carbon::instance($utc) : null);
        CarbonImmutable::setTestNow($utc);
    }

    private function local(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(self::LOCAL_TZ);
    }

    /** Move a time into office hours (Mon–Sat, 8:00–17:00 Manila time). */
    private function businessTime(CarbonImmutable $time): CarbonImmutable
    {
        $time = $time->setTimezone(self::LOCAL_TZ);
        if ($time->hour >= 17) {
            $time = $time->addDay()->setTime(8, mt_rand(0, 59));
        } elseif ($time->hour < 8) {
            $time = $time->setTime(8, mt_rand(0, 59));
        }
        if ($time->isSunday()) {
            $time = $time->addDay();
        }

        return $time;
    }

    private function pickClient(): Client
    {
        return $this->clients[$this->weighted(array_column($this->clients, 'weight'))]['client'];
    }

    /** @param  array<int|string, int>  $weights */
    private function weighted(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    private function paymentReference(string $method): string
    {
        $n = ++$this->reference;

        return $method === 'GCash'
            ? sprintf('GC%s%06d', now()->format('ymd'), $n)
            : sprintf('BT-%s-%06d', now()->format('Ymd'), $n);
    }

    private function count(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    private function failure(string $message): void
    {
        $key = mb_substr(preg_replace('/\d[\d,.\-]*/', '#', $message), 0, 160);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
    }
}
