<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { format } from '@/lib/calendarMath';

const rrule = defineModel<string | null>({ required: true });

const props = defineProps<{
    /** Anchor date for weekday / month-day presets. */
    startDate: Date;
}>();

type Preset = 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom';

const BYDAY_CODES = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const;
const BYDAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

const preset = ref<Preset>('none');
const interval = ref(1);
const customFreq = ref<'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY'>('WEEKLY');
const byDay = ref<string[]>([]);
const endsMode = ref<'never' | 'until' | 'count'>('never');
const untilDate = ref('');
const count = ref(10);

const anchorByday = computed(() => {
    // getDay(): 0 = Sunday.
    const index = (props.startDate.getDay() + 6) % 7;

    return BYDAY_CODES[index];
});

/** Parse an incoming rule into editor state (best effort). */
watch(
    rrule,
    (value) => {
        if (!value) {
            preset.value = 'none';

            return;
        }

        const parts = new Map(
            value.split(';').map((chunk) => {
                const [k, v] = chunk.split('=');

                return [k?.toUpperCase() ?? '', v ?? ''] as const;
            }),
        );

        const freq = parts.get('FREQ') ?? 'WEEKLY';
        const parsedInterval = Number(parts.get('INTERVAL') ?? '1');
        const parsedByday = (parts.get('BYDAY') ?? '').split(',').filter(Boolean);

        customFreq.value = freq as typeof customFreq.value;
        interval.value = Number.isFinite(parsedInterval) ? parsedInterval : 1;
        byDay.value = parsedByday;

        if (parts.has('UNTIL')) {
            endsMode.value = 'until';
            const raw = parts.get('UNTIL') ?? '';
            untilDate.value = `${raw.slice(0, 4)}-${raw.slice(4, 6)}-${raw.slice(6, 8)}`;
        } else if (parts.has('COUNT')) {
            endsMode.value = 'count';
            count.value = Number(parts.get('COUNT'));
        } else {
            endsMode.value = 'never';
        }

        const isSimple =
            interval.value === 1 &&
            endsMode.value === 'never' &&
            parsedByday.length === 0;

        preset.value = isSimple
            ? ((freq.toLowerCase() as Preset) ?? 'custom')
            : 'custom';
    },
    { immediate: true },
);

/** Rebuild the rule whenever editor state changes. */
const rebuild = () => {
    if (preset.value === 'none') {
        rrule.value = null;

        return;
    }

    const freq =
        preset.value === 'custom'
            ? customFreq.value
            : (preset.value.toUpperCase() as typeof customFreq.value);

    const parts = [`FREQ=${freq}`];

    if (preset.value === 'custom') {
        if (interval.value > 1) {
            parts.push(`INTERVAL=${interval.value}`);
        }

        if (freq === 'WEEKLY' && byDay.value.length > 0) {
            parts.push(`BYDAY=${byDay.value.join(',')}`);
        }

        if (endsMode.value === 'until' && untilDate.value) {
            parts.push(
                `UNTIL=${untilDate.value.replaceAll('-', '')}T235959Z`,
            );
        } else if (endsMode.value === 'count') {
            parts.push(`COUNT=${Math.max(1, count.value)}`);
        }
    }

    rrule.value = parts.join(';');
};

watch([preset, interval, customFreq, byDay, endsMode, untilDate, count], rebuild, {
    deep: true,
});

const toggleDay = (code: string) => {
    byDay.value = byDay.value.includes(code)
        ? byDay.value.filter((d) => d !== code)
        : [...byDay.value, code];
};

const presetOptions = computed(() => [
    { value: 'none', label: 'Does not repeat' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: `Weekly on ${format(props.startDate, 'EEEE')}` },
    {
        value: 'monthly',
        label: `Monthly on day ${format(props.startDate, 'd')}`,
    },
    { value: 'yearly', label: `Yearly on ${format(props.startDate, 'MMM d')}` },
    { value: 'custom', label: 'Custom' },
]);
</script>

<template>
    <div class="grid gap-3">
        <div class="grid gap-2">
            <Label for="recurrence-preset">Repeats</Label>
            <Select v-model="preset">
                <SelectTrigger id="recurrence-preset">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in presetOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="preset === 'custom'" class="grid gap-3 rounded-lg border p-3">
            <div class="flex items-center gap-2">
                <Label for="recurrence-interval" class="shrink-0">
                    Every
                </Label>
                <Input
                    id="recurrence-interval"
                    v-model.number="interval"
                    type="number"
                    min="1"
                    max="99"
                    class="w-16"
                />
                <Select v-model="customFreq">
                    <SelectTrigger class="flex-1" aria-label="Frequency">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="DAILY">day(s)</SelectItem>
                        <SelectItem value="WEEKLY">week(s)</SelectItem>
                        <SelectItem value="MONTHLY">month(s)</SelectItem>
                        <SelectItem value="YEARLY">year(s)</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="customFreq === 'WEEKLY'" class="grid gap-1.5">
                <Label>On days</Label>
                <div class="flex gap-1">
                    <button
                        v-for="(code, i) in BYDAY_CODES"
                        :key="code"
                        type="button"
                        class="size-8 rounded-full text-xs font-medium transition-colors"
                        :class="
                            byDay.includes(code) ||
                            (byDay.length === 0 && code === anchorByday)
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground hover:bg-muted/70'
                        "
                        :aria-pressed="byDay.includes(code)"
                        :aria-label="code"
                        @click="toggleDay(code)"
                    >
                        {{ BYDAY_LABELS[i] }}
                    </button>
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="recurrence-ends">Ends</Label>
                <div class="flex items-center gap-2">
                    <Select v-model="endsMode">
                        <SelectTrigger
                            id="recurrence-ends"
                            class="w-32 shrink-0"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="never">Never</SelectItem>
                            <SelectItem value="until">On date</SelectItem>
                            <SelectItem value="count">After</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input
                        v-if="endsMode === 'until'"
                        v-model="untilDate"
                        type="date"
                        aria-label="End date"
                    />
                    <template v-if="endsMode === 'count'">
                        <Input
                            v-model.number="count"
                            type="number"
                            min="1"
                            max="500"
                            class="w-20"
                            aria-label="Number of occurrences"
                        />
                        <span class="text-sm text-muted-foreground">
                            occurrence(s)
                        </span>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
