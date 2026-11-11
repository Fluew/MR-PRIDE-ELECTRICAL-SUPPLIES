<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Mail, KeyRound, Eye, EyeOff, Home, ArrowRight, ShieldCheck } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/InputError.vue';

defineOptions({
    layout: {
        title: 'Emergency Device Recovery',
        description: 'Authorize this workstation using your Administrator Recovery Key',
    },
});

defineProps<{
    status?: string;
}>();

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    recovery_key: '',
});

const submit = () => {
    form.post('/device/recover', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Admin Device Recovery | MR-Pride" />

    <div class="space-y-4 sm:space-y-4.5">
        <!-- Status Message Banner -->
        <div
            v-if="status"
            class="rounded-xl border border-emerald-400/40 bg-emerald-500/20 p-3 text-center text-xs font-semibold text-emerald-200"
        >
            {{ status }}
        </div>

        <!-- Explanatory Notice Banner (Compact, Responsive) -->
        <div
            class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5"
        >
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5"
            >
                <KeyRound class="size-4.5" />
            </div>
            <div class="min-w-0 flex-1 text-xs leading-relaxed text-white/90">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-white text-xs sm:text-sm">
                        Single-Use Master Key
                    </h4>
                    <span class="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-bold text-white tracking-wider uppercase">
                        Admin Only
                    </span>
                </div>
                <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                    Authorizes this browser as an approved workstation and rotates your key automatically.
                </p>
            </div>
        </div>

        <!-- Form with Floating Labels -->
        <form @submit.prevent="submit" class="space-y-3.5 sm:space-y-4">
            <!-- Administrator Email -->
            <div>
                <div class="relative">
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-10 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="form.errors.email ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="email"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        Administrator Email
                    </label>
                    <Mail class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                </div>
                <InputError :message="form.errors.email" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Administrator Password -->
            <div>
                <div class="relative">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-11 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="form.errors.password ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="password"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        Administrator Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3.5 text-gray-400 transition-colors hover:text-black focus:outline-none"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        :aria-pressed="showPassword"
                    >
                        <EyeOff v-if="showPassword" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError :message="form.errors.password" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Emergency Recovery Key -->
            <div>
                <div class="relative">
                    <input
                        id="recovery_key"
                        v-model="form.recovery_key"
                        type="text"
                        name="recovery_key"
                        required
                        autocomplete="off"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-10 pb-1.5 font-mono text-xs font-semibold uppercase tracking-wider text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="form.errors.recovery_key ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="recovery_key"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        Emergency Recovery Key
                    </label>
                    <KeyRound class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                </div>
                <div class="mt-1.5 flex items-center justify-between gap-2 text-[11px] text-white/80">
                    <span>Format: <strong class="font-mono text-white">RECOVER-XXXXXX-XXXXXX-XXXXXX</strong></span>
                </div>
                <InputError :message="form.errors.recovery_key" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Submit Button -->
            <Button
                type="submit"
                class="mt-2 w-full cursor-pointer bg-[#F8B803] py-2.5 text-xs font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 sm:py-3 sm:text-sm"
                :disabled="form.processing || !form.email || !form.password || !form.recovery_key"
            >
                <Spinner v-if="form.processing" class="mr-2 text-gray-950" />
                <ShieldCheck v-else class="mr-2 size-4 text-gray-950" />
                Authorize Workstation
            </Button>
        </form>

        <!-- Navigation Links -->
        <div class="flex items-center justify-between border-t border-white/15 pt-3 text-xs">
            <Link
                href="/"
                class="flex items-center gap-1.5 text-white/80 transition-colors hover:text-white hover:underline"
            >
                <Home class="size-3.5" />
                <span>Home</span>
            </Link>

            <Link
                href="/login"
                class="flex items-center gap-1 font-semibold text-[#F8B803] transition-colors hover:underline"
            >
                <span>Return to Login</span>
                <ArrowRight class="size-3.5" />
            </Link>
        </div>
    </div>
</template>
