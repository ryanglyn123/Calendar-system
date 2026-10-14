<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onMounted, useId, useTemplateRef } from 'vue';
import { cn } from '@/lib/utils';
import type { DecisionKind } from './desk';
import { decisionUrls, pillDanger, pillQuiet } from './desk';

const props = defineProps<{
    kind: DecisionKind;
    id: number;
}>();

const emit = defineEmits<{ cancel: [] }>();

// Same size as the card's Approve and Reject, so the row doesn't shrink.
const big = 'h-11 px-5 text-sm';

const fieldId = useId();
const field = useTemplateRef<HTMLTextAreaElement>('field');
const form = useForm({ reason: '' });

onMounted(() => field.value?.focus());

const submit = () =>
    form.post(decisionUrls[props.kind].reject(props.id), {
        preserveScroll: true,
        preserveState: true,
    });
</script>

<template>
    <form
        class="grid gap-2.5"
        @submit.prevent="submit"
        @keydown.esc.stop="emit('cancel')"
    >
        <label :for="fieldId" class="text-sm font-medium">
            Why are you rejecting it?
            <span class="font-normal text-muted-foreground">
                The requester sees this.
            </span>
        </label>
        <textarea
            :id="fieldId"
            ref="field"
            v-model="form.reason"
            rows="2"
            maxlength="1000"
            required
            :aria-invalid="form.errors.reason ? true : undefined"
            class="min-h-16 w-full resize-y rounded-xl bg-background px-3.5 py-2.5 text-[15px] ring-1 ring-input outline-none ring-inset placeholder:text-muted-foreground focus:ring-2 focus:ring-ring/60 aria-invalid:ring-destructive"
            placeholder="e.g. The room is closed for the elevator works that morning."
        />
        <p v-if="form.errors.reason" class="text-sm text-destructive">
            {{ form.errors.reason }}
        </p>
        <p
            v-else-if="form.reason.trim().length < 10"
            class="text-sm text-muted-foreground"
        >
            A sentence or two, at least 10 characters.
        </p>
        <div class="flex items-center justify-end gap-3">
            <button
                type="button"
                :class="cn(pillQuiet, big)"
                @click="emit('cancel')"
            >
                Keep it
            </button>
            <button
                type="submit"
                :class="cn(pillDanger, big)"
                :disabled="form.processing || form.reason.trim().length < 10"
            >
                Send rejection
            </button>
        </div>
    </form>
</template>
