<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { MailPlus, RefreshCw, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

defineProps<{
    invitations: Paginated<Invitation>;
    divisions: { data: Division[] };
    roles: { data: Role[] };
}>();

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

const revoke = (invitation: Invitation) =>
    router.delete(adminInvitations.destroy(invitation.id).url, {
        preserveScroll: true,
    });

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»').replace('&hellip;', '…');
</script>

<template>
    <Head title="Invitations" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Invited users skip approval: they pick a password and land
                straight in the calendar.
            </p>
            <Button @click="creating = true">
                <MailPlus aria-hidden="true" />
                Invite user
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Email</TableHead>
                        <TableHead>Division</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Invited by</TableHead>
                        <TableHead>Expires</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty
                        v-if="invitations.data.length === 0"
                        :colspan="6"
                    >
                        No open invitations.
                    </TableEmpty>
                    <TableRow
                        v-for="invitation in invitations.data"
                        :key="invitation.id"
                    >
                        <TableCell class="font-medium">
                            {{ invitation.email }}
                        </TableCell>
                        <TableCell>
                            {{ invitation.division?.name ?? '-' }}
                        </TableCell>
                        <TableCell>
                            <Badge variant="outline">
                                {{ invitation.role ?? 'staff' }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ invitation.inviter }}</TableCell>
                        <TableCell>
                            <Badge
                                v-if="invitation.is_expired"
                                variant="destructive"
                            >
                                Expired
                            </Badge>
                            <span v-else class="text-muted-foreground">
                                {{
                                    new Date(
                                        invitation.expires_at,
                                    ).toLocaleDateString()
                                }}
                            </span>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Resend invitation to ${invitation.email}`"
                                    @click="resend(invitation)"
                                >
                                    <RefreshCw aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Revoke invitation for ${invitation.email}`"
                                    @click="revoke(invitation)"
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
            v-if="invitations.meta.last_page > 1"
            class="flex items-center justify-center gap-1"
        >
            <template v-for="(link, i) in invitations.links" :key="i">
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
                                    v-for="role in roles.data"
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
