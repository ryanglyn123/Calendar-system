<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Maximize2,
    MessagesSquare,
    Minus,
    Settings,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ChatConversationList from '@/components/chat/ChatConversationList.vue';
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
import type { ChatConversationSummary } from '@/types/chat';

const chat = useChatStore();
const page = usePage();
const { user } = useAuth();

// Gate everything behind onMounted: SSR renders nothing here, and the first
// client render must match (hydration rule).
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;

    chat.bootstrap(
        (page.props.chatConversations as ChatConversationSummary[] | undefined) ??
            [],
        user.value.id,
    );
});

const onChatPage = computed(() => page.url.startsWith('/chat'));

const visible = computed(
    () =>
        mounted.value &&
        chat.conversations.length > 0 &&
        !onChatPage.value,
);

const settingsOpen = ref(false);

const openMini = () => chat.openMini();

const maximize = () => {
    const id = chat.activeId;
    chat.dismiss();
    router.visit(
        id === null
            ? chatPage.index().url
            : chatPage.index({ query: { conversation: id } }).url,
    );
};

const select = (id: number) => {
    void chat.openConversation(id);
};
</script>

<template>
    <template v-if="visible">
        <!-- Chat head -->
        <Transition
            appear
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-75"
            enter-to-class="opacity-100 scale-100"
        >
            <div
                v-if="chat.dock === 'head'"
                class="fixed right-4 bottom-4 z-40 origin-bottom-right"
            >
                <button
                    type="button"
                    class="flex size-14 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95"
                    aria-label="Open chats"
                    data-test="chat-head"
                    @click="openMini"
                >
                    <MessagesSquare class="size-6" aria-hidden="true" />
                    <Badge
                        v-if="chat.totalUnread > 0"
                        variant="destructive"
                        class="absolute -top-1 -right-1 h-5 min-w-5 justify-center rounded-full px-1 text-[10px] tabular-nums"
                    >
                        {{ chat.totalUnread > 99 ? '99+' : chat.totalUnread }}
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
        </Transition>

        <!-- Mini chat box -->
        <div
            v-if="chat.dock === 'mini'"
            class="fixed right-4 bottom-4 z-40 flex h-[520px] max-h-[80vh] w-[360px] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-xl border bg-background shadow-2xl"
            role="dialog"
            aria-label="Chats"
            data-test="chat-mini"
        >
            <!-- Thread header -->
            <div
                v-if="chat.activeConversation"
                class="flex items-center gap-1.5 border-b px-2 py-2"
            >
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Back to chats"
                    data-test="chat-back"
                    @click="chat.backToList()"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                </Button>
                <Avatar class="size-8">
                    <AvatarImage
                        v-if="chat.activeConversation.photo_url"
                        :src="chat.activeConversation.photo_url"
                        :alt="chat.activeConversation.name"
                    />
                    <AvatarFallback
                        class="bg-primary text-sm text-primary-foreground"
                    >
                        {{ chat.activeConversation.name[0] }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">
                        {{ chat.activeConversation.name }}
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
                    @click="chat.closeMini()"
                >
                    <Minus class="size-4" aria-hidden="true" />
                </Button>
            </div>

            <!-- List header -->
            <div v-else class="flex items-center gap-1.5 border-b px-3 py-2">
                <p class="flex-1 text-sm font-semibold">Chats</p>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    aria-label="Minimize chat"
                    data-test="chat-minimize"
                    @click="chat.closeMini()"
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

            <ChatPanel v-if="chat.activeConversation" compact />
            <ChatConversationList v-else compact @select="select" />
        </div>

        <ChatSettingsSheet v-model:open="settingsOpen" />
    </template>
</template>
