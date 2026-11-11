<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowException;
use App\Http\Middleware\EnsureRole;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\ColumnSchema;
use App\Services\Import\ImportFailure;
use App\Services\Import\ImportService;
use App\Services\MaterialList\FileReader;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document imports (JSON API used by the import preview). Inventory imports need the Inventory
 * module; supplier quotation imports need the Quotations module. Administrators can do both.
 */
class ImportController extends Controller
{
    /** The staff role (module) each import belongs to. */
    private const MODULE_ROLE = [
        ImportBatch::INVENTORY => 'staff_inventory',
        ImportBatch::SUPPLIER_QUOTATION => 'staff_quotations',
    ];

    public function __construct(private ImportService $imports) {}

    public function upload(Request $request, mixed $current_team, string $module): JsonResponse
    {
        $team = $this->team($request);
        abort_unless(isset(self::MODULE_ROLE[$module]), 404);
        $this->authorizeModule($request->user(), $module);

        $data = $this->validated($request, [
            'file' => ['required', 'file', 'max:'.(FileReader::STAFF_MAX_BYTES / 1024), 'extensions:csv,txt,xls,xlsx,pdf,png,jpg,jpeg',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream,application/x-ole-storage,application/CDFV2,application/pdf,image/png,image/jpeg'],
            'supplier_id' => [Rule::requiredIf($module === ImportBatch::SUPPLIER_QUOTATION), 'nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            // Optional: left blank, the quotation date is read from the document (and must be
            // confirmed in the review when it is missing or cannot be read reliably).
            'quoted_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', ...($request->filled('quoted_on') ? ['after_or_equal:quoted_on'] : [])],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [
            'file.max' => 'The file is larger than 25 MB.',
            'file.extensions' => 'Upload a CSV, Excel (.xls/.xlsx), PDF, PNG or JPG file.',
            'file.mimetypes' => 'The file content does not match a CSV, Excel, PDF, PNG or JPG file.',
            'quoted_on.date_format' => 'The quotation date must be a valid date (YYYY-MM-DD).',
            'valid_until.date_format' => 'The validity date must be a valid date (YYYY-MM-DD).',
        ]);

        $meta = $module === ImportBatch::SUPPLIER_QUOTATION ? [
            'supplier_id' => (int) $data['supplier_id'],
            'quoted_on' => $data['quoted_on'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
            'reference' => $data['reference'] ?? null,
        ] : [];

        try {
            $batch = $this->imports->upload($team, $request->user(), $module, $request->file('file'), $meta);

            return response()->json($this->present($batch->fresh()), 201);
        } catch (WorkflowException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        } catch (ImportFailure $e) {
            return $this->serverProblem($e->getMessage());
        } catch (QueryException|PDOException $e) {
            report($e);

            return $this->serverProblem(ImportFailure::fromThrowable($e, ImportFailure::UPLOAD)->getMessage());
        }
    }

    public function index(Request $request, mixed $current_team, string $module): JsonResponse
    {
        $team = $this->team($request);
        abort_unless(isset(self::MODULE_ROLE[$module]), 404);
        $this->authorizeModule($request->user(), $module);

        $batches = ImportBatch::where('team_id', $team->id)->where('module', $module)->with(['uploadedBy:id,name', 'importedBy:id,name'])
            ->latest('id')->paginate(10);

        return response()->json($batches->through(fn ($b) => $this->present($b)));
    }

    public function show(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $this->validated($request, ['status' => ['nullable', Rule::in(['all', 'attention', ImportRow::READY, ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE, ImportRow::EXISTING, ImportRow::SKIPPED, ImportRow::IMPORTED])], 'per_page' => ['nullable', 'integer', 'min:10', 'max:200']]);

        return $this->json(function () use ($batch, $data) {
            if ($batch->status === ImportBatch::QUEUED) {
                // Not taken by a queue worker yet: read it now (a failure is stored on the batch).
                $this->imports->processNow($batch);
                $batch = $batch->fresh(['uploadedBy:id,name', 'importedBy:id,name']) ?? $batch;
            }
            $rows = $batch->rows()->with('matchedProduct:id,name,sku,unit')
                ->when(($data['status'] ?? 'all') === 'attention', fn ($q) => $q->whereIn('status', [ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE]))
                ->when(! in_array($data['status'] ?? 'all', ['all', 'attention'], true), fn ($q) => $q->where('status', $data['status']))
                ->orderBy('row_number')->paginate($data['per_page'] ?? 50);

            return ['batch' => $this->present($batch), 'rows' => $rows];
        });
    }

    public function updateRow(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $row = ImportRow::where('import_batch_id', $batch->id)->findOrFail((int) $request->route('row'));
        $fields = $batch->module === ImportBatch::INVENTORY
            ? ['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price']
            : ['description', 'item_code', 'unit', 'quantity', 'unit_price', 'amount'];
        $data = $this->validated($request, [
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['nullable', 'integer'],
            'skipped' => ['nullable', 'boolean'],
        ]);
        $changes = array_intersect_key($data['values'] ?? [], array_flip($fields));

        return $this->json(fn () => $this->imports->updateRow($row, $request->user(), $changes, $request->has('product_id'), $data['product_id'] ?? null, $request->has('skipped') ? (bool) $data['skipped'] : null)->load('matchedProduct:id,name,sku,unit'));
    }

    public function bulk(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $this->validated($request, ['action' => ['required', Rule::in(['skip_problems', 'restore'])]]);

        return $this->json(fn () => ['changed' => $this->imports->bulkSkip($batch, $request->user(), $data['action'] === 'restore' ? 'restore' : 'skip')]);
    }

    /** Set or correct the quotation dates of a supplier quotation import during review. */
    public function updateDates(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $this->validated($request, [
            'quoted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quoted_on'],
        ], [
            'quoted_on.required' => 'Enter the quotation date.',
            'quoted_on.date_format' => 'The quotation date must be a valid date (YYYY-MM-DD).',
            'quoted_on.before_or_equal' => 'The quotation date cannot be in the future.',
            'valid_until.date_format' => 'The validity date must be a valid date (YYYY-MM-DD).',
            'valid_until.after_or_equal' => 'The validity date cannot be before the quotation date.',
        ]);

        return $this->json(fn () => ['batch' => $this->present($this->imports->updateQuoteDates($batch, $request->user(), $data['quoted_on'], $data['valid_until'] ?? null))]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $this->validated($request, ['ocr_checked' => ['nullable', 'boolean']]);

        return $this->json(fn () => $this->imports->confirm($batch, $request->user(), (bool) ($data['ocr_checked'] ?? false)) + ['batch' => $this->present($batch->fresh())]);
    }

    /**
     * The extracted table, for choosing the header row and columns by hand (see
     * ImportService::table()).
     */
    public function table(Request $request): JsonResponse
    {
        $batch = $this->batch($request);

        return $this->json(fn () => $this->imports->table($batch));
    }

    /** Rebuild the preview rows with the header row and columns chosen by staff. */
    public function remap(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $this->validated($request, [
            'header_row' => ['nullable', 'integer', 'min:0'],
            'columns' => ['required', 'array'],
            'columns.*' => ['nullable', 'integer', 'min:0', 'max:'.(FileReader::MAX_COLS - 1)],
        ], [
            'columns.required' => 'Choose the column of each field.',
            'columns.*.integer' => 'Choose one of the columns shown.',
            'columns.*.min' => 'Choose one of the columns shown.',
            'columns.*.max' => 'Choose one of the columns shown.',
        ]);
        $columns = array_map(fn ($c) => $c === null ? null : (int) $c, array_intersect_key($data['columns'], ColumnSchema::FIELDS[$batch->module]));
        $headerRow = isset($data['header_row']) ? (int) $data['header_row'] : null;

        return $this->json(fn () => ['batch' => $this->present($this->imports->remap($batch, $request->user(), $headerRow, $columns))]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $batch = $this->batch($request);

        return $this->json(function () use ($batch, $request) {
            $this->imports->cancel($batch, $request->user());

            return ['batch' => $this->present($batch->fresh())];
        });
    }

    /** The original uploaded document, from private storage. */
    public function file(Request $request): StreamedResponse
    {
        $batch = $this->batch($request);
        abort_unless(Storage::disk(ImportService::DISK)->exists($batch->stored_path), 404);

        return Storage::disk(ImportService::DISK)->download($batch->stored_path, $batch->original_filename);
    }

    /** Products for the "match to product" picker of supplier quotation rows. */
    public function products(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $q = trim((string) $request->query('q', ''));

        return response()->json(Product::where('team_id', $batch->team_id)->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'unit']));
    }

    private function present(ImportBatch $b): array
    {
        return $b->only(['id', 'module', 'status', 'original_filename', 'file_type', 'file_size', 'extraction_method', 'page_count', 'meta', 'summary', 'error', 'attempts', 'processed_at', 'imported_at', 'completed_at', 'created_at']) + [
            'uses_ocr' => $b->usedOcr(),
            'date_issues' => $b->module === ImportBatch::SUPPLIER_QUOTATION && in_array($b->status, [ImportBatch::READY, ImportBatch::COMPLETED], true)
                ? $this->imports->quoteDateIssues($b)
                : [],
            'uploaded_by' => $b->uploadedBy?->name,
            'imported_by' => $b->importedBy?->name,
        ];
    }

    /**
     * A business rule (422) or a server problem (503) as a message staff can read. Database
     * errors are logged, never shown.
     */
    private function json(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (WorkflowException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        } catch (ImportFailure $e) {
            return $this->serverProblem($e->getMessage());
        } catch (QueryException|PDOException $e) {
            report($e);
            $code = ImportFailure::sqlStateOf($e);

            return $this->serverProblem('This change could not be saved because of a database problem. Nothing was changed.'
                .(ImportFailure::isSchemaError($e)
                    ? ' The database structure is out of date: ask an administrator to run "php artisan migrate", then try again.'
                    : ' Try again; if it keeps failing, ask an administrator to check the server logs.')
                .($code !== null ? " (database error {$code})" : ''));
        }
    }

    private function serverProblem(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['error' => [$message]]], 503);
    }

    /**
     * Validate the request and answer invalid input with JSON (422): these endpoints are called
     * by the import preview with fetch, and the app renders validation errors as JSON only for
     * api/* routes (bootstrap/app.php), which would turn them into redirects here.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules, array $messages = []): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }

    private function batch(Request $request): ImportBatch
    {
        $batch = ImportBatch::where('team_id', $this->team($request)->id)->with(['uploadedBy:id,name', 'importedBy:id,name'])->findOrFail((int) $request->route('batch'));
        $this->authorizeModule($request->user(), $batch->module);

        return $batch;
    }

    private function authorizeModule(User $user, string $module): void
    {
        abort_unless(EnsureRole::allows($user, [self::MODULE_ROLE[$module]]), 403, 'You do not have access to this import.');
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : Team::where('slug', (string) $param)->first();
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }
}
