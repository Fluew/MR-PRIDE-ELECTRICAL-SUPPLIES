<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extracted line of an import. `extracted` keeps what was read from the document; `values`
 * holds the current values after staff edits; `sources` says where each value came from
 * (document, calculated, edited, generated). Issues decide whether the row may be imported.
 */
class ImportRow extends Model
{
    public const READY = 'ready';

    public const NEEDS_REVIEW = 'needs_review';

    public const DUPLICATE = 'duplicate';

    public const EXISTING = 'existing';

    public const SKIPPED = 'skipped';

    public const IMPORTED = 'imported';

    protected $fillable = [
        'import_batch_id', 'row_number', 'page', 'raw', 'extracted', 'values', 'sources', 'issues', 'status',
        'skipped', 'matched_product_id', 'suggestions', 'created_type', 'created_id', 'edited_by_id',
    ];

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'values' => 'array',
            'sources' => 'array',
            'issues' => 'array',
            'suggestions' => 'array',
            'skipped' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }

    public function hasErrors(): bool
    {
        return collect($this->issues ?? [])->contains('level', 'error');
    }
}
