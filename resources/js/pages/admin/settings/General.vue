<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import admin from '@/routes/admin';
import adminSettings from '@/routes/admin/settings';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Settings', href: adminSettings.index() },
            { title: 'General', href: adminSettings.edit() },
        ],
    },
});

const props = defineProps<{
    general: {
        organization_name: string;
        organization_acronym: string;
        timezone: string;
        week_starts_on: number;
    };
    registration: {
        self_registration_enabled: boolean;
        allowed_email_domain: string | null;
        require_approval: boolean;
    };
}>();

const form = useForm({
    general: { ...props.general },
    registration: { ...props.registration },
});

const submit = () => {
    form.put(adminSettings.update().url, { preserveScroll: true });
};
</script>

<template>
    <Head title="General Settings" />

    <div class="flex max-w-2xl flex-col gap-4 p-4">
        <Card>
            <CardHeader>
                <CardTitle>Organization</CardTitle>
                <CardDescription>
                    Naming and calendar defaults used across the app.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="grid grid-cols-[1fr_8rem] gap-3">
                    <div class="grid gap-2">
                        <Label for="org-name">Organization name</Label>
                        <Input
                            id="org-name"
                            v-model="form.general.organization_name"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="org-acronym">Acronym</Label>
                        <Input
                            id="org-acronym"
                            v-model="form.general.organization_acronym"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="org-timezone">Timezone</Label>
                        <Input
                            id="org-timezone"
                            v-model="form.general.timezone"
                        />
                        <p
                            v-if="form.errors['general.timezone']"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors['general.timezone'] }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="org-week-start">Week starts on</Label>
                        <Select
                            :model-value="String(form.general.week_starts_on)"
                            @update:model-value="
                                (v) => (form.general.week_starts_on = Number(v))
                            "
                        >
                            <SelectTrigger id="org-week-start">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="1">Monday</SelectItem>
                                <SelectItem value="0">Sunday</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Registration</CardTitle>
                <CardDescription>
                    Who can sign up, and what happens when they do.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <Label class="flex items-center gap-3 font-normal">
                    <Switch
                        v-model="form.registration.self_registration_enabled"
                    />
                    Allow self-registration
                </Label>
                <Label class="flex items-center gap-3 font-normal">
                    <Switch v-model="form.registration.require_approval" />
                    New accounts need admin approval
                </Label>
                <div class="grid gap-2">
                    <Label for="reg-domain">
                        Limit sign-ups to email domain
                    </Label>
                    <Input
                        id="reg-domain"
                        :model-value="
                            form.registration.allowed_email_domain ?? ''
                        "
                        placeholder="e.g. fdcp.gov.ph (empty = any)"
                        @update:model-value="
                            (v) =>
                                (form.registration.allowed_email_domain =
                                    v === '' ? null : String(v))
                        "
                    />
                </div>
            </CardContent>
        </Card>

        <div>
            <Button :disabled="form.processing" @click="submit">
                Save settings
            </Button>
        </div>
    </div>
</template>
