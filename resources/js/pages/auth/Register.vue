<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { TeamInvitationContext } from '@/types';

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});

defineProps<{
    teamInvitation?: TeamInvitationContext | null;
}>();
</script>

<template>
    <Head title="Register" />

    <TeamInvitationAlert
        v-if="teamInvitation"
        :invitation="teamInvitation"
        action="Register"
    />

    <Form
        action="/register"
        method="post"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Full Name</Label>
                <Input
                    id="name"
                    type="text"
                    name="name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="John Doe"
                    class="bg-white/10 border-white/20 text-white placeholder:text-white/70 focus:border-white focus-visible:ring-white/50"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                    class="bg-white/10 border-white/20 text-white placeholder:text-white/70 focus:border-white focus-visible:ring-white/50"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Password"
                    class="bg-white/10 border-white/20 text-white placeholder:text-white/70 focus:border-white focus-visible:ring-white/50"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm Password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm Password"
                    class="bg-white/10 border-white/20 text-white placeholder:text-white/70 focus:border-white focus-visible:ring-white/50"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full bg-white text-[#4b5320] font-semibold hover:bg-gray-100 transition-colors"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>

            <div class="text-center text-sm text-white/80">
                Already have an account?
                <TextLink href="/login" class="text-white font-semibold underline underline-offset-4 ml-1">
                    Log in
                </TextLink>
            </div>
        </div>
    </Form>
</template>
