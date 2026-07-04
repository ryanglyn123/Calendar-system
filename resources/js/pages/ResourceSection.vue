<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarPlus, ClipboardList } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BookingDialog from '@/components/booking/BookingDialog.vue';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { format } from '@/lib/calendarMath';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import bookingsApi from '@/routes/api/bookings';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import type {
    CalendarItem,
    CalendarPanelGroup,
    CalendarScope,
    EventOccurrence,
} from '@/types/calendar';

type SectionResourceType = {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
    description: string | null;
    requires_approval: boolean;
    max_duration_minutes: number | null;
};

type SectionResource = {
    id: number;
    name: string;
    location: string | null;
    capacity: number | null;
    description: string | null;
    calendar_id: number;
};

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

const props = defineProps<{
    resourceType: SectionResourceType;
    resources: SectionResource[];
    weekStartsOn: 0 | 1;
    initialCalendars: CalendarItem[];
    initialEvents: EventOccurrence[];
}>();

const grid = useCalendarGridStore();
const { confirm } = useConfirm();

const scope = computed<CalendarScope>(() => ({
    resourceTypeId: props.resourceType.id,
}));

const panelGroups = computed<CalendarPanelGroup[]>(() => [
    { key: 'resource', label: props.resourceType.name },
]);

/* My bookings, scoped to this resource type. */
const mine = ref<MyBooking[]>([]);
const loadingMine = ref(true);

const loadMine = async () => {
    loadingMine.value = true;

    try {
        const response = await api.get<{ data: MyBooking[] }>(
            bookingsApi.mine({
                query: { resource_type_id: props.resourceType.id },
            }).url,
        );

        mine.value = response.data;
    } finally {
        loadingMine.value = false;
    }
};

onMounted(loadMine);

// Inertia reuses this component when hopping between sections
// (Boardroom -> Carpool), so reload on type change too.
watch(
    () => props.resourceType.id,
    () => void loadMine(),
);

const booking = ref(false);

const onBooked = (startsAt: string) => {
    void loadMine();
    void grid.invalidateAround(new Date(startsAt));
};

const cancelBooking = async (target: MyBooking) => {
    const confirmed = await confirm({
        title: `Cancel this ${target.resource.name} booking?`,
        confirmLabel: 'Cancel booking',
        cancelLabel: 'Keep it',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    try {
        await api.post(bookingsApi.cancel(target.id).url, {});
        toast.success('Booking cancelled.');
        void loadMine();
        void grid.invalidateAround(new Date(target.starts_at));
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Cancel failed.',
        );
    }
};

const statusVariant = (status: MyBooking['status']) =>
    (({
        approved: 'success',
        pending: 'secondary',
        rejected: 'destructive',
        cancelled: 'outline',
    })[status] ?? 'outline') as
        'success' | 'secondary' | 'destructive' | 'outline';

const when = (target: MyBooking) =>
    `${format(new Date(target.starts_at), 'MMM d, HH:mm')} - ${format(new Date(target.ends_at), 'HH:mm')}`;
</script>

<template>
    <Head :title="resourceType.name" />

    <div class="flex h-full min-h-0 flex-1 flex-col overflow-y-auto">
        <!-- Section header -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-6"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-card"
                >
                    <component
                        :is="resourceTypeIcon(resourceType)"
                        class="size-5"
                        aria-hidden="true"
                    />
                </span>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold">
                        {{ resourceType.name }}
                    </h1>
                    <p
                        v-if="resourceType.description"
                        class="truncate text-sm text-muted-foreground"
                    >
                        {{ resourceType.description }}
                    </p>
                </div>
            </div>

            <Button data-test="new-booking" @click="booking = true">
                <CalendarPlus aria-hidden="true" />
                New booking
            </Button>
        </div>

        <!-- Availability calendar: one sub-calendar per room/vehicle.
             Keyed so hopping between sections remounts the shell and its
             scope instead of reusing the previous section's. -->
        <div class="h-[70vh] min-h-[480px] shrink-0 border-b">
            <CalendarShell
                :key="resourceType.id"
                :event-types="[]"
                :week-starts-on="weekStartsOn"
                :initial-calendars="initialCalendars"
                :initial-events="initialEvents"
                :scope="scope"
                :panel-groups="panelGroups"
            />
        </div>

        <!-- My bookings in this section -->
        <section class="flex flex-col gap-3 p-4 sm:p-6">
            <h2 class="text-sm font-medium text-muted-foreground">
                My bookings
            </h2>
            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ resourceType.name }}</TableHead>
                            <TableHead>When</TableHead>
                            <TableHead>Purpose</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="w-28 text-right">
                                Actions
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty
                            v-if="mine.length === 0 && !loadingMine"
                            :colspan="5"
                        >
                            <span
                                class="inline-flex flex-col items-center gap-2 py-4"
                            >
                                <ClipboardList
                                    class="size-8 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                No bookings yet. Use "New booking" to reserve.
                            </span>
                        </TableEmpty>
                        <TableRow v-for="item in mine" :key="item.id">
                            <TableCell>
                                <div class="font-medium">
                                    {{ item.resource.name }}
                                </div>
                            </TableCell>
                            <TableCell>{{ when(item) }}</TableCell>
                            <TableCell
                                class="max-w-56 truncate text-muted-foreground"
                            >
                                {{ item.purpose ?? '-' }}
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col gap-1">
                                    <Badge
                                        :variant="statusVariant(item.status)"
                                    >
                                        {{ item.status }}
                                    </Badge>
                                    <span
                                        v-if="item.rejection_reason"
                                        class="max-w-52 text-xs text-muted-foreground"
                                    >
                                        {{ item.rejection_reason }}
                                    </span>
                                    <Badge
                                        v-if="item.has_pending_edit"
                                        variant="outline"
                                    >
                                        change pending
                                    </Badge>
                                </div>
                            </TableCell>
                            <TableCell class="text-right">
                                <Button
                                    v-if="item.can_cancel"
                                    variant="outline"
                                    size="sm"
                                    @click="cancelBooking(item)"
                                >
                                    Cancel
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <BookingDialog
            v-model:open="booking"
            :type="resourceType"
            :resources="resources"
            @created="onBooked"
        />
    </div>
</template>
