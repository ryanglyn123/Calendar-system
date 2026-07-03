<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { format } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminAudit from '@/routes/admin/audit';
import type { PaginationLink } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Audit Log', href: adminAudit.index() },
        ],
    },
});

type ActivityRow = {
    id: number;
    description: string;
    event: string | null;
    causer: string | null;
    subject_type: string | null;
    subject_id: number | null;
    changes: Record<string, unknown>;
    created_at: string;
};

const props = defineProps<{
    activities: {
        data: ActivityRow[];
        links: PaginationLink[];
    };
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');

const applySearch = useDebounceFn(() => {
    router.get(
        adminAudit.index().url,
        { search: search.value || undefined },
        { preserveState: true, replace: true },
    );
}, 300);

watch(search, applySearch);

const paginationLabel = (label: string) =>
    label
        .replace('&laquo;', '«')
        .replace('&raquo;', '»')
        .replace('&hellip;', '…');
</script>

<template>
    <Head title="Audit Log" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Audit Log"
            description="Every administrative action, recorded and searchable."
        >
            <template #actions>
                <div class="relative max-w-xs">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search activity…"
                        class="pl-8"
                        aria-label="Search audit log"
                    />
                </div>
            </template>
        </PageHeader>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>Who</TableHead>
                        <TableHead>What</TableHead>
                        <TableHead>Subject</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty
                        v-if="activities.data.length === 0"
                        :colspan="4"
                    >
                        Nothing recorded yet.
                    </TableEmpty>
                    <TableRow
                        v-for="activity in activities.data"
                        :key="activity.id"
                    >
                        <TableCell
                            class="whitespace-nowrap text-muted-foreground"
                        >
                            {{
                                format(
                                    new Date(activity.created_at),
                                    'MMM d, HH:mm',
                                )
                            }}
                        </TableCell>
                        <TableCell>{{ activity.causer ?? 'System' }}</TableCell>
                        <TableCell>
                            <span class="inline-flex items-center gap-2">
                                <Badge
                                    v-if="activity.event"
                                    variant="outline"
                                >
                                    {{ activity.event }}
                                </Badge>
                                {{ activity.description }}
                            </span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            <template v-if="activity.subject_type">
                                {{ activity.subject_type }} #{{
                                    activity.subject_id
                                }}
                            </template>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div class="flex items-center justify-center gap-1">
            <template v-for="(link, i) in activities.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted'
                    "
                >
                    {{ paginationLabel(link.label) }}
                </Link>
            </template>
        </div>
    </div>
</template>
