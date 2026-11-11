<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportFailure;
use App\Services\Import\ImportService;
use App\Services\MaterialList\ExtractionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;
use Throwable;

/**
 * Reads one uploaded document into preview rows (OCR can take minutes for long scans).
 * A problem with the file itself (unsupported, damaged, password-protected, no text) fails at
 * once with a message for staff. Other errors are retried; after the last attempt the batch is
 * marked failed with a plain message (never the raw error). Each attempt replaces the rows of
 * the previous one, so retries never duplicate.
 */
class ProcessImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Long scanned PDFs: Tesseract runs page by page. */
    public int $timeout = 1800;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImportService $imports): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch || ! in_array($batch->status, [ImportBatch::QUEUED, ImportBatch::PROCESSING, ImportBatch::FAILED], true)) {
            return; // cancelled, already reviewed or imported
        }

        try {
            $imports->process($batch);
        } catch (ExtractionException $e) {
            // The file cannot be read as it is; trying again would give the same result.
            $imports->markFailed($batch, $e->getMessage(), ImportFailure::READING, $e);
        }
    }

    public function failed(?Throwable $e): void
    {
        try {
            $batch = ImportBatch::find($this->batchId);
        } catch (Throwable $dbError) {
            report($dbError);

            return;
        }
        if (! $batch || in_array($batch->status, [ImportBatch::READY, ImportBatch::IMPORTING, ImportBatch::COMPLETED, ImportBatch::CANCELLED], true)) {
            return;
        }

        if ($e instanceof TimeoutExceededException) {
            $failure = new ImportFailure('Reading the file took too long (long scanned documents are read page by page). Nothing was imported. Try a clearer or shorter file, or split the document.', ImportFailure::READING, null, $e);
        } else {
            $failure = ImportFailure::fromThrowable($e ?? new RuntimeException('The import job failed.'), ImportFailure::READING, $batch);
        }

        // A queued run was tried several times; an inline run (SyncJob) only once.
        $message = $failure->getMessage();
        if (! $this->job instanceof SyncJob && ! $e instanceof ExtractionException) {
            $attempts = $this->job ? $this->job->attempts() : $this->tries;
            $message = "Processing failed after {$attempts} ".($attempts === 1 ? 'attempt' : 'attempts').'. '.$message;
        }

        app(ImportService::class)->markFailed($batch, $message, $failure->stage, $failure);
    }
}
