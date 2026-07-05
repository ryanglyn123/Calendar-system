<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useAuth } from '@/composables/useAuth';
import { ADMIN_SETTINGS_SECTIONS } from '@/lib/adminSettingsNav';
import admin from '@/routes/admin';
import adminSettings from '@/routes/admin/settings';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Settings', href: adminSettings.index() },
        ],
    },
});

const { hasPermission } = useAuth();

const sections = computed(() =>
    ADMIN_SETTINGS_SECTIONS.map((section) => ({
        ...section,
        links: section.links.filter((link) => hasPermission(link.permission)),
    })).filter((section) => section.links.length > 0),
);
</script>

<template>
    <Head title="Settings" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-lg font-semibold">Settings</h1>
            <p class="text-sm text-muted-foreground">
                Configuration for scheduling, resources, access, and the system.
            </p>
        </div>

        <section
            v-for="section in sections"
            :key="section.title"
            class="flex flex-col gap-3"
        >
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ section.title }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="link in section.links"
                    :key="link.title"
                    :href="link.href"
                    class="group rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Card
                        class="h-full transition-colors group-hover:bg-accent/50"
                    >
                        <CardHeader class="flex flex-row items-center gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background"
                            >
                                <component
                                    :is="link.icon"
                                    class="size-4 text-muted-foreground"
                                />
                            </div>
                            <div class="grid gap-1">
                                <CardTitle class="text-sm">
                                    {{ link.title }}
                                </CardTitle>
                                <CardDescription class="text-xs">
                                    {{ link.description }}
                                </CardDescription>
                            </div>
                        </CardHeader>
                    </Card>
                </Link>
            </div>
        </section>
    </div>
</template>
