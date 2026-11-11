<?php

namespace App\Services\Import;

use App\Exceptions\WorkflowException;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\SkuGenerator;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Support\DateNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Universal document import for Inventory (new products) and Supplier Quotations.
 *
 *   upload → (queued) extraction → preview rows with issues → staff edit / match / skip
 *   (or choose the columns by hand) → confirm → records saved in one transaction.
 *
 * Nothing reaches inventory or supplier quotes before staff confirm. The original file is kept
 * on the private disk; extracted values, edits (with who edited), and the final import are
 * stored with the batch and in the audit log. OCR results always need staff confirmation.
 * Server problems (database, storage) never show their raw text to staff: they become an
 * ImportFailure with a plain message, and a failed import leaves nothing half-saved.
 */
class ImportService
{
    public const DISK = 'local';

    /** CSV and Excel files this small are read at once; everything else goes to the queue. */
    public const INLINE_BYTES = 2 * 1024 * 1024;

    public function __construct(private DocumentExtractor $extractor) {}

    public function rules(string $module): RowRules
    {
        return $module === ImportBatch::INVENTORY ? new InventoryRowRules : new SupplierQuoteRowRules;
    }

    /**
     * Store the file privately and start extraction.
     *
     * @param  array<string, mixed>  $meta
     */
    public function upload(Team $team, User $user, string $module, UploadedFile $file, array $meta = []): ImportBatch
    {
        if (! in_array($module, ImportBatch::MODULES, true)) {
            throw new WorkflowException('Unknown import type.');
        }
        $path = $file->getRealPath() ?: $file->getPathname();
        $name = $file->getClientOriginalName();
        try {
            // Content check before anything is stored: the real file type, size and readability.
            $type = app(FileReader::class)->forStaffImports()->detectType($path, $name);
        } catch (ExtractionException $e) {
            throw new WorkflowException($e->getMessage(), 'file');
        }
        if ($module === ImportBatch::SUPPLIER_QUOTATION) {
            Supplier::where('team_id', $team->id)->findOrFail($meta['supplier_id'] ?? 0);
        }

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $stored = Storage::disk(self::DISK)->putFileAs("imports/{$team->id}", $file, Str::uuid().'.'.$ext);
        if (! $stored) {
            throw new ImportFailure('The upload could not be recorded because the file could not be stored on the server. Nothing was saved. Try again, or ask an administrator to check the storage folder.', ImportFailure::UPLOAD);
        }

        try {
            $batch = DB::transaction(function () use ($team, $user, $module, $file, $meta, $name, $path, $stored, $type) {
                $batch = ImportBatch::create([
                    'team_id' => $team->id,
                    'module' => $module,
                    'status' => ImportBatch::QUEUED,
                    'original_filename' => mb_substr($name, 0, 255),
                    'stored_path' => $stored,
                    'mime_type' => $file->getMimeType(),
                    'file_type' => $type,
                    'file_size' => (int) $file->getSize(),
                    'file_hash' => hash_file('sha256', $path),
                    'meta' => $meta ?: null,
                    'uploaded_by_id' => $user->id,
                ]);
                AuditLogger::record('import.uploaded', "Import of \"{$batch->original_filename}\" ({$module}) uploaded.", $batch, ['size' => $batch->file_size, 'type' => $type], $user);

                return $batch;
            });
        } catch (QueryException|PDOException $e) {
            // No batch was recorded, so the stored copy would be orphaned.
            Storage::disk(self::DISK)->delete($stored);
            report($e);
            throw ImportFailure::fromThrowable($e, ImportFailure::UPLOAD);
        }

        if ($batch->file_size <= 5 * 1024 * 1024) {
            $this->processNow($batch);
        } else {
            try {
                ProcessImportBatch::dispatch($batch->id);
            } catch (Throwable $e) {
                // The queue did not take the job: the batch stays queued and is read when staff open it.
                report($e);
            }
        }

        return $batch->fresh() ?? $batch;
    }

    /**
     * Read a batch in this request (small files, or a queued batch opened before a worker took
     * it). Never throws: a failure is stored on the batch with a message staff can read.
     */
    public function processNow(ImportBatch $batch): void
    {
        if (! app()->runningUnitTests()) { // long OCR runs; in tests it would limit the whole run
            @set_time_limit(300);
        }
        try {
            ProcessImportBatch::dispatchSync($batch->id);
        } catch (Throwable $e) {
            report($e);
            // The job's failed() has normally recorded the failure already; keep that message.
            try {
                $fresh = $batch->fresh();
                if ($fresh && in_array($fresh->status, [ImportBatch::QUEUED, ImportBatch::PROCESSING], true)) {
                    $failure = ImportFailure::fromThrowable($e, ImportFailure::READING, $fresh);
                    $this->markFailed($fresh, $failure->getMessage(), $failure->stage, $failure);
                }
            } catch (Throwable $again) {
                report($again);
            }
        }
    }

    /**
     * Extract the document into preview rows. Safe to run again (a retry replaces the rows
     * it made before), so a failed or repeated attempt never leaves duplicates.
     *
     * Reading and saving are separate stages: a problem with the file itself is passed on as the
     * reader's ExtractionException; any other problem becomes an ImportFailure (reading or
     * saving) with a safe message. Columns chosen by staff (meta.column_mapping) are kept.
     *
     * @throws ExtractionException|ImportFailure
     */
    public function process(ImportBatch $batch): void
    {
        $this->runStage($batch, ImportFailure::READING, fn () => $batch->update(['status' => ImportBatch::PROCESSING, 'error' => null, 'attempts' => $batch->attempts + 1]));

        try {
            $result = $this->extractor->extract(Storage::disk(self::DISK)->path($batch->stored_path), $batch->original_filename, $batch->module);
            $table = array_values($result['rows']);
            $this->storeTable($batch, $table);
            $mapped = RowMapper::map($batch->module, $table, $this->chosenColumns($batch));
            $dates = $batch->module === ImportBatch::SUPPLIER_QUOTATION
                ? $this->documentDates($table, in_array($result['method'], ImportBatch::OCR_METHODS, true))
                : null;
        } catch (ExtractionException|ImportFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImportFailure::fromThrowable($e, ImportFailure::READING, $batch);
        }

        $this->runStage($batch, ImportFailure::SAVING, function () use ($batch, $result, $table, $mapped, $dates) {
            DB::transaction(function () use ($batch, $result, $table, $mapped, $dates) {
                $batch->forceFill(['extraction_method' => $result['method'], 'page_count' => $result['pages']]);
                if ($dates !== null) {
                    $batch->meta = $this->applyDocumentDates($batch->meta ?? [], $dates);
                }
                $this->rebuildRows($batch, $table, $mapped);
                $batch->forceFill(['status' => ImportBatch::READY, 'processed_at' => now()])->save();

                AuditLogger::record('import.extracted', "\"{$batch->original_filename}\" read by {$batch->extraction_method}: {$batch->rows()->count()} rows for review.", $batch,
                    ['pages' => $batch->page_count, 'header_found' => $mapped['header_found'], 'manual_mapping' => $batch->summary['manual_mapping'] ?? false], null);
            });
        });
    }

    /**
     * Record a failed extraction: the safe message for staff, the stage and database error code,
     * and no preview rows. If the database itself is failing, the problem is only reported, so
     * the original error is not hidden by a second one.
     */
    public function markFailed(ImportBatch $batch, string $message, string $stage = ImportFailure::READING, ?Throwable $e = null): void
    {
        $code = $e ? ImportFailure::sqlStateOf($e) : null;
        try {
            DB::transaction(function () use ($batch, $message, $stage, $code) {
                $batch->rows()->delete();
                $batch->update([
                    'status' => ImportBatch::FAILED,
                    'error' => mb_substr($message, 0, 2000),
                    'summary' => array_merge($batch->summary ?? [], $this->summary($batch), ['failure' => ['stage' => $stage, 'code' => $code]]),
                ]);
            });
            AuditLogger::record('import.failed', "Import of \"{$batch->original_filename}\" failed ({$stage}): ".mb_substr($message, 0, 500), $batch, ['stage' => $stage, 'code' => $code], null, 'failure', 'warning');
        } catch (Throwable $dbError) {
            report($dbError);
        }
    }

    /** Where the extracted table of a batch is kept for the column-mapping screen. */
    public function tablePath(ImportBatch $batch): string
    {
        return "imports/{$batch->team_id}/tables/{$batch->id}.json";
    }

    /**
     * The extracted table for choosing columns by hand: the first rows (cells shortened), and the
     * header row and columns in use (chosen by staff), or suggested when none were recognised.
     *
     * @return array{rows: list<list<string>>, width: int, total_rows: int, header_row: ?int, mapping: array<string, int>, manual: bool, columns: list<string>, fields: list<array{key: string, label: string, required: bool}>}
     */
    public function table(ImportBatch $batch): array
    {
        $table = $this->storedTable($batch);
        $module = $batch->module;
        $width = min(FileReader::MAX_COLS, RowMapper::width($table));

        $chosen = $this->chosenColumns($batch);
        [$headerRow, $mapping] = $chosen !== null
            ? [$chosen['header_row'], $chosen['columns']]
            : RowMapper::suggest($module, $table);

        return [
            'rows' => array_map(fn ($cells) => array_map(fn ($c) => mb_substr((string) $c, 0, 120), array_pad(array_slice(array_values($cells), 0, $width), $width, '')), array_slice($table, 0, 40)),
            'width' => $width,
            'total_rows' => count($table),
            'header_row' => $headerRow,
            'mapping' => $mapping,
            'manual' => $chosen !== null,
            'columns' => RowMapper::columnLabels($table, $headerRow, $width),
            'fields' => array_map(fn ($field, $label) => ['key' => $field, 'label' => $label, 'required' => $field === ColumnSchema::KEY[$module]], array_keys(ColumnSchema::LABELS[$module]), ColumnSchema::LABELS[$module]),
        ];
    }

    /**
     * Staff choose the header row and the column of each field. The preview rows are rebuilt from
     * the extracted table with these columns (edits on the old rows are discarded), and the
     * choice is kept for retries.
     *
     * @param  array<string, int|null>  $columns  field => column index (null: not in the file)
     */
    public function remap(ImportBatch $batch, User $user, ?int $headerRow, array $columns): ImportBatch
    {
        $this->assertEditable($batch);
        $table = $this->storedTable($batch);

        try {
            return DB::transaction(function () use ($batch, $user, $headerRow, $columns, $table) {
                $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
                $this->assertEditable($batch);
                $module = $batch->module;
                $labels = ColumnSchema::LABELS[$module];
                $key = ColumnSchema::KEY[$module];
                $width = min(FileReader::MAX_COLS, RowMapper::width($table));

                $columns = array_filter(array_intersect_key($columns, ColumnSchema::FIELDS[$module]), fn ($c) => $c !== null);
                if (! isset($columns[$key])) {
                    throw new WorkflowException("Choose the column that holds the {$this->lower($labels[$key])}.", 'columns');
                }
                foreach ($columns as $field => $index) {
                    if (! is_int($index) || $index < 0 || $index >= $width) {
                        throw new WorkflowException("The column chosen for {$this->lower($labels[$field])} is not in the file. Choose one of the columns shown.", 'columns');
                    }
                }
                $used = [];
                foreach ($columns as $field => $index) {
                    if (isset($used[$index])) {
                        throw new WorkflowException("{$labels[$used[$index]]} and {$this->lower($labels[$field])} cannot use the same column. Choose a different column for one of them.", 'columns');
                    }
                    $used[$index] = $field;
                }
                if ($headerRow !== null && ($headerRow < 0 || $headerRow >= count($table))) {
                    throw new WorkflowException('The header row chosen is not in the file. Choose one of the rows shown, or "No header row".', 'header_row');
                }

                $chosen = ['header_row' => $headerRow, 'columns' => $columns];
                $batch->meta = array_merge($batch->meta ?? [], ['column_mapping' => $chosen]);
                $mapped = RowMapper::map($module, $table, $chosen);
                $this->rebuildRows($batch, $table, $mapped);
                $batch->save();

                AuditLogger::record('import.remapped', "Columns of \"{$batch->original_filename}\" chosen by hand: ".count($mapped['rows']).' rows rebuilt for review.', $batch,
                    ['header_row' => $headerRow, 'columns' => $columns, 'mapping' => $batch->summary['mapping'] ?? []], $user);

                return $batch->fresh();
            });
        } catch (QueryException|PDOException $e) {
            report($e);
            throw ImportFailure::fromThrowable($e, ImportFailure::SAVING, $batch);
        }
    }

    /**
     * Staff edit of one row: changed fields, product match, or skip/restore. The other rows are
     * checked again because duplicates depend on them.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateRow(ImportRow $row, User $user, array $changes, bool $matchChanged = false, ?int $productId = null, ?bool $skip = null): ImportRow
    {
        return DB::transaction(function () use ($row, $user, $changes, $matchChanged, $productId, $skip) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($row->import_batch_id);
            $this->assertEditable($batch);
            $row = ImportRow::lockForUpdate()->findOrFail($row->id);
            if ($row->status === ImportRow::IMPORTED) {
                throw new WorkflowException('This row was already imported.');
            }

            $values = $row->values ?? [];
            $sources = $row->sources ?? [];
            foreach ($changes as $field => $value) {
                $values[$field] = is_string($value) ? trim($value) : $value;
                $sources[$field] = 'edited';
            }
            if ($matchChanged) {
                if ($productId !== null) {
                    Product::where('team_id', $batch->team_id)->findOrFail($productId);
                }
                $row->matched_product_id = $productId;
                $sources['product'] = 'edited';
            }
            if ($skip !== null) {
                $row->skipped = $skip;
            }
            $row->values = $values;
            $row->sources = $sources;
            $row->edited_by_id = $user->id;
            $row->save();

            $this->reanalyze($batch);

            return $row->fresh();
        });
    }

    /** Skip every row with errors (or restore all skipped rows). */
    public function bulkSkip(ImportBatch $batch, User $user, string $which): int
    {
        return DB::transaction(function () use ($batch, $which, $user) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertEditable($batch);
            $n = $which === 'restore'
                ? $batch->rows()->where('skipped', true)->where('status', '!=', ImportRow::IMPORTED)->update(['skipped' => false, 'edited_by_id' => $user->id])
                : $batch->rows()->whereIn('status', [ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE])->update(['skipped' => true, 'edited_by_id' => $user->id]);
            $this->reanalyze($batch);

            return $n;
        });
    }

    /**
     * Save the reviewed rows. Refused while any row still has an error (fix or skip it first),
     * and, for OCR documents, until staff confirm they checked the values against the original.
     *
     * @return array{created: int, skipped: int}
     */
    public function confirm(ImportBatch $batch, User $user, bool $ocrChecked = false): array
    {
        try {
            return $this->importRows($batch, $user, $ocrChecked);
        } catch (WorkflowException|ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The transaction was rolled back: nothing was created and the rows are still for review.
            report($e);
            $failure = ImportFailure::fromThrowable($e, ImportFailure::IMPORTING, $batch);
            try {
                AuditLogger::record('import.confirm_failed', "Import of \"{$batch->original_filename}\" could not be saved; nothing was imported.", $batch,
                    ['stage' => ImportFailure::IMPORTING, 'code' => $failure->sqlState], $user, 'failure', 'warning');
            } catch (Throwable $auditError) {
                report($auditError);
            }

            throw $failure;
        }
    }

    /** @return array{created: int, skipped: int} */
    private function importRows(ImportBatch $batch, User $user, bool $ocrChecked): array
    {
        return DB::transaction(function () use ($batch, $user, $ocrChecked) {
            // One import per team at a time, so two confirms cannot create the same product twice.
            Team::whereKey($batch->team_id)->lockForUpdate()->first();
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== ImportBatch::READY) {
                throw new WorkflowException("This import is {$this->statusLabel($batch->status)} and cannot be confirmed.");
            }
            $rows = $batch->rows()->orderBy('row_number')->get();
            $this->rules($batch->module)->analyze($batch, $rows); // check again against current data

            $blocking = $rows->where('status', ImportRow::NEEDS_REVIEW)->count();
            if ($blocking) {
                throw new WorkflowException("{$blocking} row(s) still need review. Fix them or skip them before importing.");
            }
            if ($batch->usedOcr() && ! $ocrChecked) {
                throw new WorkflowException('This document was read with OCR. Confirm that you checked the values against the original before importing.', 'ocr_checked');
            }
            $ready = $rows->where('status', ImportRow::READY);
            if ($ready->isEmpty()) {
                throw new WorkflowException('There are no rows to import.');
            }
            if ($batch->module === ImportBatch::SUPPLIER_QUOTATION) {
                $this->assertQuoteMeta($batch);
            }

            $batch->update(['status' => ImportBatch::IMPORTING]);
            // Products with a SKU in the file first, so a generated SKU never takes a number the file uses.
            $ordered = $batch->module === ImportBatch::INVENTORY ? $ready->sortBy(fn ($r) => ($r->values['sku'] ?? null) === null ? 1 : 0) : $ready;
            foreach ($ordered as $row) {
                [$type, $id] = $batch->module === ImportBatch::INVENTORY ? $this->createProduct($batch, $row) : $this->createQuote($batch, $row, $user);
                $row->forceFill(['status' => ImportRow::IMPORTED, 'created_type' => $type, 'created_id' => $id])->save();
            }
            foreach ($rows->where('status', '!=', ImportRow::IMPORTED) as $row) {
                $row->save();
            }

            $edited = $rows->filter(fn ($r) => collect($r->sources ?? [])->contains('edited'))->count();
            $now = now();
            $batch->update([
                'status' => ImportBatch::COMPLETED,
                'imported_by_id' => $user->id,
                'imported_at' => $now,
                'completed_at' => $now,
                'summary' => array_merge($batch->summary ?? [], $this->summary($batch), ['created' => $ready->count(), 'edited_rows' => $edited]),
            ]);
            AuditLogger::record('import.completed', "Imported {$ready->count()} ".($batch->module === ImportBatch::INVENTORY ? 'product(s)' : 'supplier quote(s)')." from \"{$batch->original_filename}\".", $batch,
                ['created' => $ready->count(), 'skipped' => $rows->count() - $ready->count(), 'edited_rows' => $edited, 'method' => $batch->extraction_method, 'ocr_checked' => $ocrChecked]
                + ($batch->module === ImportBatch::SUPPLIER_QUOTATION ? ['dates' => [
                    'quoted_on' => $batch->meta['quoted_on'] ?? null,
                    'valid_until' => $batch->meta['valid_until'] ?? null,
                    'sources' => $batch->meta['date_sources'] ?? null,
                    'document_original' => [
                        'quoted_on' => $batch->meta['document_dates']['quoted_on']['original'] ?? null,
                        'valid_until' => $batch->meta['document_dates']['valid_until']['original'] ?? null,
                    ],
                ]] : []), $user);

            return ['created' => $ready->count(), 'skipped' => $rows->count() - $ready->count()];
        });
    }

    public function cancel(ImportBatch $batch, User $user): void
    {
        DB::transaction(function () use ($batch, $user) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, [ImportBatch::COMPLETED, ImportBatch::IMPORTING, ImportBatch::CANCELLED], true)) {
                throw new WorkflowException("This import is {$this->statusLabel($batch->status)} and cannot be cancelled.");
            }
            $batch->update(['status' => ImportBatch::CANCELLED]);
            AuditLogger::record('import.cancelled', "Import of \"{$batch->original_filename}\" cancelled.", $batch, [], $user);
        });
    }

    /** @return array<string, int> */
    public function summary(ImportBatch $batch): array
    {
        $counts = $batch->rows()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $summary = ['total' => (int) $counts->sum()] + collect([ImportRow::READY, ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE, ImportRow::EXISTING, ImportRow::SKIPPED, ImportRow::IMPORTED])
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();

        if ($batch->module === ImportBatch::INVENTORY) {
            // Shown once in the preview instead of on every row: stock values in the file (never
            // imported), and new products that will be (or were) saved inactive for a missing unit or price.
            $rows = $batch->rows()->get(['status', 'values']);
            $summary['stock_in_file'] = $rows->filter(fn ($r) => ($r->values['stock'] ?? null) !== null)->count();
            $summary['inactive'] = $rows->whereIn('status', [ImportRow::READY, ImportRow::IMPORTED])
                ->filter(fn ($r) => collect(InventoryRowRules::REQUIRED_TO_ACTIVATE)->contains(fn ($f) => ($r->values[$f] ?? null) === null))->count();
        }

        return $summary;
    }

    public function statusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    /**
     * Replace the preview rows with freshly mapped ones and refresh the summary: counts, the
     * columns used, what is missing, and an earlier import of the same file. Runs inside the
     * caller's transaction; the caller saves the batch.
     *
     * @param  array<int, array<int, string>>  $table
     * @param  array{rows: list<array<string, mixed>>, unparsed: list<string>, header_found: bool, mapping: array<string, int>, header_row: ?int, columns: list<string>}  $mapped
     */
    private function rebuildRows(ImportBatch $batch, array $table, array $mapped): void
    {
        $batch->rows()->delete();
        $rows = collect($mapped['rows'])->values()->map(fn ($r) => new ImportRow([
            'import_batch_id' => $batch->id,
            'row_number' => $r['row_number'],
            'raw' => mb_substr($r['raw'], 0, 2000),
            'extracted' => $r['extracted'] + array_filter(['_total_line' => $r['total_line'], '_no_header' => $r['no_header']]),
            'values' => [],
            'skipped' => $r['total_line'],
            'status' => ImportRow::READY,
        ]));
        $this->rules($batch->module)->analyze($batch, $rows, true);
        foreach ($rows as $row) {
            $row->save();
        }

        $module = $batch->module;
        $key = ColumnSchema::KEY[$module];
        $keyFound = isset($mapped['mapping'][$key]);
        $summary = $this->summary($batch) + [
            'header_found' => $mapped['header_found'],
            'unparsed_lines' => $mapped['unparsed'],
            'manual_mapping' => $this->chosenColumns($batch) !== null,
            'header_row' => $mapped['header_row'],
            'mapping' => array_map(fn (int $c) => $mapped['columns'][$c] ?? RowMapper::columnName($c), $mapped['mapping']),
            'missing_columns' => $keyFound ? [] : [$key],
            'mapping_message' => $keyFound ? null : ColumnSchema::describeMissingKey($module, RowMapper::likelyHeaderCells($module, $table)),
        ];
        if ($previous = $this->previousImport($batch)) {
            $summary['duplicate_batch'] = [
                'id' => $previous->id,
                'filename' => $previous->original_filename,
                'completed_at' => $previous->completed_at?->toIso8601String(),
            ];
        }
        $batch->summary = $summary;
    }

    /** The latest completed import of the exact same file (same team and module). */
    private function previousImport(ImportBatch $batch): ?ImportBatch
    {
        if (! $batch->file_hash) {
            return null;
        }

        return ImportBatch::where('team_id', $batch->team_id)
            ->where('module', $batch->module)
            ->where('file_hash', $batch->file_hash)
            ->where('id', '!=', $batch->id)
            ->where('status', ImportBatch::COMPLETED)
            ->latest('id')
            ->first(['id', 'original_filename', 'completed_at']);
    }

    /**
     * Run one stage of an import. A server error becomes an ImportFailure for that stage, with a
     * message staff can read; problems with the file itself are passed on unchanged.
     */
    private function runStage(ImportBatch $batch, string $stage, callable $step): mixed
    {
        try {
            return $step();
        } catch (ExtractionException|ImportFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImportFailure::fromThrowable($e, $stage, $batch);
        }
    }

    /**
     * Columns chosen by staff for this batch, if any.
     *
     * @return array{header_row: ?int, columns: array<string, int>}|null
     */
    private function chosenColumns(ImportBatch $batch): ?array
    {
        $chosen = $batch->meta['column_mapping'] ?? null;
        if (! is_array($chosen) || ! is_array($chosen['columns'] ?? null)) {
            return null;
        }

        return [
            'header_row' => isset($chosen['header_row']) ? (int) $chosen['header_row'] : null,
            'columns' => array_map('intval', array_filter($chosen['columns'], fn ($c) => $c !== null)),
        ];
    }

    /**
     * Keep the extracted table so staff can choose the columns later without reading the file
     * again. Only that screen needs it (it reads the file again when the copy is missing), so a
     * storage problem here does not stop the import.
     *
     * @param  array<int, array<int, string>>  $table
     */
    private function storeTable(ImportBatch $batch, array $table): void
    {
        try {
            Storage::disk(self::DISK)->put($this->tablePath($batch), json_encode($table, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The extracted table of a batch: the stored copy, or (older batches) the original read again.
     *
     * @return list<list<string>>
     */
    private function storedTable(ImportBatch $batch): array
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->tablePath($batch);
        if ($disk->exists($path)) {
            $table = json_decode((string) $disk->get($path), true);
            if (is_array($table)) {
                return array_values(array_map(fn ($row) => array_map(fn ($c) => is_scalar($c) ? (string) $c : '', array_values((array) $row)), $table));
            }
        }

        if (! $batch->stored_path || ! $disk->exists($batch->stored_path)) {
            throw new WorkflowException('The original file of this import is no longer stored, so its columns cannot be chosen. Upload the file again.');
        }
        try {
            $table = array_values($this->extractor->extract($disk->path($batch->stored_path), $batch->original_filename, $batch->module)['rows']);
        } catch (ExtractionException $e) {
            throw new WorkflowException($e->getMessage(), 'file');
        } catch (Throwable $e) {
            report($e);
            throw ImportFailure::fromThrowable($e, ImportFailure::READING, $batch);
        }
        $this->storeTable($batch, $table);

        return $table;
    }

    /** A field label inside a sentence: "selling price", but "SKU" stays as it is. */
    private function lower(string $label): string
    {
        return preg_match('/^\p{Lu}{2,}\b/u', $label) ? $label : mb_strtolower($label);
    }

    private function reanalyze(ImportBatch $batch): void
    {
        $rows = $batch->rows()->get();
        $this->rules($batch->module)->analyze($batch, $rows);
        foreach ($rows as $r) {
            if ($r->isDirty()) {
                $r->save();
            }
        }
        $batch->update(['summary' => array_merge($batch->summary ?? [], $this->summary($batch))]);
    }

    private function assertEditable(ImportBatch $batch): void
    {
        if ($batch->status !== ImportBatch::READY) {
            throw new WorkflowException("This import is {$this->statusLabel($batch->status)}; rows can be changed only while it is ready for review.");
        }
    }

    private function assertQuoteMeta(ImportBatch $batch): void
    {
        $m = $batch->meta ?? [];
        Supplier::where('team_id', $batch->team_id)->findOrFail($m['supplier_id'] ?? 0);
        if (empty($m['currency'])) {
            throw new WorkflowException('The quotation currency is required.');
        }
        $blocking = collect($this->quoteDateIssues($batch))->firstWhere('level', 'error');
        if ($blocking) {
            throw new WorkflowException($blocking['message'], $blocking['field']);
        }
    }

    /** Labels of the quotation date and the validity date in a supplier's document. */
    public const QUOTE_DATE_LABELS = [
        'quoted_on' => ['quotation date', 'quote date', 'date of quotation', 'date issued', 'issue date', 'issued on', 'dated', 'date'],
        'valid_until' => ['valid until', 'valid till', 'valid thru', 'valid through', 'validity date', 'validity', 'expiry date', 'expiration date', 'expires on'],
    ];

    /**
     * The quotation and validity dates printed in the document, read with DateNormalizer: the
     * original text, the YYYY-MM-DD value (null when ambiguous or invalid) and the problem.
     * No preference is configured for supplier documents, so ambiguous dates are flagged.
     *
     * @param  array<int, array<int, string>>  $table
     * @return array{quoted_on: ?array, valid_until: ?array}
     */
    public function documentDates(array $table, bool $ocr): array
    {
        return [
            'quoted_on' => DateNormalizer::findLabeled($table, self::QUOTE_DATE_LABELS['quoted_on'], null, $ocr),
            'valid_until' => DateNormalizer::findLabeled($table, self::QUOTE_DATE_LABELS['valid_until'], null, $ocr),
        ];
    }

    /**
     * Keep the document's dates on the batch (meta.document_dates) and fill a date staff left
     * blank from the document, but only when the document's date was read reliably. A date
     * staff entered or corrected is never replaced. meta.date_sources says where each value
     * came from: entered (upload form), document, or edited (corrected in the review).
     *
     * @param  array<string, mixed>  $meta
     * @param  array{quoted_on: ?array, valid_until: ?array}  $dates
     * @return array<string, mixed>
     */
    private function applyDocumentDates(array $meta, array $dates): array
    {
        $sources = $meta['date_sources'] ?? [];
        foreach (['quoted_on', 'valid_until'] as $field) {
            if (! array_key_exists($field, $sources)) {
                $sources[$field] = empty($meta[$field]) ? null : 'entered';
            }
            if (! in_array($sources[$field], [null, 'document'], true)) {
                continue;
            }
            $found = $dates[$field] ?? null;
            if ($found && $found['status'] === DateNormalizer::OK) {
                $meta[$field] = $found['value'];
                $sources[$field] = 'document';
            } else {
                $meta[$field] = null;
                $sources[$field] = null;
            }
        }
        $meta['document_dates'] = $dates;
        $meta['date_sources'] = $sources;

        return $meta;
    }

    /**
     * What staff must check about the quotation dates before importing. Errors block the
     * import: no quotation date, an ambiguous or invalid one, a future date, or a validity date
     * before the quotation date. Warnings: the document disagrees with the date entered, or OCR
     * corrected the date.
     *
     * @return list<array{level: string, field: string, message: string, original: ?string}>
     */
    public function quoteDateIssues(ImportBatch $batch): array
    {
        $m = $batch->meta ?? [];
        $issues = [];
        $labels = ['quoted_on' => 'quotation date', 'valid_until' => 'validity date'];

        foreach ($labels as $field => $label) {
            $value = $m[$field] ?? null;
            $doc = $m['document_dates'][$field] ?? null;
            $source = $m['date_sources'][$field] ?? ($value ? 'entered' : null);
            $original = $doc['original'] ?? null;
            $add = function (string $level, string $message) use (&$issues, $field, $original) {
                $issues[] = compact('level', 'field', 'message', 'original');
            };

            if (! $value) {
                if ($doc && $doc['status'] !== DateNormalizer::OK) {
                    $add($field === 'quoted_on' ? 'error' : 'warning', $doc['problem'].($field === 'quoted_on' ? ' Enter the quotation date to continue.' : ' Enter the validity date, or leave it blank.'));
                } elseif ($field === 'quoted_on') {
                    $add('error', 'No quotation date was found in the document. Enter the quotation date to continue.');
                }

                continue;
            }

            $check = DateNormalizer::parse($value);
            if ($check['status'] !== DateNormalizer::OK || $check['value'] !== $value) {
                $add('error', "The {$label} \"{$value}\" is not a valid YYYY-MM-DD date.");

                continue;
            }
            if ($field === 'quoted_on' && $value > now()->toDateString()) {
                $add('error', "The quotation date {$value} is in the future. Check the date in the document.");
            }
            if ($field === 'valid_until' && ! empty($m['quoted_on']) && $value < $m['quoted_on']) {
                $add('error', "The validity date {$value} is before the quotation date {$m['quoted_on']}.");
            }

            if ($source === 'document') {
                if (! empty($doc['corrected_from'])) {
                    $add('warning', "OCR read the {$label} as \"{$doc['corrected_from']}\"; it was read as {$value}. Check it against the original.");
                }
            } elseif ($doc && $doc['status'] === DateNormalizer::OK && $doc['value'] !== $value) {
                $add('warning', "The document's {$label} reads {$doc['value']} (\"{$original}\"), but {$value} is set. Check which date is right.");
            } elseif ($doc && $doc['status'] !== DateNormalizer::OK) {
                $add('warning', $doc['problem']." The {$label} set here ({$value}) will be used.");
            }
        }

        return $issues;
    }

    /**
     * Staff set or correct the quotation dates during review. The document's original text stays
     * in meta.document_dates; the change is audited with the old and new values.
     */
    public function updateQuoteDates(ImportBatch $batch, User $user, string $quotedOn, ?string $validUntil): ImportBatch
    {
        return DB::transaction(function () use ($batch, $user, $quotedOn, $validUntil) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertEditable($batch);
            if ($batch->module !== ImportBatch::SUPPLIER_QUOTATION) {
                throw new WorkflowException('Only supplier quotation imports have quotation dates.');
            }
            $meta = $batch->meta ?? [];
            $before = ['quoted_on' => $meta['quoted_on'] ?? null, 'valid_until' => $meta['valid_until'] ?? null];
            $after = ['quoted_on' => $quotedOn, 'valid_until' => $validUntil];
            $sources = $meta['date_sources'] ?? [];
            foreach ($after as $field => $value) {
                if ($value !== $before[$field]) {
                    $sources[$field] = $value === null ? null : 'edited';
                }
            }
            $batch->meta = array_merge($meta, $after, ['date_sources' => $sources]);
            $batch->save();

            AuditLogger::record('import.dates_corrected', "Quotation dates of import \"{$batch->original_filename}\" set by staff.", $batch, [
                'before' => $before,
                'after' => $after,
                'document_original' => [
                    'quoted_on' => $meta['document_dates']['quoted_on']['original'] ?? null,
                    'valid_until' => $meta['document_dates']['valid_until']['original'] ?? null,
                ],
            ], $user);

            return $batch;
        });
    }

    /** @return array{0: string, 1: int} */
    private function createProduct(ImportBatch $batch, ImportRow $row): array
    {
        $v = $row->values;
        $sku = $v['sku']; // a SKU in the file is kept as it is
        if ($sku === null) {
            // No SKU in the file: the next one of the product's category (BRK-000012, GEN-000003, …).
            $sku = app(SkuGenerator::class)->next($batch->team_id, $v['category'] ?? null);
            $row->sources = array_merge($row->sources ?? [], ['sku' => 'generated']);
            $row->values = array_merge($v, ['sku' => $sku]);
        }
        $missing = array_values(array_filter(InventoryRowRules::COMPLETION_FIELDS, fn ($f) => $v[$f] === null));
        $active = ! array_intersect($missing, InventoryRowRules::REQUIRED_TO_ACTIVATE);

        $product = new Product([
            'team_id' => $batch->team_id,
            'sku' => $sku,
            'name' => $v['name'],
            'category' => $v['category'] ?? 'General',
            'brand' => $v['brand'],
            'unit' => $v['unit'],
            'cost_price' => $v['cost_price'],
            'price' => $v['price'],
            'stock' => 0,
            'is_active' => $active,
            'missing_fields' => $missing ?: null,
            'import_batch_id' => $batch->id,
        ]);
        $product->save();
        $product->updateCalculatedStatus();
        if (! $active) {
            $product->forceFill(['status' => 'Incomplete'])->save();
        }

        return ['product', $product->id];
    }

    /** @return array{0: string, 1: int} */
    private function createQuote(ImportBatch $batch, ImportRow $row, User $user): array
    {
        $v = $row->values;
        $m = $batch->meta;
        $quote = SupplierQuote::create([
            'team_id' => $batch->team_id,
            'supplier_id' => $m['supplier_id'],
            'product_id' => $row->matched_product_id,
            'reference' => $m['reference'] ?? null,
            'unit_price' => $v['unit_price'],
            'quantity' => $v['quantity'],
            'amount' => $v['amount'],
            'unit_price_source' => ($row->sources['unit_price'] ?? null) === 'calculated' ? 'calculated' : 'supplier',
            'amount_source' => $v['amount'] === null ? null : (($row->sources['amount'] ?? null) === 'calculated' ? 'calculated' : 'supplier'),
            'description_as_quoted' => $v['description'],
            'currency' => $m['currency'],
            'unit' => $v['unit'],
            'tax_inclusive' => (bool) ($m['tax_inclusive'] ?? false),
            'quoted_on' => $m['quoted_on'],
            'valid_until' => $m['valid_until'] ?? null,
            'notes' => "Imported from {$batch->original_filename}",
            'recorded_by_id' => $user->id,
            'import_batch_id' => $batch->id,
        ]);

        return ['supplier_quote', $quote->id];
    }
}
