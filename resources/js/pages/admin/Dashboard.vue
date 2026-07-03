<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, UserCheck, Users } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import admin from '@/routes/admin';
import adminDivisions from '@/routes/admin/divisions';
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

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Admin Dashboard"
            description="Accounts, divisions and access at a glance."
        />

        <!-- What needs attention right now -->
        <Link
            :href="adminPending.index()"
            class="group flex items-center justify-between gap-4 rounded-xl border p-5 transition-colors"
            :class="
                stats.pending_users > 0
                    ? 'border-primary/40 bg-primary/10 hover:bg-primary/15'
                    : 'bg-card hover:bg-muted/50'
            "
        >
            <div class="flex items-center gap-4">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                    :class="
                        stats.pending_users > 0
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    <UserCheck class="size-5" aria-hidden="true" />
                </span>
                <div class="space-y-0.5">
                    <p class="text-sm font-semibold">
                        {{
                            stats.pending_users > 0
                                ? `${stats.pending_users} registration${stats.pending_users === 1 ? '' : 's'} waiting for review`
                                : 'No registrations waiting'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            stats.pending_users > 0
                                ? 'New sign-ups need approval and a division before they can book.'
                                : "You're all caught up."
                        }}
                    </p>
                </div>
            </div>
            <ArrowRight
                class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </Link>

        <!-- Standing totals — one panel, hairline-divided, both cells navigable -->
        <div
            class="grid gap-px overflow-hidden rounded-xl border bg-border sm:grid-cols-2"
        >
            <Link
                :href="adminUsers.index()"
                class="flex flex-col gap-2 bg-card p-5 transition-colors hover:bg-muted/40"
            >
                <span
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Users class="size-4" aria-hidden="true" />
                    Total users
                </span>
                <span class="text-3xl font-semibold tabular-nums">
                    {{ stats.total_users }}
                </span>
            </Link>

            <Link
                :href="adminDivisions.index()"
                class="flex flex-col gap-2 bg-card p-5 transition-colors hover:bg-muted/40"
            >
                <span
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Building2 class="size-4" aria-hidden="true" />
                    Active divisions
                </span>
                <span class="text-3xl font-semibold tabular-nums">
                    {{ stats.divisions }}
                </span>
            </Link>
        </div>
    </div>
</template>
