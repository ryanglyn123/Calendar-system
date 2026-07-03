<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Inbox, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
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
import adminEditRequests from '@/routes/admin/edit-requests';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Change Requests', href: adminEditRequests.index() },
        ],
    },
});

type Slot = {
    starts_at: string | null;
    ends_at: string | null;
    purpose: string | null;
};

type PendingChange = {
    id: number;
    resource: string;
    requester: string | null;
    note: string | null;
    current: Slot;
    proposed: Slot;
};

defineProps<{
    pending: PendingChange[];
}>();

const slotLabel = (slot: Slot) =>
    slot.starts_at
        ? `${format(new Date(slot.starts_at), 'MMM d, HH:mm')} - ${format(new Date(slot.ends_at ?? slot.starts_at), 'HH:mm')}`
        : 'unchanged';

const apply = (change: PendingChange) =>
    router.post(
        adminEditRequests.apply(change.id).url,
        {},
        { preserveScroll: true },
    );

const rejecting = ref<PendingChange | null>(null);

const rejectForm = useForm({ reason: '' });

const submitReject = () => {
    if (!rejecting.value) {
        return;
    }

    rejectForm.post(adminEditRequests.reject(rejecting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
            rejectForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Change Requests" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Change Requests"
            description="Review requested changes to existing bookings before they take effect."
        />

        <div
            v-if="pending.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 text-center"
        >
            <Inbox class="size-10 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm text-muted-foreground">
                No booking changes are waiting for review.
            </p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <Card v-for="change in pending" :key="change.id">
                <CardHeader>
                    <CardTitle>{{ change.resource }}</CardTitle>
                    <CardDescription>
                        Requested by {{ change.requester }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground line-through">
                            {{ slotLabel(change.current) }}
                        </span>
                        <ArrowRight
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="font-medium">
                            {{ slotLabel(change.proposed) }}
                        </span>
                    </div>

                    <p
                        v-if="change.proposed.purpose"
                        class="text-sm text-muted-foreground"
                    >
                        New purpose: {{ change.proposed.purpose }}
                    </p>
                    <p
                        v-if="change.note"
                        class="rounded-md bg-muted/50 px-2.5 py-1.5 text-sm"
                    >
                        {{ change.note }}
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            @click="rejecting = change"
                        >
                            <XCircle aria-hidden="true" />
                            Reject
                        </Button>
                        <Button size="sm" @click="apply(change)">
                            <CheckCircle2 aria-hidden="true" />
                            Apply change
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
                    <DialogTitle>Reject this change?</DialogTitle>
                    <DialogDescription>
                        The requester keeps the original booking and sees your
                        reason.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="change-reject-reason">Reason</Label>
                    <Textarea
                        id="change-reject-reason"
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
                        Reject change
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
