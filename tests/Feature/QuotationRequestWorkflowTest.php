<?php

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationFile;
use App\Models\Team;
use App\Models\User;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\OcrEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The quotation request workflow: brands (catalog and not listed) and product-list files on the
 * public form, and what Quotation Staff can see, open and import on the management page.
 */
beforeEach(function () {
    Storage::fake('local');
    fakeImageOcr();
    $this->team = Team::factory()->create(['slug' => 'mr-pride']);
    Brand::firstOrCreate(['name' => 'Schneider Electric'], ['is_active' => true]);
});

function wfStaff(Team $team, string $role = 'staff_quotations'): array
{
    $user = User::factory()->create(['role' => $role, 'current_team_id' => $team->id, 'password_changed_at' => now()]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

function wfRequest(array $overrides = []): array
{
    return array_merge([
        'company' => 'Acme Builders',
        'contactName' => 'Juan Dela Cruz',
        'email' => 'juan@acme.test',
        'phone' => '09171234567',
        'projectType' => 'Industrial Facility',
        'timeline' => '1-3 months',
    ], $overrides);
}

function wfPng(string $name = 'products.png', int $seed = 1): UploadedFile
{
    $img = imagecreatetruecolor(20 + $seed, 20);
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

function wfPdf(string $name = 'products.pdf'): UploadedFile
{
    return textPdf(['Products needed for Site B', 'Circuit breaker 2P 30A 20 pcs', 'THHN wire 3.5mm 100 meters'], $name);
}

// --- 1. No material list ---------------------------------------------------------------------

it('accepts a request with only contact and project details', function () {
    $this->post('/quotation', wfRequest())->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->items)->toHaveCount(0);
});

it('ignores material lists and specification files sent to the removed fields', function () {
    $this->post('/quotation', wfRequest([
        'materials' => [['description' => 'THHN wire 3.5mm', 'quantity' => 12, 'unit' => 'rolls', 'product_id' => 1]],
        'materials_confirmed' => true,
        'material_token' => str_repeat('a', 40),
        'attachment' => wfPdf('boq.pdf'),
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('items', 'files')->sole();
    expect($quotation->items)->toHaveCount(0)
        ->and($quotation->files)->toHaveCount(0)
        ->and($quotation->attachment_path)->toBeNull()
        ->and($quotation->material_file_path)->toBeNull()
        ->and((float) $quotation->grand_total)->toBe(0.0)
        ->and($quotation->notes)->not->toContain('Material List')->not->toContain('THHN');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('no longer offers the public material-list upload endpoints', function () {
    $this->postJson('/quotation/materials/extract', ['file' => wfPng('site-list.png')])->assertNotFound();
    $this->postJson('/quotation/materials/remap', ['token' => 'x'])->assertNotFound();
});

// --- 2. Product-list files -----------------------------------------------------------------

it('stores several product-list files with the request, once each', function () {
    $this->post('/quotation', wfRequest([
        'productFiles' => [
            wfPng('photo-1.png', 1),
            wfPng('photo-2.png', 2),
            wfPng('photo-1-again.png', 1), // the same image twice
            wfPdf('catalog-page.pdf'),
        ],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('files')->sole();
    expect($quotation->files->pluck('original_name')->all())->toBe(['photo-1.png', 'photo-2.png', 'catalog-page.pdf'])
        ->and($quotation->files->pluck('kind')->unique()->all())->toBe([QuotationFile::KIND_PRODUCT_LIST]);
    foreach ($quotation->files as $file) {
        Storage::disk('local')->assertExists($file->path);
        expect($file->path)->toStartWith('quotations/product-lists/')->not->toContain('photo');
    }
});

it('rejects unsupported, disguised, oversized and too many product-list files', function (array $files, string $errorKey) {
    $this->post('/quotation', wfRequest(['productFiles' => $files]))->assertSessionHasErrors($errorKey);

    expect(Quotation::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('quotations/product-lists'))->toBe([]);
})->with([
    'executable' => [[UploadedFile::fake()->createWithContent('setup.exe', 'MZ...')], 'productFiles.0'],
    'script renamed to .pdf' => [[UploadedFile::fake()->createWithContent('list.pdf', '<?php echo 1;')], 'productFiles.0'],
    'over 25 MB' => [[UploadedFile::fake()->create('big.pdf', 25 * 1024 + 1, 'application/pdf')], 'productFiles.0'],
    'more than five files' => [array_map(fn ($i) => wfPng("p{$i}.png", $i), range(1, 6)), 'productFiles'],
]);

it('accepts product-list files up to 25 MB each, more than the old 10 MB / 20 MB limits together', function () {
    // A real CSV product list padded to the size wanted.
    $csv = function (string $name, int $megabytes): UploadedFile {
        $row = 'Circuit breaker 2P 30A bolt-on '.str_repeat('x', 60).",20,pcs\n";
        $content = "Description,Qty,Unit\n".str_repeat($row, intdiv($megabytes * 1024 * 1024 - 64, strlen($row)));

        return UploadedFile::fake()->createWithContent($name, $content);
    };

    $this->post('/quotation', wfRequest(['productFiles' => [$csv('big-list.csv', 24), $csv('second-list.csv', 12)]]))
        ->assertSessionHasNoErrors();

    expect(Quotation::sole()->files->pluck('original_name')->all())->toBe(['big-list.csv', 'second-list.csv']);
});

// --- 3. Everything is saved together --------------------------------------------------------

it('saves brands, project details and files together as a request awaiting review', function () {
    $this->post('/quotation', wfRequest([
        'timeline' => '3–6 months',
        'brands' => [['brand' => 'schneider electric', 'details' => '20A breakers']],
        'hasUnlistedBrands' => true,
        'unlistedBrands' => 'Omron',
        'productFiles' => [wfPdf()],
    ]))->assertSessionHasNoErrors();

    $quotation = Quotation::with('files', 'items')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->request_details)->toMatchArray([
            'source' => 'public_form',
            'project_type' => 'Industrial Facility',
            'timeline' => '3-6 months',
            'brands' => [['brand' => 'Schneider Electric', 'details' => '20A breakers']],
            'unlisted_brands' => 'Omron',
        ])
        ->and($quotation->request_details['submitted_at'])->not->toBeEmpty()
        ->and($quotation->files)->toHaveCount(1)
        // Brand requests, and the products read from the attached list, wait for staff: nothing is priced.
        ->and($quotation->items->pluck('sku')->all())->toBe(['BRAND', 'BRAND-UNLISTED', 'CLIENT-LIST', 'CLIENT-LIST'])
        ->and((float) $quotation->grand_total)->toBe(0.0);
});

it('only accepts the listed project types and timelines', function () {
    $this->post('/quotation', wfRequest(['projectType' => 'Spaceport']))->assertSessionHasErrors('projectType');
    $this->post('/quotation', wfRequest(['timeline' => 'Someday']))->assertSessionHasErrors('timeline');
});

// --- 4. Staff review, and who may open the files --------------------------------------------

function wfSubmittedRequest(): Quotation
{
    test()->post('/quotation', wfRequest([
        'brands' => [['brand' => 'Schneider Electric', 'details' => '']],
        'hasUnlistedBrands' => true,
        'unlistedBrands' => 'Omron, Legrand',
        'productFiles' => [wfPdf('wanted-products.pdf')],
    ]))->assertSessionHasNoErrors();

    return Quotation::with('files')->latest('id')->first();
}

it('shows quotation staff the submitted details and files, without storage paths', function () {
    $quotation = wfSubmittedRequest();
    [$staff, $cookies] = wfStaff($this->team);

    $this->actingAs($staff)->withCookies($cookies)
        ->get('/mr-pride/quotations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotations/AdminIndex')
            ->where('quotations.data.0.request_details.project_type', 'Industrial Facility')
            ->where('quotations.data.0.request_details.brands.0.brand', 'Schneider Electric')
            ->where('quotations.data.0.request_details.unlisted_brands', 'Omron, Legrand')
            ->where('quotations.data.0.files.0.original_name', 'wanted-products.pdf')
            ->has('quotations.data.0.files.0.url')
            ->missing('quotations.data.0.files.0.path')
            ->missing('quotations.data.0.source_file_sha256')
        );

    $this->actingAs($staff)->withCookies($cookies)
        ->get($quotation->files->first()->url)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('keeps client files away from guests, other modules, other teams and other quotations', function () {
    $quotation = wfSubmittedRequest();
    $file = $quotation->files->first();
    $url = "/mr-pride/quotations/{$quotation->id}/files/{$file->id}";

    $this->get($url)->assertRedirect(); // guest: to the login page

    [$inventoryStaff, $cookies] = wfStaff($this->team, 'staff_inventory');
    $this->actingAs($inventoryStaff)->withCookies($cookies)->get($url)->assertForbidden();

    [$staff, $staffCookies] = wfStaff($this->team);
    $other = Quotation::create([
        'team_id' => $this->team->id, 'quotation_number' => 'Q-OTHER', 'client_name' => 'Other',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(), 'status' => QuotationStatus::Draft,
    ]);
    $this->actingAs($staff)->withCookies($staffCookies)
        ->get("/mr-pride/quotations/{$other->id}/files/{$file->id}")
        ->assertNotFound();

    $otherTeam = Team::factory()->create(['slug' => 'other-team']);
    [$outsider, $outsiderCookies] = wfStaff($otherTeam);
    $this->actingAs($outsider)->withCookies($outsiderCookies)->get("/other-team/quotations/{$quotation->id}/files/{$file->id}")
        ->assertForbidden();
});

// --- 8-9. Import: creates drafts, never duplicates -----------------------------------------

it('imports a material list as a draft quotation, with catalog prices only for exact matches', function () {
    [$staff, $cookies] = wfStaff($this->team);
    Product::create(['team_id' => $this->team->id, 'sku' => 'BRK-2060', 'name' => 'Breaker 2P 60A', 'category' => 'Breakers', 'price' => 520, 'stock' => 5, 'is_active' => true]);
    $csv = "Item Code,Description,Qty\nBRK-2060,Breaker 2P 60A,3\n,Junction box,10\n";

    $preview = $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations/import-materials', [
            'file' => UploadedFile::fake()->createWithContent('site-a.csv', $csv),
            'client_name' => 'Metro Builders',
        ], ['Accept' => 'application/json'])
        ->assertOk()->json();
    expect($preview['valid_count'])->toBe(2)
        ->and(Quotation::count())->toBe(0); // the preview saves nothing

    $this->actingAs($staff)->withCookies($cookies)
        ->post('/mr-pride/quotations/import-materials', [
            'file' => UploadedFile::fake()->createWithContent('site-a.csv', $csv),
            'client_name' => 'Metro Builders',
            'execute' => true,
        ])->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->client_name)->toBe('Metro Builders')
        ->and($quotation->material_file_name)->toBe('site-a.csv')
        ->and($quotation->request_details['source'])->toBe('staff_import')
        ->and($quotation->items->map(fn ($i) => [$i->sku, $i->quantity, (float) $i->unit_price])->all())
        ->toBe([['BRK-2060', 3, 520.0], ['CUSTOM', 10, 0.0]]);
});

it('refuses to import the same material list twice', function () {
    [$staff, $cookies] = wfStaff($this->team);
    $csv = "Description,Qty\nJunction box,10\n";
    $import = fn (bool $execute) => $this->actingAs($staff)->withCookies($cookies)->post('/mr-pride/quotations/import-materials', [
        'file' => UploadedFile::fake()->createWithContent('list.csv', $csv),
        'client_name' => 'Metro Builders',
        'execute' => $execute,
    ], $execute ? [] : ['Accept' => 'application/json']);

    $import(true)->assertSessionHasNoErrors();
    $import(false)->assertStatus(409)->assertJsonPath('duplicate_of', Quotation::sole()->quotation_number);
    $import(true)->assertSessionHasErrors('file');

    expect(Quotation::count())->toBe(1);
});

it('skips quotations that already exist when the same CSV is imported again', function () {
    [$admin, $cookies] = wfStaff($this->team, 'admin');
    $csv = "DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n10-25-2026,Q102526-ABC1,5,Conduit,10,50\n";
    $import = fn () => $this->actingAs($admin)->withCookies($cookies)->post('/mr-pride/quotations/import-csv', [
        'file' => UploadedFile::fake()->createWithContent('q.csv', $csv),
        'execute' => true,
        'mode' => 'append',
    ]);

    $import()->assertSessionHas('success', 'Imported 1 quotation(s).');
    $import()->assertSessionHas('success', 'Imported 0 quotation(s). Skipped 1 that already exist: Q102526-ABC1.');

    expect(Quotation::count())->toBe(1)->and(Quotation::sole()->items)->toHaveCount(1);

    $preview = $this->actingAs($admin)->withCookies($cookies)->post('/mr-pride/quotations/import-csv', [
        'file' => UploadedFile::fake()->createWithContent('q.csv', $csv),
    ], ['Accept' => 'application/json'])->json();
    expect($preview['existing_count'])->toBe(1)->and($preview['valid_rows'][0]['exists'])->toBeTrue();
});

it('does not let other modules import quotations', function () {
    [$inventoryStaff, $cookies] = wfStaff($this->team, 'staff_inventory');

    $this->actingAs($inventoryStaff)->withCookies($cookies)->post('/mr-pride/quotations/import-materials', [
        'file' => UploadedFile::fake()->createWithContent('list.csv', "Description,Qty\nBox,1\n"),
        'client_name' => 'X',
        'execute' => true,
    ])->assertForbidden();

    expect(Quotation::count())->toBe(0);
});

// --- Product lists must contain writing, not photos -----------------------------------------

it('rejects a product photo with no written list', function () {
    $photo = UploadedFile::fake()->image('breaker-photo.jpg', 800, 600); // fake OCR reads wide images as photos

    $this->post('/quotation', wfRequest(['productFiles' => [$photo]]))
        ->assertSessionHasErrors(['productFiles.0' => "breaker-photo.jpg: We couldn't find any written text in this file. It looks like a photo. Please upload a document or a picture of a typed or handwritten list of the products you need."]);

    expect(Quotation::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('quotations/product-lists'))->toBe([]);
});

it('accepts PDF, Excel, CSV, JPG and PNG lists that contain text', function () {
    $sheet = new Spreadsheet;
    $sheet->getActiveSheet()->fromArray([['Item', 'Qty'], ['Circuit breaker 2P 30A', 20], ['PVC conduit 20mm', 50]]);
    $xlsx = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
    (new Xlsx($sheet))->save($xlsx);

    $this->post('/quotation', wfRequest(['productFiles' => [
        wfPdf('list.pdf'),
        new UploadedFile($xlsx, 'list.xlsx', null, null, true),
        UploadedFile::fake()->createWithContent('list.csv', "Item,Qty\nCircuit breaker 2P 30A,20\nTHHN wire 3.5mm,100\n"),
        UploadedFile::fake()->image('handwritten-list.jpg', 300, 400),
        wfPng('typed-list.png'),
    ]]))->assertSessionHasNoErrors();

    expect(Quotation::sole()->files)->toHaveCount(5);
});

it('rejects documents and spreadsheets without any words', function (UploadedFile $file) {
    $this->post('/quotation', wfRequest(['productFiles' => [$file]]))->assertSessionHasErrors('productFiles.0');
})->with([
    'empty CSV rows' => fn () => UploadedFile::fake()->createWithContent('empty.csv', ",,\n,,\n"),
    'PDF without text' => fn () => textPdf(['--- ---', '...'], 'blank.pdf'),
]);

it('accepts an image without the text check when OCR is not installed, and tells staff', function () {
    fakeImageOcr(available: false);

    $this->post('/quotation', wfRequest(['productFiles' => [UploadedFile::fake()->image('list.jpg', 800, 600)]]))->assertSessionHasNoErrors();

    expect(Quotation::sole()->notes)->toContain('Not checked for text (OCR unavailable): list.jpg');
});

it('tells photos and written lists apart', function (array $lines, bool $expected) {
    expect(FileReader::looksLikeWrittenList($lines))->toBe($expected);
})->with([
    'numbered list' => [['1. Breaker 30A', '2. Wire 3.5mm'], true],
    'plain list with quantities' => [['Circuit breaker 20 pcs', 'THHN wire 100 meters'], true],
    'OCR noise from a photo' => [['~ .,| ;: rr', 'Sx Zk %'], false],
    'a single label' => [['Schneider'], false],
    'nothing' => [[], false],
]);

it('reads lines and the share of the image covered by words from OCR output', function () {
    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
        ."1\t1\t0\t0\t0\t0\t0\t0\t100\t100\t-1\t\n"
        ."5\t1\t1\t1\t1\t1\t0\t0\t10\t10\t96\tBreaker\n"
        ."5\t1\t1\t1\t1\t2\t12\t0\t10\t10\t91\t30A\n"
        ."5\t1\t1\t1\t2\t1\t0\t20\t10\t10\t20\tblurry\n"; // low confidence: ignored

    [$lines, $coverage] = FileReader::linesFromTsv($tsv);

    expect($lines)->toBe(['Breaker 30A'])->and($coverage)->toBe(0.02);
});

it('rejects real photos and accepts a real list image with Tesseract', function () {
    $reader = app(FileReader::class);
    app()->forgetInstance(OcrEngine::class);
    $reader = new FileReader(new OcrEngine);
    if (! (new OcrEngine)->tesseractAvailable()) {
        $this->markTestSkipped('Tesseract is not installed.');
    }

    $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    $img = imagecreatetruecolor(900, 360);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    foreach (['Material list for Site B', '1. Circuit breaker 2P 30A - 20 pcs', '2. THHN wire 3.5mm - 100 meters', '3. PVC conduit 20mm - 50 pieces'] as $i => $line) {
        imagettftext($img, 22, 0, 30, 60 + $i * 70, imagecolorallocate($img, 0, 0, 0), $font, $line);
    }
    $list = tempnam(sys_get_temp_dir(), 'ls').'.png';
    imagepng($img, $list);

    expect($reader->assertHasText($list, 'image'))->toBeTrue();
    // A photo that shows a small warning sign is still a photo.
    expect(fn () => $reader->assertHasText(public_path('LOGIN_PHOTO.png'), 'image'))
        ->toThrow(ExtractionException::class);
    expect(fn () => $reader->assertHasText(public_path('PRODUCT_A.png'), 'image'))
        ->toThrow(ExtractionException::class);
});
