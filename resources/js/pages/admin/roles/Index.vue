<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Lock, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

const editableRoles = computed(() =>
    props.roles.data.filter((role) => role.name !== 'super_admin'),
);

const superAdmin = computed(() =>
    props.roles.data.find((role) => role.name === 'super_admin'),
);

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

const deleting = ref<Role | null>(null);

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(adminRoles.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};
</script>

<template>
    <Head title="Roles & Permissions" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Roles bundle permissions. Assign roles to users from the Users
                screen.
            </p>
            <Button @click="openCreate">
                <Plus aria-hidden="true" />
                New role
            </Button>
        </div>

        <Card v-if="superAdmin">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    super_admin
                    <Lock
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                </CardTitle>
                <CardDescription>
                    Bypasses every permission check. Cannot be edited —
                    assign it sparingly.
                    {{ superAdmin.users_count ?? 0 }} user(s) hold it.
                </CardDescription>
            </CardHeader>
        </Card>

        <div class="grid gap-4 md:grid-cols-2">
            <Card v-for="role in editableRoles" :key="role.id">
                <CardHeader>
                    <CardTitle
                        class="flex items-center justify-between gap-2 text-base"
                    >
                        <span class="flex items-center gap-2">
                            {{ role.name }}
                            <Lock
                                v-if="role.is_system"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="flex gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="openEdit(role)"
                            >
                                Edit
                            </Button>
                            <Button
                                v-if="!role.is_system"
                                variant="ghost"
                                size="icon"
                                class="text-destructive hover:text-destructive"
                                :disabled="(role.users_count ?? 0) > 0"
                                :aria-label="`Delete ${role.name}`"
                                @click="deleting = role"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </span>
                    </CardTitle>
                    <CardDescription>
                        {{ role.description || 'No description' }} ·
                        {{ role.users_count ?? 0 }} user(s)
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="permission in role.permissions"
                            :key="permission"
                            variant="secondary"
                        >
                            {{ permission }}
                        </Badge>
                        <span
                            v-if="(role.permissions ?? []).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No permissions
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? `Edit ${editing.name}` : 'New role' }}
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
                        <Label>Permissions</Label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Label
                                v-for="permission in permissions"
                                :key="permission"
                                class="flex items-center gap-2 font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        form.permissions.includes(permission)
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            togglePermission(
                                                permission,
                                                checked === true,
                                            )
                                    "
                                />
                                <span class="font-mono text-xs">
                                    {{ permission }}
                                </span>
                            </Label>
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
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Create role' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="deleting !== null"
            @update:open="(open) => !open && (deleting = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                    <DialogDescription>
                        Only custom roles with no assigned users can be
                        deleted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="destroy">
                        Delete role
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
