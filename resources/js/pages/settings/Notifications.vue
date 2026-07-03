<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { update as notificationsUpdate } from '@/routes/notifications';

type PreferenceRow = {
    key: string;
    label: string;
    mail_enabled: boolean;
    database_enabled: boolean;
};

const props = defineProps<{
    groups: Record<string, PreferenceRow[]>;
}>();

const form = useForm({
    preferences: Object.values(props.groups)
        .flat()
        .map((row) => ({ ...row })),
});

const rowFor = (key: string) =>
    form.preferences.find((row) => row.key === key)!;

const submit = () => {
    form.put(notificationsUpdate().url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Notification settings" />

    <div class="space-y-6">
        <header class="space-y-0.5">
            <h2 class="text-lg font-medium">Notifications</h2>
            <p class="text-sm text-muted-foreground">
                Choose how each kind of update reaches you.
            </p>
        </header>

        <div class="space-y-6">
            <section
                v-for="(rows, groupName) in groups"
                :key="groupName"
                class="space-y-1"
            >
                <h3 class="pb-1 text-sm font-semibold">{{ groupName }}</h3>

                <div class="overflow-hidden rounded-lg border">
                    <div
                        class="grid grid-cols-[1fr_4rem_4rem] gap-2 border-b bg-muted/40 px-3 py-1.5 text-xs font-medium text-muted-foreground"
                    >
                        <span>Notification</span>
                        <span class="text-center">Email</span>
                        <span class="text-center">In-app</span>
                    </div>

                    <div
                        v-for="row in rows"
                        :key="row.key"
                        class="grid grid-cols-[1fr_4rem_4rem] items-center gap-2 border-b px-3 py-2 last:border-b-0"
                    >
                        <Label class="font-normal">{{ row.label }}</Label>
                        <span class="flex justify-center">
                            <Switch
                                v-model="rowFor(row.key).mail_enabled"
                                :aria-label="`Email for: ${row.label}`"
                            />
                        </span>
                        <span class="flex justify-center">
                            <Switch
                                v-model="rowFor(row.key).database_enabled"
                                :aria-label="`In-app for: ${row.label}`"
                            />
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <Button :disabled="form.processing" @click="submit">
            Save preferences
        </Button>
    </div>
</template>
