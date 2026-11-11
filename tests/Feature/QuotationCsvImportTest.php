<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('can validate and preview quotation csv upload with required headers', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $csvContent = implode("\n", [
        'DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT',
        '10-25-2026,Q102526-068SSCR2,5,"0.68mm Stainless Steel Conduit Rod 2m",450.00,2250.00',
        '01-04-2023,Q010423-068SSCR2,8,"0.68mm Stainless Steel Conduit Rod 2m",450.00,3600.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('quotations.csv', $csvContent);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/quotations/import-csv", [
            'file' => $file,
            'execute' => false,
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertSuccessful();
    $data = $response->json();

    expect($data['success'])->toBeTrue()
        ->and($data['total_rows'])->toBe(2)
        ->and($data['valid_count'])->toBe(2)
        ->and($data['invalid_count'])->toBe(0)
        ->and($data['valid_rows'][0]['display_date'])->toBe('10-25-2026')
        ->and($data['valid_rows'][0]['quote_number'])->toBe('Q102526-068SSCR2')
        ->and($data['valid_rows'][0]['quantity'])->toBe(5)
        ->and($data['valid_rows'][0]['description'])->toBe('0.68mm Stainless Steel Conduit Rod 2m')
        ->and($data['valid_rows'][0]['unit_price'])->toEqual(450.0)
        ->and($data['valid_rows'][0]['amount'])->toEqual(2250.0)
        ->and($data['valid_rows'][1]['display_date'])->toBe('01-04-2023')
        ->and($data['valid_rows'][1]['quote_number'])->toBe('Q010423-068SSCR2');
});

test('can execute quotation csv import and create quotation records and line items', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => '068SSCR2',
        'name' => '0.68mm Stainless Steel Conduit Rod 2m',
        'category' => 'Conduits',
        'unit' => 'pcs',
        'price' => 450.00,
        'cost_price' => 300.00,
        'stock' => 50,
        'status' => 'In Stock',
    ]);

    $csvContent = implode("\n", [
        'DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT',
        '10-25-2026,Q102526-068SSCR2,5,"0.68mm Stainless Steel Conduit Rod 2m",450.00,2250.00',
        '01-04-2023,Q010423-068SSCR2,8,"0.68mm Stainless Steel Conduit Rod 2m",450.00,3600.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('quotations.csv', $csvContent);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/quotations/import-csv", [
            'file' => $file,
            'execute' => true,
            'default_client_name' => 'Metro Electric Builders',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('quotations', [
        'team_id' => $team->id,
        'quotation_number' => 'Q102526-068SSCR2',
        'quotation_date' => '2026-10-25',
        'client_name' => 'Metro Electric Builders',
        'grand_total' => 2250.00,
    ]);

    $this->assertDatabaseHas('quotation_items', [
        'sku' => '068SSCR2',
        'product_name' => '0.68mm Stainless Steel Conduit Rod 2m',
        'quantity' => 5,
        'unit_price' => 450.00,
        'subtotal' => 2250.00,
        'product_id' => $product->id,
    ]);

    $this->assertDatabaseHas('quotations', [
        'team_id' => $team->id,
        'quotation_number' => 'Q010423-068SSCR2',
        'quotation_date' => '2023-01-04',
        'grand_total' => 3600.00,
    ]);
});

test('csv upload fails validation when required headers are missing', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $csvContent = implode("\n", [
        'WRONG_HEADER,ANOTHER_ONE',
        '123,456',
    ]);

    $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/quotations/import-csv", [
            'file' => $file,
            'execute' => false,
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertStatus(422);
    $response->assertJsonFragment([
        'success' => false,
    ]);
});
