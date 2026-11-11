<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- Force dark mode specifically for auth layouts to match the new brand theme -->
    <div class="dark min-h-screen bg-[#0a0a0a] text-gray-100 font-sans selection:bg-[#F8B803] selection:text-black relative overflow-hidden">
        <!-- Background Decorations -->
        <div class="absolute inset-0 z-0">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMSIgY3k9IjEiIHI9IjEiIGZpbGw9IiMzMzMiLz48L3N2Zz4=')] opacity-20"></div>
            <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-gradient-to-br from-[#F8B803]/10 to-transparent rounded-full blur-[100px] -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-gradient-to-tr from-[#4b5320]/10 to-transparent rounded-full blur-[100px] translate-y-1/3 -translate-x-1/3"></div>
        </div>

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10 relative z-10">
            <div class="w-full max-w-md">
                <!-- Glassmorphic Card -->
                <div class="bg-[#111]/80 backdrop-blur-xl border border-gray-800 rounded-3xl p-8 sm:p-10 shadow-2xl">
                    <div class="flex flex-col gap-8">
                        <div class="flex flex-col items-center gap-4 text-center">
                            <Link :href="home()" class="flex flex-col items-center gap-3 group">
                                <img src="/LOGO_MR_PRIDE.webp" alt="MR-Pride Logo" class="w-16 h-16 object-contain group-hover:scale-105 transition-transform duration-300" />
                                <div class="flex flex-col justify-center leading-none text-center">
                                    <span class="text-2xl font-bold tracking-tight text-white">MR-PRIDE</span>
                                    <span class="text-xs font-bold tracking-widest text-gray-400 uppercase mt-1">Electrical Supplies</span>
                                </div>
                            </Link>
                            <div class="space-y-1">
                                <h1 class="text-2xl font-bold text-white">{{ title }}</h1>
                                <p class="text-sm text-gray-400">
                                    {{ description }}
                                </p>
                            </div>
                        </div>
                        
                        <div class="text-gray-300">
                            <slot />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
