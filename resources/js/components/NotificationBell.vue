<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Ban,
    Bell,
    Check,
    CheckCheck,
    CircleCheck,
    CircleX,
    Inbox,
    Printer,
    Trash2,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import bookingsPage from '@/routes/bookings';
import { useNotificationStore } from '@/stores/notifications';
import type { AppNotification } from '@/types/calendar';

const store = useNotificationStore();

onMounted(() => store.startPolling());
onBeforeUnmount(() => store.stopPolling());

/** The list closes once a notice is followed; the page it opens takes over. */
const listOpen = ref(false);

const open = async (notification: AppNotification) => {
    listOpen.value = false;
    await store.markRead(notification);

    if (notification.action_url) {
        router.visit(notification.action_url);
    }
};

/** An "approval needed" notice someone already acted on says so. */
const SETTLED: Record<string, string> = {
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Withdrawn',
    removed: 'Removed',
};

const settled = (n: AppNotification) =>
    n.key === 'booking.requested' &&
    n.booking_status &&
    n.booking_status !== 'pending'
        ? SETTLED[n.booking_status]
        : null;

/** How a requester's booking turned out, drawn so it reads at a glance. */
const OUTCOME: Record<
    string,
    { icon: LucideIcon; tone: string; status: string }
> = {
    'booking.approved': {
        icon: CircleCheck,
        tone: 'bg-success/15 text-success',
        status: 'approved',
    },
    'booking.rejected': {
        icon: CircleX,
        tone: 'bg-destructive/10 text-destructive',
        status: 'rejected',
    },
    'booking.cancelled': {
        icon: Ban,
        tone: 'bg-muted text-muted-foreground',
        status: 'cancelled',
    },
};

const outcome = (n: AppNotification) => (n.key ? OUTCOME[n.key] : undefined);

/** The booking moved on since this notice (approved, then cancelled). */
const since = (n: AppNotification) => {
    const o = outcome(n);

    if (!o || !n.booking_status || n.booking_status === o.status) {
        return null;
    }

    return n.booking_status === 'removed'
        ? 'Since removed'
        : `Since ${n.booking_status}`;
};

/** Still approved: its form can be printed straight from here. */
const printable = (n: AppNotification) =>
    n.key === 'booking.approved' &&
    n.booking_status === 'approved' &&
    n.booking_id !== null;

const timeAgo = (iso: string): string => {
    const minutes = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.floor(hours / 24)}d ago`;
};
</script>

<template>
    <Popover v-model:open="listOpen">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="`Notifications, ${store.unreadCount} unread`"
            >
                <Bell aria-hidden="true" />
                <span
                    v-if="store.unreadCount > 0"
                    class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-fdcp-magenta px-1 text-[10px] leading-4 font-semibold text-white"
                >
                    {{ store.unreadCount > 99 ? '99+' : store.unreadCount }}
                </span>
            </Button>
        </PopoverTrigger>

        <PopoverContent align="end" class="w-96 max-w-[calc(100vw-1rem)] p-0">
            <div class="flex items-center justify-between border-b px-3 py-2">
                <span class="text-sm font-semibold">Notifications</span>
                <span class="flex gap-1">
                    <Button
                        v-if="store.unreadCount > 0"
                        variant="ghost"
                        size="sm"
                        class="h-7 text-xs"
                        @click="store.markAllRead()"
                    >
                        <CheckCheck aria-hidden="true" />
                        Mark all read
                    </Button>
                    <Button
                        v-if="store.items.length > 0"
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        aria-label="Clear all notifications"
                        @click="store.clearAll()"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                    </Button>
                </span>
            </div>

            <div
                v-if="store.items.length === 0"
                class="flex flex-col items-center gap-2 px-4 py-8 text-center"
            >
                <Inbox
                    class="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-sm text-muted-foreground">
                    You are all caught up.
                </p>
            </div>

            <ul v-else class="max-h-96 overflow-y-auto">
                <li
                    v-for="notification in store.items"
                    :key="notification.id"
                    class="flex items-stretch border-b last:border-b-0"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-start gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-muted"
                        @click="open(notification)"
                    >
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="
                                notification.read_at
                                    ? 'bg-transparent'
                                    : 'bg-fdcp-magenta'
                            "
                            aria-hidden="true"
                        />
                        <span
                            v-if="outcome(notification)"
                            class="grid size-8 shrink-0 place-items-center rounded-full"
                            :class="outcome(notification)!.tone"
                            aria-hidden="true"
                        >
                            <component
                                :is="outcome(notification)!.icon"
                                class="size-4"
                            />
                        </span>
                        <span class="min-w-0">
                            <span class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm font-medium"
                                    :class="{
                                        'text-muted-foreground':
                                            settled(notification) ||
                                            since(notification),
                                    }"
                                >
                                    {{ notification.title }}
                                </span>
                                <span
                                    v-if="settled(notification)"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-1.5 text-[11px] leading-4 font-medium text-muted-foreground"
                                >
                                    <Check class="size-3" aria-hidden="true" />
                                    {{ settled(notification) }}
                                </span>
                                <span
                                    v-else-if="since(notification)"
                                    class="shrink-0 rounded-full bg-muted px-1.5 text-[11px] leading-4 font-medium text-muted-foreground"
                                >
                                    {{ since(notification) }}
                                </span>
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ notification.message }}
                            </span>
                            <span
                                class="block pt-0.5 text-[10px] text-muted-foreground/70"
                            >
                                {{ timeAgo(notification.created_at) }}
                            </span>
                        </span>
                    </button>
                    <a
                        v-if="printable(notification)"
                        :href="bookingsPage.pdf(notification.booking_id!).url"
                        target="_blank"
                        rel="noopener"
                        class="grid w-11 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none focus-visible:ring-inset"
                        :aria-label="`Print the booking form for ${notification.message}`"
                        title="Print booking form"
                        @click="
                            () => {
                                listOpen = false;
                                void store.markRead(notification);
                            }
                        "
                    >
                        <Printer class="size-4" aria-hidden="true" />
                    </a>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
