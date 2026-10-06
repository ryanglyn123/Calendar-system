<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, RefreshCw, TriangleAlert } from '@lucide/vue';
import { formatDistanceToNowStrict } from 'date-fns';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useConfirm } from '@/composables/useConfirm';
import { connect, destroy, sync, update } from '@/routes/google-calendar';
import calendarRoutes from '@/routes/google-calendar/calendars';

type GoogleCalendarRow = {
    id: number;
    summary: string;
    color: string | null;
    access_role: string;
    is_primary: boolean;
    is_holiday: boolean;
    sync_enabled: boolean;
    last_synced_at: string | null;
    error: string | null;
};

const props = defineProps<{
    configured: boolean;
    permissions: { scope: string; label: string }[];
    account: {
        email: string;
        needs_reconnect: boolean;
        granted: string[];
        push_bookings: boolean;
        last_synced_at: string | null;
        push_notifications: boolean;
    } | null;
    calendars: GoogleCalendarRow[];
}>();

const { confirm } = useConfirm();

const ROLE_LABELS: Record<string, string> = {
    owner: 'Yours',
    writer: 'Can edit',
    writerWithoutPrivateAccess: 'Can edit',
    reader: 'View only',
    freeBusyReader: 'Busy times only',
};

const missing = computed(() =>
    props.account
        ? props.permissions.filter(
              (p) => !props.account!.granted.includes(p.scope),
          )
        : [],
);

const canPushBookings = computed(
    () =>
        props.account !== null &&
        props.account.granted.includes(
            'https://www.googleapis.com/auth/calendar.app.created',
        ),
);

const ago = (iso: string | null) =>
    iso ? formatDistanceToNowStrict(new Date(iso), { addSuffix: true }) : null;

const busy = ref<number | 'push' | 'sync' | null>(null);

const toggleCalendar = (row: GoogleCalendarRow, enabled: boolean) => {
    busy.value = row.id;
    router.patch(
        calendarRoutes.update(row.id).url,
        { sync_enabled: enabled },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
};

const togglePush = (enabled: boolean) => {
    busy.value = 'push';
    router.patch(
        update().url,
        { push_bookings: enabled },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
};

const syncNow = () => {
    busy.value = 'sync';
    router.post(
        sync().url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
};

const disconnect = async () => {
    const ok = await confirm({
        title: 'Disconnect Google Calendar?',
        description:
            'Your Google calendars disappear from here and the app loses access to your Google account. Bookings already copied to your Google Calendar stay there.',
        confirmLabel: 'Disconnect',
        destructive: true,
    });

    if (ok) {
        router.delete(destroy().url, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Google Calendar" />

    <div class="space-y-8">
        <header class="space-y-0.5">
            <h2 class="text-lg font-medium">Google Calendar</h2>
            <p class="text-sm text-muted-foreground">
                See your Google events beside your schedule here, and keep
                approved bookings in your Google Calendar.
            </p>
        </header>

        <!-- The server has no Google credentials yet. -->
        <p
            v-if="!configured && account === null"
            class="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground"
        >
            Google Calendar is not set up on this server yet. An administrator
            needs to add the Google credentials first.
        </p>

        <!-- Not connected -->
        <section v-else-if="account === null" class="space-y-4">
            <ul class="space-y-2 text-sm">
                <li
                    v-for="permission in permissions"
                    :key="permission.scope"
                    class="flex items-start gap-2"
                >
                    <Check
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ permission.label }}
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                Only you see your Google events here, and the app never edits
                them. Bookings still need approval as usual; only approved ones
                are added to Google.
            </p>
            <Button as-child>
                <a :href="connect().url">Connect Google Calendar</a>
            </Button>
        </section>

        <template v-else>
            <!-- Account -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ account.email }}</p>
                        <p
                            v-if="account.needs_reconnect"
                            class="flex items-center gap-1.5 text-sm text-destructive"
                            role="status"
                        >
                            <TriangleAlert class="size-4" aria-hidden="true" />
                            Google stopped accepting this connection. Reconnect
                            to resume syncing.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Connected<template v-if="account.last_synced_at">
                                · calendar list checked
                                {{ ago(account.last_synced_at) }}</template
                            >
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="account.needs_reconnect || missing.length"
                            as-child
                            size="sm"
                        >
                            <a :href="connect().url">
                                {{
                                    account.needs_reconnect
                                        ? 'Reconnect'
                                        : 'Grant access'
                                }}
                            </a>
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="outline"
                            :disabled="busy === 'sync'"
                            @click="syncNow"
                        >
                            <RefreshCw
                                :class="{
                                    'animate-spin motion-reduce:animate-none':
                                        busy === 'sync',
                                }"
                                aria-hidden="true"
                            />
                            Sync now
                        </Button>
                    </div>
                </div>

                <div
                    v-if="missing.length && !account.needs_reconnect"
                    class="rounded-lg border px-4 py-3 text-sm"
                >
                    <p class="font-medium">Some access was not granted</p>
                    <ul
                        class="mt-1 list-inside list-disc text-muted-foreground"
                    >
                        <li
                            v-for="permission in missing"
                            :key="permission.scope"
                        >
                            {{ permission.label }}
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-muted-foreground">
                    {{
                        account.push_notifications
                            ? 'Changes you make in Google show up here within moments.'
                            : 'Changes you make in Google show up here within about 10 minutes.'
                    }}
                </p>
            </section>

            <!-- Calendars -->
            <section class="space-y-2">
                <h3 class="text-sm font-semibold">Show in this calendar</h3>
                <p
                    v-if="calendars.length === 0"
                    class="text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Fetching your Google calendars…
                </p>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="row in calendars"
                        :key="row.id"
                        class="flex items-start gap-3 px-3 py-2.5"
                    >
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: row.color ?? '#039BE5' }"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <Label
                                :for="`gcal-${row.id}`"
                                class="block truncate font-normal"
                            >
                                {{ row.summary }}
                            </Label>
                            <p class="text-xs text-muted-foreground">
                                {{ ROLE_LABELS[row.access_role] ?? 'View only'
                                }}<template v-if="row.is_primary">
                                    · primary</template
                                ><template v-if="row.is_holiday">
                                    · holidays</template
                                ><template
                                    v-if="
                                        row.sync_enabled &&
                                        row.last_synced_at &&
                                        !row.error
                                    "
                                >
                                    · synced
                                    {{ ago(row.last_synced_at) }}</template
                                >
                            </p>
                            <p
                                v-if="row.sync_enabled && row.error"
                                class="text-xs text-destructive"
                            >
                                {{ row.error }}
                            </p>
                        </div>
                        <Switch
                            :id="`gcal-${row.id}`"
                            :model-value="row.sync_enabled"
                            :disabled="
                                busy === row.id || account.needs_reconnect
                            "
                            @update:model-value="
                                (value: boolean) => toggleCalendar(row, value)
                            "
                        />
                    </li>
                </ul>
            </section>

            <!-- Bookings -->
            <section class="space-y-2">
                <h3 class="text-sm font-semibold">Bookings</h3>
                <div
                    class="flex items-start justify-between gap-4 rounded-lg border px-3 py-2.5"
                >
                    <div class="text-sm">
                        <Label for="gcal-push" class="font-normal">
                            Add my approved bookings to Google Calendar
                        </Label>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            They go to a separate “Bookings” calendar in your
                            Google account. A request is added once it is
                            approved, moved when a change is approved, and
                            removed if it is cancelled or rejected.
                        </p>
                        <p
                            v-if="!canPushBookings"
                            class="mt-1 text-xs text-destructive"
                        >
                            Needs the bookings permission. Use “Grant access”.
                        </p>
                    </div>
                    <Switch
                        id="gcal-push"
                        :model-value="account.push_bookings && canPushBookings"
                        :disabled="
                            !canPushBookings ||
                            busy === 'push' ||
                            account.needs_reconnect
                        "
                        @update:model-value="
                            (value: boolean) => togglePush(value)
                        "
                    />
                </div>
            </section>

            <section class="border-t pt-6">
                <Button
                    variant="outline"
                    class="text-destructive"
                    @click="disconnect"
                >
                    Disconnect Google Calendar
                </Button>
            </section>
        </template>
    </div>
</template>
