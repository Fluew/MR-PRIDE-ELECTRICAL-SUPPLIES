<script setup lang="ts">
/**
 * Reusable confirmation dialog for destructive or important actions.
 *
 * Usage:
 *   <ConfirmDialog v-model:open="showConfirm" title="Delete product?" :loading="processing"
 *       description="This action cannot be undone." confirm-label="Delete" variant="destructive"
 *       @confirm="handleDelete" />
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Variant = 'destructive' | 'warning' | 'default';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title?: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        variant?: Variant;
        loading?: boolean;
    }>(),
    {
        title: 'Are you sure?',
        description: 'This action cannot be undone.',
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        variant: 'destructive',
        loading: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();

const close = () => emit('update:open', false);
const confirm = () => emit('confirm');

const confirmVariant = (v: Variant) => {
    if (v === 'destructive') return 'destructive' as const;
    if (v === 'warning') return 'default' as const;
    return 'default' as const;
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2">
                <Button variant="secondary" :disabled="loading" @click="close">{{ cancelLabel }}</Button>
                <Button :variant="confirmVariant(variant)" :disabled="loading" @click="confirm">
                    <svg v-if="loading" class="mr-2 size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
