<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Pencil,
    SearchX,
    ShieldBan,
    ShieldCheck,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import ListPagination from '@/components/admin/ListPagination.vue';
import SearchField from '@/components/admin/SearchField.vue';
import { iconButton, pillQuiet } from '@/components/desk/desk';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import admin from '@/routes/admin';
import adminUsers from '@/routes/admin/users';
import type { AdminUser, Division, Paginated, Role } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Users', href: adminUsers.index() },
        ],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    divisions: { data: Division[] };
    roles: { data: Role[] };
    filters: {
        search?: string;
        division?: string;
        status?: string;
        role?: string;
        sort?: string;
    };
}>();

// super_admin is granted by super admins only (the server enforces it too).
const { user: authUser, hasPermission } = useAuth();

// Approvers (users.approve) may browse the list; editing, suspending and
// deleting need users.manage, which the server enforces too.
const canManageUsers = computed(() => hasPermission('users.manage'));
const assignableRoles = computed(() =>
    props.roles.data.filter(
        (role) => role.name !== 'super_admin' || authUser.value.is_super_admin,
    ),
);

const filters = useServerFilters(() => adminUsers.index().url, {
    search: props.filters.search ?? '',
    division: props.filters.division ?? 'all',
    status: props.filters.status ?? 'all',
    role: props.filters.role ?? 'all',
    sort: props.filters.sort ?? 'name',
});

const SORTS: Record<string, string> = {
    name: 'Name A–Z',
    newest: 'Newest first',
    division: 'By division',
};

// Sort is an ordering, not a filter: clearing filters keeps it.
const filtered = computed(
    () =>
        filters.search !== '' ||
        [filters.division, filters.status, filters.role].some(
            (value) => value !== 'all',
        ),
);

const clearFilters = () =>
    Object.assign(filters, {
        search: '',
        division: 'all',
        status: 'all',
        role: 'all',
    });

const editing = ref<AdminUser | null>(null);

const editForm = useForm({
    name: '',
    division_id: null as number | null,
    roles: [] as string[],
});

const openEdit = (user: AdminUser) => {
    editing.value = user;
    editForm.name = user.name;
    editForm.division_id = user.division?.id ?? null;
    editForm.roles = [...user.roles];
};

const submitEdit = () => {
    if (!editing.value) {
        return;
    }

    editForm.patch(adminUsers.update(editing.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};

const toggleRole = (role: string, checked: boolean) => {
    editForm.roles = checked
        ? [...new Set([...editForm.roles, role])]
        : editForm.roles.filter((r) => r !== role);
};

const { confirm } = useConfirm();

// Suspending locks the person out, so it asks first; reactivating does not.
const suspend = async (user: AdminUser) => {
    const confirmed = await confirm({
        title: `Suspend ${user.name}?`,
        description:
            'They lose access to the calendar until you reactivate them. Their events and bookings stay.',
        confirmLabel: 'Suspend',
        destructive: true,
    });

    if (confirmed) {
        router.post(
            adminUsers.suspend(user.id).url,
            {},
            { preserveScroll: true },
        );
    }
};

const reactivate = (user: AdminUser) =>
    router.post(
        adminUsers.reactivate(user.id).url,
        {},
        { preserveScroll: true },
    );

const confirmingDelete = ref<AdminUser | null>(null);

const destroy = () => {
    if (!confirmingDelete.value) {
        return;
    }

    router.delete(adminUsers.destroy(confirmingDelete.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDelete.value = null;
        },
    });
};

const statusDot: Record<AdminUser['status'], string> = {
    active: 'bg-success',
    pending: 'bg-warning',
    suspended: 'bg-destructive',
};
</script>

<template>
    <Head title="Users" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Users"
            description="Manage accounts, divisions, roles and access."
        />

        <div class="flex flex-wrap items-center gap-2">
            <SearchField
                v-model="filters.search"
                label="Search users"
                placeholder="Search name or email…"
            />

            <Select v-model="filters.division">
                <SelectTrigger
                    size="sm"
                    class="w-48"
                    aria-label="Filter by division"
                >
                    <SelectValue placeholder="All divisions" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All divisions</SelectItem>
                    <SelectItem
                        v-for="d in divisions.data"
                        :key="d.id"
                        :value="String(d.id)"
                    >
                        {{ d.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="filters.role">
                <SelectTrigger
                    size="sm"
                    class="w-40"
                    aria-label="Filter by role"
                >
                    <SelectValue placeholder="All roles" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All roles</SelectItem>
                    <SelectItem
                        v-for="role in roles.data"
                        :key="role.id"
                        :value="role.name"
                    >
                        {{ role.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="filters.status">
                <SelectTrigger
                    size="sm"
                    class="w-36"
                    aria-label="Filter by status"
                >
                    <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                    <SelectItem value="suspended">Suspended</SelectItem>
                </SelectContent>
            </Select>

            <button
                v-if="filtered"
                type="button"
                :class="pillQuiet"
                @click="clearFilters"
            >
                Clear filters
            </button>

            <Select v-model="filters.sort">
                <SelectTrigger
                    size="sm"
                    class="w-40 sm:ml-auto"
                    aria-label="Sort users"
                >
                    <SelectValue>{{
                        SORTS[filters.sort ?? 'name']
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="(label, value) in SORTS"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <EmptyNote
            v-if="users.data.length === 0 && filtered"
            :icon="SearchX"
            title="No users match these filters"
        >
            Try another division, role or status, or a shorter search.
            <template #action>
                <button type="button" :class="pillQuiet" @click="clearFilters">
                    Clear filters
                </button>
            </template>
        </EmptyNote>

        <EmptyNote
            v-else-if="users.data.length === 0"
            :icon="Users"
            title="No users yet"
        >
            People appear here once they register or accept an invitation.
        </EmptyNote>

        <div v-else class="border-y border-border">
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Name</TableHead>
                        <TableHead>Division</TableHead>
                        <TableHead>Roles</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-32 text-right">
                            <span class="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell class="py-3">
                            <div class="font-medium">{{ user.name }}</div>
                            <div class="text-[13px] text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </TableCell>
                        <TableCell class="py-3">
                            <span
                                v-if="user.division"
                                class="inline-flex items-center gap-1.5"
                                :title="user.division.name"
                            >
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor: user.division.color,
                                    }"
                                    aria-hidden="true"
                                />
                                {{
                                    user.division.acronym ?? user.division.name
                                }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="py-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role"
                                    variant="outline"
                                >
                                    {{ role }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="py-3">
                            <span
                                class="inline-flex items-center gap-1.5 text-[13px]"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="statusDot[user.status]"
                                    aria-hidden="true"
                                />
                                {{ user.status_label }}
                            </span>
                        </TableCell>
                        <TableCell class="py-3 text-right">
                            <div
                                v-if="canManageUsers"
                                class="flex justify-end gap-0.5"
                            >
                                <button
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="`Edit ${user.name}`"
                                    @click="openEdit(user)"
                                >
                                    <Pencil aria-hidden="true" />
                                </button>
                                <button
                                    v-if="user.status === 'active'"
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="`Suspend ${user.name}`"
                                    @click="suspend(user)"
                                >
                                    <ShieldBan aria-hidden="true" />
                                </button>
                                <button
                                    v-else-if="user.status === 'suspended'"
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="`Reactivate ${user.name}`"
                                    @click="reactivate(user)"
                                >
                                    <ShieldCheck aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        iconButton,
                                        'text-destructive hover:bg-destructive/10 hover:text-destructive',
                                    ]"
                                    :aria-label="`Delete ${user.name}`"
                                    @click="confirmingDelete = user"
                                >
                                    <Trash2 aria-hidden="true" />
                                </button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <ListPagination :meta="users.meta" noun="users" />

        <Dialog
            :open="editing !== null"
            @update:open="(open) => !open && (editing = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit {{ editing?.name }}</DialogTitle>
                    <DialogDescription>
                        Update the user's profile, division and roles.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="edit-name">Name</Label>
                        <Input id="edit-name" v-model="editForm.name" />
                        <p
                            v-if="editForm.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ editForm.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-division">Division</Label>
                        <Select
                            :model-value="
                                editForm.division_id === null
                                    ? 'none'
                                    : String(editForm.division_id)
                            "
                            @update:model-value="
                                (v) =>
                                    (editForm.division_id =
                                        v === 'none' ? null : Number(v))
                            "
                        >
                            <SelectTrigger id="edit-division">
                                <SelectValue placeholder="No division" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    No division
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
                            v-if="editForm.errors.division_id"
                            class="text-sm text-destructive"
                        >
                            {{ editForm.errors.division_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Roles</Label>
                        <div class="grid gap-2">
                            <Label
                                v-for="role in assignableRoles"
                                :key="role.id"
                                class="flex items-center gap-2 font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        editForm.roles.includes(role.name)
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            toggleRole(
                                                role.name,
                                                checked === true,
                                            )
                                    "
                                />
                                {{ role.name }}
                            </Label>
                        </div>
                        <p
                            v-if="editForm.errors.roles"
                            class="text-sm text-destructive"
                        >
                            {{ editForm.errors.roles }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="editing = null">
                        Cancel
                    </Button>
                    <Button :disabled="editForm.processing" @click="submitEdit">
                        Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="confirmingDelete !== null"
            @update:open="(open) => !open && (confirmingDelete = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Remove {{ confirmingDelete?.name }}?
                    </DialogTitle>
                    <DialogDescription>
                        The account is soft-deleted: their history is kept, but
                        they can no longer sign in.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirmingDelete = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="destroy">
                        Remove user
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
