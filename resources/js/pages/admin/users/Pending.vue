<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Inbox } from '@lucide/vue';
import { formatDistanceToNowStrict } from 'date-fns';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAuth } from '@/composables/useAuth';
import { useConfirm } from '@/composables/useConfirm';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminPending from '@/routes/admin/pending-registrations';
import type { AdminUser, Division, Role } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Pending Registrations', href: adminPending.index() },
        ],
    },
});

const props = defineProps<{
    pending: { data: AdminUser[] };
    divisions: { data: Division[] };
    roles: { data: Role[] };
}>();

// super_admin is granted by super admins only (the server enforces it too).
const { user: authUser } = useAuth();
const assignableRoles = computed(() =>
    props.roles.data.filter(
        (role) => role.name !== 'super_admin' || authUser.value.is_super_admin,
    ),
);

const registeredAt = (iso: string) => format(parseISO(iso), 'MMM d, HH:mm');

const registeredAgo = (iso: string) =>
    formatDistanceToNowStrict(parseISO(iso), { addSuffix: true });

const approving = ref<AdminUser | null>(null);

const approveForm = useForm({
    division_id: null as number | null,
    role: 'staff',
});

const openApprove = (user: AdminUser) => {
    approving.value = user;
    approveForm.reset();
    // Pre-select the division the applicant chose at signup.
    approveForm.division_id = user.division?.id ?? null;
};

const submitApprove = () => {
    if (!approving.value) {
        return;
    }

    approveForm.post(adminPending.approve(approving.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            approving.value = null;
        },
    });
};

const { confirm } = useConfirm();

const reject = async (user: AdminUser) => {
    const confirmed = await confirm({
        title: `Reject ${user.name}'s registration?`,
        description:
            'The registration is removed permanently. They can register again later.',
        confirmLabel: 'Reject registration',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(adminPending.reject(user.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Pending Registrations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Pending Registrations"
            description="Review new account registrations and assign access before activating them."
        />

        <EmptyNote
            v-if="pending.data.length === 0"
            :icon="Inbox"
            title="No registrations waiting"
        >
            New sign-ups appear here until someone approves or rejects them.
        </EmptyNote>

        <ul v-else class="divide-y divide-border border-y border-border">
            <li
                v-for="user in pending.data"
                :key="user.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-2 py-3 hover:bg-muted/40 motion-safe:transition-colors motion-safe:duration-150 md:grid md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
            >
                <div class="w-full min-w-0 md:w-auto">
                    <p class="truncate text-sm font-medium">{{ user.name }}</p>
                    <p class="truncate text-[13px] text-muted-foreground">
                        {{ user.email }}
                    </p>
                </div>

                <p class="text-[13px] text-muted-foreground">
                    <time :datetime="user.created_at" class="tabular-nums">
                        {{ registeredAt(user.created_at) }}
                    </time>
                    <span class="tabular-nums md:block">
                        <span class="md:hidden"> · </span>
                        {{ registeredAgo(user.created_at) }}
                    </span>
                </p>

                <p class="min-w-0 truncate text-[13px] text-muted-foreground">
                    {{ user.division?.name ?? 'No division chosen' }}
                </p>

                <div class="ml-auto flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        @click="reject(user)"
                    >
                        Reject
                    </Button>
                    <Button
                        variant="success"
                        size="sm"
                        class="rounded-full"
                        @click="openApprove(user)"
                    >
                        <Check aria-hidden="true" />
                        Approve
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog
            :open="approving !== null"
            @update:open="(open) => !open && (approving = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Approve {{ approving?.name }}</DialogTitle>
                    <DialogDescription>
                        Assign the user's division and role to activate their
                        account. They will be notified by email.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="approve-division">Division</Label>
                        <Select
                            :model-value="
                                approveForm.division_id === null
                                    ? undefined
                                    : String(approveForm.division_id)
                            "
                            @update:model-value="
                                (v) => (approveForm.division_id = Number(v))
                            "
                        >
                            <SelectTrigger id="approve-division">
                                <SelectValue placeholder="Select a division" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="d in divisions.data"
                                    :key="d.id"
                                    :value="String(d.id)"
                                >
                                    {{ d.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="approveForm.errors.division_id"
                            class="text-sm text-destructive"
                        >
                            {{ approveForm.errors.division_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="approve-role">Role</Label>
                        <Select v-model="approveForm.role">
                            <SelectTrigger id="approve-role">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in assignableRoles"
                                    :key="role.id"
                                    :value="role.name"
                                >
                                    {{ role.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="approveForm.errors.role"
                            class="text-sm text-destructive"
                        >
                            {{ approveForm.errors.role }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="approving = null">
                        Cancel
                    </Button>
                    <Button
                        variant="success"
                        :disabled="approveForm.processing"
                        @click="submitApprove"
                    >
                        Approve account
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
