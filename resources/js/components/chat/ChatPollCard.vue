<script setup lang="ts">
import { BarChart3, Check } from '@lucide/vue';
import { computed } from 'vue';
import { useChatStore } from '@/stores/chat';
import type { ChatPollData } from '@/types/chat';

const props = defineProps<{
    messageId: number;
    poll: ChatPollData;
}>();

const chat = useChatStore();

const totalVotes = computed(() =>
    props.poll.options.reduce((sum, option) => sum + option.voters.length, 0),
);

const myOptionIds = computed(
    () =>
        new Set(
            props.poll.options
                .filter((option) =>
                    option.voters.some((voter) => voter.id === chat.myId),
                )
                .map((option) => option.id),
        ),
);

const toggle = (optionId: number) => {
    let next: number[];

    if (props.poll.allows_multiple) {
        next = myOptionIds.value.has(optionId)
            ? [...myOptionIds.value].filter((id) => id !== optionId)
            : [...myOptionIds.value, optionId];
    } else {
        next = myOptionIds.value.has(optionId) ? [] : [optionId];
    }

    void chat.vote(props.messageId, props.poll.id, next);
};

const share = (count: number) =>
    totalVotes.value === 0 ? 0 : Math.round((count / totalVotes.value) * 100);
</script>

<template>
    <div class="w-64 max-w-full rounded-xl border bg-card p-2.5">
        <div class="flex items-start gap-1.5">
            <BarChart3
                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-sm font-medium">{{ poll.question }}</p>
        </div>

        <ul class="mt-2 space-y-1.5">
            <li v-for="option in poll.options" :key="option.id">
                <button
                    type="button"
                    class="relative w-full overflow-hidden rounded-lg border px-2 py-1.5 text-left text-sm transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="{
                        'border-primary': myOptionIds.has(option.id),
                    }"
                    @click="toggle(option.id)"
                >
                    <span
                        class="absolute inset-y-0 left-0 bg-primary/15 transition-[width]"
                        :style="{ width: `${share(option.voters.length)}%` }"
                        aria-hidden="true"
                    />
                    <span class="relative flex items-center justify-between gap-2">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <Check
                                v-if="myOptionIds.has(option.id)"
                                class="size-3.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ option.label }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                            {{ option.voters.length }}
                        </span>
                    </span>
                </button>
                <p
                    v-if="option.voters.length > 0"
                    class="mt-0.5 truncate px-2 text-[10px] text-muted-foreground"
                >
                    {{ option.voters.map((voter) => voter.name).join(', ') }}
                </p>
            </li>
        </ul>

        <p class="mt-1.5 text-[10px] text-muted-foreground">
            {{ totalVotes }} {{ totalVotes === 1 ? 'vote' : 'votes' }}
            <template v-if="poll.allows_multiple"> · multiple choice</template>
        </p>
    </div>
</template>
