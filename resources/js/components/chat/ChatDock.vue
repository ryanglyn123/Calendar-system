<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Maximize2, Minus, Settings, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ChatPanel from '@/components/chat/ChatPanel.vue';
import ChatSettingsSheet from '@/components/chat/ChatSettingsSheet.vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/composables/useAuth';
import chatPage from '@/routes/chat';
import { useChatStore } from '@/stores/chat';
import type { ChatDivisionInfo } from '@/types/chat';

const chat = useChatStore();
const page = usePage();
const { user } = useAuth();

// Gate everything behind onMounted: SSR renders nothing here, and the
// first client render must match (hydration rule).
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;

    chat.bootstrap(
        (page.props.chatDivision as ChatDivisionInfo | null) ?? null,
        (page.props.chatUnread as number | undefined) ?? 0,
        user.value.id,
    );
});

const onChatPage = computed(() => page.url.startsWith('/chat'));

const visible = computed(
    () => mounted.value && chat.division !== null && !onChatPage.value,
);

const settingsOpen = ref(false);

const openMini = () => {
    chat.openMini();
    chat.setActive(true);
};

const minimize = () => {
    chat.closeMini();
};

const maximize = () => {
    chat.dismiss();
    router.visit(chatPage.index().url);
};
</script>

<template>
    <template v-if="visible">
        <!-- Chat head -->
        <div v-if="chat.dock === 'head'" class="fixed right-4 bottom-4 z-40">
            <button
                type="button"
                class="rounded-full shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95"
                :aria-label="`Open ${chat.division!.name} chat`"
                data-test="chat-head"
                @click="openMini"
            >
                <Avatar class="size-14 border-2 border-background">
                    <AvatarImage
                        v-if="chat.division!.photo_url"
                        :src="chat.division!.photo_url"
                        :alt="chat.division!.name"
                    />
                    <AvatarFallback class="bg-primary text-lg text-primary-foreground">
                        {{ chat.division!.name[0] }}
                    </AvatarFallback>
                </Avatar>
                <Badge
                    v-if="chat.unread > 0"
                    variant="destructive"
                    class="absolute -top-1 -right-1 h-5 min-w-5 justify-center rounded-full px-1 text-[10px] tabular-nums"
                >
                    {{ chat.unread > 99 ? '99+' : chat.unread }}
                </Badge>
            </button>
            <button
                type="button"
                class="absolute -top-1 -left-1 flex size-5 items-center justify-center rounded-full border border-background bg-muted text-muted-foreground shadow transition-colors hover:bg-destructive hover:text-destructive-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Dismiss chat"
                data-test="chat-head-dismiss"
                @click="chat.dismiss()"
            >
                <X class="size-3" aria-hidden="true" />
            </button>
        </div>

        <!-- Mini chat box -->
        <div
            v-else-if="chat.dock === 'mini'"
            class="fixed right-4 bottom-4 z-40 flex h-[520px] max-h-[80vh] w-[360px] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-xl border bg-background shadow-2xl"
            role="dialog"
            :aria-label="`${chat.division!.name} chat`"
            data-test="chat-mini"
        >
            <div class="flex items-center gap-2 border-b px-3 py-2">
                <Avatar class="size-8">
                    <AvatarImage
                        v-if="chat.division!.photo_url"
                        :src="chat.division!.photo_url"
                        :alt="chat.division!.name"
                    />
                    <AvatarFallback class="bg-primary text-sm text-primary-foreground">
                        {{ chat.division!.name[0] }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">
                        {{ chat.division!.name }}
                    </p>
                    <p class="text-[10px] text-muted-foreground">
                        {{ chat.members.length }} members
                    </p>
                </div>

                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Chat settings"
                    @click="settingsOpen = true"
                >
                    <Settings class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Open full chat"
                    data-test="chat-maximize"
                    @click="maximize"
                >
                    <Maximize2 class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Minimize chat"
                    data-test="chat-minimize"
                    @click="minimize"
                >
                    <Minus class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Close chat"
                    data-test="chat-close"
                    @click="chat.dismiss()"
                >
                    <X class="size-4" aria-hidden="true" />
                </Button>
            </div>

            <ChatPanel compact />
        </div>

        <ChatSettingsSheet v-model:open="settingsOpen" />
    </template>
</template>
