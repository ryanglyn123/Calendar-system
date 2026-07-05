<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Minimize2, Settings } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ChatPanel from '@/components/chat/ChatPanel.vue';
import ChatSettingsSheet from '@/components/chat/ChatSettingsSheet.vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/composables/useAuth';
import { dashboard } from '@/routes';
import { useChatStore } from '@/stores/chat';
import type {
    ChatDivisionInfo,
    ChatMemberItem,
    ChatPage,
} from '@/types/chat';

const props = defineProps<{
    division: ChatDivisionInfo;
    initialPage: ChatPage;
    members: ChatMemberItem[];
    lastReadMessageId: number | null;
}>();

const chat = useChatStore();
const { user } = useAuth();

const settingsOpen = ref(false);

// Hydrate before mount so the panel paints full in one pass; everything
// touching window/Echo waits for onMounted (SSR hydration rule).
chat.hydrate(props.initialPage, props.members, props.lastReadMessageId);

const onVisibility = () => {
    chat.setActive(document.visibilityState === 'visible');
};

const minimize = () => {
    chat.minimizeToHead();
    router.visit(dashboard().url);
};

onMounted(() => {
    chat.bootstrap(props.division, 0, user.value.id);
    chat.setActive(true);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    chat.setActive(false);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <Head :title="`${division.name} Chat`" />

    <div class="flex h-full min-h-0 flex-1 flex-col">
        <!-- Header -->
        <div class="flex items-center gap-3 border-b px-4 py-2.5 sm:px-6">
            <Avatar class="size-9">
                <AvatarImage
                    v-if="chat.division?.photo_url"
                    :src="chat.division.photo_url"
                    :alt="division.name"
                />
                <AvatarFallback class="bg-primary text-primary-foreground">
                    {{ division.name[0] }}
                </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold">
                    {{ division.name }}
                </h1>
                <p class="text-xs text-muted-foreground">
                    {{ chat.members.length }} members · division group chat
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                class="gap-1.5"
                data-test="chat-page-minimize"
                @click="minimize"
            >
                <Minimize2 class="size-4" aria-hidden="true" />
                Minimize
            </Button>
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

        <ChatSettingsSheet v-model:open="settingsOpen" />
    </div>
</template>
