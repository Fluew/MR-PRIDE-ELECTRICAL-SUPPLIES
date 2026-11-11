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
import { destroy as destroyMember } from '@/routes/teams/members';
import type { Team, TeamMember } from '@/types';

type Props = {
    team: Team;
    member: TeamMember | null;
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

const removeMember = () => {
    if (!props.member) {
        return;
    }

    router.visit(destroyMember([props.team.slug, props.member.id]), {
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
                <DialogTitle>Remove team member</DialogTitle>
                <DialogDescription>
                    Are you sure you want to remove
                    <strong>{{ props.member?.name }}</strong> from this team?
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
                    <Button variant="secondary"> Cancel </Button>
                </DialogClose>

                <Button
                    data-test="remove-member-confirm"
                    variant="destructive"
                    :disabled="processing || deleteConfirmText.trim().toUpperCase() !== 'YES'"
                    @click="removeMember"
                >
                    Remove member
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
