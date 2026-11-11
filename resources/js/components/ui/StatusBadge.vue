<script setup lang="ts">
import { computed } from 'vue';

export type StatusVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand' | 'brand-gold';

const props = withDefaults(
    defineProps<{
        status?: string | number;
        variant?: StatusVariant;
        dot?: boolean;
        size?: 'sm' | 'md';
        class?: string;
    }>(),
    {
        dot: true,
        size: 'sm',
    }
);

// Map common status strings across all MR-Pride modules to semantic variants
const resolvedVariant = computed<StatusVariant>(() => {
    if (props.variant) return props.variant;
    if (!props.status) return 'neutral';

    const normalized = String(props.status).trim().toLowerCase();

    // Success / positive
    if (['in stock', 'delivered', 'completed', 'approved', 'active', 'verified', 'safe', 'converted', 'received', 'success', 'paid',
        'accepted', 'converted to sales order', 'confirmed', 'picked up', 'issued'].includes(normalized)) {
        return 'success';
    }

    // Warning / attention required
    if (['low stock', 'pending', 'pending payment', 'pending inspection', 'pending review', 'needs reorder', 'in transit', 'soon', 'warning', 'partially received',
        'for approval', 'awaiting supplier pricing', 'payment submitted', 'under verification', 'on hold', 'order confirmed', 'preparing order', 'unpaid'].includes(normalized)) {
        return 'warning';
    }

    // Danger / destructive
    if (['out of stock', 'critical low', 'cancelled', 'canceled', 'rejected', 'expired', 'revoked', 'urgent', 'danger', 'failed', 'inactive', 'deactivated', 'deleted',
        'payment rejected', 'delivery failed', 'delivery attempt failed', 'returned'].includes(normalized)) {
        return 'danger';
    }

    // Informational / blue
    if (['sent', 'ordered', 'submitted', 'in progress', 'info', 'reviewing',
        'under review', 'under preparation', 'preparing', 'sent to customer', 'ready for delivery', 'processing', 'partially paid', 'ready for dispatch', 'ready for pickup',
        'out for delivery', 'partially delivered', 'partially picked up', 'delivery rescheduled', 'refunded'].includes(normalized)) {
        return 'info';
    }

    // Brand accent
    if (['administrator', 'admin', 'owner', 'item mode'].includes(normalized)) {
        return 'brand';
    }

    if (['category mode', 'special', 'featured', 'gold'].includes(normalized)) {
        return 'brand-gold';
    }

    return 'neutral';
});

const variantStyles = computed(() => {
    switch (resolvedVariant.value) {
        case 'success':
            return {
                badge: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
                dot: 'bg-emerald-500',
            };
        case 'warning':
            return {
                badge: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
                dot: 'bg-amber-500',
            };
        case 'danger':
            return {
                badge: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
                dot: 'bg-rose-500',
            };
        case 'info':
            return {
                badge: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
                dot: 'bg-blue-500',
            };
        case 'brand':
            return {
                badge: 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
                dot: 'bg-[#4b5320]',
            };
        case 'brand-gold':
            return {
                badge: 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
                dot: 'bg-[#F8B803]',
            };
        case 'neutral':
        default:
            return {
                badge: 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
                dot: 'bg-gray-400',
            };
    }
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 font-medium border rounded-full whitespace-nowrap select-none',
            size === 'md' ? 'px-3 py-1 text-xs' : 'px-2.5 py-0.5 text-[11px]',
            variantStyles.badge,
            props.class
        ]"
    >
        <span
            v-if="dot"
            :class="['size-1.5 rounded-full shrink-0', variantStyles.dot]"
            aria-hidden="true"
        />
        <slot>{{ status }}</slot>
    </span>
</template>
