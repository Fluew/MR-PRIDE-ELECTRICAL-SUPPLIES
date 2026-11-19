<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { usePrivacyMode } from '@/composables/usePrivacyMode';

/**
 * Header switch for Privacy Mode. Eye-Off hides every monetary value (click to hide); Eye shows
 * them again. Display only: nothing is recalculated or saved on the server.
 */
const { hidden, toggle } = usePrivacyMode();
</script>

<template>
    <button
        type="button"
        :aria-pressed="hidden"
        :aria-label="hidden ? 'Financial information is hidden. Show it' : 'Financial information is shown. Hide it'"
        title="Show/Hide Financial Information"
        class="inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg border bg-white text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-[#4b5320]"
        :class="hidden ? 'border-[#4b5320]/40 text-[#4b5320]' : 'border-gray-200'"
        data-test="privacy-toggle"
        @click="toggle"
    >
        <Eye v-if="hidden" class="size-4" aria-hidden="true" />
        <EyeOff v-else class="size-4" aria-hidden="true" />
    </button>
</template>
