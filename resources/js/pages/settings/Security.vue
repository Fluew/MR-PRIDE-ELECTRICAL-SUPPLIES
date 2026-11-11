<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import { Check, ShieldAlert } from '@lucide/vue';

type Props = {
    passwordRules: string;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const showConfirmModal = ref(false);

const promptSavePassword = () => {
    form.clearErrors();
    let hasError = false;
    if (!form.current_password) {
        form.setError('current_password', 'The current password field is required.');
        hasError = true;
    }
    if (!form.password) {
        form.setError('password', 'The new password field is required.');
        hasError = true;
    }
    if (!form.password_confirmation) {
        form.setError('password_confirmation', 'The confirmation password field is required.');
        hasError = true;
    }
    if (form.password && form.password_confirmation && form.password !== form.password_confirmation) {
        form.setError('password_confirmation', 'The password confirmation does not match.');
        hasError = true;
    }
    if (hasError) return;

    showConfirmModal.value = true;
};

const confirmSavePassword = () => {
    form.put(SecurityController.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showConfirmModal.value = false;
            form.reset();
        },
        onError: () => {
            showConfirmModal.value = false;
            form.reset('password', 'password_confirmation', 'current_password');
        },
    });
};
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <form @submit.prevent="promptSavePassword" class="space-y-6">
            <div class="grid gap-2">
                <Label for="current_password">Current password</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    v-model="form.current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                />
                <InputError :message="form.errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    v-model="form.password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="New password"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    v-model="form.password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    variant="brand-gold"
                    class="cursor-pointer font-semibold text-gray-950 transition-colors hover:bg-[#fac52e]"
                    :disabled="form.processing"
                    data-test="update-password-button"
                >
                    Save
                </Button>

                <Transition
                    enter-active-class="transition ease-in-out duration-300"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out duration-300"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-xs font-semibold text-emerald-600"
                    >
                        Password updated successfully.
                    </p>
                </Transition>
            </div>
        </form>
    </div>

    <!-- Confirmation Modal -->
    <div
        v-if="showConfirmModal"
        class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 p-4"
    >
        <div
            class="w-full max-w-md animate-in fade-in rounded-xl border border-gray-200 bg-white p-6 shadow-xl duration-150"
        >
            <div
                class="mb-4 flex h-11 w-11 items-center justify-center rounded-full border border-amber-100 bg-amber-50 text-[#F8B803]"
            >
                <ShieldAlert class="h-6 w-6" />
            </div>
            <h3 class="mb-1.5 text-xl font-semibold text-gray-900">
                Update Password?
            </h3>
            <p class="mb-4 text-sm leading-relaxed text-gray-600">
                Are you sure you want to change your password? All other active sessions on other devices will be terminated for security.
            </p>

            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showConfirmModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand-gold"
                    size="md"
                    :radius="10"
                    class="font-semibold text-gray-950"
                    @click="confirmSavePassword"
                    :disabled="form.processing"
                >
                    <Check class="mr-1.5 inline h-4 w-4" />
                    <span>Confirm & Update</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
