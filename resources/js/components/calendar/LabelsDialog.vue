<script setup lang="ts">
import { ChevronDown, Eye, EyeOff, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useColorLabels } from '@/composables/useColorLabels';
import { ApiError } from '@/lib/api';
import { colorName, EVENT_COLORS, sameColor } from '@/lib/eventColors';
import type { ColorLabel } from '@/lib/eventColors';

/**
 * Google Calendar's Labels: name any color ("Tomato" = "Urgent"), hide the
 * colors you never use from the menu, add your own colors. Edited as a draft
 * and saved together; Cancel leaves everything as it was.
 */
const open = defineModel<boolean>('open', { required: true });

const { labels, save } = useColorLabels();

const draft = ref<ColorLabel[]>([]);
const saving = ref(false);
const error = ref<string | null>(null);
const customFor = ref<number | null>(null);
const customInput = ref<HTMLInputElement | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = labels.value.map((l) => ({ ...l }));
        error.value = null;
    }
});

/** Another row already has this color (each color carries one label). */
const taken = (hex: string, index: number) =>
    draft.value.some((l, i) => i !== index && sameColor(l.color, hex));

const add = () => {
    const free = EVENT_COLORS.find(
        (c) => !draft.value.some((l) => sameColor(l.color, c.hex)),
    );

    draft.value.push({
        color: free?.hex ?? '#5F6368',
        name: null,
        hidden: false,
    });
};

const pickCustom = (index: number) => {
    customFor.value = index;
    customInput.value?.click();
};

const onCustom = (event: Event) => {
    const hex = (event.target as HTMLInputElement).value.toUpperCase();

    if (customFor.value !== null && !taken(hex, customFor.value)) {
        draft.value[customFor.value].color = hex;
    }

    customFor.value = null;
};

const submit = async () => {
    saving.value = true;
    error.value = null;

    try {
        await save(
            draft.value.map((l) => ({
                color: l.color,
                name: l.name?.trim() || null,
                hidden: l.hidden,
            })),
        );
        toast.success('Labels saved.');
        open.value = false;
    } catch (e) {
        error.value =
            e instanceof ApiError
                ? (Object.values(e.errors)[0]?.[0] ?? e.message)
                : 'Could not save the labels.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-lg">
            <DialogHeader class="border-b border-border px-6 pt-6 pb-4">
                <DialogTitle class="text-xl">Labels</DialogTitle>
                <DialogDescription>
                    Labels are only visible to you. Hidden colors stay off your
                    color menu.
                </DialogDescription>
            </DialogHeader>

            <ul class="min-h-0 flex-1 space-y-2 overflow-y-auto px-6 py-4">
                <li
                    v-for="(label, i) in draft"
                    :key="i"
                    class="group flex items-center gap-2"
                    :class="{ 'opacity-50': label.hidden }"
                >
                    <!-- The row's color -->
                    <Popover>
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex h-10 shrink-0 items-center gap-1 rounded-md px-2 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
                                :aria-label="`Color: ${colorName(label.color)}`"
                            >
                                <span
                                    class="size-5 rounded-full"
                                    :style="{ background: label.color }"
                                    aria-hidden="true"
                                />
                                <ChevronDown
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="start" class="w-auto p-3">
                            <div
                                class="grid grid-cols-[repeat(8,1.75rem)] gap-1.5"
                            >
                                <button
                                    v-for="color in EVENT_COLORS"
                                    :key="color.hex"
                                    type="button"
                                    class="size-7 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-25"
                                    :class="{
                                        'ring-2 ring-foreground ring-offset-2 ring-offset-popover':
                                            sameColor(color.hex, label.color),
                                    }"
                                    :style="{ background: color.hex }"
                                    :title="
                                        taken(color.hex, i)
                                            ? `${color.name} has its own label`
                                            : color.name
                                    "
                                    :aria-label="color.name"
                                    :disabled="taken(color.hex, i)"
                                    @click="label.color = color.hex"
                                />
                            </div>
                            <button
                                type="button"
                                class="mt-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[13px] text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                                @click="pickCustom(i)"
                            >
                                <Pencil class="size-3.5" aria-hidden="true" />
                                Custom color
                            </button>
                        </PopoverContent>
                    </Popover>

                    <input
                        v-model="label.name"
                        type="text"
                        maxlength="40"
                        placeholder="Add a label"
                        :aria-label="`Label for ${colorName(label.color)}`"
                        class="h-11 min-w-0 flex-1 rounded-md bg-muted/70 px-4 text-[15px] outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    />

                    <button
                        type="button"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-full text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                        :aria-pressed="!label.hidden"
                        :aria-label="
                            label.hidden
                                ? `Show ${colorName(label.color)} in the color menu`
                                : `Hide ${colorName(label.color)} from the color menu`
                        "
                        :title="
                            label.hidden
                                ? 'Hidden from the menu'
                                : 'Shown in the menu'
                        "
                        @click="label.hidden = !label.hidden"
                    >
                        <EyeOff
                            v-if="label.hidden"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <Eye v-else class="size-5" aria-hidden="true" />
                    </button>

                    <button
                        type="button"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-full text-muted-foreground opacity-0 outline-none group-focus-within:opacity-100 group-hover:opacity-100 hover:bg-muted hover:text-destructive focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring/60"
                        :aria-label="`Remove ${colorName(label.color)}`"
                        @click="draft.splice(i, 1)"
                    >
                        <Trash2 class="size-5" aria-hidden="true" />
                    </button>
                </li>
            </ul>

            <input
                ref="customInput"
                type="color"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
                @change="onCustom"
            />

            <DialogFooter
                class="flex-row items-center border-t border-border px-6 py-4 sm:justify-between"
            >
                <button
                    type="button"
                    class="inline-flex size-10 items-center justify-center rounded-full bg-muted outline-none hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring/60"
                    aria-label="Add a color"
                    title="Add a color"
                    @click="add"
                >
                    <Plus class="size-5" aria-hidden="true" />
                </button>
                <p
                    v-if="error"
                    class="mr-auto ml-3 text-sm text-destructive"
                    role="alert"
                >
                    {{ error }}
                </p>
                <div class="ml-auto flex items-center gap-2">
                    <Button
                        variant="ghost"
                        class="rounded-full"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="rounded-full px-6"
                        :disabled="saving"
                        @click="submit"
                    >
                        Save
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
