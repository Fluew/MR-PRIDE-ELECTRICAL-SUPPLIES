<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationFile;
use App\Models\Team;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\ProductListExtractor;
use App\Services\MaterialList\ProductListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The public "Request a Quotation" form.
 *
 * A request holds the client's contact and project details, the catalog brands they are
 * interested in, any brands not in our catalog ("brands not listed"), and optional product-list
 * files. Everything is saved with the quotation as client-submitted information with status
 * Pending: nothing is approved, priced or confirmed as available until staff review it.
 */
class PublicQuotationController extends Controller
{
    public const PROJECT_TYPES = ['Commercial Building', 'Industrial Facility', 'Infrastructure/Public Works', 'Residential Complex', 'Other'];

    public const TIMELINES = ['Immediate (ASAP)', '1-3 months', '3-6 months', 'Over 6 months'];

    public const MAX_PRODUCT_FILES = 5;

    /** Together, all product-list files may not exceed this: five files of 25 MB each. */
    public const MAX_PRODUCT_FILES_BYTES = self::MAX_PRODUCT_FILES * FileReader::MAX_BYTES;

    public const PRODUCT_FILE_EXTENSIONS = ['pdf', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];

    public const PRODUCT_FILES_DIR = 'quotations/product-lists';

    /** Most products the client can send from their uploaded lists (all files together). */
    public const MAX_PRODUCTS = 500;

    /**
     * Distinct, non-empty product brands from active catalog products and partner brands
     * (case-insensitive, first spelling wins), sorted alphabetically.
     *
     * @return list<string>
     */
    public static function availableBrands(): array
    {
        $partnerBrands = Schema::hasTable('brands')
            ? Brand::query()->where('is_active', true)->pluck('name')
            : collect();

        $productBrands = Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand')
            ->pluck('brand');

        return $partnerBrands->concat($productBrands)
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => $b !== '')
            ->unique(fn ($b) => mb_strtolower($b))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Show the public quotation request form with the brands available in the catalog.
     */
    public function create(): Response
    {
        return Inertia::render('Quotations/Create', [
            'brands' => self::availableBrands(),
        ]);
    }

    /**
     * Store a public quotation request.
     */
    public function store(Request $request, FileReader $reader, ProductListExtractor $extractor): RedirectResponse
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'contactName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'projectType' => ['nullable', 'string', Rule::in(self::PROJECT_TYPES)],
            // The form shows "1–3 months"; both dash styles are accepted.
            'timeline' => ['nullable', 'string', Rule::in([...self::TIMELINES, '1–3 months', '3–6 months'])],
            'brands' => ['nullable', 'array', 'max:50'],
            'hasUnlistedBrands' => ['nullable', 'boolean'],
            'unlistedBrands' => ['nullable', 'string', 'max:3000'],

            // Optional files listing specific products the client wants quoted.
            'productFiles' => ['nullable', 'array', 'max:'.self::MAX_PRODUCT_FILES],
            'productFiles.*' => ['file', 'max:'.(FileReader::MAX_BYTES / 1024), 'extensions:'.implode(',', self::PRODUCT_FILE_EXTENSIONS)],

            // Products read from those files, reviewed and confirmed by the client in the form.
            'products' => ['nullable', 'array', 'max:'.self::MAX_PRODUCTS],
            'products.*' => ['array'],
            'products.*.description' => ['required', 'string', 'max:'.ProductListParser::MAX_DESCRIPTION, 'regex:/\p{L}/u', 'not_regex:/^\s*[=+@]/'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.ProductListParser::MAX_QUANTITY, 'decimal:0,'.ProductListParser::MAX_DECIMALS],
            // Optional: the unit written in the client's file, kept as it is (staff verify units when quoting).
            'products.*.unit' => ['nullable', 'string', 'max:'.ProductListParser::MAX_UNIT, 'regex:/\p{L}/u'],
            'products.*.source' => ['nullable', 'string', 'max:255'],
        ], [
            'projectType.in' => 'Please choose a project type from the list.',
            'timeline.in' => 'Please choose an expected timeline from the list.',
            'productFiles.max' => 'You can attach up to '.self::MAX_PRODUCT_FILES.' product-list files.',
            'productFiles.*.max' => PublicProductListController::TOO_LARGE,
            'productFiles.*.extensions' => 'Product lists must be PDF, Excel (.xls/.xlsx), CSV, JPG or PNG files.',
            'productFiles.*.uploaded' => 'A product-list file could not be uploaded. '.PublicProductListController::TOO_LARGE,
            'products.max' => 'You can send up to '.self::MAX_PRODUCTS.' products from your list.',
            'products.*.description.required' => 'Product :position: the description is missing.',
            'products.*.description.max' => 'Product :position: the description can be up to '.ProductListParser::MAX_DESCRIPTION.' characters.',
            'products.*.description.regex' => 'Product :position: please enter a product name.',
            'products.*.description.not_regex' => 'Product :position: the description looks like a spreadsheet formula. Please type the product name.',
            'products.*.quantity.required' => 'Product :position: the quantity is missing.',
            'products.*.quantity.numeric' => 'Product :position: the quantity must be a number.',
            'products.*.quantity.gt' => 'Product :position: the quantity must be greater than zero.',
            'products.*.quantity.max' => 'Product :position: the quantity is too large.',
            'products.*.quantity.decimal' => 'Product :position: use at most '.ProductListParser::MAX_DECIMALS.' decimal places for the quantity.',
            'products.*.unit.max' => 'Product :position: the unit can be up to '.ProductListParser::MAX_UNIT.' characters.',
            'products.*.unit.regex' => 'Product :position: the unit must be a unit name such as pcs, m or box.',
        ]);

        if ($request->hasFile('productFiles') && ! app()->runningUnitTests()) {
            @set_time_limit(300); // files not read on the form yet are checked for text (OCR) here
        }
        // Read for staff only: the client does not review what was extracted. Read first, so a
        // list in which products were found (even a one-line spreadsheet) passes the text check.
        $extracted = $this->extractProductLists(
            array_values(array_filter((array) $request->file('productFiles', []), fn ($f) => $f instanceof UploadedFile)),
            $extractor,
        );
        $productFiles = $this->checkProductFiles($request, $reader);
        $products = $this->confirmedProducts($validated['products'] ?? []);

        [$brandItems, $catalog] = $this->brandItems($request);
        $brands = collect($brandItems)->reject(fn ($i) => self::isOthers($i['brand']))
            ->map(fn ($i) => $catalog[mb_strtolower($i['brand'])])->unique()->values()->all();

        $unlisted = $request->boolean('hasUnlistedBrands') ? trim((string) ($validated['unlistedBrands'] ?? '')) : '';
        if ($request->boolean('hasUnlistedBrands') && $unlisted === '') {
            return back()->withErrors([
                'unlistedBrands' => "Please enter the brand name(s) you're looking for.",
            ])->withInput();
        }

        $team = Team::first();
        if ($team) {
            $storedFiles = [];
            try {
                // Retried as a whole if the database reports a deadlock between simultaneous requests.
                DB::transaction(function () use ($validated, $team, $brandItems, $brands, $catalog, $unlisted, $products, $extracted, $productFiles, &$storedFiles) {
                    $storedFiles = [];
                    $quotation = $this->createQuotation($validated, $team, $brandItems, $brands, $catalog, $unlisted, $products, $extracted);
                    $this->keepProductFiles($quotation, $productFiles, $storedFiles);
                }, 3);
            } catch (Throwable $e) {
                // Nothing was saved (the transaction rolled back): remove the copied files, log the
                // cause, and send the visitor back to the form with everything they entered.
                Storage::disk('local')->delete($storedFiles);
                Log::error('Public quotation request could not be saved: '.$e->getMessage(), [
                    'exception' => $e,
                    'company' => $validated['company'],
                    'email' => $validated['email'],
                ]);

                return back()->withErrors([
                    'submit' => 'We could not send your request just now. Please try again in a moment. If it keeps happening, please contact us directly.',
                ])->withInput();
            }
        }

        return back()->with('success', 'Thank you! Your quotation request has been received. Our team will review your request and get in touch shortly.');
    }

    /**
     * Check product-list files by content (not just the name) and their combined size.
     *
     * @return array<int, UploadedFile>
     */
    private function checkProductFiles(Request $request, FileReader $reader): array
    {
        $files = array_values(array_filter((array) $request->file('productFiles', []), fn ($f) => $f instanceof UploadedFile));

        $total = array_sum(array_map(fn (UploadedFile $f) => (int) $f->getSize(), $files));
        if ($total > self::MAX_PRODUCT_FILES_BYTES) {
            throw ValidationException::withMessages([
                'productFiles' => 'Product-list files can be up to '.intdiv(self::MAX_PRODUCT_FILES_BYTES, 1048576).' MB together. Please remove a file or send smaller ones.',
            ]);
        }

        foreach ($files as $i => $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $name = Str::limit($file->getClientOriginalName(), 60);
            try {
                $type = $reader->detectType($path, $file->getClientOriginalName());
                // Already read on this form (the product-list preview): the same bytes need no second OCR pass.
                if (Cache::get(ProductListExtractor::textCheckKey((string) hash_file('sha256', $path)))) {
                    continue;
                }
                // Lists must be written (typed, printed or handwritten text), not plain photos.
                if (! $reader->assertHasText($path, $type)) {
                    $this->uncheckedFiles[] = $file->getClientOriginalName();
                    Log::warning("Public quotation file {$name} was accepted without a text check: OCR is not available.");
                }
            } catch (ExtractionException $e) {
                $message = in_array($e->reason, ['ocr_failed'], true)
                    ? "We couldn't read the text in this file. Please upload a clearer image, or a PDF, Excel or CSV file."
                    : $e->getMessage();
                throw ValidationException::withMessages(["productFiles.{$i}" => "{$name}: {$message}"]);
            }
        }

        return $files;
    }

    /** @var list<string> files accepted without a text check because OCR is not installed */
    private array $uncheckedFiles = [];

    /** Most rows kept from all product-list files of one request. */
    public const MAX_EXTRACTED_ROWS = 500;

    /**
     * Read each product-list file (OCR for scans and pictures, direct reading for Excel, CSV and
     * text PDFs) so staff see its Description / Quantity / Unit rows when preparing the
     * quotation. The client never sees or confirms these rows, so they are kept as read, with
     * any doubt noted ("issues"). Files already read on the form come from the cache, so nothing
     * is read twice. A file that cannot be read never stops the request: staff open the file.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<array{file: string, status: 'read'|'columns_not_found'|'not_read', method: ?string, message: ?string, rows: list<array{ref: string, description: string, quantity: int|float|null, unit: ?string, issues: list<string>}>}>
     */
    private function extractProductLists(array $files, ProductListExtractor $extractor): array
    {
        $results = [];
        $seen = [];
        $total = 0;
        foreach ($files as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $hash = (string) hash_file('sha256', $path);
            if (isset($seen[$hash])) {
                continue; // the same file twice is stored once (keepProductFiles)
            }
            $seen[$hash] = true;
            $result = ['file' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''), 'status' => 'not_read', 'method' => null, 'message' => null, 'rows' => []];

            try {
                $read = $extractor->extract($path, $file->getClientOriginalName());
                $result['method'] = $read['method'];
                if ($read['status'] !== 'ok') {
                    $result['status'] = 'columns_not_found';
                    $result['message'] = 'The Description and Quantity columns were not found automatically. Please open the file.';
                } else {
                    $result['status'] = 'read';
                    foreach (array_slice($read['items'], 0, max(0, self::MAX_EXTRACTED_ROWS - $total)) as $item) {
                        $quantity = $item['quantity'];
                        $issues = [];
                        foreach ([...$item['errors'], ...$item['checks']] as $field => $message) {
                            $issues[] = ucfirst((string) $field).': '.$message;
                        }
                        if ($item['duplicate_of'] !== null) {
                            $issues[] = "Same product as {$item['duplicate_of']}.";
                        }
                        $result['rows'][] = [
                            'ref' => $item['ref'],
                            'description' => $item['description'],
                            'quantity' => $quantity !== null && fmod((float) $quantity, 1.0) === 0.0 ? (int) $quantity : $quantity,
                            'unit' => $item['unit'],
                            'issues' => $issues,
                        ];
                    }
                    $total += count($result['rows']);
                }
            } catch (ExtractionException $e) {
                $result['message'] = match ($e->reason) {
                    'ocr_unavailable' => 'Not read automatically: text recognition (OCR) is not available on the server. Please open the file.',
                    'ocr_failed', 'pdf_render_failed' => 'Not read automatically: text recognition took too long or failed. Please open the file.',
                    default => 'Not read automatically. Please open the file.',
                };
            } catch (Throwable $e) {
                Log::warning('Product list could not be read for staff: '.$e->getMessage(), ['file' => $result['file']]);
                $result['message'] = 'Not read automatically. Please open the file.';
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * The products the client confirmed from their lists, as plain text: control characters
     * removed, spaces collapsed. Values are kept as the client confirmed them (no unit conversion,
     * decimal quantities kept).
     *
     * @param  array<int, array<string, mixed>>  $rows  validated rows
     * @return list<array{description: string, quantity: int|float, unit: ?string, source: ?string}>
     */
    private function confirmedProducts(array $rows): array
    {
        $products = [];
        foreach ($rows as $row) {
            $quantity = round((float) $row['quantity'], ProductListParser::MAX_DECIMALS);
            $source = ProductListParser::cellText($row['source'] ?? '');
            $products[] = [
                'description' => ProductListParser::cellText($row['description']),
                'quantity' => fmod($quantity, 1.0) === 0.0 ? (int) $quantity : $quantity,
                'unit' => ProductListParser::cellText($row['unit'] ?? '') !== '' ? ProductListParser::cellText($row['unit']) : null,
                'source' => $source !== '' ? Str::limit(basename(str_replace('\\', '/', $source)), 200, '') : null,
            ];
        }

        return $products;
    }

    /**
     * Brands chosen in the form, limited to the catalog (case-insensitive, catalog spelling).
     * Accepts ['Schneider'] and [['brand' => 'Schneider', 'details' => '...', 'quantity' => '10']];
     * an "Others" row also has 'brand_name' (the brand that is not listed). The quantity is
     * optional; when given it must be a number greater than zero. Requests sent before the
     * Quantity field existed used 'quantity' for the description; those are still read.
     *
     * @return array{0: array<int, array{brand: string, details: string, quantity: int|float|null, brand_name: string}>, 1: Collection}
     */
    private function brandItems(Request $request): array
    {
        $brandItems = [];
        $position = 0;
        foreach ((array) $request->input('brands', []) as $item) {
            $position++;
            $name = trim((string) (is_array($item) ? ($item['brand'] ?? $item['name'] ?? '') : $item));
            if ($name === '') {
                continue;
            }
            $current = is_array($item) && array_key_exists('details', $item);
            $details = is_array($item) ? ProductListParser::cellText($item['details'] ?? $item['quantity'] ?? $item['specifics'] ?? '') : '';
            $quantityText = $current && is_scalar($item['quantity'] ?? null) ? trim((string) $item['quantity']) : '';
            $quantity = null;
            if ($quantityText !== '') {
                $quantity = self::typedQuantity($quantityText);
                if ($quantity === null) {
                    throw ValidationException::withMessages([
                        'brands' => "Brand #{$position}: please enter the quantity as a number greater than zero (for example 10 or 2.5).",
                    ]);
                }
            }
            $brandName = is_array($item) ? ProductListParser::cellText($item['brand_name'] ?? $item['brandName'] ?? '') : '';
            $brandItems[] = [
                'brand' => $name,
                'details' => Str::limit($details, 1000, ''),
                'quantity' => $quantity,
                'brand_name' => Str::limit($brandName, 100, ''),
            ];
        }

        // "Others" is a brand not in the list: the client describes the product in their own words.
        $catalog = collect(self::availableBrands())->keyBy(fn ($b) => mb_strtolower($b))->put(mb_strtolower(self::OTHERS), self::OTHERS);
        $unknown = collect($brandItems)->pluck('brand')->map(fn ($b) => mb_strtolower($b))->reject(fn ($b) => $catalog->has($b));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'brands' => 'One or more selected brands are not available. Please choose from the list, or choose Others.',
            ]);
        }
        if (collect($brandItems)->contains(fn ($i) => self::isOthers($i['brand']) && $i['brand_name'] === '')) {
            throw ValidationException::withMessages([
                'brands' => 'Please enter the brand name for each "Others" row.',
            ]);
        }
        if (collect($brandItems)->contains(fn ($i) => self::isOthers($i['brand']) && $i['details'] === '')) {
            throw ValidationException::withMessages([
                'brands' => 'Please describe the product you need for each "Others" row.',
            ]);
        }

        return [$brandItems, $catalog];
    }

    /**
     * A quantity typed by the client: "10", "1,000" or "2.5" (up to 3 decimals, more than 0, at
     * most 1,000,000). Whole numbers come back as int. Null when it is not such a number.
     */
    private static function typedQuantity(string $text): int|float|null
    {
        $v = preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $text) ? str_replace(',', '', $text) : $text;
        if (! preg_match('/^\d+(?:\.\d{1,'.ProductListParser::MAX_DECIMALS.'})?$/', $v)) {
            return null;
        }
        $q = (float) $v;
        if ($q <= 0 || $q > ProductListParser::MAX_QUANTITY) {
            return null;
        }

        return fmod($q, 1.0) === 0.0 ? (int) $q : $q;
    }

    /** "Others (Omron): floodlight 200W · Qty 6": a brand line as staff read it in the notes. */
    private static function brandLine(array $item, string $brand): string
    {
        $label = self::isOthers($brand) ? ($item['brand_name'] !== '' ? $item['brand_name'] : 'Brand not listed') : $brand;
        $parts = array_filter([$item['details'], $item['quantity'] !== null ? 'Qty '.$item['quantity'] : null]);

        return $label.($parts !== [] ? ': '.implode(' · ', $parts) : '');
    }

    /** The brand choice for products whose brand is not in the list. */
    public const OTHERS = 'Others';

    private static function isOthers(string $brand): bool
    {
        return mb_strtolower(trim($brand)) === mb_strtolower(self::OTHERS);
    }

    private function createQuotation(array $validated, Team $team, array $brandItems, array $brands, $catalog, string $unlisted, array $products = [], array $extracted = []): Quotation
    {
        $client = Client::firstOrCreate(
            ['team_id' => $team->id, 'company_name' => $validated['company']],
            ['contact_person' => $validated['contactName'], 'email' => $validated['email'], 'phone' => $validated['phone']]
        );

        $projectType = $validated['projectType'] ?? 'General';
        $timeline = str_replace('–', '-', $validated['timeline'] ?? 'Flexible');

        $notes = "Project Type: {$projectType}\nExpected Timeline: {$timeline}\n";
        if ($brands !== []) {
            $notes .= "\n--- Brands Requested ---\n".implode(', ', $brands)."\n";
            $withDetails = array_filter($brandItems, fn ($i) => ($i['details'] !== '' || $i['quantity'] !== null) && ! self::isOthers($i['brand']));
            if ($withDetails !== []) {
                $notes .= "\n--- Specific Requirements & Quantities ---\n";
                foreach ($withDetails as $item) {
                    $notes .= '• '.self::brandLine($item, $catalog[mb_strtolower($item['brand'])])."\n";
                }
            }
        }
        $others = array_filter($brandItems, fn ($i) => self::isOthers($i['brand']));
        if ($others !== []) {
            $notes .= "\n--- Other Products (brand not listed) ---\n";
            foreach ($others as $item) {
                $notes .= '• '.self::brandLine($item, self::OTHERS)."\n";
            }
        }
        if ($unlisted !== '') {
            $notes .= "\n--- Brands Not Listed ---\n".$unlisted."\n";
        }
        if ($products !== []) {
            $notes .= "\n--- Products from the client's list (confirmed by the client) ---\n";
            foreach ($products as $p) {
                $unit = $p['unit'] !== null ? " {$p['unit']}" : ' (unit not given)';
                $notes .= "• {$p['description']}: {$p['quantity']}{$unit}".($p['source'] ? " ({$p['source']})" : '')."\n";
            }
        }
        if ($extracted !== []) {
            $notes .= "\n--- Products read from the uploaded lists (automatic, not checked by the client) ---\n";
            foreach ($extracted as $file) {
                $notes .= "{$file['file']}:".($file['message'] !== null ? " {$file['message']}" : '')."\n";
                foreach ($file['rows'] as $row) {
                    $quantity = $row['quantity'] ?? '?';
                    $unit = $row['unit'] !== null ? " {$row['unit']}" : '';
                    $notes .= "• {$row['description']}: {$quantity}{$unit}".($row['issues'] !== [] ? ' [check]' : '')."\n";
                }
            }
        }

        // Number: Q<today>-PROD, -01, -02, … reserved so no other request can get it.
        $quotation = Quotation::createWithNumber([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'client_name' => $validated['company'],
            'contact_person' => $validated['contactName'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'quotation_date' => now()->toDateString(),
            'valid_until' => null, // set when staff get it approved
            'subtotal' => 0.00,
            'discount' => 0.00,
            'tax_rate' => 0.00,
            'tax_amount' => 0.00,
            'grand_total' => 0.00,
            'status' => QuotationStatus::Draft,
            'notes' => $notes,
            // The same request in a structured form, for the staff review screen.
            'request_details' => [
                'source' => 'public_form',
                'project_type' => $projectType,
                'timeline' => $timeline,
                // Quantity and brand name are added only when given (older requests have neither).
                'brands' => array_values(array_map(fn ($i) => array_filter([
                    'brand' => $catalog[mb_strtolower($i['brand'])],
                    'brand_name' => self::isOthers($i['brand']) ? $i['brand_name'] : null,
                    'details' => $i['details'],
                    'quantity' => $i['quantity'],
                ], fn ($v, $k) => $v !== null || $k === 'details', ARRAY_FILTER_USE_BOTH), $brandItems)),
                'unlisted_brands' => $unlisted !== '' ? $unlisted : null,
                // Exactly as the client confirmed them (decimal quantities included).
                'product_list' => $products !== [] ? $products : null,
                // What was read from each uploaded list, for staff to verify (the client does not review it).
                'extracted_products' => $extracted !== [] ? $extracted : null,
                'submitted_at' => now()->toIso8601String(),
            ],
        ]);

        // One line per selected brand item (details appended), for the existing item views.
        $seen = [];
        foreach ($brandItems as $item) {
            $canonical = $catalog[mb_strtolower($item['brand'])];
            $key = mb_strtolower($canonical.'|'.$item['brand_name'].'|'.$item['details'].'|'.$item['quantity']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // A free description, never matched to inventory: staff choose the products when pricing.
            // Lines hold whole numbers: a decimal quantity stays in the name and the line starts at 1.
            $whole = is_int($item['quantity']);
            $decimal = $item['quantity'] !== null && ! $whole ? ' (Qty '.$item['quantity'].')' : '';
            $quotation->items()->create([
                'product_id' => null,
                'sku' => self::isOthers($canonical) ? 'OTHERS' : 'BRAND',
                'product_name' => self::isOthers($canonical)
                    ? 'Others'.($item['brand_name'] !== '' ? " ({$item['brand_name']})" : '').': '.Str::limit($item['details'], 200).$decimal
                    : 'Brand: '.$canonical.($item['details'] !== '' ? ' - '.Str::limit($item['details'], 100) : '').$decimal,
                'quantity' => $whole ? $item['quantity'] : 1,
                'unit_price' => 0.00,
                'discount' => 0.00,
                'subtotal' => 0.00,
            ]);
        }

        if ($unlisted !== '') {
            $quotation->items()->create([
                'product_id' => null,
                'sku' => 'BRAND-UNLISTED',
                'product_name' => 'Brands not listed: '.Str::limit($unlisted, 75),
                'quantity' => 1,
                'unit_price' => 0.00,
                'discount' => 0.00,
                'subtotal' => 0.00,
            ]);
        }

        // One unpriced line per product, for staff to match and price: the products the client
        // confirmed (older form) or, otherwise, the rows read from the uploaded lists that have a
        // description and a quantity. Rows read with doubts are marked "[check]".
        if ($products !== []) {
            foreach ($products as $p) {
                $this->addListLine($quotation, $p['description'], $p['quantity'], $p['unit']);
            }
        } else {
            foreach ($extracted as $file) {
                foreach ($file['rows'] as $row) {
                    if ($row['description'] !== '' && $row['quantity'] !== null) {
                        $this->addListLine($quotation, $row['description'], $row['quantity'], $row['unit'], $row['issues'] !== [] ? ' [check]' : '');
                    }
                }
            }
        }

        return $quotation;
    }

    /**
     * An unpriced CLIENT-LIST line. Quotation lines hold whole numbers, so a decimal quantity is
     * kept in the name (and request_details) and the line starts at 1 for staff to set.
     */
    private function addListLine(Quotation $quotation, string $description, int|float $quantity, ?string $unit, string $suffix = ''): void
    {
        $quotation->items()->create([
            'product_id' => null,
            'sku' => 'CLIENT-LIST',
            'product_name' => Str::limit($description, 200).' ('.trim($quantity.' '.Str::limit((string) $unit, 30, '')).')'.$suffix,
            'quantity' => is_int($quantity) ? $quantity : 1,
            'unit_price' => 0.00,
            'discount' => 0.00,
            'subtotal' => 0.00,
        ]);
    }

    /**
     * Store the product-list files. A file identical to another product file is not stored twice.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $stored  receives the storage path of every file copied, for clean-up if saving fails
     */
    private function keepProductFiles(Quotation $quotation, array $files, array &$stored): void
    {
        $seen = [];
        foreach ($files as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $hash = hash_file('sha256', $path);
            if (in_array($hash, $seen, true)) {
                continue;
            }
            $seen[] = $hash;

            $stored[] = $file->storeAs(self::PRODUCT_FILES_DIR, Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
            $quotation->files()->create([
                'kind' => QuotationFile::KIND_PRODUCT_LIST,
                'path' => end($stored),
                'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'sha256' => $hash,
            ]);
        }

        $count = $quotation->files()->count();
        if ($count > 0) {
            $unchecked = $this->uncheckedFiles === []
                ? ''
                : 'Not checked for text (OCR unavailable): '.implode(', ', $this->uncheckedFiles).".\n";
            $quotation->update(['notes' => rtrim((string) $quotation->notes)."\n\n--- Product list files ---\n{$count} file(s) attached for staff review.\n{$unchecked}"]);
        }
    }
}
