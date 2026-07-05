<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { MessagesSquare, Settings } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ChatConversationList from '@/components/chat/ChatConversationList.vue';
import ChatPanel from '@/components/chat/ChatPanel.vue';
import ChatSettingsSheet from '@/components/chat/ChatSettingsSheet.vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/composables/useAuth';
import { useChatStore } from '@/stores/chat';
import type { ChatConversationSummary } from '@/types/chat';

const props = defineProps<{
    initialConversationId: number | null;
}>();

const chat = useChatStore();
const page = usePage();
const { user } = useAuth();

const settingsOpen = ref(false);

const onVisibility = () => {
    chat.setActive(document.visibilityState === 'visible');
};

const select = (id: number) => {
    void chat.openConversation(id);
};

const showList = computed(() => chat.activeConversation === null);

onMounted(async () => {
    chat.bootstrap(
        (page.props.chatConversations as ChatConversationSummary[] | undefined) ??
            [],
        user.value.id,
    );
    chat.setActive(true);
    document.addEventListener('visibilitychange', onVisibility);

    if (props.initialConversationId !== null) {
        await chat.openConversation(props.initialConversationId);
    } else {
        await chat.ensureLoaded();
    }
});

onBeforeUnmount(() => {
    chat.setActive(false);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <Head title="Chats" />

    <div class="flex h-full min-h-0 flex-1">
        <!-- Conversation list -->
        <aside
            class="w-full shrink-0 flex-col border-r sm:flex sm:w-72"
            :class="showList ? 'flex' : 'hidden'"
        >
            <ChatConversationList @select="select" />
        </aside>

        <!-- Active thread -->
        <div
            class="min-w-0 flex-1 flex-col"
            :class="showList ? 'hidden sm:flex' : 'flex'"
        >
            <template v-if="chat.activeConversation">
                <div
                    class="flex items-center gap-3 border-b px-4 py-2.5 sm:px-6"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        class="sm:hidden"
                        @click="chat.backToList()"
                    >
                        Back
                    </Button>
                    <Avatar class="size-9">
                        <AvatarImage
                            v-if="chat.activeConversation.photo_url"
                            :src="chat.activeConversation.photo_url"
                            :alt="chat.activeConversation.name"
                        />
                        <AvatarFallback
                            class="bg-primary text-primary-foreground"
                        >
                            {{ chat.activeConversation.name[0] }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-base font-semibold">
                            {{ chat.activeConversation.name }}
                        </h1>
                        <p class="text-xs text-muted-foreground">
                            {{ chat.members.length }} members
                            <span v-if="chat.activeConversation.is_division">
                                · division group chat
                            </span>
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        @click="settingsOpen = true"
                    >
                        <Settings class="size-4" aria-hidden="true" />
                        Settings
                    </Button>
                </div>

                <ChatPanel />
            </template>

            <div
                v-else
                class="hidden flex-1 flex-col items-center justify-center gap-2 text-muted-foreground sm:flex"
            >
                <MessagesSquare class="size-10" aria-hidden="true" />
                <p class="text-sm">Select a chat to start messaging.</p>
            </div>
        </div>

        <ChatSettingsSheet v-model:open="settingsOpen" />
    </div>
</template>
