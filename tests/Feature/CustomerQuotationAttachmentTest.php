<?php

use App\Enums\TeamRole;
use App\Models\Brand;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function attachmentActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    $deviceData = createTrustedDeviceFor($user);

    return [$user, $deviceData['cookies']];
}

it('no longer accepts a specifications/BOQ attachment on the public form', function () {
    $brand = Brand::firstOrCreate(['name' => 'Schneider Electric'], ['is_partnered' => true]);
    $file = UploadedFile::fake()->create('project_specifications.pdf', 1024, 'application/pdf');

    $response = $this->post('/quotation', [
        'company' => 'Acme Industrial Solutions',
        'contactName' => 'Juan Dela Cruz',
        'email' => 'juan@acme.ph',
        'phone' => '09171234567',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
        'brands' => [
            [
                'brand' => $brand->name,
                'details' => '30A 2-pole breakers - 50 pcs',
            ],
        ],
        'attachment' => $file,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    // The request is saved; the file sent to the removed field is not.
    $quotation = Quotation::where('email', 'juan@acme.ph')->latest('id')->first();
    expect($quotation)->not->toBeNull();
    expect($quotation->attachment_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('allows authorized staff to download quotation attachment', function () {
    [$user, $cookies] = attachmentActor('admin', $this->team);

    $file = UploadedFile::fake()->create('electrical_layout.pdf', 500, 'application/pdf');
    $path = $file->store('quotations/attachments', 'local');

    $quotation = Quotation::create([
        'team_id' => $this->team->id,
        'quotation_number' => 'Q-TEST-ATTACH-001',
        'client_name' => 'Apex Corp',
        'contact_person' => 'Maria Santos',
        'email' => 'maria@apex.ph',
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal' => 1000.00,
        'discount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 120.00,
        'grand_total' => 1120.00,
        'status' => 'draft',
        'attachment_path' => $path,
    ]);

    $response = $this->actingAs($user)->withCookies($cookies)->get("/{$this->team->slug}/quotations/{$quotation->id}/attachment");
    $response->assertOk();
});
