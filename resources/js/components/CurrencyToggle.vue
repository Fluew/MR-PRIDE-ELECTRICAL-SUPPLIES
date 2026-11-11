<script setup lang="ts">
import { AlertTriangle, Loader2, RotateCw } from '@lucide/vue';
import { computed } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

/**
 * Header switch between USD (default) and PHP for every Admin/Staff page. Display only: amounts
 * are stored in USD and converted on screen (see useCurrency).
 */
const { state, rateLabel, formatRateDate, setCurrency, retryRate } = useCurrency();

const php = computed(() => state.preferred === 'PHP');
const rateTitle = computed(() =>
    state.rate
        ? `${rateLabel.value} · Frankfurter rate of ${formatRateDate(state.rate)}. Amounts are converted from USD for display only.`
        : '',
);
const unavailableTitle = computed(() =>
    [
        state.message,
        state.lastSuccess ? `Last available rate: ${formatRateDate(state.lastSuccess)}.` : null,
        'Amounts are shown in USD until the rate loads.',
    ]
        .filter(Boolean)
        .join(' '),
);

function toggle() {
    void setCurrency(php.value ? 'USD' : 'PHP');
}
</script>

<template>
    <div class="flex items-center gap-2" data-test="currency-toggle">
        <!-- Rate status, only while pesos are chosen -->
        <template v-if="php">
            <span
                v-if="state.status === 'loading' && !state.rate"
                class="inline-flex items-center gap-1.5 text-xs text-gray-500"
                role="status"
            >
                <Loader2 class="size-3.5 animate-spin" aria-hidden="true" />
                <span class="hidden md:inline">Loading exchange rate…</span>
                <span class="sr-only md:hidden">Loading exchange rate</span>
            </span>
            <span
                v-else-if="state.rate"
                class="hidden text-right text-[11px] leading-tight text-gray-500 md:block"
                :title="rateTitle"
            >
                <span class="block font-medium text-gray-700">{{ rateLabel }}</span>
                <span class="block">Rate of {{ formatRateDate(state.rate) }}</span>
            </span>
            <span
                v-else-if="state.status === 'unavailable'"
                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 py-1 pr-1 pl-2 text-xs text-amber-800"
                role="alert"
                :title="unavailableTitle"
            >
                <AlertTriangle class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="hidden sm:inline">Rate unavailable, showing USD</span>
                <span class="sr-only sm:hidden">{{ unavailableTitle }}</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-amber-600"
                    @click="retryRate()"
                >
                    <RotateCw class="size-3" aria-hidden="true" />
                    <span>Retry</span>
                </button>
            </span>
        </template>

        <span v-if="state.saveError" class="hidden text-xs text-red-600 sm:inline" role="alert">{{
            state.saveError
        }}</span>

        <button
            type="button"
            role="switch"
            :aria-checked="php"
            :aria-label="php ? 'Showing amounts in Philippine pesos. Switch to US dollars' : 'Showing amounts in US dollars. Switch to Philippine pesos'"
            :title="state.saveError || (php ? 'Show amounts in US dollars' : 'Show amounts in Philippine pesos')"
            :disabled="state.saving"
            class="inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-300 focus-visible:outline-2 focus-visible:outline-[#4b5320] disabled:cursor-wait"
            @click="toggle"
        >
            <span class="w-12 text-left tabular-nums">{{ php ? '₱ PHP' : '$ USD' }}</span>
            <span
                class="relative inline-flex h-4 w-7 shrink-0 rounded-full transition-colors"
                :class="php ? 'bg-[#4b5320]' : 'bg-gray-300'"
                aria-hidden="true"
            >
                <span
                    class="absolute top-0.5 left-0.5 size-3 rounded-full bg-white transition-transform"
                    :class="php ? 'translate-x-3' : ''"
                ></span>
            </span>
        </button>
    </div>
</template>
