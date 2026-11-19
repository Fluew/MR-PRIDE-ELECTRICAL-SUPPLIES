<?php

namespace Database\Seeders;

use App\Models\EvaluationSnapshot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deterministic, additive fixture import. Query builder inserts deliberately dispatch no events or notifications. */
class AlgorithmHistorySeeder extends Seeder
{
    public const SLUG = 'electrodesk-fictional-history-v1';
    public const MARKER = 'FICTIONAL ALGORITHM TEST V1';
    public const END = '2026-11-12';

    private int $teamId;
    private ?int $actor;

    public function run(): void
    {
        $connection = DB::connection();
        $memory = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        if (! $memory && (! app()->environment(['local', 'staging']) || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || ! config('historical_data.database') || $connection->getDatabaseName() !== config('historical_data.database'))) {
            throw new RuntimeException('Refused: explicitly set HISTORICAL_DATA_DATABASE to the configured local MySQL database in local/staging.');
        }
        DB::transaction(function () {
            if (DB::table('teams')->where('slug', self::SLUG)->exists()) {
                $this->command?->info('Fixture team already exists; no records changed.');
                return;
            }
            $this->actor = DB::table('users')->where('role', 'admin')->value('id');
            $start = '2020-01-01 00:00:00';
            $this->teamId = $this->insert('teams', ['name' => self::MARKER, 'slug' => self::SLUG, 'is_personal' => false], $start);
            if ($this->actor) {
                $this->insert('team_members', ['team_id' => $this->teamId, 'user_id' => $this->actor, 'role' => 'owner'], $start);
            }
            if (! DB::table('brands')->where('name', self::MARKER)->exists()) {
                $this->insert('brands', ['name' => self::MARKER, 'category' => 'Electrical Materials', 'is_partner' => false, 'is_active' => true], $start);
            }
            $suppliers = [];
            foreach (['Quezon Circuit Trading', 'Cebu Cable Depot', 'Davao Power Parts'] as $i => $name) {
                $suppliers[] = $this->insert('suppliers', ['team_id' => $this->teamId, 'name' => self::MARKER.' - '.$name,
                    'email' => 'supplier'.$i.'@example.test', 'address' => 'Fictional Philippine warehouse', 'status' => 'Active'], $start);
            }
            $clients = [];
            foreach (['Pasig Housing Project', 'Cebu School Maintenance', 'Davao Workshop'] as $i => $name) {
                $clients[] = $this->insert('clients', ['team_id' => $this->teamId, 'company_name' => self::MARKER.' - '.$name,
                    'email' => 'client'.$i.'@example.test', 'address' => 'Fictional project site, Philippines'], $start);
            }
            $catalog = [
                ['THHN copper wire 3.5 mm2', 'Wiring', 'meters', 0.65, 'consistent', 5],
                ['LED lamp 9W 220V', 'Lighting', 'pcs', 1.80, 'increasing', 10],
                ['Fluorescent ballast 40W', 'Lighting', 'pcs', 3.20, 'decreasing', 300],
                ['Weatherproof outlet 16A', 'General', 'pcs', 4.50, 'seasonal', 25],
                ['Circuit breaker 2P 30A', 'Breakers', 'pcs', 8.50, 'intermittent', 15],
                ['Spare contactor coil 220V', 'Controls', 'pcs', 12.00, 'zero', 40],
            ];
            foreach ($catalog as $i => [$name, $category, $unit, $price, $pattern, $stock]) {
                $sku = 'ALG-V1-'.($i + 1);
                $product = $this->insert('products', ['team_id' => $this->teamId, 'sku' => $sku, 'name' => $name.' (FICTIONAL)',
                    'category' => $category, 'brand' => self::MARKER, 'unit' => $unit, 'price' => $price, 'cost_price' => round($price * .65, 2),
                    'stock' => $stock, 'min_stock' => 10, 'reorder_level' => 10, 'reorder_quantity' => 20,
                    'status' => $stock <= 10 ? 'Low Stock' : 'In Stock', 'is_active' => true, 'supplier_id' => $suppliers[0],
                    'description' => self::MARKER.'; demand pattern: '.$pattern], $start);
                $this->movement($product, $stock, 0, $stock, $start, $sku.'-OPEN', 'stock_in');
                foreach ($suppliers as $supplier) {
                    $this->insert('product_supplier', ['team_id' => $this->teamId, 'product_id' => $product, 'supplier_id' => $supplier,
                        'is_available' => true, 'verified_at' => $start, 'verified_by_id' => $this->actor, 'notes' => self::MARKER], $start);
                }
                for ($month = CarbonImmutable::parse('2020-01-01'), $n = 0; $month->lte(CarbonImmutable::parse(self::END)); $month = $month->addMonth(), $n++) {
                    $demand = self::demand($pattern, $n, $month->month);
                    if ($demand === 0) {
                        continue;
                    }
                    $supplierIndex = $n % 3;
                    $this->cycle($product, $sku, $name, $unit, $price, $stock, $demand, $month, $suppliers[$supplierIndex], $supplierIndex, $clients[$n % 3]);
                }
            }
            $now = CarbonImmutable::parse(self::END.' 23:59:59');
            foreach ([EvaluationSnapshot::PRODUCT_PRIORITY => app(ProductPrioritizer::class)->forTeam($this->teamId, $now),
                EvaluationSnapshot::SUPPLIER_EVALUATION => app(SupplierEvaluator::class)->forTeam($this->teamId, $now)] as $kind => $results) {
                foreach ($results as $id => $result) {
                    $this->insert('evaluation_snapshots', ['team_id' => $this->teamId, 'kind' => $kind, 'subject_id' => $id,
                        'score' => $result['score'], 'classification' => $result['classification'], 'complete' => $result['complete'],
                        'payload' => json_encode($result, JSON_THROW_ON_ERROR), 'trigger' => self::MARKER, 'calculated_at' => $now], $now);
                }
            }
            $this->command?->info('Created fictional history through '.self::END.' in team '.self::SLUG.'.');
        });
    }

    public static function demand(string $pattern, int $monthIndex, int $month): int
    {
        return match ($pattern) {
            'consistent' => 60,
            'increasing' => 20 + $monthIndex,
            'decreasing' => max(5, 110 - $monthIndex),
            'seasonal' => in_array($month, [3, 4, 5, 11, 12], true) ? 120 : 40,
            'intermittent' => $monthIndex % 4 === 0 ? 36 : 0,
            default => 0,
        };
    }

    private function cycle(int $product, string $sku, string $name, string $unit, float $price, int $stock, int $demand, CarbonImmutable $month, int $supplier, int $profile, int $client): void
    {
        $key = $sku.'-'.$month->format('Ym');
        $day = fn (int $d) => $month->setDay($d)->setTime(10, 0)->toDateTimeString();
        $cost = round($price * [.65, .60, .75][$profile], 2);
        $rejected = (int) round($demand * [0, .05, .12][$profile]);
        $received = $demand + $rejected;
        $pr = $this->insert('purchase_requests', ['team_id' => $this->teamId, 'request_number' => 'PR-'.$key,
            'product_id' => $product, 'supplier_id' => $supplier, 'requested_by_id' => $this->actor, 'reviewed_by_id' => $this->actor,
            'quantity' => $received, 'estimated_cost' => $received * $cost, 'status' => PurchaseRequest::CONVERTED,
            'requested_at' => $day(1), 'submitted_at' => $day(1), 'reviewed_at' => $day(2), 'ordered_at' => $day(3),
            'delivered_at' => $day(8), 'notes' => self::MARKER, 'justification' => 'Fictional monthly replenishment'], $day(1));
        $po = $this->insert('purchase_orders', ['team_id' => $this->teamId, 'purchase_request_id' => $pr, 'po_number' => 'PO-'.$key,
            'po_date' => $day(3), 'sent_at' => $day(3), 'promised_delivery_date' => $day($profile === 0 ? 8 : 6),
            'quantity' => $received, 'product_id' => $product, 'item_sku' => $sku, 'description' => $name, 'unit_of_measure' => $unit,
            'unit_cost' => $cost, 'total_cost' => $received * $cost, 'supplier_id' => $supplier, 'prepared_by_id' => $this->actor,
            'status' => $rejected ? PurchaseOrder::CLOSED : PurchaseOrder::FULL, 'closed_at' => $rejected ? $day(8) : null, 'notes' => self::MARKER], $day(3));
        $header = $this->insert('inbound_deliveries', ['team_id' => $this->teamId, 'delivery_number' => 'IN-'.$key, 'delivery_date' => $day(8),
            'purchase_order_number' => 'PO-'.$key, 'supplier_id' => $supplier, 'status' => 'confirmed', 'confirmed_at' => $day(8),
            'confirmed_by_id' => $this->actor, 'submission_key' => 'ALG-IN-'.$key, 'notes' => self::MARKER], $day(8));
        $movement = $this->movement($product, $demand, $stock, $stock + $demand, $day(8), 'IN-'.$key, 'stock_in');
        $delivery = $this->insert('deliveries', ['team_id' => $this->teamId, 'inbound_delivery_id' => $header, 'delivery_number' => 'IN-'.$key,
            'delivery_date' => $day(8), 'purchase_order_id' => $po, 'purchase_order_number' => 'PO-'.$key, 'supplier_id' => $supplier,
            'quantity' => $received, 'accepted_quantity' => $demand, 'rejected_quantity' => $rejected, 'description' => $name, 'unit' => $unit,
            'status' => 'Delivered', 'inspection_result' => $rejected ? 'partially_accepted' : 'accepted', 'inspected_on' => $day(8),
            'inspection_number' => 'QC-'.$key, 'inspected_by_id' => $this->actor, 'received_by_id' => $this->actor,
            'rejection_category' => $rejected ? 'damaged' : null, 'rejection_reason' => $rejected ? 'Fictional cracked housing / damaged insulation' : null,
            'resolution_status' => $rejected ? 'credited' : null, 'resolved_at' => $rejected ? $day(8) : null,
            'posted_quantity' => $demand, 'posted_at' => $day(8), 'posted_by_id' => $this->actor, 'stock_movement_id' => $movement, 'notes' => self::MARKER], $day(8));
        $this->insert('supplier_product_costs', ['team_id' => $this->teamId, 'supplier_id' => $supplier, 'product_id' => $product,
            'unit_cost' => $cost, 'unit_of_measure' => $unit, 'purchase_order_id' => $po, 'delivery_id' => $delivery, 'recorded_on' => $day(8)], $day(8));
        $subtotal = round($demand * $price, 2);
        $tax = round($subtotal * .12, 2);
        $total = $subtotal + $tax;
        $money = ['subtotal' => $subtotal, 'tax_amount' => $tax, 'grand_total' => $total];
        $quote = $this->insert('quotations', $money + ['team_id' => $this->teamId, 'quotation_number' => 'QT-'.$key,
            'client_id' => $client, 'client_name' => self::MARKER.' customer '.$client, 'email' => 'customer@example.test',
            'quotation_date' => $day(9), 'valid_until' => $day(12), 'status' => 'accepted', 'notes' => self::MARKER,
            'request_details' => json_encode(['request' => 'Fictional electrical installation materials']), 'prepared_by_id' => $this->actor,
            'submitted_for_approval_at' => $day(9), 'approved_at' => $day(9), 'approved_by_id' => $this->actor,
            'sent_at' => $day(9), 'customer_decision_at' => $day(10), 'converted_at' => $day(10)], $day(9));
        $line = ['product_id' => $product, 'sku' => $sku, 'product_name' => $name, 'quantity' => $demand, 'unit_price' => $price, 'subtotal' => $subtotal];
        $quoteLine = $this->insert('quotation_items', $line + ['quotation_id' => $quote], $day(9));
        $sale = $this->insert('sales', $money + ['team_id' => $this->teamId, 'order_number' => 'SO-'.$key, 'quotation_id' => $quote,
            'client_name' => self::MARKER.' customer '.$client, 'email' => 'customer@example.test', 'sale_date' => $day(10),
            'status' => 'completed', 'channel' => 'quotation', 'fulfillment_type' => 'delivery', 'payment_status' => 'paid',
            'amount_paid' => $total, 'outstanding_balance' => 0, 'confirmed_at' => $day(10), 'completed_at' => $day(12),
            'confirmed_by_id' => $this->actor, 'completed_by_id' => $this->actor, 'invoice_number' => 'SI-'.$key, 'notes' => self::MARKER], $day(10));
        DB::table('quotations')->where('id', $quote)->update(['converted_to_sale_id' => $sale]);
        $saleLine = $this->insert('sale_items', $line + ['sale_id' => $sale, 'quotation_item_id' => $quoteLine,
            'deducted_quantity' => $demand, 'released_quantity' => $demand, 'delivered_quantity' => $demand], $day(10));
        $payment = $this->insert('payments', ['team_id' => $this->teamId, 'sale_id' => $sale, 'method' => 'cash',
            'submitted_amount' => $total, 'verified_amount' => $total, 'payment_date' => $day(10), 'verified_payment_date' => $day(10),
            'status' => 'verified', 'verified_at' => $day(10), 'verified_by_id' => $this->actor, 'remarks' => self::MARKER], $day(10));
        $this->insert('payment_receipts', ['team_id' => $this->teamId, 'sale_id' => $sale, 'payment_id' => $payment,
            'receipt_number' => 'RC-'.$key, 'amount_received' => $total, 'order_total' => $total, 'previously_paid' => 0, 'balance_after' => 0,
            'method' => 'cash', 'payment_date' => $day(10), 'generated_at' => $day(10)], $day(10));
        $this->insert('sales_invoices', $money + ['team_id' => $this->teamId, 'sale_id' => $sale, 'invoice_number' => 'SI-'.$key,
            'issue_key' => 'ALG-'.$key, 'customer' => json_encode(['name' => self::MARKER.' customer '.$client]),
            'items' => json_encode([$line]), 'tax_rate' => 12, 'issued_at' => $day(10)], $day(10));
        $fulfillment = $this->insert('order_fulfillments', ['team_id' => $this->teamId, 'sale_id' => $sale, 'fulfillment_number' => 'FUL-'.$key,
            'recipient_name' => 'Fictional site foreman', 'status' => 'delivered', 'dispatched_at' => $day(11), 'delivered_at' => $day(12),
            'completed_at' => $day(12), 'notes' => self::MARKER], $day(10));
        $release = $this->insert('delivery_releases', ['team_id' => $this->teamId, 'sale_id' => $sale, 'order_fulfillment_id' => $fulfillment,
            'release_number' => 'REL-'.$key, 'status' => 'delivered', 'dispatched_at' => $day(11), 'delivered_at' => $day(12), 'remarks' => self::MARKER], $day(11));
        $this->insert('delivery_release_items', ['delivery_release_id' => $release, 'sale_item_id' => $saleLine, 'product_id' => $product,
            'quantity' => $demand, 'delivered_quantity' => $demand, 'deducted_quantity' => $demand], $day(11));
        $this->movement($product, -$demand, $stock + $demand, $stock, $day(12), 'OUT-'.$key, 'sale_release', $sale, $release);
    }

    private function insert(string $table, array $values, mixed $date): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => $date, 'updated_at' => $date]);
    }

    private function movement(int $product, int $quantity, int $before, int $after, string $date, string $key, string $type, ?int $sale = null, ?int $release = null): int
    {
        return $this->insert('stock_movements', ['team_id' => $this->teamId, 'product_id' => $product, 'user_id' => $this->actor,
            'type' => $type, 'quantity' => $quantity, 'previous_stock' => $before, 'new_stock' => $after,
            'sale_id' => $sale, 'delivery_release_id' => $release, 'movement_key' => 'ALG:'.$key,
            'reference_number' => $key, 'remarks' => self::MARKER, 'movement_date' => $date], $date);
    }
}
