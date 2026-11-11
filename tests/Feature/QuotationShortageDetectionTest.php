<?php

use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('accurately computes available stock and shortage on quotation line items', function () {
    $inStockProduct = Product::create([
        'team_id' => $this->team->id,
        'name' => '100A Molded Case Circuit Breaker',
        'sku' => 'MCCB-100A',
        'price' => 3500.00,
        'stock' => 50,
        'is_active' => true,
    ]);

    $lowStockProduct = Product::create([
        'team_id' => $this->team->id,
        'name' => '200A Main Industrial Breaker',
        'sku' => 'MCCB-200A',
        'price' => 7500.00,
        'stock' => 3,
        'is_active' => true,
    ]);

    $quotation = Quotation::create([
        'team_id' => $this->team->id,
        'quotation_number' => 'Q-SHORTAGE-TEST',
        'client_name' => 'Prime Power Corp',
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal' => 0,
        'discount' => 0,
        'tax_rate' => 12,
        'tax_amount' => 0,
        'grand_total' => 0,
        'status' => 'draft',
    ]);

    // Item 1: Requested 10, stock 50 -> shortage should be 0
    $item1 = QuotationItem::create([
        'quotation_id' => $quotation->id,
        'product_id' => $inStockProduct->id,
        'sku' => $inStockProduct->sku,
        'product_name' => $inStockProduct->name,
        'quantity' => 10,
        'unit_price' => 3500.00,
        'subtotal' => 35000.00,
    ]);

    // Item 2: Requested 10, stock 3 -> shortage should be 7
    $item2 = QuotationItem::create([
        'quotation_id' => $quotation->id,
        'product_id' => $lowStockProduct->id,
        'sku' => $lowStockProduct->sku,
        'product_name' => $lowStockProduct->name,
        'quantity' => 10,
        'unit_price' => 7500.00,
        'subtotal' => 75000.00,
    ]);

    $item1->load('product');
    $item2->load('product');

    expect($item1->available_stock)->toBe(50);
    expect($item1->shortage)->toBe(0);

    expect($item2->available_stock)->toBe(3);
    expect($item2->shortage)->toBe(7);
});
