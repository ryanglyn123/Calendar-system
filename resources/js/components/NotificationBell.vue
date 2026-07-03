<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Inbox, Trash2 } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useNotificationStore } from '@/stores/notifications';
import type { AppNotification } from '@/types/calendar';

const store = useNotificationStore();

onMounted(() => store.startPolling());
onBeforeUnmount(() => store.stopPolling());

const open = async (notification: AppNotification) => {
    await store.markRead(notification);

    if (notification.action_url) {
        router.visit(notification.action_url);
    }
};

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
    <Popover>
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

        <PopoverContent align="end" class="w-96 p-0">
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
                >
                    <button
                        type="button"
                        class="flex w-full items-start gap-2.5 border-b px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-muted"
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
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">
                                {{ notification.title }}
                            </span>
                            <span
                                class="block text-xs text-muted-foreground"
                            >
                                {{ notification.message }}
                            </span>
                            <span
                                class="block pt-0.5 text-[10px] text-muted-foreground/70"
                            >
                                {{ timeAgo(notification.created_at) }}
                            </span>
                        </span>
                    </button>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
