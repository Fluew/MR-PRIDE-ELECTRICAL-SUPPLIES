<?php

namespace App\Jobs;

use App\Events\Procurement\AHPPrioritizationRecalculated;
use App\Events\Procurement\SupplierEvaluationUpdated;
use App\Models\EvaluationSnapshot;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates AHP Product Prioritization and Supplier Evaluation for a team with the same
 * services the pages use, and stores a snapshot for every product or supplier whose result
 * changed since its last snapshot (score, classification, completeness or reorder advice).
 * Incomplete evaluations are stored as incomplete, never given a score. Retried on failure;
 * only one recalculation per team waits in the queue at a time.
 */
class RecalculateEvaluations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $teamId, public string $trigger, public ?string $reference = null) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ProductPrioritizer $prioritizer, SupplierEvaluator $evaluator): void
    {
        $now = now();
        $priorities = $prioritizer->forTeam($this->teamId, $now);
        $suppliers = $evaluator->forTeam($this->teamId, $now);

        [$changedProducts, $changedSuppliers] = DB::transaction(fn () => [
            $this->store(EvaluationSnapshot::PRODUCT_PRIORITY, $priorities, fn ($p) => $p['product']['id'], fn ($p) => ['reorder' => $p['reorder']['needed'] ?? false], $now),
            $this->store(EvaluationSnapshot::SUPPLIER_EVALUATION, $suppliers, fn ($s, $id) => $id, fn () => [], $now),
        ]);

        AHPPrioritizationRecalculated::dispatch($this->teamId, $this->trigger, $changedProducts, collect($priorities)->where('reorder.needed', true)->count());
        SupplierEvaluationUpdated::dispatch($this->teamId, $this->trigger, $changedSuppliers);
    }

    /** @return int how many snapshots were written */
    private function store(string $kind, array $results, callable $subject, callable $extra, $now): int
    {
        $latest = EvaluationSnapshot::where('team_id', $this->teamId)->where('kind', $kind)
            ->whereIn('id', EvaluationSnapshot::selectRaw('MAX(id)')->where('team_id', $this->teamId)->where('kind', $kind)->groupBy('subject_id'))
            ->get()->keyBy('subject_id');

        $written = 0;
        foreach ($results as $id => $r) {
            $subjectId = (int) $subject($r, $id);
            $fingerprint = [$r['complete'] ? round((float) $r['score'], 2) : null, $r['classification'], (bool) $r['complete'], $extra($r)];
            $prev = $latest->get($subjectId);
            if ($prev && [$prev->complete ? round((float) $prev->score, 2) : null, $prev->classification, $prev->complete, $prev->payload['extra'] ?? []] == $fingerprint) {
                continue;
            }
            EvaluationSnapshot::create([
                'team_id' => $this->teamId,
                'kind' => $kind,
                'subject_id' => $subjectId,
                'score' => $r['complete'] ? $r['score'] : null,
                'classification' => $r['classification'],
                'complete' => (bool) $r['complete'],
                'payload' => ['criteria' => $r['criteria'], 'missing' => $r['missing'], 'extra' => $extra($r)] + (isset($r['reorder']) ? ['reorder' => $r['reorder']] : []),
                'trigger' => $this->trigger,
                'trigger_reference' => $this->reference,
                'calculated_at' => $now,
            ]);
            $written++;
        }

        return $written;
    }
}
