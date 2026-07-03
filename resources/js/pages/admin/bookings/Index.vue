<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Inbox, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { format } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminBookings from '@/routes/admin/bookings';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Booking Approvals', href: adminBookings.index() },
        ],
    },
});

type PendingBooking = {
    id: number;
    resource: string;
    type: string;
    requester: string | null;
    starts_at: string;
    ends_at: string;
    purpose: string | null;
    attendee_count: number | null;
};

defineProps<{
    pending: PendingBooking[];
}>();

const approve = (booking: PendingBooking) =>
    router.post(
        adminBookings.approve(booking.id).url,
        {},
        { preserveScroll: true },
    );

const rejecting = ref<PendingBooking | null>(null);

const rejectForm = useForm({ reason: '' });

const submitReject = () => {
    if (!rejecting.value) {
        return;
    }

    rejectForm.post(adminBookings.reject(rejecting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
            rejectForm.reset();
        },
    });
};

const when = (booking: PendingBooking) =>
    `${format(new Date(booking.starts_at), 'EEE, MMM d · HH:mm')} - ${format(new Date(booking.ends_at), 'HH:mm')}`;
</script>

<template>
    <Head title="Booking Approvals" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div
            v-if="pending.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 text-center"
        >
            <Inbox class="size-10 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm text-muted-foreground">
                No bookings are waiting for your approval.
            </p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="booking in pending" :key="booking.id">
                <CardHeader>
                    <CardTitle class="flex items-center justify-between gap-2">
                        {{ booking.resource }}
                        <Badge variant="secondary">{{ booking.type }}</Badge>
                    </CardTitle>
                    <CardDescription>
                        {{ when(booking) }} · {{ booking.requester }}
                        <template v-if="booking.attendee_count">
                            · {{ booking.attendee_count }} pax
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p
                        v-if="booking.purpose"
                        class="text-sm text-muted-foreground"
                    >
                        {{ booking.purpose }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            @click="rejecting = booking"
                        >
                            <XCircle aria-hidden="true" />
                            Reject
                        </Button>
                        <Button size="sm" @click="approve(booking)">
                            <CheckCircle2 aria-hidden="true" />
                            Approve
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Dialog
            :open="rejecting !== null"
            @update:open="(open) => !open && (rejecting = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reject this booking?</DialogTitle>
                    <DialogDescription>
                        The requester sees your reason, so make it useful.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="reject-reason">Reason</Label>
                    <Textarea
                        id="reject-reason"
                        v-model="rejectForm.reason"
                        rows="3"
                        placeholder="At least 10 characters"
                    />
                    <p
                        v-if="rejectForm.errors.reason"
                        class="text-sm text-destructive"
                    >
                        {{ rejectForm.errors.reason }}
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="rejecting = null">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="rejectForm.processing"
                        @click="submitReject"
                    >
                        Reject booking
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
