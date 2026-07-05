<script setup lang="ts">
import { CalendarClock, PenSquare, Users } from '@lucide/vue';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useChatStore } from '@/stores/chat';

defineProps<{ compact?: boolean }>();

const emit = defineEmits<{ select: [id: number] }>();

const chat = useChatStore();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center justify-between px-3 py-2">
            <h2 class="text-sm font-semibold">Chats</h2>
            <Button
                variant="ghost"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                @click="chat.openNewGroup()"
            >
                <PenSquare class="size-3.5" aria-hidden="true" />
                New group
            </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <p
                v-if="chat.conversations.length === 0"
                class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
                No chats yet. Start a group chat with your teammates.
            </p>

            <button
                v-for="conversation in chat.sortedConversations"
                :key="conversation.id"
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-muted/60"
                :class="{
                    'bg-muted': conversation.id === chat.activeId,
                }"
                @click="emit('select', conversation.id)"
            >
                <Avatar class="size-10 shrink-0">
                    <AvatarImage
                        v-if="conversation.photo_url"
                        :src="conversation.photo_url"
                        :alt="conversation.name"
                    />
                    <AvatarFallback
                        :class="
                            conversation.is_division
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-accent text-accent-foreground'
                        "
                    >
                        <Users
                            v-if="conversation.is_division"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <CalendarClock
                            v-else-if="conversation.event"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <template v-else>
                            {{ conversation.name[0] }}
                        </template>
                    </AvatarFallback>
                </Avatar>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span
                            class="truncate text-sm"
                            :class="
                                conversation.unread > 0
                                    ? 'font-semibold'
                                    : 'font-medium'
                            "
                        >
                            {{ conversation.name }}
                        </span>
                        <Badge
                            v-if="conversation.is_division"
                            variant="outline"
                            class="shrink-0 px-1.5 py-0 text-[10px] font-normal"
                        >
                            Division
                        </Badge>
                    </div>
                    <p
                        class="truncate text-xs"
                        :class="
                            conversation.unread > 0
                                ? 'font-medium text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <span v-if="conversation.event" class="text-primary">
                            {{ conversation.event.title }} ·
                        </span>
                        {{ conversation.last_preview ?? 'No messages yet' }}
                    </p>
                </div>

                <Badge
                    v-if="conversation.unread > 0"
                    variant="destructive"
                    class="h-5 min-w-5 shrink-0 justify-center rounded-full px-1 text-[10px] tabular-nums"
                >
                    {{ conversation.unread > 99 ? '99+' : conversation.unread }}
                </Badge>
            </button>
        </div>
    </div>
</template>
