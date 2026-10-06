<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { MailPlus, RefreshCw, SearchX, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import FilterTabs from '@/components/admin/FilterTabs.vue';
import ListPagination from '@/components/admin/ListPagination.vue';
import { iconButton, pillPrimary, pillQuiet } from '@/components/desk/desk';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAuth } from '@/composables/useAuth';
import { useConfirm } from '@/composables/useConfirm';
import { useServerFilters } from '@/composables/useServerFilters';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminInvitations from '@/routes/admin/invitations';
import type { Division, Invitation, Paginated, Role } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Invitations', href: adminInvitations.index() },
        ],
    },
});

const props = defineProps<{
    invitations: Paginated<Invitation>;
    counts: { all: number; waiting: number; expired: number };
    divisions: { data: Division[] };
    roles: { data: Role[] };
    filters: { status?: string };
}>();

// super_admin is granted by super admins only (the server enforces it too).
const { user: authUser } = useAuth();
const assignableRoles = computed(() =>
    props.roles.data.filter(
        (role) => role.name !== 'super_admin' || authUser.value.is_super_admin,
    ),
);

const filters = useServerFilters(
    () => adminInvitations.index().url,
    { status: props.filters.status ?? 'all' },
    { debounce: 0 },
);

const tabs = computed(() => [
    { value: 'all', label: 'All', count: props.counts.all },
    { value: 'waiting', label: 'Waiting', count: props.counts.waiting },
    { value: 'expired', label: 'Expired', count: props.counts.expired },
]);

const creating = ref(false);

const form = useForm({
    email: '',
    division_id: null as number | null,
    role: 'staff',
});

const submit = () => {
    form.post(adminInvitations.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            form.reset();
        },
    });
};

const resend = (invitation: Invitation) =>
    router.post(
        adminInvitations.resend(invitation.id).url,
        {},
        { preserveScroll: true },
    );

const { confirm } = useConfirm();

const revoke = async (invitation: Invitation) => {
    const confirmed = await confirm({
        title: `Revoke the invitation to ${invitation.email}?`,
        description:
            'The link in their email stops working. You can invite them again later.',
        confirmLabel: 'Revoke',
        destructive: true,
    });

    if (confirmed) {
        router.delete(adminInvitations.destroy(invitation.id).url, {
            preserveScroll: true,
        });
    }
};

const when = (iso: string) => format(parseISO(iso), 'MMM d, HH:mm');
</script>

<template>
    <Head title="Invitations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Invitations"
            description="Invited users skip approval: they pick a password and land straight in the calendar."
        >
            <template #actions>
                <button
                    type="button"
                    :class="pillPrimary"
                    @click="creating = true"
                >
                    <MailPlus aria-hidden="true" />
                    Invite user
                </button>
            </template>
        </PageHeader>

        <FilterTabs
            v-if="counts.all > 0"
            v-model="filters.status"
            :options="tabs"
            label="Filter invitations by status"
        />

        <EmptyNote
            v-if="counts.all === 0"
            :icon="MailPlus"
            title="No open invitations"
        >
            Invite someone to give them an account without the approval queue.
        </EmptyNote>

        <EmptyNote
            v-else-if="invitations.data.length === 0"
            :icon="SearchX"
            :title="`No ${filters.status} invitations`"
        >
            {{
                filters.status === 'expired'
                    ? 'Every open invitation can still be accepted.'
                    : 'Every open invitation has expired. Resend one to give the person a fresh link.'
            }}
            <template #action>
                <button
                    type="button"
                    :class="pillQuiet"
                    @click="filters.status = 'all'"
                >
                    Show all
                </button>
            </template>
        </EmptyNote>

        <div v-else class="border-y border-border">
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Email</TableHead>
                        <TableHead>Division</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Invited by</TableHead>
                        <TableHead>Expires</TableHead>
                        <TableHead class="w-40 text-right">
                            <span class="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="invitation in invitations.data"
                        :key="invitation.id"
                    >
                        <TableCell class="py-3 font-medium">
                            {{ invitation.email }}
                        </TableCell>
                        <TableCell class="py-3">
                            <span
                                v-if="invitation.division"
                                class="inline-flex items-center gap-1.5"
                                :title="invitation.division.name"
                            >
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            invitation.division.color,
                                    }"
                                    aria-hidden="true"
                                />
                                {{
                                    invitation.division.acronym ??
                                    invitation.division.name
                                }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="py-3">
                            <Badge variant="outline">
                                {{ invitation.role ?? 'staff' }}
                            </Badge>
                        </TableCell>
                        <TableCell
                            class="py-3 text-[13px] text-muted-foreground"
                        >
                            {{ invitation.inviter }}
                        </TableCell>
                        <TableCell class="py-3 text-[13px] tabular-nums">
                            <span
                                v-if="invitation.is_expired"
                                class="inline-flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2 rounded-full bg-warning"
                                    aria-hidden="true"
                                />
                                Expired {{ when(invitation.expires_at) }}
                            </span>
                            <span v-else class="text-muted-foreground">
                                {{ when(invitation.expires_at) }}
                            </span>
                        </TableCell>
                        <TableCell class="py-3 text-right">
                            <div class="flex items-center justify-end gap-0.5">
                                <!-- An expired link can't be accepted: resending is the next step. -->
                                <button
                                    v-if="invitation.is_expired"
                                    type="button"
                                    :class="pillPrimary"
                                    :aria-label="`Resend invitation to ${invitation.email}`"
                                    @click="resend(invitation)"
                                >
                                    <RefreshCw aria-hidden="true" />
                                    Resend
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="`Resend invitation to ${invitation.email}`"
                                    @click="resend(invitation)"
                                >
                                    <RefreshCw aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        iconButton,
                                        'text-destructive hover:bg-destructive/10 hover:text-destructive',
                                    ]"
                                    :aria-label="`Revoke invitation for ${invitation.email}`"
                                    @click="revoke(invitation)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <ListPagination :meta="invitations.meta" noun="invitations" />

        <Dialog v-model:open="creating">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite a user</DialogTitle>
                    <DialogDescription>
                        They receive an email link to set up their account with
                        the division and role you pick here.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email address</Label>
                        <Input
                            id="invite-email"
                            v-model="form.email"
                            type="email"
                            placeholder="name@fdcp.gov.ph"
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="invite-division">Division</Label>
                        <Select
                            :model-value="
                                form.division_id === null
                                    ? 'none'
                                    : String(form.division_id)
                            "
                            @update:model-value="
                                (v) =>
                                    (form.division_id =
                                        v === 'none' ? null : Number(v))
                            "
                        >
                            <SelectTrigger id="invite-division">
                                <SelectValue
                                    placeholder="Assign later (optional)"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Assign later
                                </SelectItem>
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
                            v-if="form.errors.division_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.division_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <Select v-model="form.role">
                            <SelectTrigger id="invite-role">
                                <SelectValue placeholder="staff" />
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
                            v-if="form.errors.role"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.role }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="creating = false">
                        Cancel
                    </Button>
                    <Button :disabled="form.processing" @click="submit">
                        Send invitation
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
