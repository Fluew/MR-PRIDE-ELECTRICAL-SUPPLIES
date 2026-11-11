<script setup lang="ts">
/**
 * Shows a backend-calculated weighted score: each criterion's inputs, formula with the actual
 * values substituted, normalized score, weight and weighted result, then the total. Nothing is
 * recalculated here. A criterion without data shows why, and the total is marked incomplete.
 */
export interface EvaluationCriterion {
    key: string;
    label: string;
    weight: number;
    source: string;
    available: boolean;
    score: number | null;
    weighted: number | null;
    formula?: string;
    substitution?: string;
    weighted_formula?: string;
    reason: string | null;
    inputs?: Record<string, any>;
}

export interface Evaluation {
    complete: boolean;
    score: number | null;
    partial_score: number | null;
    weight_available: number;
    classification: string | null;
    missing: string[];
    criteria: EvaluationCriterion[];
    final_formula: string | null;
    calculated_at: string;
    /** 1–5 AHP priority scale (product prioritization only). */
    scale?: { score: number; min: number; max: number; range: string; action: string | null; formula: string } | null;
}

defineProps<{ evaluation: Evaluation }>();

const fmt = (n: number | null | undefined) => (n === null || n === undefined ? '—' : Number(n).toFixed(2));
const pct = (w: number) => `${Math.round(w * 100)}%`;
</script>

<template>
    <div class="space-y-4">
        <div class="overflow-x-auto rounded-xl border border-gray-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Criterion</th>
                        <th class="px-3 py-2 text-right font-medium">Score (0–100)</th>
                        <th class="px-3 py-2 text-right font-medium">Weight</th>
                        <th class="px-3 py-2 text-right font-medium">Weighted</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in evaluation.criteria" :key="c.key" class="border-t border-gray-100">
                        <td class="px-3 py-2 font-medium text-gray-900">{{ c.label }}</td>
                        <td class="px-3 py-2 text-right font-mono">
                            <span v-if="c.available">{{ fmt(c.score) }}</span>
                            <span v-else class="font-sans text-xs text-amber-700">Insufficient data</span>
                        </td>
                        <td class="px-3 py-2 text-right font-mono">{{ pct(c.weight) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ c.available ? fmt(c.weighted) : '—' }}</td>
                    </tr>
                    <tr class="border-t border-gray-200 bg-gray-50/60 font-semibold">
                        <td class="px-3 py-2">Total</td>
                        <td></td>
                        <td class="px-3 py-2 text-right font-mono">100%</td>
                        <td class="px-3 py-2 text-right font-mono">
                            <span v-if="evaluation.complete">{{ fmt(evaluation.score) }}</span>
                            <span v-else class="font-sans text-xs text-amber-700">Incomplete</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="!evaluation.complete" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            No final score or classification: {{ evaluation.missing.join(', ') }} {{ evaluation.missing.length > 1 ? 'have' : 'has' }} insufficient data.
            Weights are not moved to other criteria and missing data is not counted as 0.
            The criteria with data add up to {{ fmt(evaluation.partial_score) }} out of their {{ evaluation.weight_available }}% share.
        </div>

        <div class="space-y-2">
            <details v-for="c in evaluation.criteria" :key="c.key" class="rounded-xl border border-gray-200 p-3" :open="!c.available">
                <summary class="flex cursor-pointer items-center justify-between gap-2 text-sm font-medium text-gray-900">
                    <span>{{ c.label }} ({{ pct(c.weight) }})</span>
                    <span class="font-mono text-xs text-gray-500">{{ c.available ? c.weighted_formula : 'insufficient data' }}</span>
                </summary>
                <div class="mt-2 space-y-1.5 text-xs text-gray-600">
                    <p><span class="font-medium text-gray-800">Source:</span> {{ c.source }}</p>
                    <template v-if="c.available">
                        <p><span class="font-medium text-gray-800">Formula:</span> <span class="font-mono">{{ c.formula }}</span></p>
                        <p><span class="font-medium text-gray-800">With values:</span> <span class="font-mono">{{ c.substitution }}</span></p>
                        <p><span class="font-medium text-gray-800">Weighted:</span> <span class="font-mono">{{ c.weighted_formula }}</span></p>
                    </template>
                    <p v-else class="text-amber-800">{{ c.reason }}</p>
                    <slot name="inputs" :criterion="c" />
                </div>
            </details>
        </div>

        <div class="rounded-xl bg-gray-50 px-3 py-2 font-mono text-xs text-gray-700">
            <template v-if="evaluation.complete">Final: {{ evaluation.final_formula }}</template>
            <template v-else>Final: not calculated (incomplete data)</template>
            <template v-if="evaluation.complete && evaluation.scale">
                <br />1–5 priority scale: {{ evaluation.scale.formula }} → {{ evaluation.classification }} ({{ evaluation.scale.range }}){{ evaluation.scale.action ? ` — ${evaluation.scale.action}` : '' }}
            </template>
        </div>
        <p class="text-xs text-gray-500">Calculated {{ new Date(evaluation.calculated_at).toLocaleString() }} from the current records.</p>
    </div>
</template>
