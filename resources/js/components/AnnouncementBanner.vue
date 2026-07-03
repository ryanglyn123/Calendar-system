<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Info, Megaphone, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { dismiss } from '@/routes/api/announcements';

type Announcement = {
    id: number;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'critical';
    is_dismissible: boolean;
};

const page = usePage();

const locallyDismissed = ref<number[]>([]);

const announcements = computed(() =>
    ((page.props.announcements as Announcement[] | undefined) ?? []).filter(
        (a) => !locallyDismissed.value.includes(a.id),
    ),
);

const dismissAnnouncement = async (announcement: Announcement) => {
    locallyDismissed.value.push(announcement.id);

    await api.post(dismiss(announcement.id).url);

    router.reload({ only: ['announcements'] });
};

const styles: Record<Announcement['level'], string> = {
    info: 'border-fdcp-blue/30 bg-fdcp-blue/10 text-foreground',
    warning: 'border-warning/40 bg-warning/10 text-foreground',
    critical: 'border-destructive/40 bg-destructive/10 text-foreground',
};

const icons = {
    info: Info,
    warning: Megaphone,
    critical: AlertTriangle,
};
</script>

<template>
    <div v-if="announcements.length > 0" class="space-y-1.5 px-4 pt-3">
        <div
            v-for="announcement in announcements"
            :key="announcement.id"
            class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm"
            :class="styles[announcement.level]"
            role="status"
        >
            <component
                :is="icons[announcement.level]"
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <span class="font-medium">{{ announcement.title }}</span>
                <span class="text-muted-foreground">
                    · {{ announcement.body }}
                </span>
            </div>
            <Button
                v-if="announcement.is_dismissible"
                variant="ghost"
                size="icon"
                class="-my-1 size-6 shrink-0"
                :aria-label="`Dismiss ${announcement.title}`"
                @click="dismissAnnouncement(announcement)"
            >
                <X class="size-3.5" aria-hidden="true" />
            </Button>
        </div>
    </div>
</template>
