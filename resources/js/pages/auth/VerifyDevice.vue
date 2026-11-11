<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/InputError.vue';
import { ShieldCheck, Laptop, KeyRound, AlertTriangle, RefreshCw, LogOut, ArrowLeft } from '@lucide/vue';

defineOptions({
    layout: {
        title: 'Trusted Device Verification',
        description: 'Verify your device to access the administrative portal',
    },
});

defineProps<{
    status: 'pending' | 'revoked' | 'approved';
    device?: {
        name: string;
        ip: string;
        expires_at?: string;
    };
    email?: string;
    isAdmin?: boolean;
    recoveryMessage?: string;
}>();

const showRecoveryMode = ref(false);

const verifyForm = useForm({
    code: '',
});

const recoveryForm = useForm({
    recovery_key: '',
});

const resendForm = useForm({});

const submitVerification = () => {
    verifyForm.post('/device/verify', {
        preserveScroll: true,
    });
};

const submitRecovery = () => {
    recoveryForm.post('/device/verify/recovery', {
        preserveScroll: true,
    });
};

const submitResend = () => {
    resendForm.post('/device/verify/resend', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Device Verification | MR-Pride" />

    <div class="space-y-4 sm:space-y-4.5">
        <!-- Revoked Device Notice -->
        <div v-if="status === 'revoked'" class="space-y-4">
            <div class="rounded-xl border border-red-400/40 bg-red-500/20 p-5 text-center text-white sm:p-6">
                <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-red-400/30 text-red-200">
                    <AlertTriangle class="size-6" />
                </div>
                <h3 class="mb-1 text-base font-semibold text-white sm:text-lg">
                    Access Suspended on this Device
                </h3>
                <p class="text-xs leading-relaxed text-white/90 sm:text-sm">
                    This device (<span class="font-semibold text-white">{{ device?.name || 'Device' }}</span>) has been revoked by an administrator for security purposes.
                </p>
            </div>

            <p class="text-center text-xs text-white/80">
                Please sign in from an approved device or contact your system administrator to re-authorize this workstation.
            </p>

            <Link
                href="/logout"
                method="post"
                as="button"
                class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-white py-2.5 text-xs font-semibold text-gray-900 shadow-md transition-colors hover:bg-gray-100 sm:py-3 sm:text-sm"
            >
                <LogOut class="size-4 text-gray-700" />
                Return to Login
            </Link>
        </div>

        <!-- Verification Form -->
        <div v-else class="space-y-4 sm:space-y-4.5">
            <!-- Device Banner -->
            <div class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5">
                    <Laptop class="size-4.5" />
                </div>
                <div class="min-w-0 flex-1 text-xs leading-relaxed text-white/90">
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-xs font-semibold text-white sm:text-sm">
                            Unrecognized Device Detected
                        </h4>
                        <span class="inline-flex items-center rounded border border-amber-300/30 bg-amber-400/20 px-1.5 py-0.5 text-[10px] font-bold tracking-wider text-amber-200 uppercase">
                            Verification Required
                        </span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                        Workstation: <span class="font-medium text-white">{{ device?.name || 'Current Browser' }}</span> • IP: <span class="font-medium text-white">{{ device?.ip || 'Detected' }}</span>
                    </p>
                    <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                        A 6-digit confirmation code was sent to <strong class="font-semibold text-[#F8B803]">{{ email }}</strong>.
                    </p>
                </div>
            </div>

            <!-- Standard 6-Digit Code Mode -->
            <form v-if="!showRecoveryMode" @submit.prevent="submitVerification" class="space-y-3.5 sm:space-y-4">
                <div>
                    <label for="verification-code" class="mb-1.5 block text-xs font-semibold text-white/90 sm:text-sm">
                        Enter 6-Digit Confirmation Code
                    </label>
                    <input
                        id="verification-code"
                        v-model="verifyForm.code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="6"
                        placeholder="••••••"
                        class="block h-12 w-full rounded-xl border border-gray-200 bg-white text-center font-mono text-xl font-bold tracking-[0.4em] text-gray-900 focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:h-13 sm:text-2xl sm:tracking-[0.5em]"
                        autofocus
                        required
                        :disabled="verifyForm.processing"
                    />
                    <InputError :message="verifyForm.errors.code" class="mt-1 text-xs text-red-300" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full cursor-pointer bg-[#F8B803] py-2.5 text-xs font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 sm:py-3 sm:text-sm"
                    :disabled="verifyForm.processing || verifyForm.code.length !== 6"
                >
                    <Spinner v-if="verifyForm.processing" class="mr-2 text-gray-950" />
                    <ShieldCheck v-else class="mr-2 size-4 text-gray-950" />
                    Authorize & Register Device
                </Button>

                <div class="flex items-center justify-between pt-1 text-xs">
                    <button
                        type="button"
                        @click="submitResend"
                        :disabled="resendForm.processing"
                        class="inline-flex cursor-pointer items-center gap-1.5 text-white/80 transition-colors hover:text-white"
                    >
                        <RefreshCw class="size-3.5" :class="{ 'animate-spin': resendForm.processing }" />
                        Resend Code
                    </button>

                    <button
                        v-if="isAdmin"
                        type="button"
                        @click="showRecoveryMode = true"
                        class="inline-flex cursor-pointer items-center gap-1.5 font-semibold text-[#F8B803] transition-colors hover:underline"
                    >
                        <KeyRound class="size-3.5" />
                        Lost access? Use Recovery Key
                    </button>
                </div>
            </form>

            <!-- Admin Master Recovery Key Mode -->
            <form v-else @submit.prevent="submitRecovery" class="space-y-3.5 sm:space-y-4">
                <div class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5">
                        <KeyRound class="size-4.5" />
                    </div>
                    <div class="min-w-0 flex-1 text-xs leading-relaxed text-white/90">
                        <h4 class="text-xs font-semibold text-white sm:text-sm">
                            Admin Emergency Device Recovery
                        </h4>
                        <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                            Enter your single-use Emergency Admin Recovery Key to immediately approve this device.
                        </p>
                    </div>
                </div>

                <div>
                    <label for="recovery-key" class="mb-1.5 block text-xs font-semibold text-white/90 sm:text-sm">
                        Admin Recovery Key
                    </label>
                    <input
                        id="recovery-key"
                        v-model="recoveryForm.recovery_key"
                        type="text"
                        placeholder="RECOVER-XXXXXX-XXXXXX-XXXXXX"
                        class="block h-11 w-full rounded-xl border border-gray-200 bg-white px-3.5 font-mono text-xs font-semibold uppercase tracking-wider text-gray-900 focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:h-12 sm:text-sm"
                        autofocus
                        required
                        :disabled="recoveryForm.processing"
                    />
                    <InputError :message="recoveryForm.errors.recovery_key" class="mt-1 text-xs text-red-300" />
                </div>

                <div class="flex gap-2.5 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        @click="showRecoveryMode = false"
                        class="cursor-pointer border-white/20 bg-white/10 py-2.5 text-xs font-semibold text-white hover:bg-white/20 sm:py-3 sm:text-sm"
                    >
                        <ArrowLeft class="mr-1.5 size-3.5" />
                        Back
                    </Button>
                    <Button
                        type="submit"
                        class="flex-1 cursor-pointer bg-[#F8B803] py-2.5 text-xs font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] sm:py-3 sm:text-sm"
                        :disabled="recoveryForm.processing || !recoveryForm.recovery_key"
                    >
                        <Spinner v-if="recoveryForm.processing" class="mr-2 text-gray-950" />
                        Verify Recovery Key
                    </Button>
                </div>
            </form>

            <div class="border-t border-white/15 pt-3 text-center">
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="cursor-pointer text-xs font-semibold text-white/80 transition-colors hover:text-white hover:underline"
                >
                    Sign out and return to login
                </Link>
            </div>
        </div>
    </div>
</template>
