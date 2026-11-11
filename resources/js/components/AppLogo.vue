<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div class="flex shrink-0 items-center justify-center">
        <AppLogoIcon class="size-8 object-contain transition-all duration-300 group-data-[collapsible=icon]:size-7" />
    </div>
    <div class="ml-2.5 flex flex-col justify-center leading-none overflow-hidden transition-all duration-300 ease-in-out group-data-[collapsible=icon]:hidden group-data-[collapsible=icon]:w-0 group-data-[collapsible=icon]:opacity-0">
        <span class="text-base font-bold tracking-tight text-gray-900 select-none whitespace-nowrap">MR-PRIDE</span>
        <span class="text-[9px] font-bold tracking-widest text-gray-500 uppercase mt-0.5 select-none whitespace-nowrap">Electrical Supplies</span>
    </div>
</template>
