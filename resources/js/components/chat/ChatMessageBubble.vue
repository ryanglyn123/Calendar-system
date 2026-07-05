<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CalendarDays,
    Clock,
    MapPin,
    Pin,
    Reply,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import ChatPollCard from '@/components/chat/ChatPollCard.vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { format } from '@/lib/calendarMath';
import { linkify } from '@/lib/linkify';
import { useChatStore } from '@/stores/chat';
import { CHAT_REACTIONS } from '@/types/chat';
import type { ChatMessageItem } from '@/types/chat';

const props = defineProps<{
    message: ChatMessageItem;
    /** Hide avatar/name when the previous message is the same author. */
    grouped: boolean;
    compact?: boolean;
}>();

const emit = defineEmits<{
    reply: [message: ChatMessageItem];
    openImage: [url: string];
}>();

const chat = useChatStore();

const isMine = computed(() => props.message.user?.id === chat.myId);

const segments = computed(() =>
    props.message.body === null ? [] : linkify(props.message.body),
);

const myReactions = computed(
    () =>
        new Set(
            props.message.reactions
                .filter((group) =>
                    group.user_ids.includes(chat.myId ?? -1),
                )
                .map((group) => group.emoji),
        ),
);

const time = computed(() => format(new Date(props.message.created_at), 'HH:mm'));

const cardWhen = computed(() => {
    const card = props.message.event_card;

    if (!card) {
        return '';
    }

    const start = new Date(card.starts_at);

    return card.all_day
        ? format(start, 'EEE, MMM d, yyyy')
        : `${format(start, 'EEE, MMM d')} · ${format(start, 'HH:mm')} - ${format(new Date(card.ends_at), 'HH:mm')}`;
});

const gridClass = computed(() =>
    props.message.attachments.length > 1 ? 'grid-cols-2' : 'grid-cols-1',
);
</script>

<template>
    <div
        class="group/message flex gap-2 px-3"
        :class="[isMine ? 'flex-row-reverse' : '', grouped ? 'mt-0.5' : 'mt-3']"
    >
        <!-- Author avatar (others only, first of a group) -->
        <div class="w-7 shrink-0" v-if="!isMine">
            <Avatar v-if="!grouped" class="size-7">
                <AvatarImage
                    v-if="message.user?.avatar_path"
                    :src="message.user.avatar_path"
                    :alt="message.user.name"
                />
                <AvatarFallback class="text-[10px]">
                    {{
                        (message.user?.name ?? '?')
                            .split(' ')
                            .map((part) => part[0])
                            .slice(0, 2)
                            .join('')
                    }}
                </AvatarFallback>
            </Avatar>
        </div>

        <div
            class="flex min-w-0 max-w-[78%] flex-col"
            :class="isMine ? 'items-end' : 'items-start'"
        >
            <span
                v-if="!grouped && !isMine"
                class="px-1 pb-0.5 text-[11px] font-medium text-muted-foreground"
            >
                {{ message.user?.name ?? 'Former member' }}
            </span>

            <!-- Reply excerpt -->
            <div
                v-if="message.reply_to"
                class="mb-0.5 max-w-full truncate rounded-lg border-l-2 border-primary/50 bg-muted/50 px-2 py-1 text-xs text-muted-foreground"
            >
                <span class="font-medium">
                    {{ message.reply_to.user_name ?? 'Someone' }}:
                </span>
                {{ message.reply_to.excerpt ?? 'Removed message' }}
            </div>

            <div class="relative">
                <!-- Tombstone -->
                <div
                    v-if="message.is_deleted"
                    class="rounded-2xl border border-dashed px-3 py-1.5 text-sm text-muted-foreground italic"
                >
                    Message removed
                </div>

                <template v-else>
                    <!-- Shared event card -->
                    <button
                        v-if="message.event_card"
                        type="button"
                        class="mb-1 block w-64 overflow-hidden rounded-xl border bg-card text-left shadow-sm transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="router.visit(message.event_card.url)"
                    >
                        <div
                            class="flex items-center gap-1.5 border-b bg-muted/40 px-3 py-1.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            <CalendarDays class="size-3.5" aria-hidden="true" />
                            Shared event
                        </div>
                        <div class="space-y-1 px-3 py-2">
                            <p class="text-sm leading-snug font-semibold">
                                {{ message.event_card.title }}
                            </p>
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Clock
                                    class="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ cardWhen }}
                            </p>
                            <p
                                v-if="message.event_card.location"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <MapPin
                                    class="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">
                                    {{ message.event_card.location }}
                                </span>
                            </p>
                            <p class="pt-0.5 text-xs font-medium text-primary">
                                View event
                            </p>
                        </div>
                    </button>

                    <!-- Body -->
                    <div
                        v-if="segments.length > 0"
                        class="rounded-2xl px-3 py-1.5 text-sm break-words whitespace-pre-wrap"
                        :class="
                            isMine
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted'
                        "
                    >
                        <template
                            v-for="(segment, index) in segments"
                            :key="index"
                        >
                            <a
                                v-if="segment.type === 'link'"
                                :href="segment.href"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="underline underline-offset-2"
                                >{{ segment.value }}</a
                            >
                            <template v-else>{{ segment.value }}</template>
                        </template>
                        <span
                            v-if="message.is_pinned"
                            class="ml-1 inline-flex align-middle opacity-70"
                        >
                            <Pin class="size-3" aria-hidden="true" />
                        </span>
                    </div>

                    <!-- Photos -->
                    <div
                        v-if="message.attachments.length > 0"
                        class="mt-1 grid max-w-72 gap-1"
                        :class="gridClass"
                    >
                        <button
                            v-for="attachment in message.attachments"
                            :key="attachment.id"
                            type="button"
                            class="overflow-hidden rounded-xl border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :style="
                                attachment.width && attachment.height
                                    ? {
                                          aspectRatio: `${attachment.width} / ${attachment.height}`,
                                      }
                                    : { aspectRatio: '1 / 1' }
                            "
                            @click="emit('openImage', attachment.url)"
                        >
                            <img
                                :src="attachment.url"
                                :alt="attachment.original_name"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                        </button>
                    </div>

                    <!-- Poll -->
                    <ChatPollCard
                        v-if="message.poll"
                        :message-id="message.id"
                        :poll="message.poll"
                        class="mt-1"
                    />
                </template>

                <!-- Hover actions -->
                <div
                    v-if="!message.is_deleted"
                    class="absolute top-1/2 flex -translate-y-1/2 items-center gap-0.5 rounded-full border bg-background p-0.5 opacity-0 shadow-sm transition-opacity group-hover/message:opacity-100 focus-within:opacity-100"
                    :class="isMine ? 'right-full mr-1' : 'left-full ml-1'"
                >
                    <Popover>
                        <PopoverTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-6"
                                aria-label="React"
                            >
                                <SmilePlus class="size-3.5" aria-hidden="true" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            class="flex w-auto gap-0.5 p-1"
                            side="top"
                        >
                            <button
                                v-for="emoji in CHAT_REACTIONS"
                                :key="emoji"
                                type="button"
                                class="rounded-full px-1.5 py-0.5 text-lg transition-transform hover:scale-125"
                                :class="{
                                    'bg-accent': myReactions.has(emoji),
                                }"
                                :aria-label="`React with ${emoji}`"
                                @click="chat.toggleReaction(message.id, emoji)"
                            >
                                {{ emoji }}
                            </button>
                        </PopoverContent>
                    </Popover>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        aria-label="Reply"
                        @click="emit('reply', message)"
                    >
                        <Reply class="size-3.5" aria-hidden="true" />
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        :aria-label="message.is_pinned ? 'Unpin' : 'Pin'"
                        @click="chat.togglePin(message.id)"
                    >
                        <Pin
                            class="size-3.5"
                            :class="{ 'fill-current': message.is_pinned }"
                            aria-hidden="true"
                        />
                    </Button>

                    <Button
                        v-if="isMine"
                        variant="ghost"
                        size="icon"
                        class="size-6 text-destructive"
                        aria-label="Delete message"
                        @click="chat.deleteMessage(message.id)"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <!-- Reactions -->
            <div
                v-if="message.reactions.length > 0"
                class="-mt-1.5 flex flex-wrap gap-1 px-1"
            >
                <button
                    v-for="group in message.reactions"
                    :key="group.emoji"
                    type="button"
                    class="z-10 inline-flex items-center gap-0.5 rounded-full border bg-background px-1.5 py-0 text-xs shadow-sm"
                    :class="{
                        'border-primary': myReactions.has(group.emoji),
                    }"
                    :aria-label="`${group.count} reacted ${group.emoji}`"
                    @click="chat.toggleReaction(message.id, group.emoji)"
                >
                    {{ group.emoji }}
                    <span class="tabular-nums">{{ group.count }}</span>
                </button>
            </div>

            <span
                v-if="!compact && !grouped"
                class="px-1 pt-0.5 text-[10px] text-muted-foreground"
            >
                {{ time }}
            </span>
        </div>
    </div>
</template>
