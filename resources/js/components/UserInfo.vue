<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { Team, User } from '@/types';

type Props = {
    user: User;
    showEmail?: boolean;
    team?: Team | null;
};

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
    team: null,
});

const { getInitials } = useInitials();

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <Avatar class="h-8 w-8 shrink-0 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback class="rounded-lg text-black dark:text-white font-semibold">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div
        class="grid flex-1 text-left text-sm leading-tight overflow-hidden transition-all duration-300 ease-in-out group-data-[collapsible=icon]:hidden group-data-[collapsible=icon]:w-0 group-data-[collapsible=icon]:opacity-0"
    >
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="team" class="truncate text-xs text-muted-foreground">{{
            team.name
        }}</span>
        <span
            v-else-if="showEmail"
            class="truncate text-xs text-muted-foreground"
            >{{ user.email }}</span
        >
    </div>
</template>
