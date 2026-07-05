<script setup lang="ts">
import { CalendarClock, Search, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import { ApiError } from '@/lib/api';
import { useChatStore } from '@/stores/chat';
import type { ChatCandidate } from '@/types/chat';

const chat = useChatStore();

const open = computed({
    get: () => chat.newGroup.open,
    set: (value) => {
        if (!value) {
            chat.closeNewGroup();
        }
    },
});

const name = ref('');
const term = ref('');
const results = ref<ChatCandidate[]>([]);
const selected = ref<ChatCandidate[]>([]);
const creating = ref(false);

// Reset each time the dialog opens; seed members from an event when present.
watch(
    () => chat.newGroup.open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        selected.value = chat.newGroup.seed.filter((c) => c.id !== chat.myId);
        name.value = chat.newGroup.eventTitle ?? '';
        term.value = '';
        results.value = [];
        void runSearch();
    },
);

// Empty term lists people right away; typing narrows it live.
const runSearch = useDebounceFn(async () => {
    const query = term.value.trim();
    const found = await chat.searchCandidates(query);

    if (query === term.value.trim()) {
        results.value = found;
    }
}, 250);

watch(term, runSearch);

const isSelected = (id: number) => selected.value.some((c) => c.id === id);

const toggle = (candidate: ChatCandidate) => {
    if (candidate.id === chat.myId) {
        return;
    }

    selected.value = isSelected(candidate.id)
        ? selected.value.filter((c) => c.id !== candidate.id)
        : [...selected.value, candidate];
};

const canCreate = computed(
    () => name.value.trim() !== '' && selected.value.length > 0,
);

const create = async () => {
    if (!canCreate.value || creating.value) {
        return;
    }

    creating.value = true;

    try {
        const id = await chat.createConversation({
            name: name.value.trim(),
            memberIds: selected.value.map((c) => c.id),
            eventId: chat.newGroup.eventId ?? undefined,
        });

        chat.closeNewGroup();
        chat.openMini();
        await chat.openConversation(id);
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not create the chat.',
        );
    } finally {
        creating.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>New group chat</DialogTitle>
                <DialogDescription>
                    Add anyone across divisions. Everyone you add can see and
                    post in this chat.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div
                    v-if="chat.newGroup.eventTitle"
                    class="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
                >
                    <CalendarClock
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate">
                        Discussion for
                        <span class="font-medium">
                            {{ chat.newGroup.eventTitle }}
                        </span>
                    </span>
                </div>

                <div class="grid gap-2">
                    <Label for="group-name">Chat name</Label>
                    <Input
                        id="group-name"
                        v-model="name"
                        placeholder="e.g. Festival planning"
                        maxlength="100"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Members</Label>
                    <div
                        v-if="selected.length > 0"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="member in selected"
                            :key="member.id"
                            class="inline-flex items-center gap-1 rounded-full border bg-card py-0.5 pr-1 pl-0.5 text-xs"
                        >
                            <Avatar class="size-5">
                                <AvatarImage
                                    v-if="member.avatar_path"
                                    :src="member.avatar_path"
                                    :alt="member.name"
                                />
                                <AvatarFallback class="text-[9px]">
                                    {{ getInitials(member.name) }}
                                </AvatarFallback>
                            </Avatar>
                            {{ member.name }}
                            <button
                                type="button"
                                class="rounded-full p-0.5 hover:bg-muted"
                                :aria-label="`Remove ${member.name}`"
                                @click="toggle(member)"
                            >
                                <X class="size-3" aria-hidden="true" />
                            </button>
                        </span>
                    </div>

                    <div class="relative">
                        <Search
                            class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="term"
                            class="pl-8"
                            placeholder="Search people…"
                            aria-label="Search people to add"
                        />
                    </div>

                    <ul
                        v-if="results.length > 0"
                        class="max-h-48 overflow-y-auto rounded-lg border"
                    >
                        <li v-for="candidate in results" :key="candidate.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 px-2.5 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                                :class="{
                                    'opacity-50': candidate.id === chat.myId,
                                }"
                                :disabled="candidate.id === chat.myId"
                                @click="toggle(candidate)"
                            >
                                <Avatar class="size-7">
                                    <AvatarImage
                                        v-if="candidate.avatar_path"
                                        :src="candidate.avatar_path"
                                        :alt="candidate.name"
                                    />
                                    <AvatarFallback class="text-[10px]">
                                        {{ getInitials(candidate.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <span class="flex-1 truncate">
                                    {{ candidate.name }}
                                </span>
                                <span
                                    v-if="isSelected(candidate.id)"
                                    class="text-xs text-primary"
                                >
                                    Added
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="chat.closeNewGroup()">
                    Cancel
                </Button>
                <Button :disabled="!canCreate || creating" @click="create">
                    Create chat
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
