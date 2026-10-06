<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Grid3x3, List, Lock, Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ViewSwitcher from '@/components/admin/ViewSwitcher.vue';
import type { ViewOption } from '@/components/admin/ViewSwitcher.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { useConfirm } from '@/composables/useConfirm';
import { useViewPreference } from '@/composables/useViewPreference';
import admin from '@/routes/admin';
import adminRoles from '@/routes/admin/roles';
import type { Role } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Roles & Permissions', href: adminRoles.index() },
        ],
    },
});

const props = defineProps<{
    roles: { data: Role[] };
    permissions: string[];
}>();

const SUPER_ADMIN = 'super_admin';

/** `board_room_approver` → "Board room approver", `event-types` → "Event types". */
const humanize = (value: string): string => {
    const words = value.replace(/[-_]/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
};

/** `bookings.approve` → { domain: "Bookings", action: "Approve" }, grouped in the given order. */
const groupByDomain = (names: string[]) => {
    const groups = new Map<string, { name: string; action: string }[]>();

    for (const name of names) {
        const [domain, action = ''] = name.split('.');
        const label = humanize(domain);
        groups.set(label, [
            ...(groups.get(label) ?? []),
            { name, action: humanize(action) },
        ]);
    }

    return [...groups].map(([domain, items]) => ({ domain, items }));
};

// Matrix rows read as plain verbs: `bookings.approve` → "Approve bookings".
// Most domains hold one permission, so domain headings would double the rows.
const permissionGroups = computed(() => groupByDomain(props.permissions));

const permissionRows = computed(() =>
    permissionGroups.value.flatMap((group) =>
        group.items.map((item) => ({
            name: item.name,
            label: `${item.action} ${group.domain.toLowerCase()}`,
        })),
    ),
);

const summary = (role: Role): string =>
    groupByDomain([...(role.permissions ?? [])].sort())
        .map(
            (group) =>
                `${group.domain}: ${group.items.map((item) => item.action.toLowerCase()).join(', ')}`,
        )
        .join(' · ');

const members = (role: Role): string => {
    const count = role.users_count ?? 0;

    return `${count} ${count === 1 ? 'member' : 'members'}`;
};

// super_admin first; the server's name order holds for the rest (stable sort).
const roles = computed(() =>
    [...props.roles.data].sort(
        (a, b) =>
            Number(b.name === SUPER_ADMIN) - Number(a.name === SUPER_ADMIN),
    ),
);

const view = useViewPreference('roles', ['list', 'matrix'] as const);

const viewOptions: ViewOption<'list' | 'matrix'>[] = [
    {
        value: 'list',
        label: 'Roles',
        icon: List,
        hint: 'Each role with its members and a permission summary',
    },
    {
        value: 'matrix',
        label: 'Matrix',
        icon: Grid3x3,
        hint: 'Compare and edit permissions across every role',
    },
];

// Matrix: each tick saves its role straight away. Inertia's optimistic update
// shows the tick at once and rolls it back if the save fails.
const saving = reactive(new Set<number>());

const failed = (role: Role) =>
    toast.error(`Couldn't save ${humanize(role.name)}. The change was undone.`);

const toggleCell = (role: Role, permission: string, checked: boolean) => {
    const current = role.permissions ?? [];
    const permissions = checked
        ? [...new Set([...current, permission])]
        : current.filter((name) => name !== permission);

    saving.add(role.id);

    router.patch(
        adminRoles.update(role.id).url,
        {
            ...(role.is_system ? {} : { name: role.name }),
            description: role.description,
            permissions,
        },
        {
            preserveScroll: true,
            preserveState: true,
            optimistic: (pageProps) => {
                const page = pageProps.roles as { data: Role[] };

                return {
                    roles: {
                        ...page,
                        data: page.data.map((item) =>
                            item.id === role.id
                                ? { ...item, permissions }
                                : item,
                        ),
                    },
                };
            },
            onError: () => failed(role),
            onHttpException: () => {
                failed(role);

                return false;
            },
            onNetworkError: () => {
                failed(role);

                return false;
            },
            onFinish: () => saving.delete(role.id),
        },
    );
};

const editing = ref<Role | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (role: Role) => {
    form.clearErrors();
    form.name = role.name;
    form.description = role.description ?? '';
    form.permissions = [...(role.permissions ?? [])];
    editing.value = role;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    if (editing.value) {
        form.patch(adminRoles.update(editing.value.id).url, {
            preserveScroll: true,
            onSuccess: close,
        });
    } else {
        form.post(adminRoles.store().url, {
            preserveScroll: true,
            onSuccess: close,
        });
    }
};

const togglePermission = (permission: string, checked: boolean) => {
    form.permissions = checked
        ? [...new Set([...form.permissions, permission])]
        : form.permissions.filter((p) => p !== permission);
};

const { confirm } = useConfirm();

const destroy = async (role: Role) => {
    if (
        !(await confirm({
            title: `Delete ${humanize(role.name)}?`,
            description:
                'The role and its permission set are removed. Nobody holds it, so no one loses access.',
            confirmLabel: 'Delete role',
            destructive: true,
        }))
    ) {
        return;
    }

    router.delete(adminRoles.destroy(role.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Roles & Permissions" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Roles & Permissions"
            description="Roles bundle permissions. Assign roles to users from the Users screen."
        >
            <template #actions>
                <Button class="rounded-full" @click="openCreate">
                    <Plus aria-hidden="true" />
                    New role
                </Button>
            </template>
        </PageHeader>

        <section class="flex flex-col gap-3">
            <div class="flex min-h-8 items-center justify-between gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">
                    {{ view === 'list' ? 'Roles' : 'Permissions' }}
                    <span
                        class="ml-1 text-sm font-normal text-muted-foreground tabular-nums"
                    >
                        {{
                            view === 'list' ? roles.length : permissions.length
                        }}
                    </span>
                </h2>
                <ViewSwitcher
                    v-model="view"
                    :options="viewOptions"
                    label="Roles view"
                />
            </div>

            <ul
                v-if="view === 'list'"
                class="divide-y divide-border border-y border-border"
            >
                <li
                    v-for="role in roles"
                    :key="role.id"
                    class="grid gap-x-6 gap-y-1.5 px-2 py-3 md:grid-cols-[minmax(0,18rem)_minmax(0,1fr)_6rem_7.5rem] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-x-2">
                            <span class="text-sm font-medium">
                                {{ humanize(role.name) }}
                            </span>
                            <code
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ role.name }}
                            </code>
                            <span
                                v-if="role.is_system"
                                :title="
                                    role.name === SUPER_ADMIN
                                        ? 'Locked: bypasses every permission check'
                                        : 'System role: it can\'t be renamed or deleted'
                                "
                            >
                                <Lock
                                    class="size-3.5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">System role</span>
                            </span>
                        </p>
                        <p class="text-[13px] text-muted-foreground">
                            {{
                                role.description ||
                                (role.name === SUPER_ADMIN
                                    ? 'Bypasses every permission check. It cannot be edited, so assign it sparingly.'
                                    : 'No description')
                            }}
                        </p>
                    </div>

                    <p class="text-[13px] text-foreground/80">
                        <template v-if="role.name === SUPER_ADMIN">
                            All permissions
                        </template>
                        <template v-else-if="(role.permissions ?? []).length">
                            {{ summary(role) }}
                        </template>
                        <span v-else class="text-muted-foreground">
                            No permissions
                        </span>
                    </p>

                    <p
                        class="text-[13px] text-muted-foreground tabular-nums md:text-right"
                    >
                        {{ members(role) }}
                    </p>

                    <div
                        v-if="role.name !== SUPER_ADMIN"
                        class="flex items-center gap-1 md:justify-end"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                            :aria-label="`Edit ${humanize(role.name)}`"
                            @click="openEdit(role)"
                        >
                            Edit
                        </Button>
                        <span
                            v-if="!role.is_system"
                            :title="
                                (role.users_count ?? 0) > 0
                                    ? `Held by ${members(role)}. Reassign them before deleting.`
                                    : undefined
                            "
                        >
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :disabled="(role.users_count ?? 0) > 0"
                                :aria-label="
                                    (role.users_count ?? 0) > 0
                                        ? `Delete ${humanize(role.name)} (unavailable: held by ${members(role)})`
                                        : `Delete ${humanize(role.name)}`
                                "
                                @click="destroy(role)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </span>
                    </div>
                </li>
            </ul>

            <template v-else>
                <p class="text-[13px] text-muted-foreground">
                    Ticking a box saves that role right away. Super admin always
                    has every permission.
                </p>

                <div
                    class="overflow-x-auto border-y border-border outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    role="region"
                    aria-label="Permission matrix"
                    tabindex="0"
                >
                    <table
                        class="w-full border-separate border-spacing-0 text-[13px]"
                    >
                        <thead>
                            <tr>
                                <th
                                    scope="col"
                                    class="z-20 min-w-40 border-b border-border px-2 py-2 text-left align-bottom font-medium max-md:sticky max-md:left-0 max-md:bg-card"
                                >
                                    Permission
                                </th>
                                <th
                                    v-for="role in roles"
                                    :key="role.id"
                                    scope="col"
                                    class="min-w-28 border-b border-border px-3 py-2 text-center align-bottom font-medium"
                                    :title="
                                        role.name === SUPER_ADMIN
                                            ? 'Super admin always has every permission'
                                            : role.name
                                    "
                                >
                                    <span class="block">
                                        {{ humanize(role.name) }}
                                    </span>
                                    <span
                                        class="block font-normal text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            saving.has(role.id)
                                                ? 'Saving…'
                                                : members(role)
                                        }}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in permissionRows" :key="item.name">
                                <th
                                    scope="row"
                                    class="z-1 border-t border-border px-2 py-0 text-left font-normal max-md:sticky max-md:left-0 max-md:bg-card"
                                    :title="item.name"
                                >
                                    {{ item.label }}
                                </th>
                                <td
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="border-t border-border p-0"
                                    :title="
                                        role.name === SUPER_ADMIN
                                            ? 'Super admin always has every permission'
                                            : undefined
                                    "
                                >
                                    <label
                                        class="flex h-9 items-center justify-center"
                                        :class="
                                            role.name === SUPER_ADMIN ||
                                            saving.has(role.id)
                                                ? 'cursor-not-allowed'
                                                : 'cursor-pointer'
                                        "
                                    >
                                        <Checkbox
                                            v-if="role.name === SUPER_ADMIN"
                                            :model-value="true"
                                            disabled
                                            :aria-label="`${humanize(role.name)}: ${item.label.toLowerCase()} (always granted)`"
                                        />
                                        <Checkbox
                                            v-else
                                            :model-value="
                                                (
                                                    role.permissions ?? []
                                                ).includes(item.name)
                                            "
                                            :disabled="saving.has(role.id)"
                                            :aria-label="`${humanize(role.name)}: ${item.label.toLowerCase()}`"
                                            @update:model-value="
                                                (checked) =>
                                                    toggleCell(
                                                        role,
                                                        item.name,
                                                        checked === true,
                                                    )
                                            "
                                        />
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editing
                                ? `Edit ${humanize(editing.name)}`
                                : 'New role'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Pick the permissions this role grants.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div v-if="!editing?.is_system" class="grid gap-2">
                        <Label for="role-name">Name</Label>
                        <Input
                            id="role-name"
                            v-model="form.name"
                            placeholder="e.g. events_editor"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="role-description">Description</Label>
                        <Input
                            id="role-description"
                            v-model="form.description"
                        />
                        <p
                            v-if="form.errors.description"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.description }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <p class="text-sm font-medium">Permissions</p>
                        <div
                            class="divide-y divide-border border-y border-border"
                        >
                            <div
                                v-for="group in permissionGroups"
                                :key="group.domain"
                                role="group"
                                :aria-label="group.domain"
                                class="grid gap-x-4 gap-y-2 py-2.5 sm:grid-cols-[8rem_1fr]"
                            >
                                <p class="text-[13px] font-medium">
                                    {{ group.domain }}
                                </p>
                                <div class="flex flex-wrap gap-x-4 gap-y-2">
                                    <Label
                                        v-for="item in group.items"
                                        :key="item.name"
                                        class="flex items-center gap-2 text-[13px] font-normal"
                                        :title="item.name"
                                    >
                                        <Checkbox
                                            :model-value="
                                                form.permissions.includes(
                                                    item.name,
                                                )
                                            "
                                            @update:model-value="
                                                (checked) =>
                                                    togglePermission(
                                                        item.name,
                                                        checked === true,
                                                    )
                                            "
                                        />
                                        {{ item.action }}
                                    </Label>
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="form.errors.permissions"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.permissions }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        class="rounded-full"
                        @click="close"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="rounded-full"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        {{ editing ? 'Save changes' : 'Create role' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
