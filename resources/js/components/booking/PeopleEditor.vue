<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, nextTick, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { PEOPLE_MAX, rowErrors, tidyName } from '@/lib/people';

/**
 * Everyone on a booking, one name per row: they go on the printed form to
 * sign. Built for typing a list fast: Enter adds the next row, Backspace
 * on an empty row removes it, and pasting several lines fills several rows.
 */
const props = defineProps<{
    /** Prefix for element ids; one editor per form. */
    id: string;
    /** "attendee" or "passenger", for labels. */
    noun: string;
    capacity?: number | null;
    resourceName?: string | null;
    /** Server or form errors, keyed `people` and `people.N`. */
    errors?: Record<string, string[] | undefined>;
}>();

const people = defineModel<string[]>({ required: true });

const emit = defineEmits<{
    /** The person edited something an error was about. */
    clear: [];
}>();

/* Edits build on the newest list. The model only reflects an edit once
   the parent re-renders, so two edits in one tick (a paste fills this row,
   then adds rows after it) would otherwise drop the first. */
let latest = people.value;

watch(people, (next) => (latest = next));

function commit(next: string[]): void {
    latest = next;
    people.value = next;
    emit('clear');
}

/* Stable row keys, so typing never remounts an input under the caret.
   Plain (not reactive): the rows already re-render with the list. */
let nextKey = 0;
const keys: number[] = [];

const keyAt = (i: number) => {
    while (keys.length < people.value.length) {
        keys.push(nextKey++);
    }

    keys.length = people.value.length;

    return keys[i];
};

const limit = computed(() =>
    Math.min(props.capacity || PEOPLE_MAX, PEOPLE_MAX),
);
const named = computed(
    () => people.value.filter((n) => tidyName(n) !== '').length,
);
const full = computed(() => people.value.length >= limit.value);

const perRow = computed(() => rowErrors(people.value, props.errors ?? {}));
const listError = computed(() => props.errors?.people?.[0]);

const focusRow = async (i: number, atEnd = false) => {
    await nextTick();

    const input = document.getElementById(
        `${props.id}-${i}`,
    ) as HTMLInputElement | null;

    input?.focus();

    if (atEnd && input) {
        input.setSelectionRange(input.value.length, input.value.length);
    }
};

function set(i: number, value: string): void {
    const next = [...latest];
    next[i] = value;
    commit(next);
}

function add(at = latest.length, names: string[] = ['']): void {
    const room = limit.value - latest.length;

    if (room <= 0) {
        return;
    }

    const next = [...latest];
    next.splice(at, 0, ...names.slice(0, room));
    keys.splice(at, 0, ...names.slice(0, room).map(() => nextKey++));
    commit(next);
    void focusRow(at + Math.min(names.length, room) - 1, true);
}

function remove(i: number): void {
    // The last row clears instead, so there is always a row to type in.
    if (latest.length === 1) {
        set(0, '');
        void focusRow(0);

        return;
    }

    keys.splice(i, 1);
    commit(latest.filter((_, index) => index !== i));
    void focusRow(Math.max(0, i - 1), true);
}

function onEnter(i: number): void {
    if (i < latest.length - 1) {
        void focusRow(i + 1, true);
    } else if (tidyName(latest[i]) !== '') {
        add();
    }
}

function onBackspace(event: KeyboardEvent, i: number): void {
    if (latest[i] === '' && latest.length > 1) {
        event.preventDefault();
        remove(i);
    }
}

/** A pasted list (one name per line) fills this row and the ones after. */
function onPaste(event: ClipboardEvent, i: number): void {
    const lines = (event.clipboardData?.getData('text') ?? '')
        .split(/\r?\n/)
        .map(tidyName)
        .filter(Boolean);

    if (lines.length < 2) {
        return;
    }

    event.preventDefault();

    const [first, ...rest] = lines;
    set(i, latest[i].trim() ? `${latest[i]} ${first}` : first);
    add(i + 1, rest);
}

const label = (i: number) =>
    tidyName(people.value[i]) ? people.value[i] : `person ${i + 1}`;
</script>

<template>
    <section class="grid gap-2" :aria-labelledby="`${id}-label`">
        <div class="flex items-baseline justify-between gap-3">
            <h3 :id="`${id}-label`" class="text-sm font-medium capitalize">
                {{ noun }}s
            </h3>
            <span
                class="text-xs text-muted-foreground tabular-nums"
                aria-live="polite"
            >
                {{ named
                }}{{
                    capacity
                        ? ` of ${capacity} seats`
                        : ` ${named === 1 ? 'person' : 'people'}`
                }}
            </span>
        </div>
        <p class="-mt-1 text-xs text-muted-foreground">
            Everyone who’ll be there, one name each. They sign beside their
            names on the printed form.
        </p>

        <ol class="grid gap-1.5">
            <li v-for="(name, i) in people" :key="keyAt(i)" class="grid gap-1">
                <div class="flex items-center gap-2">
                    <span
                        class="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        {{ i + 1 }}
                    </span>
                    <Input
                        :id="`${id}-${i}`"
                        :model-value="name"
                        class="h-10 rounded-xl"
                        maxlength="100"
                        autocomplete="off"
                        :placeholder="i === 0 ? 'Full name' : ''"
                        :aria-label="`${noun} ${i + 1}, full name`"
                        :aria-invalid="!!perRow[i]"
                        :aria-describedby="
                            perRow[i] ? `${id}-${i}-error` : undefined
                        "
                        @update:model-value="(v) => set(i, String(v))"
                        @keydown.enter.prevent="onEnter(i)"
                        @keydown.backspace="
                            (e: KeyboardEvent) => onBackspace(e, i)
                        "
                        @paste="(e: ClipboardEvent) => onPaste(e, i)"
                    />
                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none"
                        :aria-label="`Remove ${label(i)}`"
                        @click="remove(i)"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </div>
                <p
                    v-if="perRow[i]"
                    :id="`${id}-${i}-error`"
                    class="pl-7 text-sm text-destructive"
                >
                    {{ perRow[i] }}
                </p>
            </li>
        </ol>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pl-5">
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-foreground/85 transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-45"
                :disabled="full"
                @click="add()"
            >
                <Plus class="size-4" aria-hidden="true" />
                Add {{ noun }}
            </button>
            <span v-if="full" class="text-xs text-muted-foreground">
                {{ resourceName ?? 'It' }} seats {{ limit }}.
            </span>
            <span v-else class="text-xs text-muted-foreground max-sm:hidden">
                Enter adds the next row. Paste a list to add several.
            </span>
        </div>

        <p
            v-if="listError"
            :id="`${id}-error`"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ listError }}
        </p>
    </section>
</template>
