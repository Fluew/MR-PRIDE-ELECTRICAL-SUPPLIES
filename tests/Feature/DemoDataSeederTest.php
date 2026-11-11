<?php

use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;

/*
 * The demo data seeder runs the real workflows over a short period and leaves consistent data:
 * stock on hand equals the movement history, and nothing is emailed to anyone.
 */

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
});

it('seeds four months of consistent workflow data', function () {
    putenv('DEMO_DATA_UNTIL=2020-04-30');
    $_ENV['DEMO_DATA_UNTIL'] = $_SERVER['DEMO_DATA_UNTIL'] = '2020-04-30';

    try {
        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class])->assertSuccessful();
    } finally {
        putenv('DEMO_DATA_UNTIL');
        unset($_ENV['DEMO_DATA_UNTIL'], $_SERVER['DEMO_DATA_UNTIL']);
    }

    expect(Product::count())->toBe(44)
        ->and(Quotation::count())->toBeGreaterThan(10)
        ->and(Sale::count())->toBeGreaterThan(5)
        ->and(Sale::where('status', 'completed')->count())->toBeGreaterThan(0)
        ->and(PurchaseOrder::count())->toBeGreaterThan(0)
        ->and(Delivery::whereNotNull('inspection_result')->count())->toBeGreaterThan(0)
        ->and(Quotation::min('quotation_date'))->toStartWith('2020-01')
        ->and(Quotation::max('quotation_date'))->toBeLessThanOrEqual('2020-04-30');

    // Stock on hand = sum of the movement history, for every product.
    foreach (Product::all() as $product) {
        expect((int) StockMovement::where('product_id', $product->id)->sum('quantity'))->toBe((int) $product->stock, $product->name);
    }
    // No customer email left the system.
    expect(config('mail.default'))->toBe('array');
});

it('refuses to seed a team that already has products', function () {
    Product::create(['team_id' => $this->team->id, 'sku' => 'GEN-000001', 'name' => 'Existing', 'price' => 1, 'stock' => 1, 'is_active' => true]);

    $this->artisan('db:seed', ['--class' => DemoDataSeeder::class])->assertSuccessful();

    expect(Product::count())->toBe(1)->and(Quotation::count())->toBe(0);
});
