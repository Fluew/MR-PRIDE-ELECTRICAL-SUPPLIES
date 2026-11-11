<?php

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Exceptions\QuotationNumberException;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Quotation numbers (Q<mmddyy>-<SKU or PROD>, then -01, -02, …) are unique per team and never
 * handed out twice: not after archiving or deleting, not to requests sent at the same time, and
 * not by any of the ways a quotation is created (public form, staff form, imports).
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-30 09:00:00'); // numbers of the day start with Q103026
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
});

function numberingStaff(Team $team): array
{
    $user = User::factory()->create(['role' => 'staff_quotations', 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function publicRequest(array $overrides = []): array
{
    return $overrides + [
        'company' => 'Acme Builders',
        'contactName' => 'Juan Dela Cruz',
        'email' => 'juan@acme.test',
        'phone' => '09171234567',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
    ];
}

/** A quotation saved with a fixed number, the way older records and CSV imports have them. */
function quotationNumbered(Team $team, string $number): Quotation
{
    return Quotation::create([
        'team_id' => $team->id, 'quotation_number' => $number, 'client_name' => 'Old Client',
        'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29', 'status' => QuotationStatus::Draft,
    ]);
}

function staffQuotation(string $sku = 'PROD'): array
{
    return [
        'client_name' => 'Metro Electric', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29',
        'status' => QuotationStatus::Draft->value,
        'items' => [['sku' => $sku, 'product_name' => 'Breaker 30A', 'quantity' => 2, 'unit_price' => 100]],
    ];
}

/** Simulates another request saving the same number at the same moment as this one. */
function collideOnCreate(int $times = PHP_INT_MAX): void
{
    $left = $times;
    Quotation::creating(function (Quotation $q) use (&$left) {
        if ($left-- > 0) {
            Quotation::withoutEvents(fn () => quotationNumbered($q->team, $q->quotation_number));
        }
    });
}

it('numbers the first quotation of the day, then counts up', function () {
    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();
    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();
    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();

    expect(Quotation::orderBy('id')->pluck('quotation_number')->all())
        ->toBe(['Q103026-PROD', 'Q103026-PROD-01', 'Q103026-PROD-02']);
});

it('never reuses the number of an archived quotation (the reported duplicate-entry error)', function () {
    // Q103026-PROD-02 is in the Recycle Bin: the old generator could not see it and handed it out again.
    quotationNumbered($this->team, 'Q103026-PROD');
    quotationNumbered($this->team, 'Q103026-PROD-01');
    quotationNumbered($this->team, 'Q103026-PROD-02')->delete();

    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();

    expect(Quotation::latest('id')->value('quotation_number'))->toBe('Q103026-PROD-03')
        ->and(Quotation::onlyTrashed()->sole()->quotation_number)->toBe('Q103026-PROD-02');
});

it('never reuses the number of a permanently deleted quotation', function () {
    Quotation::createWithNumber(['team_id' => $this->team->id, 'client_name' => 'A', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29']);
    $last = Quotation::createWithNumber(['team_id' => $this->team->id, 'client_name' => 'B', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29']);
    expect($last->quotation_number)->toBe('Q103026-PROD-01');

    $last->forceDelete();
    $next = Quotation::createWithNumber(['team_id' => $this->team->id, 'client_name' => 'C', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29']);

    expect($next->quotation_number)->toBe('Q103026-PROD-02');
});

it('continues after the highest existing number and leaves existing numbers unchanged', function () {
    $older = [
        quotationNumbered($this->team, 'Q103026-PROD-01'),
        quotationNumbered($this->team, 'q103026-prod-07'), // other spelling: MySQL's unique index treats it as the same number
        quotationNumbered($this->team, 'Q103026-PRODUCT-09'), // another SKU: not part of this sequence
    ];

    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();

    expect(Quotation::latest('id')->value('quotation_number'))->toBe('Q103026-PROD-08');
    foreach ($older as $q) {
        expect($q->fresh()->quotation_number)->toBe($q->quotation_number);
    }
});

it('gives each request its own number when several arrive in quick succession', function () {
    foreach (range(1, 6) as $i) {
        $this->post('/quotation', publicRequest(['email' => "client{$i}@acme.test"]))->assertSessionHasNoErrors();
    }

    $numbers = Quotation::pluck('quotation_number');
    expect($numbers)->toHaveCount(6)
        ->and($numbers->unique())->toHaveCount(6)
        ->and($numbers->last())->toBe('Q103026-PROD-05');
});

it('numbers each team on its own', function () {
    $other = Team::factory()->create();
    quotationNumbered($this->team, 'Q103026-PROD');

    $mine = Quotation::createWithNumber(['team_id' => $this->team->id, 'client_name' => 'A', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29']);
    $theirs = Quotation::createWithNumber(['team_id' => $other->id, 'client_name' => 'B', 'quotation_date' => '2026-10-30', 'valid_until' => '2026-11-29']);

    expect($mine->quotation_number)->toBe('Q103026-PROD-01')
        ->and($theirs->quotation_number)->toBe('Q103026-PROD');
});

it('uses one sequence for the public form, the staff form and the CSV import', function () {
    [$staff, $cookies] = numberingStaff($this->team);

    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();
    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations', staffQuotation('PROD'))
        ->assertSessionHasNoErrors();
    // CSV rows without a quote number are numbered when saved, after the others.
    $csv = "DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n10-30-2026,,5,Breaker 30A,100,500\n10-30-2026,,2,THHN wire,50,100\n";
    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations/import-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $csv), 'execute' => true])
        ->assertSessionHasNoErrors();

    expect(Quotation::orderBy('id')->pluck('quotation_number')->all())
        ->toBe(['Q103026-PROD', 'Q103026-PROD-01', 'Q103026-PROD-02'])
        ->and(Quotation::latest('id')->first()->items)->toHaveCount(2); // same-day rows stay one quotation
});

it('shows the next number on the staff page without reserving it', function () {
    [$staff, $cookies] = numberingStaff($this->team);
    $page = fn () => $this->actingAs($staff)->withCookies($cookies)->get('/mr-pride/quotations');

    $page()->assertInertia(fn (Assert $p) => $p->where('nextNumber', 'Q103026-PROD'));
    $page()->assertInertia(fn (Assert $p) => $p->where('nextNumber', 'Q103026-PROD'));

    // A CSV preview does not use up numbers either.
    $csv = "DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n10-30-2026,,5,Breaker 30A,100,500\n";
    $preview = $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations/import-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $csv), 'execute' => false], ['Accept' => 'application/json'])
        ->assertOk();
    expect($preview->json('valid_rows.0.quote_number'))->toBe('Q103026-PROD');

    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();
    expect(Quotation::sole()->quotation_number)->toBe('Q103026-PROD');
});

it('retries with the next number when another request took the number at the same moment', function () {
    collideOnCreate(times: 1);

    $this->post('/quotation', publicRequest())->assertSessionHasNoErrors();

    expect(Quotation::where('email', 'juan@acme.test')->sole()->quotation_number)->toBe('Q103026-PROD-01');
});

it('gives up after a few collisions, saves nothing and shows the visitor a plain message', function () {
    Log::spy();
    collideOnCreate();

    $this->from('/quotation')
        ->post('/quotation', publicRequest())
        ->assertRedirect('/quotation')
        ->assertSessionHasErrors(['submit' => 'We could not send your request just now. Please try again in a moment. If it keeps happening, please contact us directly.'])
        ->assertSessionHasInput('company', 'Acme Builders');

    expect(Quotation::withTrashed()->count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'Public quotation request could not be saved')
        && $context['exception'] instanceof QuotationNumberException)->once();
});

it('keeps the staff form open with a message when no number can be assigned', function () {
    [$staff, $cookies] = numberingStaff($this->team);
    collideOnCreate();

    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations', staffQuotation())
        ->assertSessionHasErrors('quotation_number');

    expect(Quotation::withTrashed()->count())->toBe(0);
});

it('skips CSV rows whose quote number belongs to an archived quotation, without changing it', function () {
    [$staff, $cookies] = numberingStaff($this->team);
    $archived = quotationNumbered($this->team, 'Q103026-PROD-02');
    $archived->delete();

    $csv = "DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n10-30-2026,Q103026-PROD-02,5,Breaker 30A,100,500\n";
    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations/import-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $csv), 'execute' => true, 'mode' => 'replace'])
        ->assertSessionHasNoErrors();

    expect(Quotation::withTrashed()->count())->toBe(1)
        ->and($archived->fresh()->trashed())->toBeTrue()
        ->and($archived->fresh()->client_name)->toBe('Old Client');
});
