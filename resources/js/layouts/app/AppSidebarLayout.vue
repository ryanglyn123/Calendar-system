<script setup lang="ts">
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ChatDock from '@/components/chat/ChatDock.vue';
import ChatNewGroupDialog from '@/components/chat/ChatNewGroupDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <AnnouncementBanner />
            <slot />
        </AppContent>
        <Toaster />
        <ChatDock />
        <ChatNewGroupDialog />
    </AppShell>
</template>
