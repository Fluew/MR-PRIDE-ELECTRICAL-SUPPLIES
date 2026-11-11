<?php

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\DocumentExtractor;
use App\Services\Import\ImportService;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Import reliability: the import_batches schema matches the code, failures never show raw
 * database errors and never leave half-saved data, staff can choose columns by hand when the
 * headers are not recognised, and the same product is never created twice.
 */
beforeEach(function () {
    // Like Storage::fake('local'), but in a folder of its own: other test runs in parallel clean
    // the shared fake folder, which would delete the uploads of this run.
    $this->relRoot = storage_path('framework/testing/disks/rel-'.getmypid());
    (new Filesystem)->cleanDirectory($this->relRoot);
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => $this->relRoot, 'throw' => false]));

    $this->team = Team::factory()->create(['slug' => 'rel-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->cookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->url = "/{$this->team->slug}/imports";
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->relRoot));

function relAs($t)
{
    return $t->actingAs($t->admin)->withCookies($t->cookies)->withCredentials();
}

function relCsv(string $csv, string $name = 'list.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rel').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

function relUpload($t, UploadedFile $file, string $module = 'inventory', array $extra = []): ImportBatch
{
    $id = relAs($t)->post("{$t->url}/{$module}", ['file' => $file] + $extra)->assertCreated()->json('id');

    return ImportBatch::findOrFail($id);
}

function relDbError(string $message): QueryException
{
    return new QueryException('mysql', 'insert into `import_rows` (`completed_at`) values (?)', [], new PDOException($message));
}

function relMessages(ImportRow $row): string
{
    return collect($row->issues)->pluck('message')->implode(' ');
}

// --- Schema ---------------------------------------------------------------------------------

test('every column the import batch model writes exists in the database', function () {
    // SQLite reads an unknown "double-quoted" column name as a text value instead of failing, so
    // a query on a missing column (completed_at) passed here but failed on MySQL. Check the
    // columns themselves, not only queries that use them.
    $columns = array_merge((new ImportBatch)->getFillable(), ['completed_at', 'created_at', 'updated_at']);
    foreach ($columns as $column) {
        expect(Schema::hasColumn('import_batches', $column))->toBeTrue("import_batches.{$column} is missing");
    }
    expect(Schema::hasIndex('import_batches', ['team_id', 'module', 'file_hash']))->toBeTrue();
});

test('the completed_at migration fills completed batches, keeps every row, and can run again', function () {
    $migration = require database_path('migrations/2026_11_02_000001_add_completed_at_to_import_batches_table.php');
    $make = fn (array $attrs) => ImportBatch::create($attrs + ['team_id' => $this->team->id, 'module' => 'inventory', 'original_filename' => 'a.csv',
        'stored_path' => 'imports/a.csv', 'file_type' => 'csv', 'file_size' => 10, 'file_hash' => 'h']);
    $done = $make(['status' => ImportBatch::COMPLETED, 'imported_at' => '2026-10-30 10:00:00']);
    $open = $make(['status' => ImportBatch::READY]);

    $migration->up(); // the column and index already exist: nothing breaks
    expect($done->fresh()->completed_at->toDateTimeString())->toBe('2026-10-30 10:00:00')
        ->and($open->fresh()->completed_at)->toBeNull();

    $migration->down();
    expect(Schema::hasColumn('import_batches', 'completed_at'))->toBeFalse()->and(ImportBatch::count())->toBe(2);

    $migration->up();
    expect(Schema::hasColumn('import_batches', 'completed_at'))->toBeTrue()
        ->and(Schema::hasIndex('import_batches', ['team_id', 'module', 'file_hash']))->toBeTrue()
        ->and(ImportBatch::find($done->id)->completed_at->toDateTimeString())->toBe('2026-10-30 10:00:00');
});

// --- Completion and duplicate files -----------------------------------------------------------

test('confirming an import records completed_at with imported_at and returns it', function () {
    $batch = relUpload($this, relCsv("SKU,Product Name,Unit,Selling Price\nRL-1,Relay 12V,pcs,150\n"));

    $res = relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 1]);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::COMPLETED)
        ->and($batch->completed_at)->not->toBeNull()
        ->and($batch->completed_at->equalTo($batch->imported_at))->toBeTrue()
        ->and(Carbon::parse($res->json('batch.completed_at'))->equalTo($batch->completed_at))->toBeTrue();
    expect(relAs($this)->getJson("{$this->url}/inventory")->json('data.0.completed_at'))->not->toBeNull();
});

test('uploading the same file again after it was imported points to the earlier import', function () {
    $csv = "SKU,Product Name,Unit,Selling Price\nRL-1,Relay 12V,pcs,150\n";
    $first = relUpload($this, relCsv($csv, 'relays.csv'));
    relAs($this)->postJson("{$this->url}/batches/{$first->id}/confirm")->assertOk();

    $second = relUpload($this, relCsv($csv, 'relays-again.csv'));

    expect($second->status)->toBe(ImportBatch::READY)
        ->and($second->summary['duplicate_batch'])->toBe([
            'id' => $first->id,
            'filename' => 'relays.csv',
            'completed_at' => $first->fresh()->completed_at->toIso8601String(),
        ])
        ->and($second->rows()->sole()->status)->toBe(ImportRow::EXISTING);
    expect(Product::count())->toBe(1);
});

// --- Failures -------------------------------------------------------------------------------

test('a database error while saving the preview fails the batch with a safe message and no rows', function () {
    ImportRow::creating(fn () => throw relDbError('SQLSTATE[42S22]: Column not found: 1054 Unknown column'));

    $res = relAs($this)->post("{$this->url}/inventory", ['file' => relCsv("SKU,Product Name,Unit,Selling Price\nRL-1,Relay 12V,pcs,150\n")])->assertCreated()->json();
    $batch = ImportBatch::findOrFail($res['id']);

    expect($res['status'])->toBe(ImportBatch::FAILED)
        ->and($batch->status)->toBe(ImportBatch::FAILED)
        ->and($batch->error)->not->toContain('SQLSTATE')->not->toContain('Unknown column')->not->toContain('insert')
        ->and($batch->error)->toContain('preview could not be saved')->toContain('database')->toContain('php artisan migrate')
        ->toContain("import #{$batch->id}")->not->toContain('attempts')
        ->and($batch->summary['failure'])->toBe(['stage' => 'saving', 'code' => '42S22'])
        ->and($batch->rows()->count())->toBe(0)
        ->and(Product::count())->toBe(0);
    $audit = AuditLog::where('event', 'import.failed')->sole();
    expect($audit->outcome)->toBe('failure')->and($audit->metadata)->toMatchArray(['stage' => 'saving', 'code' => '42S22'])
        ->and($audit->description)->not->toContain('SQLSTATE');
});

test('an unexpected error while reading is not shown to staff and is kept apart from database errors', function () {
    $this->mock(DocumentExtractor::class, fn ($m) => $m->shouldReceive('extract')->andThrow(new RuntimeException('Tesseract crashed at C:\\tmp\\ocr-123')));

    $batch = relUpload($this, UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%scan"));

    expect($batch->status)->toBe(ImportBatch::FAILED)
        ->and($batch->error)->toContain('could not be read because of an unexpected server problem')
        ->not->toContain('Tesseract crashed')->not->toContain('attempts')
        ->and($batch->summary['failure'])->toBe(['stage' => 'reading', 'code' => null]);
});

test('a database error while importing rolls everything back and keeps the reviewed rows', function () {
    $batch = relUpload($this, relCsv("SKU,Product Name,Unit,Selling Price\nRL-1,Relay 12V,pcs,150\nRL-2,Relay 24V,pcs,180\nTM-1,Timer,pcs,90\n"));
    $fail = true;
    $created = 0;
    Product::creating(function () use (&$fail, &$created) {
        if ($fail && ++$created === 2) {
            throw new QueryException('mysql', 'insert into `products` (`sku`) values (?)', ['RL-2'], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        }
    });

    $res = relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertStatus(503);

    expect($res->json('message'))->toContain('Nothing was imported')->toContain('reviewed rows are kept')->toContain('database error HY000')
        ->not->toContain('SQLSTATE')->not->toContain('insert')->not->toContain('Lock wait')
        ->and($res->json('errors.error.0'))->toBe($res->json('message'));
    expect(Product::count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(ImportBatch::READY)
        ->and($batch->fresh()->completed_at)->toBeNull()
        ->and($batch->rows()->where('status', ImportRow::IMPORTED)->count())->toBe(0)
        ->and($batch->rows()->whereNotNull('created_id')->count())->toBe(0)
        ->and(AuditLog::where('event', 'import.confirm_failed')->where('outcome', 'failure')->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'import.completed')->exists())->toBeFalse();

    // Once the problem is gone the same reviewed rows import.
    $fail = false;
    relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 3]);
    expect(Product::count())->toBe(3);
});

test('a database error while recording the upload removes the stored file and says so plainly', function () {
    ImportBatch::creating(fn () => throw relDbError("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mrp.import_batches' doesn't exist"));

    $res = relAs($this)->post("{$this->url}/inventory", ['file' => relCsv("SKU,Product Name\nA-1,Alpha\n")])->assertStatus(503);

    expect($res->json('message'))->toContain('The upload could not be recorded')->toContain('php artisan migrate')->toContain('database error 42S02')
        ->not->toContain('SQLSTATE')->not->toContain('import_batches')
        ->and(ImportBatch::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a database error on a row edit is answered with a safe message, not the SQL', function () {
    $batch = relUpload($this, relCsv("SKU,Product Name,Unit,Selling Price\nRL-1,Relay 12V,pcs,150\n"));
    $row = $batch->rows()->sole();
    ImportRow::updating(fn () => throw relDbError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));

    $res = relAs($this)->patchJson("{$this->url}/batches/{$batch->id}/rows/{$row->id}", ['values' => ['name' => 'Relay 12 V']])->assertStatus(503);

    expect($res->json('message'))->toContain('could not be saved because of a database problem')->toContain('database error HY000')
        ->not->toContain('gone away')->not->toContain('SQLSTATE')
        ->and($row->fresh()->values['name'])->toBe('Relay 12V');
});

test('an unreadable PDF fails at the reading stage with the reader\'s message', function () {
    $pdf = UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.7\n".str_repeat("garbage \x01\x02 not a pdf body\n", 40));

    $batch = relUpload($this, $pdf);

    expect($batch->status)->toBe(ImportBatch::FAILED)
        ->and($batch->error)->toBe('We could not open this PDF. It may be damaged. Please re-save it, or upload an Excel/CSV file.')
        ->and($batch->summary['failure']['stage'])->toBe('reading')
        ->and($batch->rows()->count())->toBe(0);
});

// --- Choosing columns by hand ----------------------------------------------------------------

test('staff can choose the header row and columns when the headers are not recognised', function () {
    $batch = relUpload($this, relCsv("Price list October\nThing,How much,Cost each\nWidget A,5,10\nWidget B,3,12.50\n", 'odd.csv'));

    expect($batch->status)->toBe(ImportBatch::READY)
        ->and($batch->summary['header_found'])->toBeFalse()
        ->and($batch->summary['manual_mapping'])->toBeFalse()
        ->and($batch->summary['missing_columns'])->toBe(['name'])
        ->and($batch->summary['mapping'])->toBe([])
        ->and($batch->summary['mapping_message'])->toContain('No product name column was found')->toContain('"Thing", "How much", "Cost each"');

    $table = relAs($this)->getJson("{$this->url}/batches/{$batch->id}/table")->assertOk()->json();
    expect($table['rows'])->toBe([['Price list October', '', ''], ['Thing', 'How much', 'Cost each'], ['Widget A', '5', '10'], ['Widget B', '3', '12.50']])
        ->and($table['width'])->toBe(3)
        ->and($table['total_rows'])->toBe(4)
        ->and($table['header_row'])->toBeNull()
        ->and($table['manual'])->toBeFalse()
        ->and($table['columns'])->toBe(['Column A', 'Column B', 'Column C'])
        ->and(collect($table['fields'])->firstWhere('key', 'name'))->toBe(['key' => 'name', 'label' => 'Product name', 'required' => true])
        ->and(collect($table['fields'])->where('required', true)->count())->toBe(1);

    $res = relAs($this)->postJson("{$this->url}/batches/{$batch->id}/mapping", ['header_row' => 1, 'columns' => ['name' => 0, 'stock' => 1, 'price' => 2, 'sku' => null, 'bogus' => 1]])
        ->assertOk()->json('batch');
    expect($res['summary'])->toMatchArray([
        'header_found' => true, 'manual_mapping' => true, 'header_row' => 1, 'missing_columns' => [], 'mapping_message' => null,
        'mapping' => ['name' => 'Thing', 'stock' => 'How much', 'price' => 'Cost each'], 'unparsed_lines' => ['Price list October'], 'total' => 2,
    ])->and($res['meta']['column_mapping'])->toBe(['header_row' => 1, 'columns' => ['name' => 0, 'stock' => 1, 'price' => 2]]);

    $rows = $batch->rows()->orderBy('row_number')->get();
    expect($rows->map(fn ($r) => [$r->row_number, $r->values['name'], $r->values['stock'], $r->values['price'], $r->status])->all())
        ->toEqual([[3, 'Widget A', 5.0, 10.0, ImportRow::READY], [4, 'Widget B', 3.0, 12.5, ImportRow::READY]])
        ->and($rows[0]->values['unit'])->toBeNull(); // never invented
    expect(AuditLog::where('event', 'import.remapped')->exists())->toBeTrue();

    // The table now shows the chosen columns; a retry keeps them.
    expect(relAs($this)->getJson("{$this->url}/batches/{$batch->id}/table")->json())
        ->toMatchArray(['header_row' => 1, 'manual' => true, 'mapping' => ['name' => 0, 'stock' => 1, 'price' => 2], 'columns' => ['Thing', 'How much', 'Cost each']]);
    app(ImportService::class)->process($batch->fresh());
    expect($batch->fresh()->summary['manual_mapping'])->toBeTrue()->and($batch->rows()->count())->toBe(2);

    // An older batch without a stored table: the original is read again.
    Storage::disk('local')->delete(app(ImportService::class)->tablePath($batch));
    expect(relAs($this)->getJson("{$this->url}/batches/{$batch->id}/table")->assertOk()->json('total_rows'))->toBe(4);
    Storage::disk('local')->assertExists(app(ImportService::class)->tablePath($batch));

    relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 2]);
    $widget = Product::where('name', 'Widget A')->sole();
    expect($widget->stock)->toBe(0)->and((float) $widget->price)->toBe(10.0)->and($widget->unit)->toBeNull();
});

test('the column choice is checked before the rows are rebuilt', function () {
    $batch = relUpload($this, relCsv("Thing,How much,Cost each\nWidget A,5,10\n"));
    $post = fn (array $body) => relAs($this)->postJson("{$this->url}/batches/{$batch->id}/mapping", $body);

    $post(['header_row' => 0, 'columns' => ['price' => 2]])->assertStatus(422)->assertJsonPath('errors.columns.0', fn ($m) => str_contains($m, 'product name'));
    $post(['header_row' => 0, 'columns' => ['name' => 0, 'price' => 0]])->assertStatus(422)->assertJsonPath('errors.columns.0', fn ($m) => str_contains($m, 'same column'));
    $post(['header_row' => 0, 'columns' => ['name' => 7]])->assertStatus(422)->assertJsonPath('errors.columns.0', fn ($m) => str_contains($m, 'not in the file'));
    $post(['header_row' => 9, 'columns' => ['name' => 0]])->assertStatus(422)->assertJsonValidationErrors('header_row');
    $post(['columns' => ['name' => 'first']])->assertStatus(422)->assertJsonValidationErrors('columns.name');
    $post([])->assertStatus(422)->assertJsonValidationErrors('columns');
    expect($batch->fresh()->meta['column_mapping'] ?? null)->toBeNull()->and($batch->rows()->count())->toBe(2);

    // Only while the import is ready for review.
    $post(['header_row' => 0, 'columns' => ['name' => 0, 'price' => 2]])->assertOk();
    relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertOk();
    $post(['header_row' => 0, 'columns' => ['name' => 0]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'completed'));
});

test('supplier quotation columns can be chosen by hand and the quotation details are kept', function () {
    $supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $batch = relUpload($this, relCsv("Thing,Pack,How many,Each\nTHHN Wire 3.5mm,roll,10,1200\n"), 'supplier_quotation',
        ['supplier_id' => $supplier->id, 'quoted_on' => now()->toDateString(), 'currency' => 'PHP']);
    expect($batch->summary['missing_columns'])->toBe(['description']);

    relAs($this)->postJson("{$this->url}/batches/{$batch->id}/mapping", ['header_row' => 0, 'columns' => ['description' => 0, 'unit' => 1, 'quantity' => 2, 'unit_price' => 3]])->assertOk();

    $batch->refresh();
    $row = $batch->rows()->sole();
    expect($batch->meta)->toMatchArray(['supplier_id' => $supplier->id, 'currency' => 'PHP'])
        ->and([$row->values['description'], $row->values['unit'], $row->values['quantity'], $row->values['unit_price'], $row->values['amount']])
        ->toEqual(['THHN Wire 3.5mm', 'roll', 10.0, 1200.0, 12000.0]);
});

// --- Duplicate products ----------------------------------------------------------------------

test('rows without a SKU never create a second product with the same name', function () {
    $lamp = Product::create(['team_id' => $this->team->id, 'sku' => 'LMP-1', 'name' => 'LED Bulb 9W', 'brand' => 'Philips', 'price' => 100, 'cost_price' => 60, 'stock' => 4, 'unit' => 'pcs', 'is_active' => true]);
    $csv = "SKU,Product Name,Brand,Unit,Selling Price\n"
        .",led  bulb 9w.,,pcs,120\n"            // the existing product (brand missing on one side)
        .",LED Bulb 9W,Omni,pcs,95\n"           // another brand: a different product
        ."LB-NEW,LED Bulb 9W,Philips,pcs,99\n"  // its own new SKU: imported, with a similar-name warning only
        .",Junction Box 4x4,,pcs,35\n"
        .",Junction box 4x4,,pcs,35\n";         // same as the row above

    $batch = relUpload($this, relCsv($csv));
    $rows = $batch->rows()->orderBy('row_number')->get();

    expect($rows->pluck('status')->all())->toBe([ImportRow::EXISTING, ImportRow::READY, ImportRow::READY, ImportRow::READY, ImportRow::DUPLICATE])
        ->and($rows[0]->matched_product_id)->toBe($lamp->id)
        ->and(relMessages($rows[0]))->toContain('A product named "LED Bulb 9W" already exists (SKU LMP-1)')
        ->and(relMessages($rows[4]))->toContain('Same product as row 5.');

    // Another file with the same product, confirmed after the first: not created again.
    $later = relUpload($this, relCsv("Product Name,Unit,Selling Price\nJunction Box 4x4,pcs,35\n", 'later.csv'));
    expect($later->rows()->sole()->status)->toBe(ImportRow::READY);

    relAs($this)->postJson("{$this->url}/batches/{$batch->id}/confirm")->assertOk()->assertJson(['created' => 3]);
    relAs($this)->postJson("{$this->url}/batches/{$later->id}/confirm")->assertStatus(422)->assertJsonPath('message', 'There are no rows to import.');
    relAs($this)->postJson("{$this->url}/batches/{$later->id}/bulk", ['action' => 'restore'])->assertOk(); // checks the rows again

    expect(Product::where('name', 'like', 'Junction%')->count())->toBe(1)
        ->and(Product::where('name', 'LED Bulb 9W')->count())->toBe(3)
        ->and($lamp->fresh()->stock)->toBe(4)
        ->and($later->rows()->sole()->status)->toBe(ImportRow::EXISTING);
});
