<?php

use App\Models\Client;
use App\Models\Quotation;
use App\Models\Team;
use App\Services\DocumentNumbers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * The public "Request a Quotation" submission on any database server.
 *
 * Staging (MySQL 8) refused every request with "We could not send your request just now": the
 * number sequence used `last_value` unquoted in raw SQL, and LAST_VALUE is a reserved word in
 * MySQL 8 (not in MariaDB, used locally). The same pattern numbered SKUs, inbound deliveries,
 * invoices, receipts and releases.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function rfqForm(array $overrides = []): array
{
    return array_merge([
        'company' => 'Metro Builders Inc.',
        'contactName' => 'Engr. Ana Reyes',
        'email' => 'ana@metro.example',
        'phone' => '0917 555 0101',
        'projectType' => 'Commercial Building',
        'timeline' => '1-3 months',
    ], $overrides);
}

function rfqCsv(string $name = 'list.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "Description,Qty,Unit\nCircuit breaker 2P 40A,10,pcs\nTHHN wire 12 AWG,3,roll\n");
}

it('never uses the reserved word last_value unquoted in raw SQL', function () {
    $bare = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->filter(fn ($f) => preg_match('/DB::raw\([^)]*(?<![`"\w{])last_value(?![`"\w])/', $f->getContents()))
        ->map(fn ($f) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname()))
        ->values()->all();

    expect($bare)->toBe([]);
});

it('quotes last_value in the SQL the number sequences run', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        if (str_contains($query->sql, 'quotation_number_sequences') && str_starts_with(strtolower($query->sql), 'update')) {
            $sql[] = $query->sql;
        }
    });

    // First call inserts the sequence row; the second runs the UPDATE with the CASE expression.
    Quotation::generateNumber($this->team->id, 'BRK1');
    Quotation::generateNumber($this->team->id, 'BRK1');
    DocumentNumbers::next($this->team->id, DocumentNumbers::RECEIPT);
    DocumentNumbers::next($this->team->id, DocumentNumbers::RECEIPT);

    $quoted = DB::getQueryGrammar()->wrap('last_value');
    expect($sql)->not->toBeEmpty();
    foreach ($sql as $statement) {
        expect($statement)->toContain("CASE WHEN {$quoted} >")
            ->and(preg_match('/(?<![`"\w])last_value(?![`"\w])/', $statement))->toBe(0);
    }
});

it('saves a valid request with its client and files and confirms it', function () {
    $this->post('/quotation', rfqForm([
        'brands' => [['brand' => 'Others', 'brand_name' => 'Firefly', 'details' => 'LED panel 2x2', 'quantity' => '20']],
        'productFiles' => [rfqCsv()],
    ]))->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'has been received'));

    $quotation = Quotation::with('files', 'client')->sole();
    expect($quotation->quotation_number)->toStartWith('Q'.now()->format('mdy'))
        ->and($quotation->client_name)->toBe('Metro Builders Inc.')
        ->and($quotation->email)->toBe('ana@metro.example')
        ->and($quotation->request_details['source'])->toBe('public_form')
        ->and($quotation->client->company_name)->toBe('Metro Builders Inc.')
        ->and($quotation->files)->toHaveCount(1)
        ->and($quotation->files->first()->original_name)->toBe('list.csv');
    Storage::disk('local')->assertExists($quotation->files->first()->path);
    expect(Client::count())->toBe(1);
});

it('does not save a resubmitted identical request twice, but still confirms it', function () {
    $this->post('/quotation', rfqForm(['productFiles' => [rfqCsv()]]))->assertSessionHasNoErrors()->assertSessionHas('success');
    // The visitor retries (slow connection, Back + submit): same details, same file content.
    $this->post('/quotation', rfqForm(['productFiles' => [rfqCsv('list-copy.csv')]]))->assertSessionHasNoErrors()->assertSessionHas('success');
    $this->post('/quotation', rfqForm(['email' => ' ANA@metro.example ', 'productFiles' => [rfqCsv()]]))->assertSessionHasNoErrors();

    expect(Quotation::count())->toBe(1);
    expect(Quotation::sole()->files)->toHaveCount(1);
});

it('saves a different request from the same customer, and the same one again after the window', function () {
    $this->post('/quotation', rfqForm())->assertSessionHasNoErrors();
    $this->post('/quotation', rfqForm(['timeline' => '3-6 months']))->assertSessionHasNoErrors();
    expect(Quotation::count())->toBe(2);

    $this->travel(11)->minutes();
    $this->post('/quotation', rfqForm())->assertSessionHasNoErrors();
    expect(Quotation::count())->toBe(3)
        ->and(Quotation::pluck('quotation_number')->unique())->toHaveCount(3);
});

it('keeps the visitor on the form with a clear message when saving fails, and a retry then works', function () {
    Log::spy();
    Quotation::creating(function () {
        throw new RuntimeException('database unavailable');
    });

    $this->post('/quotation', rfqForm(['productFiles' => [rfqCsv()]]))
        ->assertSessionHasErrors(['submit' => 'We could not send your request just now. Please try again in a moment. If it keeps happening, please contact us directly.']);
    expect(Quotation::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Public quotation request could not be saved: database unavailable'))->once();

    // The failure was not remembered as "received": the retry is saved.
    Quotation::flushEventListeners();
    Quotation::boot();
    $this->post('/quotation', rfqForm(['productFiles' => [rfqCsv()]]))->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(Quotation::count())->toBe(1);
});

it('shows field errors for invalid input and saves nothing', function () {
    $this->post('/quotation', rfqForm(['company' => '', 'email' => 'not-an-email', 'projectType' => 'Spaceport']))
        ->assertSessionHasErrors(['company', 'email', 'projectType']);
    expect(Quotation::count())->toBe(0);
});
