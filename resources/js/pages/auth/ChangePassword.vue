<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/InputError.vue';
import {
    ShieldAlert,
    KeyRound,
    CheckCircle2,
    XCircle,
    LogOut,
    Eye,
    EyeOff,
} from '@lucide/vue';

defineOptions({
    layout: {
        title: 'Mandatory Password Update',
        description: 'Secure your account with a strong permanent password',
    },
});

const props = defineProps<{
    reason: string;
    isTemporary: boolean;
    notice?: string;
}>();

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirm = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const lengthValid = computed(() => form.password.length >= 12);
const upperLowerValid = computed(
    () => /[a-z]/.test(form.password) && /[A-Z]/.test(form.password),
);
const numberValid = computed(() => /[0-9]/.test(form.password));
const symbolValid = computed(() => /[^A-Za-z0-9]/.test(form.password));
const matchValid = computed(
    () =>
        form.password.length > 0 &&
        form.password === form.password_confirmation,
);

const isFormValid = computed(
    () =>
        lengthValid.value &&
        upperLowerValid.value &&
        numberValid.value &&
        symbolValid.value &&
        matchValid.value &&
        form.current_password.length > 0,
);

const submit = () => {
    form.put('/password/change', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Update Password | MR-Pride" />

    <div class="space-y-4 sm:space-y-4.5">
        <!-- Notice Banner -->
        <div
            class="flex items-start gap-3 rounded-xl border border-white/20 bg-white/10 p-3 text-white sm:p-3.5"
        >
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#F8B803] font-semibold text-gray-950 sm:size-8.5"
            >
                <ShieldAlert class="size-4.5" />
            </div>
            <div class="min-w-0 flex-1">
                <h4 class="text-xs font-semibold text-white sm:text-sm">
                    {{
                        isTemporary
                            ? 'Initial Temporary Password Change'
                            : '90-Day Password Rotation Policy'
                    }}
                </h4>
                <p
                    class="mt-0.5 text-[11px] leading-relaxed text-white/80 sm:text-xs"
                >
                    {{ reason }}
                </p>
            </div>
        </div>

        <!-- Password Form with Floating Labels -->
        <form @submit.prevent="submit" class="space-y-3 sm:space-y-3.5">
            <!-- Current / Temporary Password -->
            <div>
                <div class="relative">
                    <input
                        id="current_password"
                        v-model="form.current_password"
                        :type="showCurrent ? 'text' : 'password'"
                        name="current_password"
                        required
                        autofocus
                        autocomplete="current-password"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-11 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="
                            form.errors.current_password
                                ? 'border-red-400 focus:border-red-400 focus:ring-red-400'
                                : ''
                        "
                    />
                    <label
                        for="current_password"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        {{
                            isTemporary
                                ? 'Current / Temporary Password'
                                : 'Old Password'
                        }}
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showCurrent = !showCurrent"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="
                            showCurrent ? 'Hide password' : 'Show password'
                        "
                        :aria-pressed="showCurrent"
                    >
                        <EyeOff v-if="showCurrent" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError
                    :message="form.errors.current_password"
                    class="mt-1 text-xs text-red-300"
                />
            </div>

            <!-- New Password -->
            <div>
                <div class="relative">
                    <input
                        id="new_password"
                        v-model="form.password"
                        :type="showNew ? 'text' : 'password'"
                        name="password"
                        required
                        autocomplete="new-password"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-11 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="
                            form.errors.password
                                ? 'border-red-400 focus:border-red-400 focus:ring-red-400'
                                : ''
                        "
                    />
                    <label
                        for="new_password"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        New Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showNew = !showNew"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="
                            showNew ? 'Hide password' : 'Show password'
                        "
                        :aria-pressed="showNew"
                    >
                        <EyeOff v-if="showNew" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError
                    :message="form.errors.password"
                    class="mt-1 text-xs text-red-300"
                />
            </div>

            <!-- Confirm New Password -->
            <div>
                <div class="relative">
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showConfirm ? 'text' : 'password'"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder=" "
                        :disabled="form.processing"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-4.5 pr-11 pb-1.5 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:pt-5 sm:pb-2 sm:text-sm"
                        :class="
                            form.errors.password_confirmation
                                ? 'border-red-400 focus:border-red-400 focus:ring-red-400'
                                : ''
                        "
                    />
                    <label
                        for="password_confirmation"
                        class="pointer-events-none absolute top-3 left-3.5 origin-[0] -translate-y-2 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2 peer-focus:scale-75 peer-focus:text-[#4b5320] sm:top-3.5 sm:-translate-y-2.5 sm:peer-focus:-translate-y-2.5"
                    >
                        Confirm New Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showConfirm = !showConfirm"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="
                            showConfirm ? 'Hide password' : 'Show password'
                        "
                        :aria-pressed="showConfirm"
                    >
                        <EyeOff v-if="showConfirm" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <InputError
                    :message="form.errors.password_confirmation"
                    class="mt-1 text-xs text-red-300"
                />
            </div>

            <!-- Password Policy Indicators -->
            <div
                class="space-y-1.5 rounded-xl border border-white/20 bg-white/10 p-3 text-xs text-white/90"
            >
                <p
                    class="text-[10px] font-semibold text-[#F8B803]"
                >
                    Password Requirements
                </p>
                <ul
                    class="grid grid-cols-1 gap-1 text-[11px] text-white/90 sm:grid-cols-2"
                >
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="lengthValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="
                                lengthValid ? 'text-[#F8B803]' : 'text-white/40'
                            "
                        />
                        <span
                            :class="
                                lengthValid
                                    ? 'font-medium text-white'
                                    : 'text-white/70'
                            "
                        >
                            At least 12 characters
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="upperLowerValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="
                                upperLowerValid
                                    ? 'text-[#F8B803]'
                                    : 'text-white/40'
                            "
                        />
                        <span
                            :class="
                                upperLowerValid
                                    ? 'font-medium text-white'
                                    : 'text-white/70'
                            "
                        >
                            Upper & lowercase
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="numberValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="
                                numberValid ? 'text-[#F8B803]' : 'text-white/40'
                            "
                        />
                        <span
                            :class="
                                numberValid
                                    ? 'font-medium text-white'
                                    : 'text-white/70'
                            "
                        >
                            At least one number
                        </span>
                    </li>
                    <li class="flex items-center gap-1.5">
                        <component
                            :is="symbolValid ? CheckCircle2 : XCircle"
                            class="size-3.5 shrink-0"
                            :class="
                                symbolValid ? 'text-[#F8B803]' : 'text-white/40'
                            "
                        />
                        <span
                            :class="
                                symbolValid
                                    ? 'font-medium text-white'
                                    : 'text-white/70'
                            "
                        >
                            At least one symbol
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Submit Button -->
            <Button
                type="submit"
                variant="brand"
                class="mt-1.5 w-full cursor-pointer font-semibold text-white shadow-md transition-colors focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="form.processing || !isFormValid"
            >
                <Spinner v-if="form.processing" class="mr-2 text-white" />
                <KeyRound v-else class="mr-2 size-4 text-white" />
                Update Password & Access Dashboard
            </Button>
        </form>

        <!-- Sign Out Link -->
        <div class="border-t border-white/20 pt-3 text-center">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-white/80 transition-colors hover:text-white"
            >
                <LogOut class="size-3.5" />
                Sign out
            </Link>
        </div>
    </div>
</template>
