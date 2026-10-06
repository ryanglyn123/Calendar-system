<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import DarkModeToggle from '@/components/DarkModeToggle.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import ReviewAttention from '@/components/ReviewAttention.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <ReviewAttention />
            <DarkModeToggle />
            <NotificationBell />
        </div>
    </header>
</template>
