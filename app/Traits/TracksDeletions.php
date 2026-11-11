<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

trait TracksDeletions
{
    use SoftDeletes;

    /**
     * Boot the trait to track which user deleted / archived the record.
     */
    protected static function bootTracksDeletions(): void
    {
        static::deleting(function ($model) {
            if (! $model->isForceDeleting() && auth()->check()) {
                $model->deleted_by_id = auth()->id();
                $model->saveQuietly();
            }
        });
    }

    /**
     * Get the user who deleted / archived this record.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }
}
