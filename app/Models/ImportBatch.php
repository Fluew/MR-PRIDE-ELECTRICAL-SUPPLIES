<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded document being imported: the original file (private storage), how it was read,
 * and its extracted rows waiting for staff review. Nothing is saved to inventory or supplier
 * quotes until staff confirm the batch.
 */
class ImportBatch extends Model
{
    public const INVENTORY = 'inventory';

    public const SUPPLIER_QUOTATION = 'supplier_quotation';

    public const MODULES = [self::INVENTORY, self::SUPPLIER_QUOTATION];

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready_for_review';

    public const IMPORTING = 'importing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Methods that recognised text from pixels: their values must be checked by staff. */
    public const OCR_METHODS = ['ocr', 'pdf-ocr'];

    protected $fillable = [
        'team_id', 'module', 'status', 'original_filename', 'stored_path', 'mime_type', 'file_type', 'file_size',
        'file_hash', 'extraction_method', 'page_count', 'meta', 'summary', 'error', 'attempts',
        'uploaded_by_id', 'imported_by_id', 'processed_at', 'imported_at', 'completed_at',
    ];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'summary' => 'array',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'imported_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }

    public function usedOcr(): bool
    {
        return in_array($this->extraction_method, self::OCR_METHODS, true);
    }
}
