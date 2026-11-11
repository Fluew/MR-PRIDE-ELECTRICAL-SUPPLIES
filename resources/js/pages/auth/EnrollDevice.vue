<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    ShieldCheck,
    Laptop,
    KeyRound,
    ArrowRight,
    Home,
    Mail,
    UserCheck,
    Clock,
    Eye,
    EyeOff,
    Lock,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/InputError.vue';

defineOptions({
    layout: {
        title: 'Workstation Registration',
        description: 'Authorize this browser to access MRP ElectroDesk',
    },
});

const props = defineProps<{
    initialCode?: string;
    token?: string;
    // The expected code is never sent to the browser: the user must type the OTP from their email.
    assignedUser?: {
        name: string;
        email: string;
    } | null;
    expiresAt?: string;
    isInvitation?: boolean;
    status?: string;
}>();

const showPassword = ref(false);

const form = useForm({
    code: props.initialCode || '',
    token: props.token || '',
    email: props.assignedUser?.email || '',
    password: '',
});

const submit = () => {
    form.post('/device/enroll', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Workstation Registration | MR-Pride" />

    <div class="space-y-4 sm:space-y-4.5">
        <!-- Status Message Banner -->
        <div
            v-if="status"
            class="rounded-xl border border-emerald-400/40 bg-emerald-500/20 p-3 text-center text-xs font-semibold text-emerald-200"
        >
            {{ status }}
        </div>

        <!-- Explanatory Notice Banner (Staff Invitation or Direct Enrollment) -->
        <div
            v-if="assignedUser"
            class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5"
        >
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5"
            >
                <UserCheck class="size-4.5" />
            </div>
            <div class="min-w-0 flex-1 text-xs leading-relaxed text-white/90">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-white text-xs sm:text-sm">
                        Staff Workstation Invitation
                    </h4>
                    <span
                        class="inline-flex items-center gap-1 rounded bg-amber-400/20 px-1.5 py-0.5 text-[10px] font-bold text-amber-200 tracking-wider uppercase border border-amber-300/30"
                    >
                        <Clock class="size-3" />
                        {{ expiresAt || '5 Mins' }}
                    </span>
                </div>
                <p class="mt-0.5 text-[11px] text-white/85 sm:text-xs">
                    Invitation for <strong class="font-semibold text-[#F8B803]">{{ assignedUser.name }}</strong> ({{ assignedUser.email }}).
                </p>
                <p class="mt-0.5 text-[10px] text-white/70 sm:text-[11px] flex items-center gap-1">
                    <ShieldCheck class="size-3 text-emerald-300 inline shrink-0" />
                    Strictly bound to your registered work email.
                </p>
            </div>
        </div>

        <div
            v-else
            class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5"
        >
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5"
            >
                <Laptop class="size-4.5" />
            </div>
            <div class="min-w-0 flex-1 text-xs leading-relaxed text-white/90">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-white text-xs sm:text-sm">
                        Trusted Workstation Enrollment
                    </h4>
                    <span
                        class="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-bold text-white tracking-wider uppercase"
                    >
                        Single-Use
                    </span>
                </div>
                <p class="mt-0.5 text-[11px] text-white/80 sm:text-xs">
                    Enter your enrollment code and temporary password below to authorize this workstation.
                </p>
            </div>
        </div>

        <!-- Enrollment Form with Floating Labels -->
        <form @submit.prevent="submit" class="space-y-3.5 sm:space-y-4">
            <!-- Hidden Token field if tokenized -->
            <input v-if="token" type="hidden" name="token" :value="token" />

            <!-- Registered Work Email -->
            <div>
                <div class="relative">
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        :readonly="!!assignedUser"
                        autocomplete="email"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-10 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="[
                            assignedUser ? 'bg-white/90 text-gray-800 cursor-not-allowed' : '',
                            form.errors.email ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''
                        ]"
                    />
                    <label
                        for="email"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                        :class="form.email ? '-translate-y-2 scale-75 sm:-translate-y-2.5 text-gray-600 font-semibold' : ''"
                    >
                        Registered Work Email
                    </label>
                    <Mail v-if="!assignedUser" class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Lock v-else class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                </div>
                <div v-if="assignedUser" class="mt-1 flex items-center justify-between gap-2 text-[11px] text-white/80">
                    <span>Permanently bound to this employee account</span>
                    <span class="rounded bg-white/20 px-1.5 py-0.5 text-[9px] font-bold text-white uppercase tracking-wider">Locked</span>
                </div>
                <InputError :message="form.errors.email" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- One-Time Enrollment Code -->
            <div>
                <div class="relative">
                    <input
                        id="code"
                        v-model="form.code"
                        type="text"
                        name="code"
                        required
                        autocomplete="off"
                        autofocus
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-10 pb-1.5 font-mono text-xs font-semibold uppercase tracking-wider text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="form.errors.code ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="code"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                        :class="form.code ? '-translate-y-2 scale-75 sm:-translate-y-2.5 text-gray-600 font-semibold' : ''"
                    >
                        One-Time Enrollment Code
                    </label>
                    <KeyRound class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                </div>
                <div class="mt-1 flex items-center justify-between gap-2 text-[11px] text-white/80">
                    <span>Format: <strong class="font-mono text-white">MRP-XXXXXX</strong></span>
                    <span class="text-white/60">Single-use OTP</span>
                </div>
                <InputError :message="form.errors.code" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Temporary Password -->
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
                        :class="form.password ? '-translate-y-2 scale-75 sm:-translate-y-2.5 text-gray-600 font-semibold' : ''"
                    >
                        Temporary Password
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
                <div class="mt-1 text-[11px] text-white/80">
                    <span>Provided by your administrator to verify your employee account.</span>
                </div>
                <InputError :message="form.errors.password" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Submit Button -->
            <Button
                type="submit"
                class="mt-2 w-full cursor-pointer bg-[#F8B803] py-2.5 text-xs font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 sm:py-3 sm:text-sm"
                :disabled="form.processing || !form.code.trim() || !form.email.trim() || !form.password"
            >
                <Spinner v-if="form.processing" class="mr-2 text-gray-950" />
                <ShieldCheck v-else class="mr-2 size-4 text-gray-950" />
                Register & Authorize Workstation
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
