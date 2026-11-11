<?php

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\NotificationLog;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanent delete from the Recycle Bin removes the record from the database for good: its child
 * rows, its email log entries and its stored files. Files another record still uses are kept.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'bin-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
});

function binQuotation(Team $team, string $number, string $pdfPath): Quotation
{
    Storage::disk('local')->put($pdfPath, 'PDF');
    $quotation = Quotation::create([
        'team_id' => $team->id, 'quotation_number' => $number, 'client_name' => 'Apex Builders', 'email' => 'ana@apex.example',
        'quotation_date' => now()->toDateString(), 'status' => QuotationStatus::Draft, 'sent_pdf_path' => $pdfPath,
        'subtotal' => 100, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 100,
    ]);
    $quotation->items()->create(['sku' => 'BRK', 'product_name' => 'Breaker', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'subtotal' => 100]);

    return $quotation;
}

it('erases a permanently deleted quotation with its lines, files and email log, and nothing else', function () {
    $quotation = binQuotation($this->team, 'Q-BIN-1', 'quotations/Q-BIN-1.pdf');
    Storage::disk('local')->put('quotation-product-files/list.pdf', 'LIST');
    $quotation->files()->create(['kind' => 'product_list', 'path' => 'quotation-product-files/list.pdf', 'original_name' => 'list.pdf', 'mime' => 'application/pdf', 'size' => 4, 'sha256' => hash('sha256', 'LIST')]);
    NotificationLog::create(['team_id' => $this->team->id, 'category' => 'quotation', 'type' => 'quotation_sent', 'notifiable_type' => 'Quotation', 'notifiable_id' => $quotation->id, 'status' => EmailStatus::Sent, 'idempotency_key' => "quotation_sent:{$quotation->id}:1"]);

    // Another quotation that shares the same stored PDF (it must survive).
    $other = binQuotation($this->team, 'Q-BIN-2', 'quotations/shared.pdf');
    $quotation->update(['attachment_path' => 'quotations/shared.pdf']);

    $quotation->delete(); // archived in the Recycle Bin
    $this->actingAs($this->admin)->withCookies($this->cookies)
        ->delete("/bin-team/recycle-bin/quotation/{$quotation->id}/force")
        ->assertSessionHas('success');

    expect(DB::table('quotations')->where('id', $quotation->id)->exists())->toBeFalse()
        ->and(DB::table('quotation_items')->where('quotation_id', $quotation->id)->exists())->toBeFalse()
        ->and(DB::table('quotation_files')->where('quotation_id', $quotation->id)->exists())->toBeFalse()
        ->and(NotificationLog::where('notifiable_type', 'Quotation')->where('notifiable_id', $quotation->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('quotations/Q-BIN-1.pdf');
    Storage::disk('local')->assertMissing('quotation-product-files/list.pdf');

    // Untouched: the other quotation, its lines and the file it still uses.
    expect(Quotation::find($other->id))->not->toBeNull()->and($other->items()->count())->toBe(1);
    Storage::disk('local')->assertExists('quotations/shared.pdf');
});

it('empties the bin from the database too', function () {
    $a = binQuotation($this->team, 'Q-BIN-3', 'quotations/a.pdf');
    $b = binQuotation($this->team, 'Q-BIN-4', 'quotations/b.pdf');
    $a->delete();
    $b->delete();

    $this->actingAs($this->admin)->withCookies($this->cookies)->post('/bin-team/recycle-bin/empty')->assertSessionHas('success');

    expect(DB::table('quotations')->whereIn('id', [$a->id, $b->id])->count())->toBe(0)
        ->and(DB::table('quotation_items')->whereIn('quotation_id', [$a->id, $b->id])->count())->toBe(0);
    Storage::disk('local')->assertMissing('quotations/a.pdf');
    Storage::disk('local')->assertMissing('quotations/b.pdf');
});
