<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { RefreshCw, Settings2 } from '@lucide/vue';
import { formatDistanceToNowStrict } from 'date-fns';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { api, ApiError } from '@/lib/api';
import { sync as syncApi } from '@/routes/api/google';
import { connect, edit } from '@/routes/google-calendar';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { CalendarItem } from '@/types/calendar';

/**
 * The Google Calendar group's controls in the calendar panel: when it
 * last synced, what went wrong in plain words, "Sync now", and the way to
 * connect or reconnect. Calendar choice and disconnecting live on the
 * settings page this links to.
 */
const props = defineProps<{
    calendars: CalendarItem[];
    /** Render only the header actions (sync + manage). */
    actionsOnly?: boolean;
}>();

const page = usePage();
const list = useCalendarListStore();
const grid = useCalendarGridStore();

const state = computed(() => page.props.googleCalendar);
const syncing = ref(false);
let settle: number | undefined;

const lastSynced = computed(() => {
    const times = props.calendars
        .map((c) => c.google?.last_synced_at)
        .filter((t): t is string => Boolean(t))
        .sort();

    return times.at(-1) ?? null;
});

const error = computed(
    () => props.calendars.find((c) => c.google?.error)?.google?.error ?? null,
);

const syncNow = async () => {
    syncing.value = true;

    try {
        await api.post(syncApi().url);
    } catch (e) {
        syncing.value = false;
        toast.error(
            e instanceof ApiError
                ? e.message
                : 'Could not reach the server. Try again.',
        );

        return;
    }

    // The sync runs in the background; give it a moment, then refresh.
    window.clearTimeout(settle);
    settle = window.setTimeout(async () => {
        await list.load(true);
        void grid.invalidateAround(grid.focusDate);
        syncing.value = false;
    }, 4000);
};

onBeforeUnmount(() => window.clearTimeout(settle));
</script>

<template>
    <div v-if="actionsOnly" class="flex items-center gap-0.5">
        <Button
            variant="ghost"
            size="icon"
            class="size-5"
            :disabled="syncing || state?.needs_reconnect"
            aria-label="Sync Google Calendar now"
            title="Sync now"
            @click="syncNow"
        >
            <RefreshCw
                class="size-3.5"
                :class="{ 'animate-spin motion-reduce:animate-none': syncing }"
                aria-hidden="true"
            />
        </Button>
        <Button
            as-child
            variant="ghost"
            size="icon"
            class="size-5"
            aria-label="Google Calendar settings"
            title="Choose calendars"
        >
            <Link :href="edit()">
                <Settings2 class="size-3.5" aria-hidden="true" />
            </Link>
        </Button>
    </div>

    <div v-else>
        <!-- Not connected: one quiet line, not a promo card. -->
        <p
            v-if="state?.configured && !state.connected"
            class="px-1.5 text-xs text-muted-foreground"
        >
            <a
                :href="connect().url"
                class="font-medium text-foreground underline-offset-2 hover:underline"
            >
                Connect Google Calendar
            </a>
            to see your Google events here.
        </p>

        <p
            v-else-if="state?.needs_reconnect"
            class="px-1.5 text-xs text-destructive"
            role="status"
        >
            Google stopped accepting this connection.
            <a
                :href="connect().url"
                class="font-medium underline underline-offset-2"
            >
                Reconnect
            </a>
        </p>

        <p
            v-else-if="error"
            class="px-1.5 text-xs text-destructive"
            role="status"
        >
            {{ error }}
        </p>

        <p
            v-else-if="state?.connected && calendars.length === 0"
            class="px-1.5 text-xs text-muted-foreground"
            aria-live="polite"
        >
            Fetching your Google calendars…
            <Link :href="edit()" class="underline-offset-2 hover:underline">
                Choose which to show
            </Link>
        </p>

        <p
            v-else-if="lastSynced"
            class="px-1.5 text-xs text-muted-foreground"
            aria-live="polite"
        >
            {{
                syncing
                    ? 'Syncing…'
                    : `Synced ${formatDistanceToNowStrict(new Date(lastSynced), { addSuffix: true })}`
            }}
        </p>
    </div>
</template>
