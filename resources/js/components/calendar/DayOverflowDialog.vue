<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { format } from '@/lib/calendarMath';
import type { EventOccurrence } from '@/types/calendar';

defineProps<{
    day: Date | null;
    occurrences: EventOccurrence[];
}>();

const emit = defineEmits<{
    close: [];
    open: [occurrence: EventOccurrence];
}>();
</script>

<template>
    <Dialog
        :open="day !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent v-if="day" class="max-w-sm">
            <DialogHeader>
                <DialogTitle>{{ format(day, 'EEEE, MMMM d') }}</DialogTitle>
                <DialogDescription>
                    {{ occurrences.length }} event(s)
                </DialogDescription>
            </DialogHeader>

            <ul class="max-h-80 space-y-1 overflow-y-auto">
                <li
                    v-for="occurrence in occurrences"
                    :key="occurrence.occurrence_id"
                >
                    <Button
                        variant="ghost"
                        class="w-full justify-start gap-2.5"
                        @click="emit('open', occurrence)"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: occurrence.color }"
                            aria-hidden="true"
                        />
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {{
                                occurrence.all_day
                                    ? 'All day'
                                    : format(
                                          new Date(occurrence.starts_at),
                                          'HH:mm',
                                      )
                            }}
                        </span>
                        <span class="truncate">{{ occurrence.title }}</span>
                    </Button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
