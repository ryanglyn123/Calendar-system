<script setup lang="ts">
import { MapPin, Users } from '@lucide/vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';
import { format } from '@/lib/calendarMath';
import bookingsApi from '@/routes/api/bookings';
import resourcesApi from '@/routes/api/resources';

const props = defineProps<{
    type: {
        id: number;
        name: string;
        requires_approval: boolean;
    };
    resources: {
        id: number;
        name: string;
        location: string | null;
        capacity: number | null;
    }[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    /** A booking was created; starts_at ISO string for cache invalidation. */
    created: [startsAt: string];
}>();

const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const busy = ref<{ starts_at: string; ends_at: string; status: string }[]>([]);

const form = ref({
    resource_id: null as number | null,
    title: '',
    date: format(new Date(), 'yyyy-MM-dd'),
    startTime: '09:00',
    endTime: '10:00',
    purpose: '',
    attendee_count: null as number | null,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    errors.value = {};
    busy.value = [];
    form.value = {
        resource_id:
            props.resources.length === 1 ? props.resources[0].id : null,
        title: '',
        date: format(new Date(Date.now() + 86_400_000), 'yyyy-MM-dd'),
        startTime: '09:00',
        endTime: '10:00',
        purpose: '',
        attendee_count: null,
    };
    void loadAvailability();
});

const selectResource = (resourceId: number) => {
    form.value.resource_id = resourceId;
    void loadAvailability();
};

const loadAvailability = async () => {
    if (!form.value.resource_id || !form.value.date) {
        busy.value = [];

        return;
    }

    const response = await api.get<{
        data: { starts_at: string; ends_at: string; status: string }[];
    }>(
        `${resourcesApi.availability(form.value.resource_id).url}?date=${form.value.date}`,
    );

    busy.value = response.data;
};

const fieldError = (key: string) => errors.value[key]?.[0];

const submit = async () => {
    saving.value = true;
    errors.value = {};

    const startsAt = new Date(
        `${form.value.date}T${form.value.startTime}`,
    ).toISOString();

    try {
        await api.post(bookingsApi.store().url, {
            resource_id: form.value.resource_id,
            title: form.value.title,
            starts_at: startsAt,
            ends_at: new Date(
                `${form.value.date}T${form.value.endTime}`,
            ).toISOString(),
            purpose: form.value.purpose || null,
            attendee_count: form.value.attendee_count,
        });

        toast.success(
            props.type.requires_approval
                ? 'Booking requested. You will be notified once reviewed.'
                : 'Booking confirmed.',
        );
        open.value = false;
        emit('created', startsAt);
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;

            if (Object.keys(error.errors).length === 0) {
                toast.error(error.message);
            }
        } else {
            toast.error('Could not create the booking.');
        }
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Book {{ type.name }}</DialogTitle>
                <DialogDescription>
                    <template v-if="type.requires_approval">
                        Bookings are confirmed by an approver before they are
                        final.
                    </template>
                    <template v-else>
                        Pick an option and a time slot.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label id="booking-resource-label">
                        {{ type.name }}
                    </Label>
                    <div
                        v-if="resources.length > 0"
                        class="grid max-h-48 gap-2 overflow-y-auto pr-1"
                        role="radiogroup"
                        aria-labelledby="booking-resource-label"
                    >
                        <button
                            v-for="resource in resources"
                            :key="resource.id"
                            type="button"
                            role="radio"
                            :aria-checked="form.resource_id === resource.id"
                            class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:scale-[0.99]"
                            :class="
                                form.resource_id === resource.id
                                    ? 'border-primary bg-accent'
                                    : 'hover:bg-accent/50'
                            "
                            @click="selectResource(resource.id)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ resource.name }}
                                </span>
                                <span
                                    v-if="resource.location"
                                    class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <MapPin
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ resource.location }}
                                </span>
                            </span>
                            <span
                                v-if="resource.capacity"
                                class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Users class="size-3.5" aria-hidden="true" />
                                {{ resource.capacity }}
                            </span>
                        </button>
                    </div>
                    <p
                        v-else
                        class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                    >
                        Nothing bookable in this section yet.
                    </p>
                    <p
                        v-if="fieldError('resource_id')"
                        class="text-sm text-destructive"
                    >
                        {{ fieldError('resource_id') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="booking-title">Title</Label>
                    <Input
                        id="booking-title"
                        v-model="form.title"
                        placeholder="e.g. Budget review meeting"
                    />
                    <p
                        v-if="fieldError('title')"
                        class="text-sm text-destructive"
                    >
                        {{ fieldError('title') }}
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="booking-date">Date</Label>
                        <Input
                            id="booking-date"
                            v-model="form.date"
                            type="date"
                            @change="loadAvailability"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="booking-start">From</Label>
                        <Input
                            id="booking-start"
                            v-model="form.startTime"
                            type="time"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="booking-end">To</Label>
                        <Input
                            id="booking-end"
                            v-model="form.endTime"
                            type="time"
                        />
                    </div>
                </div>
                <p
                    v-if="fieldError('starts_at') || fieldError('ends_at')"
                    class="text-sm text-destructive"
                >
                    {{ fieldError('starts_at') ?? fieldError('ends_at') }}
                </p>

                <div
                    v-if="form.resource_id !== null"
                    class="rounded-lg border bg-muted/30 px-3 py-2 text-xs"
                    aria-live="polite"
                >
                    <template v-if="busy.length === 0">
                        Free all day on
                        {{ format(new Date(form.date), 'MMM d') }}. Pick any
                        time.
                    </template>
                    <template v-else>
                        <span class="font-medium">
                            Already booked that day:
                        </span>
                        <span class="mt-1.5 flex flex-wrap gap-1.5">
                            <span
                                v-for="(slot, i) in busy"
                                :key="i"
                                class="rounded-md border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 font-medium tabular-nums"
                            >
                                {{
                                    format(new Date(slot.starts_at), 'HH:mm')
                                }}-{{
                                    format(new Date(slot.ends_at), 'HH:mm')
                                }}
                            </span>
                        </span>
                    </template>
                </div>

                <div class="grid grid-cols-[1fr_7rem] gap-3">
                    <div class="grid gap-2">
                        <Label for="booking-purpose">Purpose</Label>
                        <Textarea
                            id="booking-purpose"
                            v-model="form.purpose"
                            rows="2"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="booking-count">People</Label>
                        <Input
                            id="booking-count"
                            :model-value="form.attendee_count ?? ''"
                            type="number"
                            min="1"
                            @update:model-value="
                                (v) =>
                                    (form.attendee_count =
                                        v === '' ? null : Number(v))
                            "
                        />
                        <p
                            v-if="fieldError('attendee_count')"
                            class="text-sm text-destructive"
                        >
                            {{ fieldError('attendee_count') }}
                        </p>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="open = false">
                    Cancel
                </Button>
                <Button
                    :disabled="
                        saving || !form.title || form.resource_id === null
                    "
                    @click="submit"
                >
                    <Spinner v-if="saving" />
                    {{
                        type.requires_approval ? 'Request booking' : 'Book now'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
