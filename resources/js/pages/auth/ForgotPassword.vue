<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import CodeSlots, { type CodeSlotsStatus } from '@/components/vue-bits/CodeSlots.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { 
    Mail, 
    KeyRound, 
    ArrowLeft, 
    RefreshCw, 
    CheckCircle2, 
    XCircle,
    Eye,
    EyeOff,
    Lock
} from '@lucide/vue';

defineOptions({
    layout: {
        title: 'Forgot Password',
        description: 'Reset your password securely via email OTP verification',
    },
});

const props = defineProps<{
    status?: string;
    initialEmail?: string;
    initialOtpSent?: boolean;
    initialOtpVerified?: boolean;
}>();

const step = ref<'email' | 'otp' | 'password'>(
    props.initialOtpVerified ? 'password' : (props.initialOtpSent ? 'otp' : 'email')
);

const resendCooldown = ref(0);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;

const showPassword = ref(false);
const showConfirmPassword = ref(false);

const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024);
const updateWidth = () => {
    windowWidth.value = window.innerWidth;
};

const slotSize = computed(() => {
    if (windowWidth.value < 360) return 34;
    if (windowWidth.value < 420) return 38;
    return 42;
});

const slotGap = computed(() => {
    if (windowWidth.value < 360) return 4;
    if (windowWidth.value < 420) return 6;
    return 8;
});

// Part 1: Email Form
const emailForm = useForm({
    email: props.initialEmail || '',
});

// Part 2: OTP Verification Form
const otpForm = useForm({
    email: props.initialEmail || '',
    otp: '',
});

// Part 3: Reset Password Form
const resetForm = useForm({
    email: props.initialEmail || '',
    otp: '',
    password: '',
    password_confirmation: '',
});

const otpStatus = ref<CodeSlotsStatus>('idle');

watch(
    () => otpForm.errors.otp,
    (error) => {
        if (error) {
            otpStatus.value = 'error';
        }
    }
);

function handleOtpChange(code: string) {
    otpForm.otp = code;
    resetForm.otp = code;
    if (otpStatus.value !== 'idle') {
        otpStatus.value = 'idle';
    }
    if (otpForm.errors.otp) {
        otpForm.clearErrors('otp');
    }
}

function handleOtpComplete(code: string) {
    otpForm.otp = code;
    resetForm.otp = code;
}

watch(
    () => props.status,
    (newStatus) => {
        if (newStatus && (newStatus.includes('verification code') || newStatus.includes('OTP'))) {
            if (step.value === 'email') {
                step.value = 'otp';
            }
            if (emailForm.email && !resetForm.email) {
                otpForm.email = emailForm.email;
                resetForm.email = emailForm.email;
            }
        }
    }
);

function startCooldown(seconds = 60) {
    resendCooldown.value = seconds;
    if (cooldownTimer) clearInterval(cooldownTimer);
    cooldownTimer = setInterval(() => {
        if (resendCooldown.value > 0) {
            resendCooldown.value--;
        } else {
            if (cooldownTimer) clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
    }, 1000);
}

// Step 1: Submit Email
function submitEmail() {
    emailForm.post('/forgot-password', {
        preserveScroll: true,
        onSuccess: () => {
            otpForm.email = emailForm.email;
            resetForm.email = emailForm.email;
            step.value = 'otp';
            startCooldown(60);
        },
    });
}

// Step 2: Verify Code
function submitVerifyOtp() {
    if (otpForm.otp.length < 6 || otpForm.processing) return;
    
    otpForm.email = emailForm.email || resetForm.email;
    otpForm.post('/forgot-password/check-otp', {
        preserveScroll: true,
        onSuccess: () => {
            otpStatus.value = 'success';
            resetForm.email = otpForm.email;
            resetForm.otp = otpForm.otp;
            step.value = 'password';
        },
        onError: () => {
            otpStatus.value = 'error';
        },
    });
}

function handleResendOtp() {
    if (resendCooldown.value > 0 || emailForm.processing) return;
    
    emailForm.email = otpForm.email || resetForm.email;
    emailForm.post('/forgot-password/resend-otp', {
        preserveScroll: true,
        onSuccess: () => {
            startCooldown(60);
        },
    });
}

function backToEmail() {
    step.value = 'email';
    otpForm.reset('otp');
    resetForm.reset('otp', 'password', 'password_confirmation');
    otpStatus.value = 'idle';
}

function backToOtp() {
    step.value = 'otp';
    resetForm.reset('password', 'password_confirmation');
    otpStatus.value = 'idle';
}

// Step 3: Password Format Validation Checklist
const lengthValid = computed(() => resetForm.password.length >= 12);
const upperLowerValid = computed(
    () => /[a-z]/.test(resetForm.password) && /[A-Z]/.test(resetForm.password)
);
const numberValid = computed(() => /\d/.test(resetForm.password));
const symbolValid = computed(() => /[^A-Za-z0-9]/.test(resetForm.password));
const matchValid = computed(
    () =>
        resetForm.password.length > 0 &&
        resetForm.password === resetForm.password_confirmation
);
const isPasswordValid = computed(
    () =>
        lengthValid.value &&
        upperLowerValid.value &&
        numberValid.value &&
        symbolValid.value &&
        matchValid.value
);

// Step 3: Submit New Password
function submitResetPassword() {
    if (!isPasswordValid.value || resetForm.processing) return;

    resetForm.email = otpForm.email || emailForm.email;
    resetForm.otp = otpForm.otp;
    resetForm.post('/forgot-password/verify-otp', {
        preserveScroll: true,
    });
}

onMounted(() => {
    if (props.initialOtpSent && !props.initialOtpVerified) {
        startCooldown(45);
    }
    window.addEventListener('resize', updateWidth);
});

onUnmounted(() => {
    if (cooldownTimer) clearInterval(cooldownTimer);
    window.removeEventListener('resize', updateWidth);
});
</script>

<template>
    <Head title="Forgot Password" />

    <!-- 3-Step Progress Indicator -->
    <div class="mb-3.5 flex items-center justify-between">
        <!-- Step 1: Email -->
        <div class="flex items-center gap-1.5">
            <div 
                :class="[
                    'flex size-6 sm:size-7 items-center justify-center rounded-full text-xs font-semibold transition-colors',
                    step === 'email' 
                        ? 'bg-[#F8B803] text-gray-950 ring-2 ring-[#F8B803]/40' 
                        : (step === 'otp' || step === 'password' ? 'bg-[#F8B803] text-gray-950' : 'bg-white/20 text-white')
                ]"
            >
                <CheckCircle2 v-if="step === 'otp' || step === 'password'" class="size-3.5 sm:size-4" />
                <span v-else>1</span>
            </div>
            <span :class="['text-xs font-semibold', step === 'email' ? 'text-white' : 'text-white/60']">
                Email
            </span>
        </div>

        <div class="h-0.5 flex-1 mx-2 sm:mx-2.5 bg-white/20 rounded-full overflow-hidden">
            <div 
                class="h-full bg-[#F8B803] transition-colors duration-300"
                :style="{ width: step === 'email' ? '0%' : (step === 'otp' ? '50%' : '100%') }"
            />
        </div>

        <!-- Step 2: Code -->
        <div class="flex items-center gap-1.5">
            <div 
                :class="[
                    'flex size-6 sm:size-7 items-center justify-center rounded-full text-xs font-semibold transition-colors',
                    step === 'otp' 
                        ? 'bg-[#F8B803] text-gray-950 ring-2 ring-[#F8B803]/40' 
                        : (step === 'password' ? 'bg-[#F8B803] text-gray-950' : 'bg-white/20 text-white/60')
                ]"
            >
                <CheckCircle2 v-if="step === 'password'" class="size-3.5 sm:size-4" />
                <span v-else>2</span>
            </div>
            <span :class="['text-xs font-semibold', step === 'otp' ? 'text-white' : 'text-white/60']">
                Code
            </span>
        </div>

        <div class="h-0.5 flex-1 mx-2 sm:mx-2.5 bg-white/20 rounded-full overflow-hidden">
            <div 
                class="h-full bg-[#F8B803] transition-colors duration-300"
                :style="{ width: step === 'password' ? '100%' : '0%' }"
            />
        </div>

        <!-- Step 3: Password -->
        <div class="flex items-center gap-1.5">
            <div 
                :class="[
                    'flex size-6 sm:size-7 items-center justify-center rounded-full text-xs font-semibold transition-colors',
                    step === 'password' 
                        ? 'bg-[#F8B803] text-gray-950 ring-2 ring-[#F8B803]/40' 
                        : 'bg-white/20 text-white/60'
                ]"
            >
                3
            </div>
            <span :class="['text-xs font-semibold', step === 'password' ? 'text-white' : 'text-white/60']">
                Password
            </span>
        </div>
    </div>

    <!-- Status Notice (Clean without development code) -->
    <div
        v-if="status && step === 'otp'"
        class="mb-3 rounded-xl border border-emerald-400/30 bg-emerald-950/40 p-2.5 text-xs text-emerald-200 flex items-start gap-2"
    >
        <CheckCircle2 class="size-4 shrink-0 text-emerald-400 mt-0.5" />
        <div class="flex-1 leading-snug">{{ status }}</div>
    </div>

    <!-- PART 1: Enter Email -->
    <div v-if="step === 'email'" class="space-y-3.5">
        <div class="rounded-xl border border-white/20 bg-white/10 p-3 text-xs text-white/90 leading-relaxed flex items-start gap-2.5">
            <Mail class="size-4 text-[#F8B803] shrink-0 mt-0.5" />
            <div>
                Enter your registered email address and we'll send a 6-digit verification code (OTP) to your inbox.
            </div>
        </div>

        <form @submit.prevent="submitEmail" class="space-y-3">
            <!-- Floating Label: Email -->
            <div>
                <div class="relative">
                    <input
                        id="email"
                        v-model="emailForm.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        autofocus
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:text-sm"
                        :class="emailForm.errors.email ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="email"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                    >
                        Email address
                    </label>
                </div>
                <InputError :message="emailForm.errors.email" class="mt-1 text-xs text-red-300" />
            </div>

            <Button
                type="submit"
                class="w-full cursor-pointer bg-[#F8B803] font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 h-10 sm:h-11 rounded-xl text-sm mt-1"
                :disabled="emailForm.processing"
                data-test="send-otp-button"
            >
                <Spinner v-if="emailForm.processing" class="mr-2 text-gray-950" />
                <span v-if="emailForm.processing">Sending Verification Code...</span>
                <span v-else class="flex items-center justify-center gap-2">
                    Send Verification Code (OTP)
                </span>
            </Button>
        </form>

        <div class="border-t border-white/20 pt-2.5 text-center text-xs text-white/80">
            <span>Remember your password?</span>
            <TextLink :href="login()" class="ml-1 font-semibold text-[#F8B803] hover:underline">
                Return to log in
            </TextLink>
        </div>
    </div>

    <!-- PART 2: Enter OTP Code -->
    <div v-else-if="step === 'otp'" class="space-y-3">
        <!-- Compact Recipient Email Pill + Change Button -->
        <div class="flex items-center justify-between rounded-xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs text-white">
            <div class="flex items-center gap-1.5 truncate pr-2">
                <span class="text-white/70">Code sent to:</span>
                <span class="font-semibold text-white truncate">{{ otpForm.email || emailForm.email }}</span>
            </div>
            <button
                type="button"
                @click="backToEmail"
                class="shrink-0 font-semibold text-[#F8B803] hover:underline cursor-pointer text-xs"
            >
                Change
            </button>
        </div>

        <form @submit.prevent="submitVerifyOtp" class="space-y-3">
            <!-- 6-digit OTP code input with CodeSlots -->
            <div class="space-y-1">
                <div class="flex items-center justify-between px-0.5">
                    <Label class="text-xs font-semibold text-white flex items-center gap-1.5">
                        <KeyRound class="size-3.5 text-[#F8B803]" />
                        Enter 6-Digit OTP Code
                    </Label>
                    <span class="text-[11px] text-white/70">Expires in 15 mins</span>
                </div>

                <div class="flex flex-col items-center justify-center py-1">
                    <CodeSlots
                        :length="6"
                        :value="otpForm.otp"
                        :status="otpStatus"
                        accent-color="#F8B803"
                        ink-color="#F8B803"
                        slot-color="#ffffff"
                        digit-color="#111827"
                        danger-color="#ef4444"
                        :slot-size="slotSize"
                        :gap="slotGap"
                        :radius="10"
                        :bounce="0.2"
                        :settle="0.3"
                        :rise="8"
                        :cascade="20"
                        :disabled="otpForm.processing"
                        auto-focus
                        aria-label="6-digit verification code"
                        data-test="otp-input"
                        @change="handleOtpChange"
                        @complete="handleOtpComplete"
                    />
                </div>
                <InputError :message="otpForm.errors.otp" class="text-center text-xs text-red-300" />
            </div>

            <!-- Verify Code Button -->
            <Button
                type="submit"
                class="w-full cursor-pointer bg-[#F8B803] font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 h-10 sm:h-11 rounded-xl text-sm mt-1"
                :disabled="otpForm.processing || otpForm.otp.length < 6"
                data-test="verify-otp-button"
            >
                <Spinner v-if="otpForm.processing" class="mr-2 text-gray-950" />
                <span v-if="otpForm.processing">Verifying Code...</span>
                <span v-else class="flex items-center justify-center gap-2">
                    Verify Code & Continue
                </span>
            </Button>
        </form>

        <!-- Compact Footer: Resend & Return to Log in -->
        <div class="border-t border-white/20 pt-2 flex items-center justify-between text-xs">
            <button
                type="button"
                @click="backToEmail"
                class="inline-flex items-center gap-1 text-white/80 hover:text-white transition-colors cursor-pointer"
            >
                <ArrowLeft class="size-3.5" />
                <span>Back</span>
            </button>

            <button
                type="button"
                @click="handleResendOtp"
                :disabled="resendCooldown > 0 || emailForm.processing"
                :class="[
                    'inline-flex items-center gap-1.5 font-semibold transition-colors cursor-pointer',
                    resendCooldown > 0 || emailForm.processing
                        ? 'text-white/40 cursor-not-allowed'
                        : 'text-[#F8B803] hover:underline'
                ]"
            >
                <RefreshCw :class="['size-3.5', emailForm.processing ? 'animate-spin' : '']" />
                <span v-if="resendCooldown > 0">Resend in {{ resendCooldown }}s</span>
                <span v-else-if="emailForm.processing">Sending...</span>
                <span v-else>Resend Code</span>
            </button>
        </div>

        <div class="text-center text-xs text-white/80 pt-0.5">
            <span>Remember your password?</span>
            <TextLink :href="login()" class="ml-1 font-semibold text-[#F8B803] hover:underline">
                Return to log in
            </TextLink>
        </div>
    </div>

    <!-- PART 3: New Password & Confirm (Following Password Format) -->
    <div v-else class="space-y-3">
        <!-- Verified Success Pill -->
        <div class="flex items-center justify-between rounded-xl border border-emerald-400/30 bg-emerald-950/40 px-3 py-1.5 text-xs text-emerald-200">
            <div class="flex items-center gap-1.5 truncate pr-2">
                <CheckCircle2 class="size-3.5 text-emerald-400 shrink-0" />
                <span class="truncate">Code verified for <strong class="text-white">{{ resetForm.email }}</strong></span>
            </div>
            <button
                type="button"
                @click="backToOtp"
                class="shrink-0 font-semibold text-[#F8B803] hover:underline cursor-pointer text-xs"
            >
                Change Code
            </button>
        </div>

        <form @submit.prevent="submitResetPassword" class="space-y-2.5">
            <!-- Floating Label: New Password -->
            <div>
                <div class="relative">
                    <input
                        id="password"
                        v-model="resetForm.password"
                        :type="showPassword ? 'text' : 'password'"
                        name="password"
                        autocomplete="new-password"
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pr-11 pb-2 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:text-sm"
                        :class="resetForm.errors.password ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="password"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                    >
                        New Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3.5 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        :aria-pressed="showPassword"
                    >
                        <EyeOff v-if="showPassword" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError :message="resetForm.errors.password" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Floating Label: Confirm New Password -->
            <div>
                <div class="relative">
                    <input
                        id="password_confirmation"
                        v-model="resetForm.password_confirmation"
                        :type="showConfirmPassword ? 'text' : 'password'"
                        name="password_confirmation"
                        autocomplete="new-password"
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pr-11 pb-2 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:text-sm"
                        :class="resetForm.errors.password_confirmation ? 'border-red-400 focus:border-red-400 focus:ring-red-400' : ''"
                    />
                    <label
                        for="password_confirmation"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                    >
                        Confirm New Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showConfirmPassword = !showConfirmPassword"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3.5 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                        :aria-pressed="showConfirmPassword"
                    >
                        <EyeOff v-if="showConfirmPassword" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError :message="resetForm.errors.password_confirmation" class="mt-1 text-xs text-red-300" />
            </div>

            <!-- Password Format Requirements Checklist -->
            <div class="space-y-1.5 rounded-xl border border-white/20 bg-white/10 p-2.5 text-xs text-white/90">
                <p class="text-[10px] font-semibold text-[#F8B803]">
                    Password Format Requirements
                </p>
                <ul class="grid grid-cols-1 gap-1 text-[11px] text-white/90 sm:grid-cols-2">
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="lengthValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="lengthValid ? 'text-[#F8B803]' : 'text-white/40'"
                        />
                        <span :class="lengthValid ? 'font-medium text-white' : 'text-white/70'">
                            At least 12 characters
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="upperLowerValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="upperLowerValid ? 'text-[#F8B803]' : 'text-white/40'"
                        />
                        <span :class="upperLowerValid ? 'font-medium text-white' : 'text-white/70'">
                            Upper & lowercase
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="numberValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="numberValid ? 'text-[#F8B803]' : 'text-white/40'"
                        />
                        <span :class="numberValid ? 'font-medium text-white' : 'text-white/70'">
                            At least one number
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="symbolValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="symbolValid ? 'text-[#F8B803]' : 'text-white/40'"
                        />
                        <span :class="symbolValid ? 'font-medium text-white' : 'text-white/70'">
                            At least one symbol
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5 sm:col-span-2">
                        <component
                            :is="matchValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="matchValid ? 'text-[#F8B803]' : 'text-white/40'"
                        />
                        <span :class="matchValid ? 'font-medium text-white' : 'text-white/70'">
                            Passwords match
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Submit Button -->
            <Button
                type="submit"
                class="w-full cursor-pointer bg-[#F8B803] font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 h-10 sm:h-11 rounded-xl text-sm mt-1"
                :disabled="resetForm.processing || !isPasswordValid"
                data-test="reset-password-final-button"
            >
                <Spinner v-if="resetForm.processing" class="mr-2 text-gray-950" />
                <span v-if="resetForm.processing">Updating Password...</span>
                <span v-else class="flex items-center justify-center gap-2">
                    <Lock class="size-4" />
                    Reset Password & Access Account
                </span>
            </Button>
        </form>

        <!-- Footer -->
        <div class="border-t border-white/20 pt-2 flex items-center justify-between text-xs">
            <button
                type="button"
                @click="backToOtp"
                class="inline-flex items-center gap-1 text-white/80 hover:text-white transition-colors cursor-pointer"
            >
                <ArrowLeft class="size-3.5" />
                <span>Back to Code</span>
            </button>

            <TextLink :href="login()" class="font-semibold text-[#F8B803] hover:underline">
                Return to log in
            </TextLink>
        </div>
    </div>
</template>
