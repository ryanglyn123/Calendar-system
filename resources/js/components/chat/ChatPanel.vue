<script setup lang="ts">
import { MessagesSquare, Pin } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatMessageBubble from '@/components/chat/ChatMessageBubble.vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { format, isSameDay } from '@/lib/calendarMath';
import { useChatStore } from '@/stores/chat';
import type { ChatMessageItem } from '@/types/chat';

const props = defineProps<{
    compact?: boolean;
}>();

const chat = useChatStore();

const scroller = ref<HTMLElement | null>(null);
const topSentinel = ref<HTMLElement | null>(null);
const replyTo = ref<ChatMessageItem | null>(null);
const lightboxUrl = ref<string | null>(null);

let observer: IntersectionObserver | undefined;
let stickToBottom = true;

const rows = computed(() =>
    chat.messages.map((message, index) => {
        const previous = chat.messages[index - 1];

        return {
            message,
            newDay:
                previous === undefined ||
                !isSameDay(
                    new Date(previous.created_at),
                    new Date(message.created_at),
                ),
            grouped:
                previous !== undefined &&
                previous.user?.id === message.user?.id &&
                !previous.is_deleted &&
                new Date(message.created_at).getTime() -
                    new Date(previous.created_at).getTime() <
                    5 * 60_000,
        };
    }),
);

const dayLabel = (iso: string) => format(new Date(iso), 'EEEE, MMM d');

const pinnedPreview = computed(() => {
    const latest = chat.pinned[0];

    if (!latest) {
        return '';
    }

    return (
        latest.body ??
        (latest.poll
            ? `Poll: ${latest.poll.question}`
            : (latest.event_card?.title ?? 'Photo'))
    );
});

/** Jump requests (pinned banner / settings list): load, scroll, flash. */
const highlightId = ref<number | null>(null);

watch(
    () => chat.jumpRequestId,
    async (id) => {
        if (id === null) {
            return;
        }

        await chat.ensureMessageLoaded(id);
        await nextTick();

        scroller.value
            ?.querySelector(`[data-mid="${id}"]`)
            ?.scrollIntoView({ block: 'center' });
        stickToBottom = false;

        highlightId.value = id;
        setTimeout(() => {
            if (highlightId.value === id) {
                highlightId.value = null;
            }
        }, 1700);

        chat.jumpRequestId = null;
    },
);

const scrollToBottom = (smooth = false) => {
    const el = scroller.value;

    if (el) {
        el.scrollTo({
            top: el.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto',
        });
    }
};

watch(
    () => chat.messages.length,
    async (next, previous) => {
        // New tail message: follow it unless the user scrolled up.
        const grew = next > previous;
        await nextTick();

        if (grew && stickToBottom) {
            scrollToBottom(true);
        }
    },
);

const onScroll = () => {
    const el = scroller.value;

    if (el) {
        stickToBottom =
            el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    }
};

onMounted(async () => {
    await chat.ensureLoaded();
    void chat.loadPinned();
    await nextTick();
    scrollToBottom();

    if (topSentinel.value) {
        observer = new IntersectionObserver(async (entries) => {
            if (!entries[0]?.isIntersecting || !chat.hasMore) {
                return;
            }

            const el = scroller.value;
            const heightBefore = el?.scrollHeight ?? 0;

            await chat.loadOlder();
            await nextTick();

            // Keep the viewport anchored on the same message.
            if (el) {
                el.scrollTop += el.scrollHeight - heightBefore;
            }
        });

        observer.observe(topSentinel.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- Pinned banner: latest pin, click to jump to it in the thread -->
        <button
            v-if="chat.pinned.length > 0"
            type="button"
            class="flex w-full items-center gap-2 border-b bg-muted/40 px-3 py-1.5 text-left text-xs transition-colors hover:bg-muted/70"
            @click="chat.jumpTo(chat.pinned[0].id)"
        >
            <Pin
                class="size-3.5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1 truncate">
                <span class="font-medium">
                    {{ chat.pinned[0].user?.name ?? 'Someone' }}:
                </span>
                {{ pinnedPreview }}
            </span>
            <span
                v-if="chat.pinned.length > 1"
                class="shrink-0 text-muted-foreground"
            >
                +{{ chat.pinned.length - 1 }} more
            </span>
        </button>

        <!-- Messages -->
        <div
            ref="scroller"
            class="min-h-0 flex-1 overflow-y-auto pb-2"
            @scroll.passive="onScroll"
        >
            <div ref="topSentinel" class="h-px" />

            <div
                v-if="chat.loadingOlder"
                class="flex justify-center py-2"
            >
                <Spinner class="size-4" />
            </div>

            <div
                v-if="chat.loaded && chat.messages.length === 0"
                class="flex h-full flex-col items-center justify-center gap-2 px-6 text-center text-sm text-muted-foreground"
            >
                <MessagesSquare class="size-8" aria-hidden="true" />
                No messages yet. Say hello to your division.
            </div>

            <template v-for="row in rows" :key="row.message.id">
                <div
                    v-if="row.newDay"
                    class="my-3 flex items-center gap-3 px-4"
                >
                    <span class="h-px flex-1 bg-border" />
                    <span class="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                        {{ dayLabel(row.message.created_at) }}
                    </span>
                    <span class="h-px flex-1 bg-border" />
                </div>

                <ChatMessageBubble
                    :data-mid="row.message.id"
                    :message="row.message"
                    :grouped="row.grouped && !row.newDay"
                    :compact="compact"
                    :class="{
                        'chat-jump-highlight': highlightId === row.message.id,
                    }"
                    @reply="replyTo = $event"
                    @open-image="lightboxUrl = $event"
                />

                <!-- Read receipts: who has read up to exactly this message -->
                <div
                    v-if="chat.readsByMessage.has(row.message.id)"
                    class="mt-0.5 flex justify-end gap-0.5 px-4"
                    :aria-label="`Seen by ${chat.readsByMessage
                        .get(row.message.id)!
                        .map((m) => m.name)
                        .join(', ')}`"
                >
                    <Avatar
                        v-for="reader in chat.readsByMessage.get(
                            row.message.id,
                        )"
                        :key="reader.id"
                        class="size-3.5 border border-background"
                        :title="`Seen by ${reader.name}`"
                    >
                        <AvatarImage
                            v-if="reader.avatar_path"
                            :src="reader.avatar_path"
                            :alt="reader.name"
                        />
                        <AvatarFallback class="text-[7px]">
                            {{ reader.name[0] }}
                        </AvatarFallback>
                    </Avatar>
                </div>
            </template>

            <!-- Typing indicator -->
            <p
                v-if="chat.typingNames.length > 0"
                class="px-4 pt-2 text-xs text-muted-foreground"
                aria-live="polite"
            >
                {{ chat.typingNames.join(', ') }}
                {{ chat.typingNames.length === 1 ? 'is' : 'are' }} typing…
            </p>
        </div>

        <ChatComposer
            :reply-to="replyTo"
            :compact="props.compact"
            @clear-reply="replyTo = null"
        />

        <!-- Photo lightbox -->
        <Dialog
            :open="lightboxUrl !== null"
            @update:open="(open) => !open && (lightboxUrl = null)"
        >
            <DialogContent
                class="max-w-3xl border-0 bg-transparent p-0 shadow-none"
            >
                <img
                    v-if="lightboxUrl"
                    :src="lightboxUrl"
                    alt=""
                    class="max-h-[85vh] w-full rounded-lg object-contain"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
.chat-jump-highlight {
    animation: chat-jump-flash 1.6s ease-out;
}

@keyframes chat-jump-flash {
    0%,
    35% {
        background-color: color-mix(
            in oklab,
            var(--primary) 14%,
            transparent
        );
    }

    100% {
        background-color: transparent;
    }
}
</style>
