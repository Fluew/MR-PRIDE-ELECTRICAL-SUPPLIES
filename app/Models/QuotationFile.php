<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a client sent with a quotation request (currently: product lists). Stored privately on
 * the local disk under a random name; staff open it through QuotationController::downloadFile.
 */
class QuotationFile extends Model
{
    public const KIND_PRODUCT_LIST = 'product_list';

    protected $fillable = ['quotation_id', 'kind', 'path', 'original_name', 'mime', 'size', 'sha256'];

    /** The storage path never leaves the server. */
    protected $hidden = ['path', 'sha256'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function getUrlAttribute(): string
    {
        return route('quotations.files.show', ['quotation' => $this->quotation_id, 'file' => $this->id]);
    }
}
