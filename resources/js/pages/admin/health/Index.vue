<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
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

type GoogleHealth = {
    oauth_configured: boolean;
    api_key_configured: boolean;
    push_available: boolean;
    webhook_url: string;
    redirect_url: string;
    accounts: number;
    accounts_needing_reconnect: number;
    calendars_synced: number;
    calendars_with_errors: number;
    channels_active: number;
    last_sync_at: string | null;
    booking_copies_failed: number;
    holidays_synced_at: string | null;
    holidays_error: string | null;
};

defineProps<{
    queuedJobs: number;
    failedJobs: FailedJob[];
    google: GoogleHealth;
}>();

const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : 'never';

const retryAll = () =>
    router.post(adminHealth.retry().url, {}, { preserveScroll: true });
</script>

<template>
    <Head title="System Health" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="System Health"
            description="Emails and reminders run through the queue; a worker (php artisan queue:work) must stay running in production."
        >
            <template #actions>
                <Button
                    v-if="failedJobs.length > 0"
                    variant="outline"
                    @click="retryAll"
                >
                    <RefreshCw aria-hidden="true" />
                    Retry all failed
                </Button>
            </template>
        </PageHeader>

        <div
            class="flex flex-wrap items-center gap-x-10 gap-y-5 rounded-xl border bg-card px-6 py-5"
        >
            <div class="flex items-center gap-2.5">
                <span
                    class="size-2.5 rounded-full"
                    :class="
                        failedJobs.length === 0
                            ? 'bg-success'
                            : 'bg-destructive'
                    "
                    aria-hidden="true"
                />
                <span class="text-sm font-medium">
                    {{
                        failedJobs.length === 0
                            ? 'All systems operational'
                            : 'Attention needed'
                    }}
                </span>
            </div>

            <div
                class="hidden h-9 w-px bg-border sm:block"
                aria-hidden="true"
            />

            <div class="space-y-0.5">
                <div class="text-2xl font-semibold tabular-nums">
                    {{ queuedJobs }}
                </div>
                <div class="text-xs text-muted-foreground">Jobs in queue</div>
            </div>

            <div class="space-y-0.5">
                <div
                    class="text-2xl font-semibold tabular-nums"
                    :class="failedJobs.length > 0 ? 'text-destructive' : ''"
                >
                    {{ failedJobs.length }}
                </div>
                <div class="text-xs text-muted-foreground">Failed jobs</div>
            </div>
        </div>

        <!-- Google Calendar integration: aggregate health only. Admins never
             see anyone's calendars or events here. -->
        <section class="space-y-3" aria-labelledby="google-health">
            <h2 id="google-health" class="text-[15px] font-semibold">
                Google Calendar
            </h2>
            <dl
                class="grid gap-x-8 gap-y-3 rounded-xl border bg-card px-6 py-5 text-sm sm:grid-cols-2"
            >
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">OAuth credentials</dt>
                    <dd
                        :class="
                            google.oauth_configured ? '' : 'text-destructive'
                        "
                    >
                        {{ google.oauth_configured ? 'Set' : 'Missing' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Holiday API key</dt>
                    <dd>{{ google.api_key_configured ? 'Set' : 'Not set' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Change notifications</dt>
                    <dd>
                        {{
                            google.push_available
                                ? `${google.channels_active} of ${google.calendars_synced} calendars watched`
                                : 'Off (needs HTTPS); polling every 10 min'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Connected people</dt>
                    <dd>
                        {{ google.accounts }}
                        <span
                            v-if="google.accounts_needing_reconnect"
                            class="text-destructive"
                        >
                            · {{ google.accounts_needing_reconnect }} need to
                            reconnect
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Calendars syncing</dt>
                    <dd>
                        {{ google.calendars_synced }}
                        <span
                            v-if="google.calendars_with_errors"
                            class="text-destructive"
                        >
                            · {{ google.calendars_with_errors }} with errors
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Last calendar sync</dt>
                    <dd>{{ when(google.last_sync_at) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">
                        Booking copies failing
                    </dt>
                    <dd
                        :class="
                            google.booking_copies_failed
                                ? 'text-destructive'
                                : ''
                        "
                    >
                        {{ google.booking_copies_failed }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Holidays imported</dt>
                    <dd
                        :class="google.holidays_error ? 'text-destructive' : ''"
                    >
                        {{
                            google.holidays_error ??
                            when(google.holidays_synced_at)
                        }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground">
                        Redirect URI and webhook (for Google Cloud Console)
                    </dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ google.redirect_url }}<br />{{ google.webhook_url }}
                    </dd>
                </div>
            </dl>
        </section>

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
