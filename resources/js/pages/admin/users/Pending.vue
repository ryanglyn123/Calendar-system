<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Inbox, XCircle } from '@lucide/vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

defineProps<{
    pending: { data: AdminUser[] };
    divisions: { data: Division[] };
    roles: { data: Role[] };
}>();

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

const rejecting = ref<AdminUser | null>(null);

const submitReject = () => {
    if (!rejecting.value) {
        return;
    }

    router.delete(adminPending.reject(rejecting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
        },
    });
};
</script>

<template>
    <Head title="Pending Registrations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Pending Registrations"
            description="Review new account registrations and assign access before activating them."
        />

        <div
            v-if="pending.data.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 text-center"
        >
            <Inbox class="size-10 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm text-muted-foreground">
                No registrations are waiting for review.
            </p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="user in pending.data" :key="user.id">
                <CardHeader>
                    <CardTitle>{{ user.name }}</CardTitle>
                    <CardDescription>{{ user.email }}</CardDescription>
                </CardHeader>
                <CardContent class="flex items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">
                        Registered
                        {{ new Date(user.created_at).toLocaleDateString() }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            @click="rejecting = user"
                        >
                            <XCircle aria-hidden="true" />
                            Reject
                        </Button>
                        <Button size="sm" @click="openApprove(user)">
                            <CheckCircle2 aria-hidden="true" />
                            Approve
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

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
                                    v-for="role in roles.data"
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
                        :disabled="approveForm.processing"
                        @click="submitApprove"
                    >
                        Approve account
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="rejecting !== null"
            @update:open="(open) => !open && (rejecting = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Reject {{ rejecting?.name }}'s registration?
                    </DialogTitle>
                    <DialogDescription>
                        The registration is removed permanently. They can
                        register again later.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="rejecting = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="submitReject">
                        Reject registration
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
