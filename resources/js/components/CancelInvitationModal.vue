<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy as destroyInvitation } from '@/routes/teams/invitations';
import type { Team, TeamInvitation } from '@/types';

type Props = {
    team: Team;
    invitation: TeamInvitation | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);
const deleteConfirmText = ref('');

watch(() => props.open, (newVal) => {
    if (!newVal) {
        deleteConfirmText.value = '';
    }
});

const cancelInvitation = () => {
    if (!props.invitation) {
        return;
    }

    router.visit(destroyInvitation([props.team.slug, props.invitation.code]), {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel invitation</DialogTitle>
                <DialogDescription>
                    Are you sure you want to cancel the invitation for
                    <strong>{{ props.invitation?.email }}</strong
                    >?
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2 py-2">
                <Label class="text-xs font-semibold">
                    Type <span class="font-mono font-bold text-rose-600">YES</span> if you wish to delete:
                </Label>
                <Input
                    v-model="deleteConfirmText"
                    type="text"
                    placeholder="Type YES here"
                    class="font-mono text-sm"
                />
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> Keep invitation </Button>
                </DialogClose>

                <Button
                    data-test="cancel-invitation-confirm"
                    variant="destructive"
                    :disabled="processing || deleteConfirmText.trim().toUpperCase() !== 'YES'"
                    @click="cancelInvitation"
                >
                    Cancel invitation
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
