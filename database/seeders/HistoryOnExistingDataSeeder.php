<?php

namespace Database\Seeders;

use App\Jobs\RecalculateEvaluations;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use SplMinHeap;

/**
 * Historical transactions from January 2020 for the products and suppliers ALREADY in the
 * database (unlike DemoDataSeeder / StagingDataSeeder, which create their own catalogue).
 *
 *   DEMO_DATA_UNTIL=2026-11-11 php artisan db:seed --class=HistoryOnExistingDataSeeder
 *
 * It is the same time-ordered simulation: every quotation, sales order, payment, delivery,
 * purchase request, purchase order, inbound delivery and inspection goes through the system's own
 * workflow services with the clock set to that step's date, so stock movements, reservations and
 * document numbers stay consistent. Only new customers are created (fictional, example.test).
 *
 * Before running, the products must have a unit and a selling price and exist from 2020 (the
 * preparation step does this). Each product starts with an opening stock movement on 2020-01-02;
 * its demand popularity, order sizes and demand profile come from its price, and its reorder
 * level and quantity are set from that demand. Alternate suppliers: the supplier of another record
 * of the same item, otherwise an active supplier of the same category.
 *
 * Safety: local server only; refuses when the team already has quotations or sales before
 * November 2026 (so it never runs twice); no email leaves the machine (see StagingDataSeeder).
 */
class HistoryOnExistingDataSeeder extends StagingDataSeeder
{
    /** @var list<int> active suppliers */
    private array $supplierIds = [];

    public function run(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (app()->isProduction() || ! in_array((string) ($connection['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! empty($connection['url'])) {
            $this->command->error('Refused: runs only on a local database.');

            return;
        }

        $this->team = Team::where('slug', 'mr-pride')->first() ?? Team::query()->orderBy('id')->firstOrFail();
        $this->admin = User::where('role', 'admin')->where('current_team_id', $this->team->id)->orderBy('id')->first()
            ?? User::where('role', 'admin')->orderBy('id')->firstOrFail();
        $before = '2026-11-01';
        if (Quotation::withTrashed()->where('team_id', $this->team->id)->where('quotation_date', '<', $before)->exists()
            || Sale::withTrashed()->where('team_id', $this->team->id)->where('sale_date', '<', $before)->exists()) {
            $this->command->error('Refused: this team already has quotations or sales before November 2026 (history was generated before).');

            return;
        }
        $notReady = Product::where('team_id', $this->team->id)->where(fn ($q) => $q->whereNull('unit')->orWhere('unit', '')->orWhereNull('price')->orWhere('price', '<=', 0))->count();
        if ($notReady) {
            $this->command->error("Refused: {$notReady} product(s) have no unit or selling price. Run the preparation step first.");

            return;
        }

        $this->data = require __DIR__.'/data/staging_catalog.php';
        $this->prepareEnvironment();
        // The evaluation snapshots are recalculated once at the end instead of after every delivery.
        Bus::fake([RecalculateEvaluations::class]);

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
        $this->command->info("Generating history for {$this->team->name} from {$start->toDateString()} to {$this->end->toDateString()} on the existing products…");

        $suppliers = $this->createSuppliers();
        $this->createProducts($suppliers);
        $this->createClients();
        $this->recordSupplierQuotes($start);
        $this->schedule($start);
        $this->runQueue();

        $this->travel(null);
        $this->finish();
        app()->call([new RecalculateEvaluations($this->team->id, 'history.generated'), 'handle']);
    }

    /** Busier than the demo shop: about 12 quotations a month in 2020, growing 9% a year. */
    protected function quotationsPerMonth(CarbonImmutable $month): float
    {
        return parent::quotationsPerMonth($month) * (12 / 7);
    }

    /** The existing active suppliers, each with its own delivery behaviour. @return array<string, list<Supplier>> */
    protected function createSuppliers(): array
    {
        $byCategory = [];
        foreach (Supplier::where('team_id', $this->team->id)->where('status', 'Active')->orderBy('id')->get() as $s) {
            $words = preg_split('/[^A-Za-z]+/', $s->name, -1, PREG_SPLIT_NO_EMPTY) ?: ['SUP'];
            $code = strtoupper(substr(implode('', array_map(fn ($w) => $w[0], $words)), 0, 4)) ?: 'SUP';
            $this->supplierProfile[$s->id] = [
                'lead' => mt_rand(4, 11),
                'late' => mt_rand(5, 38) / 100,
                'reject' => mt_rand(2, 14) / 100,
                'price' => mt_rand(92, 105) / 100,
                'code' => $code,
            ];
            $this->supplierIds[] = $s->id;
        }
        foreach (Product::where('team_id', $this->team->id)->whereNotNull('supplier_id')->get(['category', 'supplier_id']) as $p) {
            if (isset($this->supplierProfile[$p->supplier_id])) {
                $byCategory[$p->category][$p->supplier_id] = Supplier::find($p->supplier_id);
            }
        }
        $this->count('suppliers', count($this->supplierIds));

        return array_map('array_values', $byCategory);
    }

    /** @param  array<string, list<Supplier>>  $suppliers */
    protected function createProducts(array $suppliers): void
    {
        $products = Product::where('team_id', $this->team->id)->orderBy('id')->get();
        $start = CarbonImmutable::parse(self::START, self::LOCAL_TZ);
        $typical = CarbonImmutable::parse('2023-06-01', self::LOCAL_TZ);
        $byName = $products->groupBy(fn ($p) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $p->name))));

        // Popularity and order size from the selling price: cheap items sell often and in bulk.
        $tier = fn (float $price) => match (true) {
            $price < 5 => [8, 20, 150],
            $price < 25 => [6, 5, 60],
            $price < 100 => [5, 3, 30],
            $price < 500 => [3, 1, 12],
            $price < 2000 => [2, 1, 5],
            default => [1, 1, 2],
        };
        $profiles = ['S', 'S', 'S', 'S', 'R', 'R', 'F', 'D', 'H', 'I', 'I'];
        $rows = [];
        foreach ($products as $p) {
            [$weight, $min, $max] = $tier((float) $p->price);
            $rows[$p->id] = ['weight' => max(1, $weight + mt_rand(-1, 1)), 'min' => $min, 'max' => $max, 'profile' => $profiles[mt_rand(0, count($profiles) - 1)]];
        }
        $totalWeight = array_sum(array_column($rows, 'weight'));

        foreach ($products as $p) {
            $info = $rows[$p->id];
            $primary = $p->supplier_id && isset($this->supplierProfile[$p->supplier_id]) ? $p->supplier_id : $this->supplierIds[0];
            $sibling = $byName[mb_strtolower(trim(preg_replace('/\s+/u', ' ', $p->name)))]
                ->first(fn ($o) => $o->id !== $p->id && $o->supplier_id && $o->supplier_id !== $primary && isset($this->supplierProfile[$o->supplier_id]));
            $pool = array_values(array_filter($suppliers[$p->category] ?? [], fn ($s) => $s->id !== $primary));
            $secondary = $sibling?->supplier_id ?? ($pool ? $pool[mt_rand(0, count($pool) - 1)]->id : $primary);

            $opening = $this->monthlyDemand($info['weight'], $info['min'], $info['max'], $totalWeight, $start);
            $reorderQty = $this->lotSize($info['max']);
            $reorderLevel = max(1, (int) round($this->monthlyDemand($info['weight'], $info['min'], $info['max'], $totalWeight, $typical) * 0.8));
            $stock = max($reorderQty, (int) (ceil(($opening * 3 + $info['max']) / $reorderQty) * $reorderQty));

            $p->forceFill(['stock' => $stock, 'min_stock' => $reorderLevel, 'reorder_level' => $reorderLevel, 'reorder_quantity' => $reorderQty])->save();
            $p->updateCalculatedStatus();
            StockMovement::create([
                'team_id' => $this->team->id, 'product_id' => $p->id, 'user_id' => $this->admin->id, 'supplier_id' => $primary,
                'type' => 'stock_in', 'quantity' => $stock, 'previous_stock' => 0, 'new_stock' => $stock,
                'reference_number' => 'OPENING-'.$p->sku, 'reason' => 'Opening balance',
                'remarks' => 'Opening stock on 2 January 2020. '.self::MARKER.'.', 'movement_date' => now(),
            ]);
            foreach (array_unique([$primary, $secondary]) as $supplierId) {
                DB::table('product_supplier')->insertOrIgnore([
                    'team_id' => $this->team->id, 'product_id' => $p->id, 'supplier_id' => $supplierId, 'is_available' => true,
                    'notes' => $supplierId === $primary ? 'Usual supplier.' : 'Alternate supplier.',
                    'recorded_by_id' => $this->admin->id, 'verified_at' => now(), 'verified_by_id' => $this->admin->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->catalog[$p->id] = $info + ['primary' => $primary, 'secondary' => $secondary];
            $this->incoming[$p->id] = 0;
        }
        $this->count('products', $products->count());
    }

    /** The supplier's price: the product's cost (or its price less the 30% margin) × the supplier's price level. */
    protected function supplierPrice(Product $product, int $supplierId): float
    {
        $cost = (float) $product->cost_price > 0 ? (float) $product->cost_price : (float) $product->price / 1.3;

        return round($cost * $this->supplierProfile[$supplierId]['price'], 2);
    }
}
