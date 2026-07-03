<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarPlus, ClipboardList } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

const openCreate = () => {
    errors.value = {};
    form.value = {
        typeId: types.value[0]?.id ?? null,
        resource_id: null,
        title: '',
        date: format(new Date(Date.now() + 86_400_000), 'yyyy-MM-dd'),
        startTime: '09:00',
        endTime: '10:00',
        purpose: '',
        attendee_count: null,
    };
    busy.value = [];
    creating.value = true;
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
    ((
        {
            approved: 'default',
            pending: 'secondary',
            rejected: 'destructive',
            cancelled: 'outline',
        }
    )[status] ?? 'outline') as 'default' | 'secondary' | 'destructive' | 'outline';

const when = (booking: MyBooking) =>
    `${format(new Date(booking.starts_at), 'MMM d, HH:mm')} - ${format(new Date(booking.ends_at), 'HH:mm')}`;
</script>

<template>
    <Head title="Bookings" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Reserve rooms, vehicles and other shared resources.
            </p>
            <Button data-test="new-booking" @click="openCreate">
                <CalendarPlus aria-hidden="true" />
                New booking
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Resource</TableHead>
                        <TableHead>When</TableHead>
                        <TableHead>Purpose</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-28 text-right">Actions</TableHead>
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
                            No bookings yet. Reserve a room or vehicle with
                            the button above.
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

        <Dialog v-model:open="creating">
            <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New booking</DialogTitle>
                    <DialogDescription>
                        Pick a resource and a time slot.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="booking-type">Type</Label>
                            <Select
                                :model-value="
                                    form.typeId === null
                                        ? undefined
                                        : String(form.typeId)
                                "
                                @update:model-value="
                                    (v) => {
                                        form.typeId = Number(v);
                                        form.resource_id = null;
                                        busy = [];
                                    }
                                "
                            >
                                <SelectTrigger id="booking-type">
                                    <SelectValue placeholder="Pick a type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="type in types"
                                        :key="type.id"
                                        :value="String(type.id)"
                                    >
                                        {{ type.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="booking-resource">Resource</Label>
                            <Select
                                :model-value="
                                    form.resource_id === null
                                        ? undefined
                                        : String(form.resource_id)
                                "
                                @update:model-value="
                                    (v) => {
                                        form.resource_id = Number(v);
                                        void loadAvailability();
                                    }
                                "
                            >
                                <SelectTrigger id="booking-resource">
                                    <SelectValue placeholder="Pick one" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="resource in selectedType?.resources ??
                                        []"
                                        :key="resource.id"
                                        :value="String(resource.id)"
                                    >
                                        {{ resource.name }}
                                        <template v-if="resource.capacity">
                                            ({{ resource.capacity }} pax)
                                        </template>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="fieldError('resource_id')"
                                class="text-sm text-destructive"
                            >
                                {{ fieldError('resource_id') }}
                            </p>
                        </div>
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
                    >
                        <template v-if="busy.length === 0">
                            The resource is free all day.
                        </template>
                        <template v-else>
                            <span class="font-medium">Already booked:</span>
                            <span
                                v-for="(slot, i) in busy"
                                :key="i"
                                class="ml-1.5"
                            >
                                {{
                                    format(new Date(slot.starts_at), 'HH:mm')
                                }}-{{ format(new Date(slot.ends_at), 'HH:mm') }}
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
