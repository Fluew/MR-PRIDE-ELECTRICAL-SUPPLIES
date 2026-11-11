<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert, Laptop, ArrowRight, Home, RefreshCw, Lock, KeyRound } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        title: 'Workstation Access Restricted',
        description: 'Trusted device registration required to access MRP ElectroDesk',
    },
});

withDefaults(
    defineProps<{
        title?: string;
        message?: string;
        canEnroll?: boolean;
    }>(),
    {
        title: 'Access Denied',
        message:
            'This device is not registered or is no longer authorized to access MRP ElectroDesk. Contact your system administrator.',
        canEnroll: true,
    }
);

const reloadPage = () => {
    window.location.reload();
};
</script>

<template>
    <Head title="Access Denied | MR-Pride" />

    <div class="space-y-4 sm:space-y-4.5">
        <!-- Shield Icon & Warning Banner -->
        <div class="rounded-xl border border-red-400/40 bg-red-500/20 p-5 text-center text-white sm:p-6">
            <div
                class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-red-400/30 text-red-200 sm:size-14"
            >
                <ShieldAlert class="size-6 sm:size-7" />
            </div>

            <h3 class="text-base font-semibold tracking-tight text-white sm:text-lg">
                {{ title }}
            </h3>

            <p class="mt-1.5 text-xs leading-relaxed text-white/90 sm:text-sm">
                {{ message }}
            </p>
        </div>

        <!-- Technical explanation card -->
        <div class="rounded-xl border border-white/20 bg-white/10 p-3.5 text-xs leading-relaxed text-white/90 sm:p-4">
            <div class="flex items-start gap-3">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5">
                    <Lock class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <h4 class="text-xs font-semibold text-white sm:text-sm">
                        Zero-Trust Workstation Policy
                    </h4>
                    <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                        Only verified workstations holding an authorized cryptographic credential can open the login portal.
                    </p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="space-y-2.5 pt-1">
            <Link
                v-if="canEnroll"
                href="/device/enroll"
                class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#F8B803] py-2.5 text-xs font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus:outline-none sm:py-3 sm:text-sm"
            >
                <Laptop class="size-4" />
                Enroll Workstation
                <ArrowRight class="size-4" />
            </Link>

            <Link
                href="/device/recover"
                class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-white/20 bg-white/10 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-white/20 focus:outline-none sm:py-3 sm:text-sm"
            >
                <KeyRound class="size-3.5 text-[#F8B803]" />
                Admin Emergency Recovery Key
            </Link>

            <div class="flex gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    @click="reloadPage"
                    class="flex-1 cursor-pointer rounded-xl border-white/20 bg-white/10 py-2.5 text-xs font-semibold text-white hover:bg-white/20 sm:py-3"
                >
                    <RefreshCw class="mr-1.5 size-3.5" />
                    Retry
                </Button>

                <Link
                    href="/"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-xl border-white/20 bg-white/10 py-2.5 text-xs font-semibold text-white hover:bg-white/20 sm:py-3"
                >
                    <Home class="size-3.5" />
                    Home Page
                </Link>
            </div>
        </div>
    </div>
</template>
