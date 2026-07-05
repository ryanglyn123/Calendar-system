<script setup lang="ts">
import { BarChart3, ImagePlus, Plus, Send, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/composables/useAuth';
import { ApiError } from '@/lib/api';
import { useChatStore } from '@/stores/chat';
import type { ChatMessageItem } from '@/types/chat';

const props = defineProps<{
    replyTo: ChatMessageItem | null;
    compact?: boolean;
}>();

const emit = defineEmits<{
    clearReply: [];
}>();

const chat = useChatStore();
const { user } = useAuth();

const body = ref('');
const photos = ref<File[]>([]);
const previews = ref<string[]>([]);
const sending = ref(false);

const canSend = computed(
    () => !sending.value && (body.value.trim() !== '' || photos.value.length > 0),
);

watch(photos, (next, previous) => {
    void previous;
    previews.value.forEach((url) => URL.revokeObjectURL(url));
    previews.value = next.map((file) => URL.createObjectURL(file));
});

const pickPhotos = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const picked = [...(input.files ?? [])].slice(0, 4 - photos.value.length);

    photos.value = [...photos.value, ...picked].slice(0, 4);
    input.value = '';
};

const removePhoto = (index: number) => {
    photos.value = photos.value.filter((_, i) => i !== index);
};

const send = async () => {
    if (!canSend.value) {
        return;
    }

    sending.value = true;

    try {
        await chat.send({
            body: body.value.trim() || undefined,
            photos: photos.value,
            replyToId: props.replyTo?.id ?? null,
        });

        body.value = '';
        photos.value = [];
        emit('clearReply');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not send the message.',
        );
    } finally {
        sending.value = false;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void send();
    }
};

const onInput = () => {
    chat.whisperTyping(user.value.name);
};

/* Poll builder */
const buildingPoll = ref(false);
const pollQuestion = ref('');
const pollOptions = ref<string[]>(['', '']);
const pollMultiple = ref(false);
const sendingPoll = ref(false);

const openPollBuilder = () => {
    pollQuestion.value = '';
    pollOptions.value = ['', ''];
    pollMultiple.value = false;
    buildingPoll.value = true;
};

const validPollOptions = computed(() =>
    pollOptions.value.map((option) => option.trim()).filter(Boolean),
);

const sendPoll = async () => {
    if (pollQuestion.value.trim() === '' || validPollOptions.value.length < 2) {
        return;
    }

    sendingPoll.value = true;

    try {
        await chat.send({
            poll: {
                question: pollQuestion.value.trim(),
                options: validPollOptions.value,
                allows_multiple: pollMultiple.value,
            },
        });

        buildingPoll.value = false;
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not create the poll.',
        );
    } finally {
        sendingPoll.value = false;
    }
};
</script>

<template>
    <div class="border-t bg-background p-2">
        <!-- Reply banner -->
        <div
            v-if="replyTo"
            class="mb-1.5 flex items-center justify-between gap-2 rounded-lg border-l-2 border-primary/60 bg-muted/50 px-2 py-1 text-xs"
        >
            <span class="min-w-0 truncate text-muted-foreground">
                Replying to
                <span class="font-medium">{{ replyTo.user?.name }}</span
                >: {{ replyTo.body ?? 'attachment' }}
            </span>
            <Button
                variant="ghost"
                size="icon"
                class="size-5 shrink-0"
                aria-label="Cancel reply"
                @click="emit('clearReply')"
            >
                <X class="size-3" aria-hidden="true" />
            </Button>
        </div>

        <!-- Photo previews -->
        <div v-if="previews.length > 0" class="mb-1.5 flex gap-1.5">
            <div
                v-for="(preview, index) in previews"
                :key="preview"
                class="relative size-14 overflow-hidden rounded-lg border"
            >
                <img :src="preview" alt="" class="size-full object-cover" />
                <button
                    type="button"
                    class="absolute top-0.5 right-0.5 rounded-full bg-background/90 p-0.5"
                    aria-label="Remove photo"
                    @click="removePhoto(index)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </div>
        </div>

        <div class="flex items-end gap-1.5">
            <label
                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                :aria-label="'Add photos'"
            >
                <ImagePlus class="size-4.5" aria-hidden="true" />
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    multiple
                    class="sr-only"
                    @change="pickPhotos"
                />
            </label>

            <Button
                variant="ghost"
                size="icon"
                class="size-8 shrink-0 rounded-full text-muted-foreground"
                aria-label="Create a poll"
                @click="openPollBuilder"
            >
                <BarChart3 class="size-4.5" aria-hidden="true" />
            </Button>

            <Textarea
                v-model="body"
                :rows="1"
                placeholder="Message your division"
                class="max-h-28 min-h-9 flex-1 resize-none rounded-2xl py-1.5"
                @keydown="onKeydown"
                @input="onInput"
            />

            <Button
                size="icon"
                class="size-8 shrink-0 rounded-full"
                :disabled="!canSend"
                aria-label="Send"
                data-test="chat-send"
                @click="send"
            >
                <Spinner v-if="sending" class="size-4" />
                <Send v-else class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <!-- Poll builder -->
        <Dialog v-model:open="buildingPoll">
            <DialogContent class="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Create a poll</DialogTitle>
                    <DialogDescription>
                        Your division votes right in the chat.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="poll-question">Question</Label>
                        <Input
                            id="poll-question"
                            v-model="pollQuestion"
                            maxlength="255"
                            placeholder="e.g. Team lunch where?"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Options</Label>
                        <div
                            v-for="(option, index) in pollOptions"
                            :key="index"
                            class="flex items-center gap-1.5"
                        >
                            <Input
                                v-model="pollOptions[index]"
                                maxlength="100"
                                :placeholder="`Option ${index + 1}`"
                            />
                            <Button
                                v-if="pollOptions.length > 2"
                                variant="ghost"
                                size="icon"
                                class="size-7 shrink-0"
                                aria-label="Remove option"
                                @click="
                                    pollOptions = pollOptions.filter(
                                        (_, i) => i !== index,
                                    )
                                "
                            >
                                <X class="size-3.5" aria-hidden="true" />
                            </Button>
                        </div>
                        <Button
                            v-if="pollOptions.length < 10"
                            variant="outline"
                            size="sm"
                            class="justify-start"
                            @click="pollOptions = [...pollOptions, '']"
                        >
                            <Plus aria-hidden="true" />
                            Add option
                        </Button>
                    </div>

                    <label class="flex items-center justify-between gap-2 text-sm">
                        Allow choosing several options
                        <Switch v-model="pollMultiple" />
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="buildingPoll = false">
                        Cancel
                    </Button>
                    <Button
                        :disabled="
                            sendingPoll ||
                            pollQuestion.trim() === '' ||
                            validPollOptions.length < 2
                        "
                        @click="sendPoll"
                    >
                        <Spinner v-if="sendingPoll" />
                        Post poll
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
