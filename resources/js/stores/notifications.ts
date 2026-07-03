import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '@/lib/api';
import notificationRoutes from '@/routes/api/notifications';
import type { AppNotification } from '@/types/calendar';

const POLL_MS = 60_000;

export const useNotificationStore = defineStore('notifications', () => {
    const items = ref<AppNotification[]>([]);
    const unreadCount = ref(0);
    const loading = ref(false);

    let pollTimer: ReturnType<typeof setInterval> | undefined;

    async function refresh(): Promise<void> {
        if (loading.value) {
            return;
        }

        loading.value = true;

        try {
            const response = await api.get<{
                data: AppNotification[];
                unread_count: number;
            }>(notificationRoutes.index().url);

            items.value = response.data;
            unreadCount.value = response.unread_count;
        } finally {
            loading.value = false;
        }
    }

    function startPolling(): void {
        if (pollTimer) {
            return;
        }

        void refresh();
        pollTimer = setInterval(() => void refresh(), POLL_MS);
    }

    function stopPolling(): void {
        clearInterval(pollTimer);
        pollTimer = undefined;
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
        refresh,
        startPolling,
        stopPolling,
        markRead,
        markAllRead,
        clearAll,
    };
});
