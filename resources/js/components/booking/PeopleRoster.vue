<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * The named people on a booking, numbered as they will appear on the
 * printed form. Long lists open on their first rows. Bookings made before
 * names were recorded say so instead of showing an empty list.
 */
const props = defineProps<{
    people: string[];
    /** Head count for older bookings that kept no names. */
    count?: number | null;
    /** Rows shown before "Show all". */
    fold?: number;
}>();

const open = ref(false);
const fold = computed(() => props.fold ?? 8);
const folded = computed(
    () => !open.value && props.people.length > fold.value + 2,
);
const shown = computed(() =>
    folded.value ? props.people.slice(0, fold.value) : props.people,
);
</script>

<template>
    <div>
        <ol
            v-if="people.length > 0"
            class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
        >
            <li
                v-for="(name, i) in shown"
                :key="i"
                class="flex min-w-0 items-baseline gap-2"
            >
                <span
                    class="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                    aria-hidden="true"
                >
                    {{ i + 1 }}
                </span>
                <span class="min-w-0 break-words">{{ name }}</span>
            </li>
        </ol>
        <p v-else class="text-sm text-muted-foreground">
            {{
                count
                    ? `${count} ${count === 1 ? 'person' : 'people'}; names were not recorded with this booking.`
                    : 'No names recorded.'
            }}
        </p>
        <button
            v-if="folded"
            type="button"
            class="mt-2 ml-7 rounded-sm text-[13px] font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
            @click="open = true"
        >
            Show all {{ people.length }}
        </button>
    </div>
</template>
