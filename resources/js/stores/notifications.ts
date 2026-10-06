import { router } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { api } from '@/lib/api';
import notificationRoutes from '@/routes/api/notifications';
import type { AppNotification } from '@/types/calendar';
import type { ReviewSummary } from '@/types/desk';

const POLL_MS = 30_000;

/** Decisions a requester is told about the moment they arrive. */
const ANNOUNCED = {
    'booking.approved': toast.success,
    'booking.rejected': toast.error,
    'booking.cancelled': toast.warning,
    'edit-request.applied': toast.success,
    'edit-request.rejected': toast.error,
} as const;

/**
 * Ids already announced in this browser: every tab polls, and a decision is
 * announced once, not once per tab (nor again after the next sign-in).
 */
const SEEN = 'fdcp-announced-notices';

function seen(): string[] {
    try {
        return JSON.parse(localStorage.getItem(SEEN) ?? '[]');
    } catch {
        return [];
    }
}

function markSeen(ids: string[]): void {
    try {
        localStorage.setItem(
            SEEN,
            JSON.stringify([...ids, ...seen()].slice(0, 200)),
        );
    } catch {
        // Private mode: worst case a decision is announced again.
    }
}

export const useNotificationStore = defineStore('notifications', () => {
    const items = ref<AppNotification[]>([]);
    const unreadCount = ref(0);
    const loading = ref(false);
    /** What waits on this reviewer; page loads and this poll both refresh it. */
    const review = ref<ReviewSummary | null>(null);

    let pollTimer: ReturnType<typeof setInterval> | undefined;
    /** Ids from the last poll; null before the first one. */
    let known: Set<string> | null = null;

    /**
     * New booking news since the last poll: announce unread decisions once,
     * and tell open calendars and booking pages to catch up, so nobody has
     * to refresh to see that a booking changed.
     */
    function digest(next: AppNotification[]): void {
        const already = new Set(seen());
        const fresh = next.filter(
            (n) =>
                !n.read_at &&
                n.key !== null &&
                n.key in ANNOUNCED &&
                !already.has(n.id),
        );

        markSeen(fresh.map((n) => n.id));

        // Oldest first, and only a few: a pile after a long absence lives
        // in the bell, not in a stack of toasts.
        fresh
            .slice(0, 3)
            .reverse()
            .forEach((n) => {
                ANNOUNCED[n.key as keyof typeof ANNOUNCED](n.title, {
                    description: n.message,
                    duration: 12_000,
                    action: n.action_url
                        ? {
                              label: 'Open booking',
                              onClick: () => {
                                  void markRead(n);
                                  router.visit(n.action_url!);
                              },
                          }
                        : undefined,
                });
            });

        const arrived =
            known !== null &&
            next.some(
                (n) =>
                    !known!.has(n.id) &&
                    (n.key?.startsWith('booking.') ||
                        n.key?.startsWith('edit-request.')),
            );

        known = new Set(next.map((n) => n.id));

        if (arrived) {
            window.dispatchEvent(new Event('bookings:changed'));
        }
    }

    async function refresh(): Promise<void> {
        if (loading.value) {
            return;
        }

        loading.value = true;

        try {
            const response = await api.get<{
                data: AppNotification[];
                unread_count: number;
                review: ReviewSummary | null;
            }>(notificationRoutes.index().url);

            items.value = response.data;
            unreadCount.value = response.unread_count;
            review.value = response.review;
            digest(response.data);
        } finally {
            loading.value = false;
        }
    }

    const refreshWhenVisible = () => {
        if (document.visibilityState === 'visible') {
            void refresh().catch(() => {});
        }
    };

    function startPolling(): void {
        if (pollTimer) {
            return;
        }

        void refresh().catch(() => {});
        pollTimer = setInterval(refreshWhenVisible, POLL_MS);
        document.addEventListener('visibilitychange', refreshWhenVisible);
    }

    function stopPolling(): void {
        clearInterval(pollTimer);
        pollTimer = undefined;
        document.removeEventListener('visibilitychange', refreshWhenVisible);
    }

    async function markRead(notification: AppNotification): Promise<void> {
        if (notification.read_at) {
            return;
        }

        notification.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);

        await api.post(notificationRoutes.read(notification.id).url);
    }

    async function markAllRead(): Promise<void> {
        items.value.forEach((n) => {
            n.read_at ??= new Date().toISOString();
        });
        unreadCount.value = 0;

        await api.post(notificationRoutes.readAll().url);
    }

    async function clearAll(): Promise<void> {
        items.value = [];
        unreadCount.value = 0;

        await api.delete(notificationRoutes.clear().url);
    }

    return {
        items,
        unreadCount,
        loading,
        review,
        refresh,
        startPolling,
        stopPolling,
        markRead,
        markAllRead,
        clearAll,
    };
});
