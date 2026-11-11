<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { Check, CheckCircle2 } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(
    () =>
        page.props.auth.user as {
            name: string;
            email: string;
            email_verified_at?: string;
        },
);

const form = useForm({
    name: user.value?.name ?? '',
    email: user.value?.email ?? '',
});

// Sync if user data changes in page props
watch(
    () => user.value,
    (newUser) => {
        if (newUser && !form.isDirty) {
            form.name = newUser.name;
            form.email = newUser.email;
        }
    },
    { deep: true },
);

const showConfirmModal = ref(false);

const promptSaveProfile = () => {
    form.clearErrors();
    let hasError = false;
    if (!form.name.trim()) {
        form.setError('name', 'The name field is required.');
        hasError = true;
    }
    if (!form.email.trim()) {
        form.setError('email', 'The email field is required.');
        hasError = true;
    }
    if (hasError) return;

    showConfirmModal.value = true;
};

const confirmSaveProfile = () => {
    form.patch(ProfileController.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showConfirmModal.value = false;
        },
        onError: () => {
            showConfirmModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <form @submit.prevent="promptSaveProfile" class="space-y-6">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    variant="brand-gold"
                    class="cursor-pointer font-semibold text-gray-950 transition-colors hover:bg-[#fac52e]"
                    :disabled="form.processing"
                    data-test="update-profile-button"
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
                        Saved successfully.
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
                <CheckCircle2 class="h-6 w-6" />
            </div>
            <h3 class="mb-1.5 text-xl font-semibold text-gray-900">
                Save Profile Changes?
            </h3>
            <p class="mb-4 text-sm leading-relaxed text-gray-600">
                Are you sure you want to update your profile information?
            </p>

            <div
                class="mb-5 space-y-2 rounded-xl border border-gray-100 bg-gray-50 p-3.5 text-xs"
            >
                <div
                    class="flex items-center justify-between border-b border-gray-100 py-1"
                >
                    <span class="font-medium text-gray-500">Name</span>
                    <span class="font-semibold text-gray-900">{{
                        form.name
                    }}</span>
                </div>
                <div class="flex items-center justify-between py-1">
                    <span class="font-medium text-gray-500">Email</span>
                    <span class="font-semibold text-gray-900">{{
                        form.email
                    }}</span>
                </div>
            </div>

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
                    @click="confirmSaveProfile"
                    :disabled="form.processing"
                >
                    <Check class="mr-1.5 inline h-4 w-4" />
                    <span>Confirm & Save</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
