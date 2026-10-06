<script setup lang="ts">
import { Check } from '@lucide/vue';
import { defaultWindow, useEventListener } from '@vueuse/core';
import { nextTick, ref, watch } from 'vue';
import { pillDanger, pillQuiet, pillSuccess } from '@/components/desk/desk';

/**
 * The decision, pinned to the bottom of the request: approve in one click,
 * reject only with a reason the requester will read. A blocked approval says
 * why instead of just greying out. A and R decide from the keyboard.
 */
const props = defineProps<{
    approveLabel: string;
    /** Why approving is impossible right now, if it is. */
    blocked?: string | null;
    processing: boolean;
    /** Who reads the rejection reason. */
    requester: string | null;
    /** Ready-made reasons that fit this request; one click fills the box. */
    suggestions?: string[];
    /** A server refusal (stale data, slot taken, reason too short). */
    error?: string | null;
}>();

const emit = defineEmits<{
    approve: [];
    reject: [reason: string];
}>();

const MIN_REASON = 10;

const rejecting = ref(false);
const reason = ref('');
const box = ref<HTMLTextAreaElement | null>(null);

const openReject = async () => {
    rejecting.value = true;
    await nextTick();
    box.value?.focus();
};

const closeReject = () => {
    rejecting.value = false;
    reason.value = '';
};

const submitReject = () => {
    if (reason.value.trim().length >= MIN_REASON && !props.processing) {
        emit('reject', reason.value.trim());
    }
};

const approve = () => {
    if (!props.blocked && !props.processing) {
        emit('approve');
    }
};

const fill = async (text: string) => {
    reason.value = text;
    await nextTick();
    box.value?.focus();
};

// A new request under the bar starts clean.
watch(
    () => props.requester,
    () => closeReject(),
);

defineExpose({ closeReject });

useEventListener(defaultWindow, 'keydown', (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null;

    if (e.key === 'Escape' && rejecting.value) {
        closeReject();

        return;
    }

    if (
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        target?.closest('input, textarea, select, [contenteditable]') ||
        document.querySelector('[role="dialog"], [role="menu"]')
    ) {
        return;
    }

    if (e.key === 'a' && !rejecting.value) {
        e.preventDefault();
        approve();
    } else if (e.key === 'r' && !rejecting.value) {
        e.preventDefault();
        void openReject();
    }
});
</script>

<template>
    <div
        class="sticky bottom-0 z-10 -mx-4 border-t border-border bg-card px-4 py-3 sm:-mx-6 sm:px-6 dark:bg-background/85 dark:backdrop-blur-md"
    >
        <p
            v-if="error"
            class="mb-2 text-[13px] font-medium text-destructive"
            role="alert"
        >
            {{ error }}
        </p>

        <div v-if="!rejecting" class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                :class="pillSuccess"
                :disabled="!!blocked || processing"
                :aria-describedby="blocked ? 'decision-blocked' : undefined"
                @click="approve"
            >
                <Check aria-hidden="true" />
                {{ approveLabel }}
            </button>
            <button type="button" :class="pillQuiet" @click="openReject">
                Reject…
            </button>

            <p
                v-if="blocked"
                id="decision-blocked"
                class="text-[13px] text-muted-foreground"
            >
                {{ blocked }}
            </p>

            <p
                class="ml-auto hidden items-center gap-3 text-[12px] text-muted-foreground lg:flex"
                aria-hidden="true"
            >
                <span
                    ><kbd class="font-sans font-semibold">A</kbd> approve</span
                >
                <span><kbd class="font-sans font-semibold">R</kbd> reject</span>
                <span
                    ><kbd class="font-sans font-semibold">J</kbd>/<kbd
                        class="font-sans font-semibold"
                        >K</kbd
                    >
                    next/previous</span
                >
            </p>
        </div>

        <form v-else class="grid gap-2" @submit.prevent="submitReject">
            <label
                for="decision-reason"
                class="text-[13px] text-muted-foreground"
            >
                Reason, sent to {{ requester ?? 'the requester' }}
            </label>
            <textarea
                id="decision-reason"
                ref="box"
                v-model="reason"
                rows="2"
                maxlength="1000"
                class="w-full resize-none rounded-lg bg-background px-3 py-2 text-sm ring-1 ring-input outline-none ring-inset placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                :placeholder="`At least ${MIN_REASON} characters`"
                @keydown.meta.enter.prevent="submitReject"
                @keydown.ctrl.enter.prevent="submitReject"
            />

            <div
                v-if="suggestions?.length"
                class="flex flex-wrap gap-1.5"
                role="group"
                aria-label="Suggested reasons"
            >
                <button
                    v-for="text in suggestions"
                    :key="text"
                    type="button"
                    class="max-w-full truncate rounded-full bg-muted px-2.5 py-1 text-[12px] text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    @click="fill(text)"
                >
                    {{ text }}
                </button>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="submit"
                    :class="pillDanger"
                    :disabled="reason.trim().length < MIN_REASON || processing"
                >
                    Reject request
                </button>
                <button type="button" :class="pillQuiet" @click="closeReject">
                    Cancel
                </button>
                <span
                    class="ml-auto text-[12px] text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {{
                        reason.trim().length < MIN_REASON
                            ? `${MIN_REASON - reason.trim().length} more characters`
                            : ''
                    }}
                </span>
            </div>
        </form>
    </div>
</template>
