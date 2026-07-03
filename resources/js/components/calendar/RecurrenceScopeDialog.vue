<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type RecurrenceScope = 'this' | 'following' | 'all';

const props = defineProps<{
    open: boolean;
    /** "edit" or "delete", used in the copy. */
    action: 'edit' | 'delete';
}>();

const emit = defineEmits<{
    choose: [scope: RecurrenceScope];
    cancel: [];
}>();

const scope = ref<RecurrenceScope>('this');

watch(
    () => props.open,
    (open) => {
        if (open) {
            scope.value = 'this';
        }
    },
);

const options: { value: RecurrenceScope; label: string }[] = [
    { value: 'this', label: 'This event' },
    { value: 'following', label: 'This and following events' },
    { value: 'all', label: 'All events' },
];
</script>

<template>
    <Dialog :open="open" @update:open="(o) => !o && emit('cancel')">
        <DialogContent class="max-w-sm">
            <DialogHeader>
                <DialogTitle>
                    {{ action === 'delete' ? 'Delete' : 'Edit' }} recurring
                    event
                </DialogTitle>
                <DialogDescription>
                    Choose which occurrences this change applies to.
                </DialogDescription>
            </DialogHeader>

            <div
                class="grid gap-2"
                role="radiogroup"
                aria-label="Apply change to"
            >
                <Label
                    v-for="option in options"
                    :key="option.value"
                    class="flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5 font-normal transition-colors hover:bg-muted"
                    :class="{
                        'border-primary ring-1 ring-primary':
                            scope === option.value,
                    }"
                >
                    <input
                        v-model="scope"
                        type="radio"
                        name="recurrence-scope"
                        :value="option.value"
                        class="accent-[var(--primary)]"
                    />
                    {{ option.label }}
                </Label>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="emit('cancel')">
                    Cancel
                </Button>
                <Button
                    :variant="action === 'delete' ? 'destructive' : 'default'"
                    @click="emit('choose', scope)"
                >
                    Continue
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
