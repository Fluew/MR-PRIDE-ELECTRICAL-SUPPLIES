<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { TeamInvitationContext } from '@/types';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    teamInvitation?: TeamInvitationContext | null;
}>();

const showPassword = ref(false);
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-emerald-300"
    >
        {{ status }}
    </div>

    <TeamInvitationAlert
        v-if="teamInvitation"
        :invitation="teamInvitation"
        action="Log in"
    />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <!-- Floating Label: Email address -->
            <div>
                <div class="relative">
                    <input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:text-sm"
                        :class="
                            errors.email
                                ? 'border-red-400 focus:border-red-400 focus:ring-red-400'
                                : ''
                        "
                    />
                    <label
                        for="email"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                    >
                        Email address
                    </label>
                </div>
                <InputError
                    :message="errors.email"
                    class="mt-1.5 text-xs text-red-300"
                />
            </div>

            <!-- Floating Label: Password -->
            <div>
                <div class="relative">
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pr-11 pb-2 text-xs text-gray-900 placeholder-transparent focus:border-[#F8B803] focus:ring-2 focus:ring-[#F8B803]/40 focus:outline-none sm:text-sm"
                        :class="
                            errors.password
                                ? 'border-red-400 focus:border-red-400 focus:ring-red-400'
                                : ''
                        "
                    />
                    <label
                        for="password"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-600 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-placeholder-shown:text-black peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                    >
                        Password
                    </label>
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                        class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-3.5 text-gray-500 transition-colors hover:text-black focus:outline-none"
                        :aria-label="
                            showPassword ? 'Hide password' : 'Show password'
                        "
                        :aria-pressed="showPassword"
                    >
                        <EyeOff v-if="showPassword" class="size-4 shrink-0" />
                        <Eye v-else class="size-4 shrink-0" />
                    </button>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <InputError
                        :message="errors.password"
                        class="text-xs text-red-300"
                    />
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="ml-auto text-xs text-white/80 hover:text-white hover:underline"
                    >
                        Forgot password?
                    </TextLink>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <Label
                    for="remember"
                    class="flex cursor-pointer items-center space-x-3 text-white/90"
                >
                    <Checkbox
                        id="remember"
                        name="remember"
                        class="border-white/50 data-[state=checked]:bg-white data-[state=checked]:text-[#4b5320]"
                    />
                    <span class="text-xs sm:text-sm">Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-2 w-full cursor-pointer bg-[#F8B803] font-semibold text-gray-950 shadow-md transition-colors hover:bg-[#fac52e] focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" class="text-gray-950" />
                Log in
            </Button>
        </div>
    </Form>
</template>
