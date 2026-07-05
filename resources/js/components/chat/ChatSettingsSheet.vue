<script setup lang="ts">
import { Camera, Pin, Users } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { api, ApiError } from '@/lib/api';
import chatApi from '@/routes/api/chat';
import { useChatStore } from '@/stores/chat';
import type { ChatMessageItem } from '@/types/chat';

const open = defineModel<boolean>('open', { required: true });

const chat = useChatStore();

const uploading = ref(false);
const loadingPinned = ref(false);

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    loadingPinned.value = true;

    try {
        await chat.loadPinned();
    } finally {
        loadingPinned.value = false;
    }
});

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

        // Cache-bust so every surface swaps to the new photo at once.
        if (chat.division) {
            chat.division.photo_url = `${chatApi.photo.show().url}?v=${Date.now()}`;
        }

        toast.success('Group photo updated.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not update the photo.',
        );
    } finally {
        uploading.value = false;
    }
};

const unpin = async (message: ChatMessageItem) => {
    await chat.togglePin(message.id);
};

/** View the pinned message in context: close the sheet and jump to it. */
const viewPinned = (message: ChatMessageItem) => {
    open.value = false;
    chat.jumpTo(message.id);
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-sm">
            <SheetHeader>
                <SheetTitle>Chat settings</SheetTitle>
                <SheetDescription>
                    {{ chat.division?.name }} group chat
                </SheetDescription>
            </SheetHeader>

            <!-- Group photo -->
            <div class="flex flex-col items-center gap-2 border-b px-4 pb-5">
                <label class="group relative cursor-pointer">
                    <Avatar class="size-20 border">
                        <AvatarImage
                            v-if="chat.division?.photo_url"
                            :src="chat.division.photo_url"
                            :alt="chat.division?.name"
                        />
                        <AvatarFallback class="text-xl">
                            {{ chat.division?.name?.[0] ?? '?' }}
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
                    Any member can change the group photo.
                </p>
            </div>

            <!-- Members with read receipts always on -->
            <section class="border-b px-4 py-4">
                <h3 class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <Users class="size-3.5" aria-hidden="true" />
                    Members ({{ chat.members.length }})
                </h3>
                <ul class="mt-2 space-y-1.5">
                    <li
                        v-for="member in chat.members"
                        :key="member.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Avatar class="size-6">
                            <AvatarImage
                                v-if="member.avatar_path"
                                :src="member.avatar_path"
                                :alt="member.name"
                            />
                            <AvatarFallback class="text-[9px]">
                                {{ member.name[0] }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 truncate">
                            {{ member.name }}
                            <span
                                v-if="member.id === chat.myId"
                                class="text-muted-foreground"
                            >
                                (you)
                            </span>
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Pinned messages -->
            <section class="px-4 py-4">
                <h3 class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
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
                            <span class="text-xs font-medium text-muted-foreground">
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
                                {{ message.body ?? (message.poll ? `Poll: ${message.poll.question}` : (message.event_card?.title ?? 'Photo')) }}
                            </span>
                            <span class="mt-0.5 block text-xs font-medium text-primary">
                                View in chat
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </SheetContent>
    </Sheet>
</template>
