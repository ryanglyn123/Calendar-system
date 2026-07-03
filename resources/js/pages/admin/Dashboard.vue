<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, UserCheck, Users } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import admin from '@/routes/admin';
import adminPending from '@/routes/admin/pending-registrations';
import adminUsers from '@/routes/admin/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin', href: admin.dashboard() }],
    },
});

defineProps<{
    stats: {
        total_users: number;
        pending_users: number;
        divisions: number;
    };
}>();
</script>

<template>
    <Head title="Admin Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription class="flex items-center gap-2">
                        <Users class="size-4" aria-hidden="true" />
                        Total users
                    </CardDescription>
                    <CardTitle class="text-3xl">
                        {{ stats.total_users }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <Link
                        :href="adminUsers.index()"
                        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Manage users
                    </Link>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription class="flex items-center gap-2">
                        <UserCheck class="size-4" aria-hidden="true" />
                        Pending registrations
                    </CardDescription>
                    <CardTitle class="text-3xl">
                        {{ stats.pending_users }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <Link
                        :href="adminPending.index()"
                        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Review requests
                    </Link>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription class="flex items-center gap-2">
                        <Building2 class="size-4" aria-hidden="true" />
                        Active divisions
                    </CardDescription>
                    <CardTitle class="text-3xl">
                        {{ stats.divisions }}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>
    </div>
</template>
