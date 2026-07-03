<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Boxes,
    CalendarPlus,
    CarFront,
    ChevronRight,
    ClipboardList,
    DoorOpen,
    MapPin,
    Projector,
    Users,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { format } from '@/lib/calendarMath';
import bookingsApi from '@/routes/api/bookings';
import resourcesApi from '@/routes/api/resources';
import bookingsPage from '@/routes/bookings';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bookings', href: '/bookings' }],
    },
});

void bookingsPage;

type MyBooking = {
    id: number;
    resource: { id: number; name: string; type: string };
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_color: string;
    starts_at: string;
    ends_at: string;
    purpose: string | null;
    attendee_count: number | null;
    rejection_reason: string | null;
    has_pending_edit: boolean;
    can_cancel: boolean;
    can_request_edit: boolean;
};

type BrowserType = {
    id: number;
    name: string;
    requires_approval: boolean;
    resources: {
        id: number;
        name: string;
        location: string | null;
        capacity: number | null;
    }[];
};

const { confirm } = useConfirm();

const mine = ref<MyBooking[]>([]);
const types = ref<BrowserType[]>([]);
const loading = ref(true);

const load = async () => {
    loading.value = true;

    try {
        const [mineResponse, typesResponse] = await Promise.all([
            api.get<{ data: MyBooking[] }>(bookingsApi.mine().url),
            api.get<{ data: BrowserType[] }>(resourcesApi.index().url),
        ]);

        mine.value = mineResponse.data;
        types.value = typesResponse.data;
    } finally {
        loading.value = false;
    }
};

onMounted(load);

/* Booking dialog */
const creating = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

const form = ref({
    typeId: null as number | null,
    resource_id: null as number | null,
    title: '',
    date: format(new Date(), 'yyyy-MM-dd'),
    startTime: '09:00',
    endTime: '10:00',
    purpose: '',
    attendee_count: null as number | null,
});

const busy = ref<{ starts_at: string; ends_at: string; status: string }[]>([]);

const selectedType = computed(() =>
    types.value.find((t) => t.id === form.value.typeId),
);

const typeIcon = (name: string): LucideIcon => {
    if (/car|vehicle|van|shuttle|pool|transport/i.test(name)) {
        return CarFront;
    }

    if (/room|board|hall|conference|meeting|venue/i.test(name)) {
        return DoorOpen;
    }

    if (/equipment|projector|gear|device/i.test(name)) {
        return Projector;
    }

    return Boxes;
};

const openCreate = (typeId?: number) => {
    errors.value = {};

    const type =
        types.value.find((t) => t.id === typeId) ?? types.value[0] ?? null;

    form.value = {
        typeId: type?.id ?? null,
        resource_id: type?.resources.length === 1 ? type.resources[0].id : null,
        title: '',
        date: format(new Date(Date.now() + 86_400_000), 'yyyy-MM-dd'),
        startTime: '09:00',
        endTime: '10:00',
        purpose: '',
        attendee_count: null,
    };
    busy.value = [];
    creating.value = true;
    void loadAvailability();
};

const selectType = (typeId: number) => {
    if (form.value.typeId === typeId) {
        return;
    }

    const type = types.value.find((t) => t.id === typeId);

    form.value.typeId = typeId;
    form.value.resource_id =
        type?.resources.length === 1 ? type.resources[0].id : null;
    busy.value = [];
    void loadAvailability();
};

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

    try {
        await api.post(bookingsApi.store().url, {
            resource_id: form.value.resource_id,
            title: form.value.title,
            starts_at: new Date(
                `${form.value.date}T${form.value.startTime}`,
            ).toISOString(),
            ends_at: new Date(
                `${form.value.date}T${form.value.endTime}`,
            ).toISOString(),
            purpose: form.value.purpose || null,
            attendee_count: form.value.attendee_count,
        });

        toast.success(
            selectedType.value?.requires_approval
                ? 'Booking requested. You will be notified once reviewed.'
                : 'Booking confirmed.',
        );
        creating.value = false;
        await load();
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

const cancelBooking = async (booking: MyBooking) => {
    const confirmed = await confirm({
        title: `Cancel this ${booking.resource.name} booking?`,
        confirmLabel: 'Cancel booking',
        cancelLabel: 'Keep it',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    try {
        await api.post(bookingsApi.cancel(booking.id).url, {});
        toast.success('Booking cancelled.');
        await load();
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Cancel failed.',
        );
    }
};

const statusVariant = (status: MyBooking['status']) =>
    (({
        approved: 'default',
        pending: 'secondary',
        rejected: 'destructive',
        cancelled: 'outline',
    })[status] ?? 'outline') as
        'default' | 'secondary' | 'destructive' | 'outline';

const when = (booking: MyBooking) =>
    `${format(new Date(booking.starts_at), 'MMM d, HH:mm')} - ${format(new Date(booking.ends_at), 'HH:mm')}`;
</script>

<template>
    <Head title="Bookings" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Bookings"
            description="Reserve rooms, vehicles and other shared resources."
        >
            <template #actions>
                <Button data-test="new-booking" @click="openCreate()">
                    <CalendarPlus aria-hidden="true" />
                    New booking
                </Button>
            </template>
        </PageHeader>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-medium text-muted-foreground">
                What do you need?
            </h2>

            <div
                v-if="loading && types.length === 0"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="i in 4"
                    :key="i"
                    class="h-24 animate-pulse rounded-xl border bg-muted/40"
                />
            </div>

            <div
                v-else-if="types.length > 0"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <button
                    v-for="type in types"
                    :key="type.id"
                    type="button"
                    class="group flex min-h-24 cursor-pointer items-center gap-3 rounded-xl border bg-card p-4 text-left transition-colors duration-200 hover:border-primary/40 hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:scale-[0.99]"
                    :data-test="`book-type-${type.id}`"
                    @click="openCreate(type.id)"
                >
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-lg border bg-background transition-colors duration-200 group-hover:border-primary/40 group-hover:text-primary"
                    >
                        <component
                            :is="typeIcon(type.name)"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-sm font-semibold">
                            {{ type.name }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ type.resources.length }}
                            {{
                                type.resources.length === 1
                                    ? 'option'
                                    : 'options'
                            }}
                            <template v-if="type.requires_approval">
                                &middot; needs approval
                            </template>
                        </span>
                    </span>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </button>
            </div>

            <p
                v-else
                class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
            >
                No bookable resources have been set up yet.
            </p>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-medium text-muted-foreground">
                My bookings
            </h2>
            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Resource</TableHead>
                            <TableHead>When</TableHead>
                            <TableHead>Purpose</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="w-28 text-right"
                                >Actions</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty
                            v-if="mine.length === 0 && !loading"
                            :colspan="5"
                        >
                            <span
                                class="inline-flex flex-col items-center gap-2 py-4"
                            >
                                <ClipboardList
                                    class="size-8 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                No bookings yet. Pick a category above to
                                reserve a room or vehicle.
                            </span>
                        </TableEmpty>
                        <TableRow v-for="booking in mine" :key="booking.id">
                            <TableCell>
                                <div class="font-medium">
                                    {{ booking.resource.name }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ booking.resource.type }}
                                </div>
                            </TableCell>
                            <TableCell>{{ when(booking) }}</TableCell>
                            <TableCell
                                class="max-w-56 truncate text-muted-foreground"
                            >
                                {{ booking.purpose ?? '-' }}
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col gap-1">
                                    <Badge
                                        :variant="statusVariant(booking.status)"
                                    >
                                        {{ booking.status }}
                                    </Badge>
                                    <span
                                        v-if="booking.rejection_reason"
                                        class="max-w-52 text-xs text-muted-foreground"
                                    >
                                        {{ booking.rejection_reason }}
                                    </span>
                                    <Badge
                                        v-if="booking.has_pending_edit"
                                        variant="outline"
                                    >
                                        change pending
                                    </Badge>
                                </div>
                            </TableCell>
                            <TableCell class="text-right">
                                <Button
                                    v-if="booking.can_cancel"
                                    variant="outline"
                                    size="sm"
                                    @click="cancelBooking(booking)"
                                >
                                    Cancel
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <Dialog v-model:open="creating">
            <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New booking</DialogTitle>
                    <DialogDescription>
                        Pick a resource and a time slot.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label id="booking-type-label">Type</Label>
                        <div
                            class="flex flex-wrap gap-2"
                            role="radiogroup"
                            aria-labelledby="booking-type-label"
                        >
                            <button
                                v-for="type in types"
                                :key="type.id"
                                type="button"
                                role="radio"
                                :aria-checked="form.typeId === type.id"
                                class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:scale-[0.98]"
                                :class="
                                    form.typeId === type.id
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background text-foreground hover:bg-accent'
                                "
                                @click="selectType(type.id)"
                            >
                                <component
                                    :is="typeIcon(type.name)"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ type.name }}
                            </button>
                        </div>
                        <p
                            v-if="selectedType?.requires_approval"
                            class="text-xs text-muted-foreground"
                        >
                            {{ selectedType.name }} bookings are confirmed by an
                            approver before they are final.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label id="booking-resource-label">
                            {{ selectedType?.name ?? 'Resource' }}
                        </Label>
                        <div
                            v-if="(selectedType?.resources.length ?? 0) > 0"
                            class="grid max-h-48 gap-2 overflow-y-auto pr-1"
                            role="radiogroup"
                            aria-labelledby="booking-resource-label"
                        >
                            <button
                                v-for="resource in selectedType?.resources ??
                                []"
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
                                    <Users
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ resource.capacity }}
                                </span>
                            </button>
                        </div>
                        <p
                            v-else
                            class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                        >
                            No resources in this category yet.
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
                                        format(
                                            new Date(slot.starts_at),
                                            'HH:mm',
                                        )
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
                    <Button variant="outline" @click="creating = false">
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
                            selectedType?.requires_approval
                                ? 'Request booking'
                                : 'Book now'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
