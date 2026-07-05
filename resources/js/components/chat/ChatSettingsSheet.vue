<script setup lang="ts">
import { Camera, LogOut, Pin, Search, Users, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { getInitials } from '@/composables/useInitials';
import { api, ApiError } from '@/lib/api';
import { useChatStore } from '@/stores/chat';
import type { ChatCandidate, ChatMessageItem } from '@/types/chat';

const open = defineModel<boolean>('open', { required: true });

const chat = useChatStore();

const conversation = computed(() => chat.activeConversation);
const isCustom = computed(() => conversation.value?.type === 'custom');

const uploading = ref(false);
const loadingPinned = ref(false);

// Rename (custom chats only).
const nameDraft = ref('');
watch(
    () => conversation.value?.id,
    () => {
        nameDraft.value = conversation.value?.name ?? '';
    },
    { immediate: true },
);

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    nameDraft.value = conversation.value?.name ?? '';
    loadingPinned.value = true;

    try {
        await chat.loadPinned();
    } finally {
        loadingPinned.value = false;
    }
});

const saveName = async () => {
    const id = conversation.value?.id;
    const name = nameDraft.value.trim();

    if (!id || name === '' || name === conversation.value?.name) {
        return;
    }

    try {
        await chat.updateConversation(id, { name });
        toast.success('Chat renamed.');
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not rename.',
        );
    }
};

const uploadPhoto = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    uploading.value = true;

    const form = new FormData();
    form.append('photo', file);

    try {
        await api.postForm(chat.photoUploadUrl, form);

        if (conversation.value) {
            conversation.value.photo_url = `${chat.photoUploadUrl.replace(/\?.*$/, '')}?v=${Date.now()}`;
        }

        toast.success('Photo updated.');
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not update.',
        );
    } finally {
        uploading.value = false;
    }
};

// --- Member management (custom chats) ---

const currentMemberIds = computed(() => chat.members.map((m) => m.id));

const removeMember = async (userId: number) => {
    const id = conversation.value?.id;

    if (!id || userId === conversation.value?.created_by) {
        return;
    }

    try {
        await chat.updateConversation(id, {
            memberIds: currentMemberIds.value.filter((m) => m !== userId),
        });
        await chat.reloadMembers();
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not remove.',
        );
    }
};

const term = ref('');
const results = ref<ChatCandidate[]>([]);

const runSearch = useDebounceFn(async () => {
    results.value =
        term.value.trim() === ''
            ? []
            : await chat.searchCandidates(term.value.trim());
}, 250);

watch(term, runSearch);

const addMember = async (candidate: ChatCandidate) => {
    const id = conversation.value?.id;

    if (!id || currentMemberIds.value.includes(candidate.id)) {
        return;
    }

    try {
        await chat.updateConversation(id, {
            memberIds: [...currentMemberIds.value, candidate.id],
        });
        await chat.reloadMembers();
        term.value = '';
        results.value = [];
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not add.',
        );
    }
};

const leave = async () => {
    const id = conversation.value?.id;

    if (!id) {
        return;
    }

    try {
        await chat.leaveConversation(id);
        open.value = false;
        toast.success('You left the chat.');
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not leave.',
        );
    }
};

const unpin = async (message: ChatMessageItem) => {
    await chat.togglePin(message.id);
};

const viewPinned = (message: ChatMessageItem) => {
    open.value = false;
    chat.jumpTo(message.id);
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-sm"
        >
            <SheetHeader>
                <SheetTitle>Chat settings</SheetTitle>
                <SheetDescription>
                    {{ conversation?.name }}
                </SheetDescription>
            </SheetHeader>

            <!-- Photo -->
            <div class="flex flex-col items-center gap-2 border-b px-4 pb-5">
                <label class="group relative cursor-pointer">
                    <Avatar class="size-20 border">
                        <AvatarImage
                            v-if="conversation?.photo_url"
                            :src="conversation.photo_url"
                            :alt="conversation?.name"
                        />
                        <AvatarFallback class="text-xl">
                            {{ conversation?.name?.[0] ?? '?' }}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        class="absolute inset-0 flex items-center justify-center rounded-full bg-black/45 text-white opacity-0 transition-opacity group-hover:opacity-100"
                    >
                        <Spinner v-if="uploading" class="size-5" />
                        <Camera v-else class="size-5" aria-hidden="true" />
                    </span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="sr-only"
                        :disabled="uploading"
                        @change="uploadPhoto"
                    />
                </label>
                <p class="text-xs text-muted-foreground">
                    Any member can change the photo.
                </p>
            </div>

            <!-- Rename (custom chats) -->
            <section v-if="isCustom" class="border-b px-4 py-4">
                <label
                    for="chat-name"
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Name
                </label>
                <div class="mt-2 flex gap-2">
                    <Input id="chat-name" v-model="nameDraft" maxlength="100" />
                    <Button
                        size="sm"
                        :disabled="
                            nameDraft.trim() === '' ||
                            nameDraft.trim() === conversation?.name
                        "
                        @click="saveName"
                    >
                        Save
                    </Button>
                </div>
            </section>

            <!-- Members -->
            <section class="border-b px-4 py-4">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Users class="size-3.5" aria-hidden="true" />
                    Members ({{ chat.members.length }})
                </h3>

                <!-- Add people (custom chats) -->
                <div v-if="isCustom" class="mt-2">
                    <div class="relative">
                        <Search
                            class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="term"
                            class="pl-8"
                            placeholder="Add people…"
                            aria-label="Search people to add"
                        />
                    </div>
                    <ul
                        v-if="results.length > 0"
                        class="mt-1 max-h-40 overflow-y-auto rounded-lg border"
                    >
                        <li v-for="candidate in results" :key="candidate.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm transition-colors hover:bg-muted disabled:opacity-50"
                                :disabled="
                                    currentMemberIds.includes(candidate.id)
                                "
                                @click="addMember(candidate)"
                            >
                                <Avatar class="size-6">
                                    <AvatarImage
                                        v-if="candidate.avatar_path"
                                        :src="candidate.avatar_path"
                                        :alt="candidate.name"
                                    />
                                    <AvatarFallback class="text-[9px]">
                                        {{ getInitials(candidate.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <span class="flex-1 truncate">
                                    {{ candidate.name }}
                                </span>
                                <span
                                    v-if="currentMemberIds.includes(candidate.id)"
                                    class="text-xs text-muted-foreground"
                                >
                                    Added
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>

                <ul class="mt-2 space-y-1.5">
                    <li
                        v-for="member in chat.members"
                        :key="member.id"
                        class="group flex items-center gap-2 text-sm"
                    >
                        <Avatar class="size-6">
                            <AvatarImage
                                v-if="member.avatar_path"
                                :src="member.avatar_path"
                                :alt="member.name"
                            />
                            <AvatarFallback class="text-[9px]">
                                {{ getInitials(member.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 flex-1 truncate">
                            {{ member.name }}
                            <span
                                v-if="member.id === chat.myId"
                                class="text-muted-foreground"
                            >
                                (you)
                            </span>
                        </span>
                        <button
                            v-if="
                                isCustom &&
                                member.id !== conversation?.created_by &&
                                member.id !== chat.myId
                            "
                            type="button"
                            class="text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive"
                            :aria-label="`Remove ${member.name}`"
                            @click="removeMember(member.id)"
                        >
                            <X class="size-3.5" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
            </section>

            <!-- Pinned messages -->
            <section class="border-b px-4 py-4">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Pin class="size-3.5" aria-hidden="true" />
                    Pinned messages
                </h3>

                <div v-if="loadingPinned" class="flex justify-center py-4">
                    <Spinner class="size-4" />
                </div>

                <p
                    v-else-if="chat.pinned.length === 0"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    Nothing pinned yet. Hover a message and use the pin.
                </p>

                <ul v-else class="mt-2 space-y-2">
                    <li
                        v-for="message in chat.pinned"
                        :key="message.id"
                        class="rounded-lg border p-2 text-sm transition-colors hover:bg-accent/50"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ message.user?.name ?? 'Former member' }}
                            </span>
                            <button
                                type="button"
                                class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                                @click="unpin(message)"
                            >
                                Unpin
                            </button>
                        </div>
                        <button
                            type="button"
                            class="mt-0.5 block w-full text-left"
                            @click="viewPinned(message)"
                        >
                            <span class="line-clamp-3 break-words">
                                {{
                                    message.body ??
                                    (message.poll
                                        ? `Poll: ${message.poll.question}`
                                        : (message.event_card?.title ?? 'Photo'))
                                }}
                            </span>
                            <span
                                class="mt-0.5 block text-xs font-medium text-primary"
                            >
                                View in chat
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <!-- Leave (custom chats) -->
            <section v-if="isCustom" class="px-4 py-4">
                <Button
                    variant="outline"
                    class="w-full gap-1.5 text-destructive"
                    @click="leave"
                >
                    <LogOut class="size-4" aria-hidden="true" />
                    Leave chat
                </Button>
            </section>
        </SheetContent>
    </Sheet>
</template>
