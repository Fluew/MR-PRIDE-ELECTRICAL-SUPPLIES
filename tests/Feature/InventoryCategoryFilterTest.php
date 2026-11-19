<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only the categories products use in the Inventory filter, while the product forms keep the standard names', function () {
    Cache::flush();
    $team = Team::factory()->create(['slug' => 'cat-team']);
    $admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($admin, ['role' => TeamRole::Owner->value]);
    foreach (['Circuit Breakers', 'Wires & Conductors', 'circuit breakers ', 'Contactors & Relays'] as $i => $category) {
        Product::create(['team_id' => $team->id, 'name' => "P{$i}", 'sku' => "SKU-{$i}", 'category' => $category, 'price' => 1, 'stock' => 5, 'is_active' => true]);
    }
    Product::create(['team_id' => $team->id, 'name' => 'Gone', 'sku' => 'SKU-GONE', 'category' => 'Deleted Category', 'price' => 1, 'stock' => 5, 'is_active' => true])->delete();

    $cookies = createTrustedDeviceFor($admin)['cookies'];
    $this->actingAs($admin)->withCookies($cookies)->get('/cat-team/inventory')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filter_categories', fn ($list) => collect($list)->sort()->values()->all() === collect(['circuit breakers ', 'Circuit Breakers', 'Contactors & Relays', 'Wires & Conductors'])->sort()->values()->all()
            && ! in_array('Breakers', collect($list)->all(), true) && ! in_array('Deleted Category', collect($list)->all(), true))
        ->where('categories', fn ($list) => in_array('Breakers', collect($list)->all(), true) && in_array('Circuit Breakers', collect($list)->all(), true)));
});
