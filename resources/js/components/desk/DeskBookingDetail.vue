<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowUpRight, Check, CircleAlert } from '@lucide/vue';
import { ref } from 'vue';
import resourcesPage from '@/routes/resources';
import type { DeskBooking, DeskResource, DeskResourceType } from '@/types';
import { decisionUrls, pillQuiet, pillSuccess, timeRange } from './desk';
import DeskRejectForm from './DeskRejectForm.vue';

const props = defineProps<{
    booking: DeskBooking;
    resource: DeskResource;
    type: DeskResourceType;
    clash: boolean;
}>();

const rejecting = ref(false);
// Approval has no fields; the server reports a late clash under `status`.
const approveForm = useForm<{ status?: string }>({});

const approve = () =>
    approveForm.post(decisionUrls.booking.approve(props.booking.id), {
        preserveScroll: true,
        preserveState: true,
    });
</script>

<template>
    <div class="grid gap-3">
        <div class="space-y-1">
            <p class="text-sm leading-snug font-semibold">
                {{ booking.title }}
            </p>
            <p class="text-[13px] text-muted-foreground tabular-nums">
                {{ timeRange(booking.starts_at, booking.ends_at) }} ·
                {{ resource.name }}
            </p>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px]">
            <dt class="text-muted-foreground">Status</dt>
            <dd>
                {{
                    booking.status === 'approved'
                        ? 'Approved'
                        : 'Waiting for a decision'
                }}
            </dd>
            <dt class="text-muted-foreground">Requested by</dt>
            <dd>{{ booking.requester ?? 'Unknown' }}</dd>
            <template v-if="booking.attendee_count">
                <dt class="text-muted-foreground">People</dt>
                <dd class="tabular-nums">{{ booking.attendee_count }}</dd>
            </template>
            <template
                v-if="booking.purpose && booking.purpose !== booking.title"
            >
                <dt class="text-muted-foreground">Purpose</dt>
                <dd>{{ booking.purpose }}</dd>
            </template>
        </dl>

        <p
            v-if="clash"
            class="flex items-start gap-1.5 text-[13px] text-destructive"
        >
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            Overlaps an approved booking in this
            {{ type.name.toLowerCase() }}.
        </p>

        <p
            v-if="approveForm.errors.status"
            class="text-[13px] text-destructive"
            role="alert"
        >
            {{ approveForm.errors.status }}
        </p>

        <DeskRejectForm
            v-if="rejecting"
            kind="booking"
            :id="booking.id"
            @cancel="rejecting = false"
        />

        <div
            v-else
            class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3"
        >
            <Link
                :href="resourcesPage.section(type.slug)"
                class="inline-flex items-center gap-1 rounded-sm text-[13px] text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
            >
                {{ type.name }} calendar
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Link>
            <div
                v-if="booking.status === 'pending' && booking.can_approve"
                class="flex gap-2"
            >
                <button
                    type="button"
                    :class="pillQuiet"
                    @click="rejecting = true"
                >
                    Reject
                </button>
                <button
                    type="button"
                    :class="pillSuccess"
                    :disabled="approveForm.processing || clash"
                    @click="approve"
                >
                    <Check aria-hidden="true" />
                    Approve
                </button>
            </div>
        </div>
    </div>
</template>
