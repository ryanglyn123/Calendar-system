<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import admin from '@/routes/admin';
import adminWorkingHours from '@/routes/admin/working-hours';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Working Hours', href: adminWorkingHours.index() },
        ],
    },
});

type DayRow = {
    day_of_week: number;
    opens_at: string | null;
    closes_at: string | null;
};

const props = defineProps<{
    resourceId: number | null;
    resources: { id: number; name: string }[];
    days: DayRow[];
}>();

const DAY_NAMES = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const form = useForm({
    resource_id: props.resourceId,
    days: props.days.map((day) => ({
        ...day,
        opens_at: day.opens_at?.slice(0, 5) ?? null,
        closes_at: day.closes_at?.slice(0, 5) ?? null,
    })),
});

const switchScope = (value: string) => {
    router.get(
        adminWorkingHours.index().url,
        value === 'org' ? {} : { resource: value },
        { preserveState: false },
    );
};

const toggleDay = (day: DayRow, open: boolean) => {
    day.opens_at = open ? '08:00' : null;
    day.closes_at = open ? '18:00' : null;
};

const submit = () => {
    form.put(adminWorkingHours.update().url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Working Hours" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Working Hours"
            description="The default weekly schedule; individual resources can override it."
        >
            <template #actions>
                <Select
                    :model-value="
                        resourceId === null ? 'org' : String(resourceId)
                    "
                    @update:model-value="(v) => switchScope(String(v))"
                >
                    <SelectTrigger class="w-64" aria-label="Schedule scope">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="org">
                            Organization default
                        </SelectItem>
                        <SelectItem
                            v-for="resource in resources"
                            :key="resource.id"
                            :value="String(resource.id)"
                        >
                            {{ resource.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </PageHeader>

        <div class="max-w-xl overflow-hidden rounded-xl border">
            <div
                v-for="day in form.days"
                :key="day.day_of_week"
                class="grid grid-cols-[1fr_auto] items-center gap-3 border-b px-4 py-2.5 last:border-b-0 sm:grid-cols-[7rem_4rem_1fr]"
            >
                <Label class="font-medium">
                    {{ DAY_NAMES[day.day_of_week] }}
                </Label>

                <Switch
                    :model-value="day.opens_at !== null"
                    :aria-label="`Open on ${DAY_NAMES[day.day_of_week]}`"
                    @update:model-value="(open) => toggleDay(day, open)"
                />

                <div
                    v-if="day.opens_at !== null"
                    class="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-1"
                >
                    <Input
                        :model-value="day.opens_at ?? ''"
                        type="time"
                        class="w-28"
                        :aria-label="`${DAY_NAMES[day.day_of_week]} opens at`"
                        @update:model-value="(v) => (day.opens_at = String(v))"
                    />
                    <span class="text-sm text-muted-foreground">to</span>
                    <Input
                        :model-value="day.closes_at ?? ''"
                        type="time"
                        class="w-28"
                        :aria-label="`${DAY_NAMES[day.day_of_week]} closes at`"
                        @update:model-value="(v) => (day.closes_at = String(v))"
                    />
                </div>
                <span
                    v-else
                    class="col-span-2 text-sm text-muted-foreground sm:col-span-1"
                >
                    Closed
                </span>
            </div>
        </div>

        <div>
            <Button :disabled="form.processing" @click="submit">
                Save schedule
            </Button>
        </div>
    </div>
</template>
