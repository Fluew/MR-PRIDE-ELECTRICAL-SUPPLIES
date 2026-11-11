<?php

namespace Database\Seeders;

use App\Enums\QuotationStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class QuotationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teams = Team::all();

        foreach ($teams as $team) {
            $adminUser = User::where('email', 'admin@example.com')->first()
                ?? User::first();

            // 1. Seed Suppliers
            $suppliersData = [
                [
                    'name' => 'ElectroTech Industries',
                    'contact_person' => 'Michael Chang',
                    'phone' => '+63 (2) 8123-4567',
                    'email' => 'sales@electrotech.com.ph',
                    'address' => 'Unit 402 Tech Plaza, Pasig City, Metro Manila',
                    'status' => 'Active',
                    'ahp_score' => 0.842,
                    'metrics' => ['price' => 9, 'quality' => 8, 'delivery' => 9, 'service' => 7],
                ],
                [
                    'name' => 'Global Wire & Cable Systems',
                    'contact_person' => 'Sarah Jenkins',
                    'phone' => '+63 (2) 8987-6543',
                    'email' => 'orders@globalwire.com.ph',
                    'address' => 'Block 8 Laguna Technopark, Biñan, Laguna',
                    'status' => 'Active',
                    'ahp_score' => 0.615,
                    'metrics' => ['price' => 6, 'quality' => 7, 'delivery' => 5, 'service' => 8],
                ],
                [
                    'name' => 'Lumen Lighting Co.',
                    'contact_person' => 'David Ross',
                    'phone' => '+63 (2) 8456-7890',
                    'email' => 'support@lumenlight.ph',
                    'address' => '12 Light Ave, Quezon City, Metro Manila',
                    'status' => 'Active',
                    'ahp_score' => 0.720,
                    'metrics' => ['price' => 7, 'quality' => 8, 'delivery' => 7, 'service' => 7],
                ],
                [
                    'name' => 'Schneider Power Philippines',
                    'contact_person' => 'Eduardo Santos',
                    'phone' => '+63 (2) 8333-9000',
                    'email' => 'industrial@schneider.ph',
                    'address' => 'BGC Corporate Center, Taguig City',
                    'status' => 'Active',
                    'ahp_score' => 0.910,
                    'metrics' => ['price' => 8, 'quality' => 10, 'delivery' => 9, 'service' => 9],
                ],
            ];

            $createdSuppliers = [];
            foreach ($suppliersData as $supData) {
                $supplier = Supplier::updateOrCreate(
                    ['team_id' => $team->id, 'name' => $supData['name']],
                    [
                        'contact_person' => $supData['contact_person'],
                        'phone' => $supData['phone'],
                        'email' => $supData['email'],
                        'address' => $supData['address'],
                        'status' => $supData['status'],
                        'ahp_score' => $supData['ahp_score'],
                        'metrics' => $supData['metrics'],
                    ]
                );
                $createdSuppliers[$supData['name']] = $supplier;
            }

            $electroTech = $createdSuppliers['ElectroTech Industries'] ?? null;
            $globalWire = $createdSuppliers['Global Wire & Cable Systems'] ?? null;
            $lumenLight = $createdSuppliers['Lumen Lighting Co.'] ?? null;
            $schneider = $createdSuppliers['Schneider Power Philippines'] ?? null;

            // 2. Seed Electrical Products Inventory
            $productsData = [
                ['sku' => 'BRK-IND-001', 'name' => 'Industrial Circuit Breaker 100A', 'category' => 'Breakers', 'unit' => 'pcs', 'cost_price' => 85.00, 'price' => 120.00, 'stock' => 45, 'reorder_level' => 10, 'reorder_quantity' => 50, 'supplier_id' => $schneider?->id],
                ['sku' => 'WR-CU-100', 'name' => 'Heavy Duty Copper Wire THHN (100m)', 'category' => 'Wiring', 'unit' => 'rolls', 'cost_price' => 60.00, 'price' => 85.00, 'stock' => 8, 'reorder_level' => 15, 'reorder_quantity' => 100, 'supplier_id' => $globalWire?->id],
                ['sku' => 'LGT-FLD-200', 'name' => 'LED Floodlight 200W IP65 Outdoor', 'category' => 'Lighting', 'unit' => 'units', 'cost_price' => 30.00, 'price' => 45.00, 'stock' => 0, 'reorder_level' => 5, 'reorder_quantity' => 30, 'supplier_id' => $lumenLight?->id],
                ['sku' => 'MC-220V-01', 'name' => 'Magnetic Contactor 220V 3-Pole', 'category' => 'Controls', 'unit' => 'pcs', 'cost_price' => 22.00, 'price' => 35.00, 'stock' => 112, 'reorder_level' => 20, 'reorder_quantity' => 80, 'supplier_id' => $electroTech?->id],
                ['sku' => 'TLS-DMM-PRO', 'name' => 'Digital Multimeter True RMS PRO', 'category' => 'Tools', 'unit' => 'sets', 'cost_price' => 150.00, 'price' => 210.00, 'stock' => 15, 'reorder_level' => 5, 'reorder_quantity' => 20, 'supplier_id' => $electroTech?->id],
                ['sku' => 'REL-THM-50', 'name' => 'Thermal Overload Relay 50A', 'category' => 'Controls', 'unit' => 'pcs', 'cost_price' => 30.00, 'price' => 45.50, 'stock' => 30, 'reorder_level' => 10, 'reorder_quantity' => 40, 'supplier_id' => $electroTech?->id],
                ['sku' => 'PVC-CND-10', 'name' => 'PVC Electrical Conduit (10ft 20mm)', 'category' => 'Wiring', 'unit' => 'lengths', 'cost_price' => 8.00, 'price' => 12.00, 'stock' => 200, 'reorder_level' => 50, 'reorder_quantity' => 250, 'supplier_id' => $globalWire?->id],
                ['sku' => 'TLS-CRM-HD', 'name' => 'Heavy Duty Ratchet Crimping Tool', 'category' => 'Tools', 'unit' => 'pcs', 'cost_price' => 42.00, 'price' => 65.00, 'stock' => 4, 'reorder_level' => 5, 'reorder_quantity' => 15, 'supplier_id' => $electroTech?->id],
                ['sku' => 'PNL-DST-12', 'name' => 'Distribution Panel Board 12-Way Flush', 'category' => 'Breakers', 'unit' => 'sets', 'cost_price' => 105.00, 'price' => 150.00, 'stock' => 25, 'reorder_level' => 8, 'reorder_quantity' => 25, 'supplier_id' => $schneider?->id],
                ['sku' => 'SCK-IND-32', 'name' => 'Industrial Interlocked Socket 32A 3P+E', 'category' => 'Wiring', 'unit' => 'pcs', 'cost_price' => 18.00, 'price' => 28.00, 'stock' => 60, 'reorder_level' => 15, 'reorder_quantity' => 50, 'supplier_id' => $globalWire?->id],
                ['sku' => 'SWG-400A-01', 'name' => 'Molded Case Switchgear 400A 3P', 'category' => 'Breakers', 'unit' => 'units', 'cost_price' => 320.00, 'price' => 450.00, 'stock' => 12, 'reorder_level' => 3, 'reorder_quantity' => 10, 'supplier_id' => $schneider?->id],
                ['sku' => 'LGT-BAY-150', 'name' => 'High Bay Industrial LED 150W', 'category' => 'Lighting', 'unit' => 'units', 'cost_price' => 65.00, 'price' => 95.00, 'stock' => 18, 'reorder_level' => 6, 'reorder_quantity' => 30, 'supplier_id' => $lumenLight?->id],
            ];

            $createdProducts = [];
            foreach ($productsData as $data) {
                $status = 'In Stock';
                if ($data['stock'] <= 0) {
                    $status = 'Out of Stock';
                } elseif ($data['stock'] <= $data['reorder_level']) {
                    $status = 'Low Stock';
                }

                $product = Product::updateOrCreate(
                    ['team_id' => $team->id, 'sku' => $data['sku']],
                    [
                        'name' => $data['name'],
                        'category' => $data['category'],
                        'unit' => $data['unit'],
                        'cost_price' => $data['cost_price'],
                        'price' => $data['price'],
                        'stock' => $data['stock'],
                        'min_stock' => $data['reorder_level'],
                        'reorder_level' => $data['reorder_level'],
                        'reorder_quantity' => $data['reorder_quantity'],
                        'status' => $status,
                        'supplier_id' => $data['supplier_id'],
                        'description' => "High-quality {$data['category']} supply certified for commercial & industrial use.",
                        'is_active' => true,
                    ]
                );
                $createdProducts[$data['sku']] = $product;

                // Seed initial stock movement history if not exists
                if ($product->stockMovements()->count() === 0) {
                    StockMovement::create([
                        'team_id' => $team->id,
                        'product_id' => $product->id,
                        'user_id' => $adminUser?->id,
                        'supplier_id' => $product->supplier_id,
                        'type' => 'stock_in',
                        'quantity' => $product->stock + 10,
                        'previous_stock' => 0,
                        'new_stock' => $product->stock + 10,
                        'reference_number' => 'PO-'.date('Y').'-'.rand(1000, 9999),
                        'reason' => 'Initial Inventory Inflow',
                        'remarks' => 'Warehouse intake batch confirmed.',
                        'movement_date' => now()->subDays(rand(5, 30)),
                    ]);

                    if ($product->stock > 0 && rand(0, 1) === 1) {
                        StockMovement::create([
                            'team_id' => $team->id,
                            'product_id' => $product->id,
                            'user_id' => $adminUser?->id,
                            'type' => 'stock_out',
                            'quantity' => 10,
                            'previous_stock' => $product->stock + 10,
                            'new_stock' => $product->stock,
                            'reference_number' => 'SO-'.date('Y').'-'.rand(1000, 9999),
                            'reason' => 'Client Project Deployment',
                            'remarks' => 'Dispatched to jobsite installation.',
                            'movement_date' => now()->subDays(rand(1, 4)),
                        ]);
                    }
                }
            }

            // 3. Seed Clients
            $clientsData = [
                [
                    'company_name' => 'Alpha Builders & Engineering Inc.',
                    'contact_person' => 'Engr. Roberto Mendoza',
                    'email' => 'mendoza@alphabuilders.ph',
                    'phone' => '+63 917 555 1234',
                    'address' => '7th Floor, Greenfield Tower, Mandaluyong City',
                ],
                [
                    'company_name' => 'Megawide Infrastructure Corp.',
                    'contact_person' => 'Ms. Patricia Cruz',
                    'email' => 'procurement@megawide.ph',
                    'phone' => '+63 920 888 5678',
                    'address' => 'Megawide Bldg, 20 EDSA, Quezon City',
                ],
                [
                    'company_name' => 'Prime Power & Renewable Energy',
                    'contact_person' => 'Mr. Victor Tan',
                    'email' => 'vtan@primepower.com',
                    'phone' => '+63 918 777 9900',
                    'address' => 'Subic Bay Freeport Zone, Zambales',
                ],
                [
                    'company_name' => 'Skyline Industrial Park',
                    'contact_person' => 'Engr. Leo Santos',
                    'email' => 'lsantos@skylinepark.ph',
                    'phone' => '+63 919 444 3322',
                    'address' => 'Calamba Premiere International Park, Laguna',
                ],
            ];

            $createdClients = [];
            foreach ($clientsData as $cData) {
                $client = Client::updateOrCreate(
                    ['team_id' => $team->id, 'company_name' => $cData['company_name']],
                    $cData
                );
                $createdClients[] = $client;
            }

            // 4. Seed Sample Quotations
            $terms = "1. Quotation is valid for 30 days from issuance date.\n2. Delivery within 7-14 working days upon receipt of approved Purchase Order.\n3. Payment terms: 50% downpayment upon confirmation, 50% upon delivery/turnover.\n4. Standard 1-year manufacturer warranty for all electrical hardware components.";

            $sampleQuotations = [
                [
                    'number' => 'QT-2026-0001',
                    'client' => $createdClients[0],
                    'date' => now()->subDays(12)->toDateString(),
                    'valid_until' => now()->addDays(18)->toDateString(),
                    'status' => QuotationStatus::Draft,
                    'notes' => 'Commercial high-rise substation circuit breaker and control upgrade package.',
                    'items' => [
                        ['sku' => 'BRK-IND-001', 'qty' => 5, 'disc' => 0.00],
                        ['sku' => 'MC-220V-01', 'qty' => 8, 'disc' => 5.00],
                        ['sku' => 'PNL-DST-12', 'qty' => 2, 'disc' => 0.00],
                    ],
                ],
                [
                    'number' => 'QT-2026-0002',
                    'client' => $createdClients[1],
                    'date' => now()->subDays(5)->toDateString(),
                    'valid_until' => now()->addDays(25)->toDateString(),
                    'status' => QuotationStatus::Sent,
                    'notes' => 'Infrastructure perimeter and tunnel LED lighting supply.',
                    'items' => [
                        ['sku' => 'LGT-FLD-200', 'qty' => 20, 'disc' => 10.00],
                        ['sku' => 'LGT-BAY-150', 'qty' => 12, 'disc' => 5.00],
                        ['sku' => 'PVC-CND-10', 'qty' => 50, 'disc' => 0.00],
                    ],
                ],
                [
                    'number' => 'QT-2026-0003',
                    'client' => $createdClients[2],
                    'date' => now()->subDays(2)->toDateString(),
                    'valid_until' => now()->addDays(28)->toDateString(),
                    'status' => QuotationStatus::Draft,
                    'notes' => 'Renewable power plant wiring maintenance & diagnostics package.',
                    'items' => [
                        ['sku' => 'WR-CU-100', 'qty' => 15, 'disc' => 0.00],
                        ['sku' => 'TLS-DMM-PRO', 'qty' => 3, 'disc' => 0.00],
                        ['sku' => 'TLS-CRM-HD', 'qty' => 2, 'disc' => 0.00],
                    ],
                ],
                [
                    'number' => 'QT-2026-0004',
                    'client' => $createdClients[3],
                    'date' => now()->subDays(25)->toDateString(),
                    'valid_until' => now()->subDays(1)->toDateString(),
                    'status' => QuotationStatus::Expired,
                    'notes' => 'Industrial park heavy switchgear and socket distribution.',
                    'items' => [
                        ['sku' => 'SWG-400A-01', 'qty' => 2, 'disc' => 0.00],
                        ['sku' => 'SCK-IND-32', 'qty' => 25, 'disc' => 8.00],
                    ],
                ],
            ];

            foreach ($sampleQuotations as $qData) {
                $subtotal = 0;
                $itemsToCreate = [];

                foreach ($qData['items'] as $item) {
                    $prod = $createdProducts[$item['sku']] ?? null;
                    $pName = $prod ? $prod->name : 'Electrical Component';
                    $uPrice = $prod ? (float) $prod->price : 100.00;
                    $qty = $item['qty'];
                    $disc = $item['disc'];
                    $itemSubtotal = round(($qty * $uPrice) * (1 - ($disc / 100)), 2);
                    $subtotal += $itemSubtotal;

                    $itemsToCreate[] = [
                        'product_id' => $prod?->id,
                        'sku' => $item['sku'],
                        'product_name' => $pName,
                        'quantity' => $qty,
                        'unit_price' => $uPrice,
                        'discount' => $disc,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                $taxRate = 12.00;
                $taxAmount = round($subtotal * ($taxRate / 100), 2);
                $grandTotal = $subtotal + $taxAmount;

                $quotation = Quotation::updateOrCreate(
                    ['team_id' => $team->id, 'quotation_number' => $qData['number']],
                    [
                        'client_id' => $qData['client']->id,
                        'client_name' => $qData['client']->company_name,
                        'contact_person' => $qData['client']->contact_person,
                        'email' => $qData['client']->email,
                        'phone' => $qData['client']->phone,
                        'address' => $qData['client']->address,
                        'quotation_date' => $qData['date'],
                        'valid_until' => $qData['valid_until'],
                        'subtotal' => $subtotal,
                        'discount' => 0.00,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'grand_total' => $grandTotal,
                        'status' => $qData['status'],
                        'notes' => $qData['notes'],
                        'terms_and_conditions' => $terms,
                        'prepared_by_id' => $adminUser?->id,
                    ]
                );

                $quotation->items()->delete();
                foreach ($itemsToCreate as $itemData) {
                    $quotation->items()->create($itemData);
                }
            }
        }
    }
}
