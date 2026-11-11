<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored result of AHP Product Prioritization or Supplier Evaluation, taken when the
 * workflow recalculated them (after a delivery, inspection, invoice or stock posting).
 * The live pages still calculate from current records; snapshots are the history for audit.
 */
class EvaluationSnapshot extends Model
{
    public const PRODUCT_PRIORITY = 'product_priority';

    public const SUPPLIER_EVALUATION = 'supplier_evaluation';

    protected $fillable = ['team_id', 'kind', 'subject_id', 'score', 'classification', 'complete', 'payload', 'trigger', 'trigger_reference', 'calculated_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'complete' => 'boolean', 'score' => 'float', 'calculated_at' => 'datetime'];
    }
}
