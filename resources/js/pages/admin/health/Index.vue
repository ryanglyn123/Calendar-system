<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import admin from '@/routes/admin';
import adminHealth from '@/routes/admin/health';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'System Health', href: adminHealth.index() },
        ],
    },
});

type FailedJob = {
    id: number;
    queue: string;
    failed_at: string;
    summary: string;
};

defineProps<{
    queuedJobs: number;
    failedJobs: FailedJob[];
}>();

const retryAll = () =>
    router.post(adminHealth.retry().url, {}, { preserveScroll: true });
</script>

<template>
    <Head title="System Health" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="grid gap-4 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardDescription>Jobs waiting in queue</CardDescription>
                    <CardTitle class="text-3xl">{{ queuedJobs }}</CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Recent failed jobs</CardDescription>
                    <CardTitle class="text-3xl">
                        {{ failedJobs.length }}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Emails and reminders run through the queue; a worker
                (`php artisan queue:work`) must stay running in production.
            </p>
            <Button
                v-if="failedJobs.length > 0"
                variant="outline"
                @click="retryAll"
            >
                <RefreshCw aria-hidden="true" />
                Retry all failed
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Failed at</TableHead>
                        <TableHead>Queue</TableHead>
                        <TableHead>Error</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="failedJobs.length === 0" :colspan="3">
                        No failed jobs. Everything is flowing.
                    </TableEmpty>
                    <TableRow v-for="job in failedJobs" :key="job.id">
                        <TableCell class="whitespace-nowrap">
                            {{ job.failed_at }}
                        </TableCell>
                        <TableCell>{{ job.queue }}</TableCell>
                        <TableCell
                            class="max-w-xl truncate font-mono text-xs text-muted-foreground"
                        >
                            {{ job.summary }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
