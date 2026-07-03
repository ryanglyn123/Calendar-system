<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Search, ShieldBan, ShieldCheck, Trash2 } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    filters: { search?: string; division?: string; status?: string };
}>();

const search = ref(props.filters.search ?? '');
const division = ref(props.filters.division ?? 'all');
const status = ref(props.filters.status ?? 'all');

const applyFilters = useDebounceFn(() => {
    router.get(
        adminUsers.index().url,
        {
            search: search.value || undefined,
            division: division.value === 'all' ? undefined : division.value,
            status: status.value === 'all' ? undefined : status.value,
        },
        { preserveState: true, replace: true },
    );
}, 300);

watch([search, division, status], applyFilters);

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

const suspend = (user: AdminUser) =>
    router.post(adminUsers.suspend(user.id).url, {}, { preserveScroll: true });

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

const statusVariant = (value: AdminUser['status']) =>
    ({ active: 'default', pending: 'secondary', suspended: 'destructive' })[
        value
    ] as 'default' | 'secondary' | 'destructive';

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»').replace('&hellip;', '…');
</script>

<template>
    <Head title="Users" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative max-w-xs flex-1">
                <Search
                    class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search name or email…"
                    class="pl-8"
                    aria-label="Search users"
                />
            </div>

            <Select v-model="division">
                <SelectTrigger class="w-56" aria-label="Filter by division">
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

            <Select v-model="status">
                <SelectTrigger class="w-40" aria-label="Filter by status">
                    <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                    <SelectItem value="suspended">Suspended</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Division</TableHead>
                        <TableHead>Roles</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-32 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="users.data.length === 0" :colspan="5">
                        No users match the current filters.
                    </TableEmpty>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <div class="font-medium">{{ user.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <span
                                v-if="user.division"
                                class="inline-flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor: user.division.color,
                                    }"
                                    aria-hidden="true"
                                />
                                {{ user.division.acronym ?? user.division.name }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
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
                        <TableCell>
                            <Badge :variant="statusVariant(user.status)">
                                {{ user.status_label }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${user.name}`"
                                    @click="openEdit(user)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    v-if="user.status === 'active'"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Suspend ${user.name}`"
                                    @click="suspend(user)"
                                >
                                    <ShieldBan aria-hidden="true" />
                                </Button>
                                <Button
                                    v-else-if="user.status === 'suspended'"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Reactivate ${user.name}`"
                                    @click="reactivate(user)"
                                >
                                    <ShieldCheck aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${user.name}`"
                                    @click="confirmingDelete = user"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="users.meta.last_page > 1"
            class="flex items-center justify-center gap-1"
        >
            <template v-for="(link, i) in users.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted'
                    "
                >
                    {{ paginationLabel(link.label) }}
                </Link>
                <span
                    v-else
                    class="px-3 py-1.5 text-sm text-muted-foreground/50"
                >
                    {{ paginationLabel(link.label) }}
                </span>
            </template>
        </div>

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
                                v-for="role in roles.data"
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
                    <Button
                        :disabled="editForm.processing"
                        @click="submitEdit"
                    >
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
