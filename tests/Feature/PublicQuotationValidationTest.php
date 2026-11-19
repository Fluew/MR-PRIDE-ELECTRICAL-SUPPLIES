<?php

use App\Models\Quotation;
use App\Models\Team;
use App\Services\EmailDomainChecker;
use App\Support\ContactPhone;
use App\Support\EmailAddress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Request Quotation validation. The server repeats every rule the form applies, and decides from what it
 * receives whether a brand is required: only an accepted product-list file makes it optional.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function vqPayload(array $overrides = []): array
{
    return array_merge([
        'company' => 'Metro Builders Inc.', 'contactName' => 'Ana Reyes', 'email' => 'ana@metro.example',
        'phoneCountry' => '+63', 'phone' => '9171234567', 'projectType' => 'Commercial Building', 'timeline' => '1-3 months',
        'brands' => [vqRow()],
    ], $overrides);
}

function vqRow(string $brand = 'Others', string $details = 'Relay 24V', string $quantity = '4', string $brandName = 'Omron'): array
{
    return ['brand' => $brand, 'brand_name' => $brandName, 'details' => $details, 'quantity' => $quantity];
}

function vqList(string $name = 'list.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "Description,Qty,Unit\nCircuit breaker 2P 40A,10,pcs\n");
}

// ------------------------------------------------------------------ company and contact

it('rejects an empty, whitespace-only or symbol-only company name and contact name', function (string $value) {
    $this->post('/quotation', vqPayload(['company' => $value, 'contactName' => $value]))->assertSessionHasErrors(['company', 'contactName']);

    expect(Quotation::count())->toBe(0);
})->with(['', '   ', "\t \n", '---', '***']);

it('accepts a valid request and saves the company, contact and number', function () {
    $this->post('/quotation', vqPayload())->assertSessionHasNoErrors();

    $quotation = Quotation::sole();
    expect($quotation->client_name)->toBe('Metro Builders Inc.')->and($quotation->contact_person)->toBe('Ana Reyes')
        ->and($quotation->email)->toBe('ana@metro.example')->and($quotation->phone)->toBe('+63 9171234567');
});

// ------------------------------------------------------------------ email syntax

it('rejects malformed email addresses', function (string $email) {
    $this->post('/quotation', vqPayload(['email' => $email]))->assertSessionHasErrors('email');

    expect(Quotation::count())->toBe(0);
})->with(['name@g.com', 'name@', '@gmail.com', 'name.gmail.com', 'name@gmail', 'name@invalid', '', 'a b@gmail.com', 'name@@gmail.com', 'name@gmail..com', 'name@-gmail.com', 'name@gmail.c', 'na..me@gmail.com']);

it('accepts well-formed email addresses', function (string $email) {
    $this->post('/quotation', vqPayload(['email' => $email]))->assertSessionHasNoErrors();
})->with(['company@gmail.com', 'john@yahoo.com', 'ana.reyes+rfq@acme-builders.com.ph']);

it('checks the same syntax rules in the shared helper', function () {
    expect(EmailAddress::syntaxError('company@gmail.com'))->toBeNull()
        ->and(EmailAddress::syntaxError('name@g.com'))->not->toBeNull()
        ->and(EmailAddress::syntaxError('name@gmail'))->not->toBeNull();
});

// ------------------------------------------------------------------ email domain (DNS)

/** A resolver whose answers are set by the test: [domain => [dns type => records|false]]. Records the lookups made. */
function vqResolver(array $answers): EmailDomainChecker
{
    $checker = new class($answers) extends EmailDomainChecker
    {
        public array $lookups = [];

        public function __construct(public array $answers) {}

        protected function records(string $domain, int $type): array|false
        {
            $domain = rtrim($domain, '.');
            $this->lookups[] = $domain;

            return $this->answers[$domain][$type] ?? $this->answers[$domain]['*'] ?? [];
        }
    };
    app()->instance(EmailDomainChecker::class, $checker);
    config(['validation.email_dns_check' => true]);
    Cache::flush();

    return $checker;
}

const VQ_ADDR = DNS_A | DNS_AAAA;

it('accepts a domain that publishes a mail server, and one that only has an address record', function () {
    vqResolver([
        'gmail.com' => [DNS_MX => [['target' => 'gmail-smtp-in.l.google.com']]],
        'metro.example' => [DNS_MX => [['target' => 'mail.metro.example']]],
        'web-only.example' => [DNS_MX => [], VQ_ADDR => [['ip' => '203.0.113.5']]],
    ]);

    $this->post('/quotation', vqPayload(['email' => 'ana@metro.example']))->assertSessionHasNoErrors();
    $this->post('/quotation', vqPayload(['email' => 'ana@web-only.example', 'company' => 'Web Only Co']))->assertSessionHasNoErrors();
    expect(Quotation::count())->toBe(2);
});

it('rejects a domain that does not exist, one with no mail or address records, and one that declares no mail', function (string $domain, array $answers) {
    vqResolver(['gmail.com' => [DNS_MX => [['target' => 'gmail-smtp-in.l.google.com']]], $domain => $answers]);

    $this->post('/quotation', vqPayload(['email' => "ana@{$domain}"]))->assertSessionHasErrors(['email' => "We couldn't find a mail server for {$domain}. Please check your email address."]);

    expect(Quotation::count())->toBe(0);
})->with([
    'does not exist (lookup fails while DNS works)' => ['nowhere-at-all.example', ['*' => false]],
    'exists but has no mail or address records' => ['empty-zone.example', [DNS_MX => [], VQ_ADDR => []]],
    'null MX: accepts no mail' => ['no-mail.example', [DNS_MX => [['target' => '.']]]],
]);

it('lets the request through when DNS itself is unavailable, instead of calling the domain invalid', function () {
    // Every lookup fails, including the control domain: the resolver is down, so nothing is proven about the domain.
    $checker = vqResolver(['*' => false, 'ana-down.example' => ['*' => false], 'gmail.com' => ['*' => false]]);

    $this->post('/quotation', vqPayload(['email' => 'ana@ana-down.example']))->assertSessionHasNoErrors();
    expect(Quotation::count())->toBe(1);

    // The temporary failure is not remembered: the domain is looked up again next time.
    $before = count($checker->lookups);
    expect($checker->status('ana-down.example'))->toBe(EmailDomainChecker::UNAVAILABLE)->and(count($checker->lookups))->toBeGreaterThan($before);
});

it('remembers a confirmed result so the domain is not looked up again', function () {
    $checker = vqResolver(['metro.example' => [DNS_MX => [['target' => 'mail.metro.example']]]]);

    expect($checker->status('metro.example'))->toBe(EmailDomainChecker::VALID);
    $lookups = count($checker->lookups);
    expect($checker->status('metro.example'))->toBe(EmailDomainChecker::VALID)->and(count($checker->lookups))->toBe($lookups);
});

it('skips the DNS check when it is switched off, and never reaches it for a malformed address', function () {
    $checker = vqResolver([]);
    config(['validation.email_dns_check' => false]);
    $this->post('/quotation', vqPayload(['email' => 'ana@unchecked.example']))->assertSessionHasNoErrors();

    config(['validation.email_dns_check' => true]);
    $this->post('/quotation', vqPayload(['email' => 'name@gmail']))->assertSessionHasErrors('email');
    expect($checker->lookups)->toBe([]);                               // syntax failed first: no network
});

// ------------------------------------------------------------------ contact number

it('accepts a Philippine mobile and landline, and saves the number with its country code', function (string $digits) {
    $this->post('/quotation', vqPayload(['phone' => $digits]))->assertSessionHasNoErrors();

    expect(Quotation::sole()->phone)->toBe("+63 {$digits}");
})->with(['9171234567', '281234567', '322345678']);

it('rejects invalid Philippine numbers', function (string $digits) {
    $this->post('/quotation', vqPayload(['phone' => $digits]))->assertSessionHasErrors('phone');

    expect(Quotation::count())->toBe(0);
})->with(['917ABC567', '12345', '0000000000', '09171234567', '917123456', '91712345678', '2812345678', '1234567890', '917 123 4567', '9171234-67', '+639171234567', '']);

it('requires the country code to come from the list', function (?string $code) {
    $payload = vqPayload();
    $code === null ? $payload['phoneCountry'] = null : $payload['phoneCountry'] = $code;

    $this->post('/quotation', $payload)->assertSessionHasErrors('phoneCountry');

    expect(Quotation::count())->toBe(0);
})->with([null, '', '+999', '63', 'PH', '+0']);

it('validates other countries by their own number length', function () {
    $this->post('/quotation', vqPayload(['phoneCountry' => '+65', 'phone' => '81234567']))->assertSessionHasNoErrors();
    $this->post('/quotation', vqPayload(['phoneCountry' => '+65', 'phone' => '8123456', 'company' => 'Other Co']))->assertSessionHasErrors('phone');

    expect(Quotation::sole()->phone)->toBe('+65 81234567')
        ->and(ContactPhone::error('+63', '9171234567'))->toBeNull()
        ->and(ContactPhone::error('+63', '0000000000'))->not->toBeNull();
});

// ------------------------------------------------------------------ products: normal workflow

it('rejects a product row without a description, without a quantity, or with a bad quantity', function (array $row) {
    $this->post('/quotation', vqPayload(['brands' => [$row]]))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
})->with([
    'no description' => [vqRow('Others', '', '4')],
    'whitespace description' => [vqRow('Others', '   ', '4')],
    'no quantity' => [vqRow('Others', 'Relay', '')],
    'alphabetic quantity' => [vqRow('Others', 'Relay', 'ten')],
    'zero' => [vqRow('Others', 'Relay', '0')],
    'negative' => [vqRow('Others', 'Relay', '-3')],
    'decimal' => [vqRow('Others', 'Relay', '2.5')],
    'with a unit' => [vqRow('Others', 'Relay', '10 pcs')],
    'too large' => [vqRow('Others', 'Relay', '1000001')],
]);

it('requires a brand for a product chosen in the normal workflow', function () {
    $this->post('/quotation', vqPayload(['brands' => [['brand' => '', 'details' => 'Relay 24V', 'quantity' => '4']]]))
        ->assertSessionHasErrors(['brands' => 'Brand #1: choose a brand, or choose Others if the brand is not listed.']);

    expect(Quotation::count())->toBe(0);
});

it('requires at least one product when there is no product list', function () {
    $this->post('/quotation', vqPayload(['brands' => []]))->assertSessionHasErrors('brands');
    $this->post('/quotation', vqPayload(['brands' => null]))->assertSessionHasErrors('brands');
    $this->post('/quotation', array_diff_key(vqPayload(), ['brands' => 1]))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

// ------------------------------------------------------------------ products: the product-list exception

it('makes brand optional only when a valid product list is uploaded', function () {
    // A list and no rows at all: accepted, and the file is attached to the quotation.
    $this->post('/quotation', vqPayload(['brands' => [], 'productFiles' => [vqList()]]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('files')->sole();
    expect($quotation->files)->toHaveCount(1)->and($quotation->files->first()->original_name ?? 'list.csv')->not->toBeEmpty();
    Storage::disk('local')->assertExists($quotation->files->first()->path);
});

it('accepts a product row without a brand when a list is attached, keeping the description and quantity', function () {
    $this->post('/quotation', vqPayload(['brands' => [['brand' => '', 'details' => 'Floodlight 200W', 'quantity' => '6']], 'productFiles' => [vqList()]]))
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->request_details['brands'][0])->toMatchArray(['brand' => 'Others', 'brand_name' => 'Brand not specified', 'details' => 'Floodlight 200W', 'quantity' => 6])
        ->and($quotation->items->firstWhere('sku', 'OTHERS')->product_name)->toContain('Floodlight 200W');
});

it('still validates a started row when a list is attached: the description and a whole-number quantity are required', function () {
    $this->post('/quotation', vqPayload(['brands' => [['brand' => '', 'details' => '', 'quantity' => '6']], 'productFiles' => [vqList()]]))->assertSessionHasErrors('brands');
    $this->post('/quotation', vqPayload(['brands' => [['brand' => '', 'details' => 'Floodlight', 'quantity' => 'six']], 'productFiles' => [vqList()]]))->assertSessionHasErrors('brands');

    expect(Quotation::count())->toBe(0);
});

it('does not let anyone skip the brand rule by sending extra parameters instead of a file', function (array $extra) {
    // Refused either by the brand rule or, for a fake "file", by the file check: never saved.
    $this->post('/quotation', vqPayload(['brands' => [['brand' => '', 'details' => 'Relay 24V', 'quantity' => '4']]] + $extra))->assertSessionHasErrors();

    expect(Quotation::count())->toBe(0);
})->with([
    'a flag' => [['usesProductList' => true, 'hasProductList' => 'true', 'specifyProducts' => 1]],
    'an empty file list' => [['productFiles' => []]],
    'text where a file belongs' => [['productFiles' => ['list.csv']]],
    'a null file' => [['productFiles' => [null]]],
]);

it('does not skip the contact checks when a product list is attached', function () {
    $this->post('/quotation', vqPayload(['company' => ' ', 'contactName' => '', 'email' => 'name@g.com', 'phone' => '12345', 'brands' => [], 'productFiles' => [vqList()]]))
        ->assertSessionHasErrors(['company', 'contactName', 'email', 'phone']);

    expect(Quotation::count())->toBe(0);
});

it('rejects a product list in an unsupported format, one that is too large, and an empty one', function () {
    $this->post('/quotation', vqPayload(['brands' => [], 'productFiles' => [UploadedFile::fake()->create('list.exe', 10)]]))->assertSessionHasErrors('productFiles.0');
    $this->post('/quotation', vqPayload(['brands' => [], 'productFiles' => [UploadedFile::fake()->create('notes.docx', 10)]]))->assertSessionHasErrors('productFiles.0');
    $this->post('/quotation', vqPayload(['brands' => [], 'productFiles' => [UploadedFile::fake()->create('huge.csv', 26 * 1024)]]))->assertSessionHasErrors('productFiles.0');
    $this->post('/quotation', vqPayload(['brands' => [], 'productFiles' => array_fill(0, 6, vqList())]))->assertSessionHasErrors('productFiles');

    expect(Quotation::count())->toBe(0);
});
