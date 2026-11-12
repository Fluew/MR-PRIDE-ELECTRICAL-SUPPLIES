<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Inventory\SkuGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SIMULATED STAGING DATA, January 2020 to today, for demonstrations and testing of Demand
 * Forecasting (SMA), AHP Product Prioritization, reorder advice and Supplier Evaluation.
 *
 *   php artisan db:seed --class=StagingDataSeeder
 *
 * It is DemoDataSeeder's time-ordered simulation (every step goes through the system's own
 * workflow services) with a larger catalogue from data/staging_catalog.php and demand that
 * differs per product: stable, rising, falling, seasonal and intermittent.
 *
 * Safety:
 * - It runs only on the local server and only on the scratch database named in DATABASE, so it
 *   can never be pointed at the working, staging or production data. The result is moved to
 *   staging separately, as a reviewed data import.
 * - No email leaves the machine: the Resend key is blanked for this process, any request to the
 *   Resend API is answered locally, the mailer is in-memory and jobs run at once.
 * - Like DemoDataSeeder, it refuses a team that already has products, quotations or orders, so a
 *   second run adds nothing. A fixed random seed makes a rerun on a new database repeat the data.
 * - DEMO_DATA_UNTIL=2020-06-30 stops early, for a trial run.
 */
class StagingDataSeeder extends DemoDataSeeder
{
    public const DATABASE = 'mr_pride_staging_seed';

    public const MARKER = 'SIMULATED STAGING DATA';

    private const DRY_SEASON = [1 => 0.6, 0.7, 1.5, 1.8, 1.6, 1.0, 0.6, 0.5, 0.7, 0.9, 1.0, 0.9];

    private const YEAR_END = [1 => 0.8, 0.6, 0.7, 0.7, 0.8, 0.8, 0.9, 1.0, 1.2, 1.5, 1.8, 1.9];

    /** Products with profile P are not restocked from this month on, so they run out. */
    private const PHASE_OUT_FROM = '2026-07-01';

    /** @var array{suppliers: list<array>, clients: list<array>, products: list<array>} */
    protected array $data;

    /** @var array<string, array<int, int>> */
    private array $weightCache = [];

    public function run(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        $host = (string) ($connection['host'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        if (! in_array($host, ['127.0.0.1', 'localhost'], true) || $database !== self::DATABASE || ! empty($connection['url'])) {
            $this->command->error('Refused: StagingDataSeeder runs only on the local scratch database "'.self::DATABASE.'" (connected to "'.$database.'").');

            return;
        }

        $this->data = require __DIR__.'/data/staging_catalog.php';
        parent::run();
    }

    protected function prepareEnvironment(): void
    {
        parent::prepareEnvironment();

        // ResendService tries the Resend API before Laravel's mailer whenever a key is set.
        config(['services.resend.key' => '', 'services.resend.dev_redirect_to' => '']);
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'Blocked while seeding.'], 503)]);
        if ((string) config('services.resend.key') !== '' || config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Email suppression is not active; nothing was seeded.');
        }
    }

    // ───────────────────────────── Master data ─────────────────────────────

    /** @return array<string, list<Supplier>> suppliers by category */
    protected function createSuppliers(): array
    {
        $byCategory = [];
        foreach ($this->data['suppliers'] as $i => [$name, $contact, $city, $categories, $lead, $late, $reject, $price, $code]) {
            $supplier = Supplier::create([
                'team_id' => $this->team->id,
                'name' => $name,
                'contact_person' => $contact,
                'phone' => sprintf('+63 917 %03d %04d', 200 + $i * 7, 1000 + $i * 311),
                'email' => sprintf('supplier%03d@example.test', $i + 1),
                'address' => 'Unit '.(100 + $i * 12).", Industrial Avenue, {$city}, Philippines (".self::MARKER.')',
                'status' => 'Active',
            ]);
            $this->supplierProfile[$supplier->id] = compact('lead', 'late', 'reject', 'price', 'code');
            foreach ($categories as $category) {
                $byCategory[$category][] = $supplier;
            }
        }
        $this->count('suppliers', count($this->data['suppliers']));

        return $byCategory;
    }

    /** @param  array<string, list<Supplier>>  $suppliers */
    protected function createProducts(array $suppliers): void
    {
        $rows = $this->data['products'];
        $start = CarbonImmutable::parse(self::START, self::LOCAL_TZ);
        $typical = CarbonImmutable::parse('2023-06-01', self::LOCAL_TZ); // a mid-period month, for the reorder settings
        $totalWeight = array_sum(array_column($rows, 5));
        $skus = app(SkuGenerator::class);

        foreach ($rows as [$name, $category, $brand, $unit, $price, $weight, $min, $max, $profile]) {
            $pool = $suppliers[$category];
            $primary = $pool[mt_rand(0, count($pool) - 1)];
            $others = array_values(array_filter($pool, fn ($s) => $s->id !== $primary->id));
            $secondary = $others ? $others[mt_rand(0, count($others) - 1)] : $primary;

            $opening = $this->monthlyDemand($weight, $min, $max, $totalWeight, $start);
            $reorderQty = $this->lotSize($max);
            $reorderLevel = max(2, (int) round($this->monthlyDemand($weight, $min, $max, $totalWeight, $typical) * 0.8));
            $stock = max($reorderQty, (int) (ceil(($opening * 3 + $max) / $reorderQty) * $reorderQty));
            if ($profile === 'O') {
                $stock *= 6; // bought in bulk at the start: stays overstocked
            }

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
                'description' => trim(($brand ? "{$brand} " : '').$name).'. '.self::MARKER.'.',
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
                'remarks' => 'Initial stock registered upon product creation. '.self::MARKER.'.',
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

            $this->catalog[$product->id] = ['weight' => $weight, 'min' => $min, 'max' => $max, 'primary' => $primary->id, 'secondary' => $secondary->id, 'profile' => $profile];
            $this->incoming[$product->id] = 0;
        }
        $this->count('products', count($rows));
    }

    protected function createClients(): void
    {
        foreach ($this->data['clients'] as $i => [$company, $contact, $city, $weight]) {
            $client = Client::create([
                'team_id' => $this->team->id,
                'company_name' => $company,
                'contact_person' => $contact,
                'email' => sprintf('customer%03d@example.test', $i + 1),
                'phone' => sprintf('+63 918 %03d %04d', 300 + $i * 11, 2000 + $i * 113),
                'address' => (10 + $i * 7)." Rizal Street, {$city}, Philippines (".self::MARKER.')',
            ]);
            $this->clients[] = ['client' => $client, 'weight' => $weight];
        }
        $this->count('clients', count($this->data['clients']));
    }

    // ───────────────────────────── Demand model ─────────────────────────────

    protected function lineCountWeights(): array
    {
        return [1 => 15, 2 => 25, 3 => 25, 4 => 18, 5 => 10, 6 => 7];
    }

    protected function linesPerQuotation(): float
    {
        return 3.04;
    }

    protected function primaryShare(): int
    {
        return 60; // both suppliers of a product deliver often enough to compare their prices
    }

    protected function restocks(int $productId): bool
    {
        return ($this->catalog[$productId]['profile'] ?? 'S') !== 'P' || $this->local()->toDateString() < self::PHASE_OUT_FROM;
    }

    /** Popularity per product for the month, shaped by its demand profile (tenths of a weight point). */
    protected function demandWeights(CarbonImmutable $month, bool $planning = false): array
    {
        $ym = $month->format('Y-m');
        $key = $ym.($planning ? 'p' : 'd');
        if (isset($this->weightCache[$key])) {
            return $this->weightCache[$key];
        }

        // 0 in January 2020, 1 in November 2026.
        $progress = min(1.0, max(0.0, (($month->year - 2020) * 12 + $month->month - 1) / 82));
        $weights = [];
        foreach ($this->catalog as $id => $info) {
            $factor = match ($info['profile'] ?? 'S') {
                'R' => 0.35 + 1.5 * $progress,
                'F' => 1.9 - 1.6 * $progress,
                'D' => self::DRY_SEASON[$month->month],
                'H' => self::YEAR_END[$month->month],
                // Ordered in about one month out of three; purchasing plans for the average.
                'I' => $planning ? 0.75 : (crc32("{$id}-{$ym}") % 3 === 0 ? 2.25 : 0.0),
                default => 1.0,
            };
            $value = (int) round($info['weight'] * 10 * $factor);
            $weights[$id] = ($info['profile'] ?? 'S') === 'I' ? $value : max(1, $value);
        }
        $this->weightCache = array_slice($this->weightCache, -6, null, true); // only recent months are asked for

        return $this->weightCache[$key] = $weights;
    }

    /**
     * About one credit customer in eight pays the balance late (40 to 160 days after the usual
     * date), so the data has overdue and still-unpaid orders.
     */
    protected function payBalance(int $saleId, CarbonImmutable $when, float $share = 1.0): void
    {
        if ($share >= 1.0 && crc32("late-{$saleId}") % 8 === 0) {
            $when = $this->businessTime($when->addDays(40 + crc32("days-{$saleId}") % 120));
        }
        parent::payBalance($saleId, $when, $share);
    }
}
