<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Head, Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Activity,
    Building2,
    CalendarClock,
    CalendarOff,
    CalendarRange,
    CarFront,
    Presentation,
    ScrollText,
    Settings2,
    ShieldCheck,
    Tags,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useAuth } from '@/composables/useAuth';
import admin from '@/routes/admin';
import adminAudit from '@/routes/admin/audit';
import adminCalendars from '@/routes/admin/calendars';
import adminDivisions from '@/routes/admin/divisions';
import adminEventTypes from '@/routes/admin/event-types';
import adminHealth from '@/routes/admin/health';
import adminHolidays from '@/routes/admin/holidays';
import adminResourceTypes from '@/routes/admin/resource-types';
import adminResources from '@/routes/admin/resources';
import adminRoles from '@/routes/admin/roles';
import adminSettings from '@/routes/admin/settings';
import adminWorkingHours from '@/routes/admin/working-hours';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Settings', href: adminSettings.index() },
        ],
    },
});

type SettingsLink = {
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    permission: string;
};

type SettingsSection = {
    title: string;
    links: SettingsLink[];
};

const { hasPermission } = useAuth();

const sections = computed<SettingsSection[]>(() =>
    [
        {
            title: 'Scheduling',
            links: [
                {
                    title: 'Calendars',
                    description: 'Managed calendars and their divisions.',
                    href: adminCalendars.index(),
                    icon: CalendarRange,
                    permission: 'calendars.manage',
                },
                {
                    title: 'Event Types',
                    description: 'Categories and colors for events.',
                    href: adminEventTypes.index(),
                    icon: Tags,
                    permission: 'event-types.manage',
                },
                {
                    title: 'Holidays',
                    description: 'Non-working days that block bookings.',
                    href: adminHolidays.index(),
                    icon: CalendarOff,
                    permission: 'holidays.manage',
                },
                {
                    title: 'Working Hours',
                    description: 'Bookable hours for each weekday.',
                    href: adminWorkingHours.index(),
                    icon: CalendarClock,
                    permission: 'working-hours.manage',
                },
            ],
        },
        {
            title: 'Resources',
            links: [
                {
                    title: 'Resource Types',
                    description: 'Categories of bookable resources.',
                    href: adminResourceTypes.index(),
                    icon: CarFront,
                    permission: 'resource-types.manage',
                },
                {
                    title: 'Resources',
                    description: 'Rooms, vehicles, and equipment.',
                    href: adminResources.index(),
                    icon: Presentation,
                    permission: 'resources.manage',
                },
            ],
        },
        {
            title: 'Organization & Access',
            links: [
                {
                    title: 'Divisions',
                    description: 'Departments and office units.',
                    href: adminDivisions.index(),
                    icon: Building2,
                    permission: 'divisions.manage',
                },
                {
                    title: 'Roles & Permissions',
                    description: 'What each role is allowed to do.',
                    href: adminRoles.index(),
                    icon: ShieldCheck,
                    permission: 'roles.manage',
                },
            ],
        },
        {
            title: 'System',
            links: [
                {
                    title: 'General',
                    description: 'Organization details and registration.',
                    href: adminSettings.edit(),
                    icon: Settings2,
                    permission: 'settings.manage',
                },
                {
                    title: 'System Health',
                    description: 'Queues, mail, and background jobs.',
                    href: adminHealth.index(),
                    icon: Activity,
                    permission: 'settings.manage',
                },
                {
                    title: 'Audit Log',
                    description: 'Who changed what, and when.',
                    href: adminAudit.index(),
                    icon: ScrollText,
                    permission: 'audit.view',
                },
            ],
        },
    ]
        .map((section) => ({
            ...section,
            links: section.links.filter((link) =>
                hasPermission(link.permission),
            ),
        }))
        .filter((section) => section.links.length > 0),
);
</script>

<template>
    <Head title="Settings" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-lg font-semibold">Settings</h1>
            <p class="text-sm text-muted-foreground">
                Configuration for scheduling, resources, access, and the system.
            </p>
        </div>

        <section
            v-for="section in sections"
            :key="section.title"
            class="flex flex-col gap-3"
        >
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ section.title }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="link in section.links"
                    :key="link.title"
                    :href="link.href"
                    class="group rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Card
                        class="h-full transition-colors group-hover:bg-accent/50"
                    >
                        <CardHeader class="flex flex-row items-center gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background"
                            >
                                <component
                                    :is="link.icon"
                                    class="size-4 text-muted-foreground"
                                />
                            </div>
                            <div class="grid gap-1">
                                <CardTitle class="text-sm">
                                    {{ link.title }}
                                </CardTitle>
                                <CardDescription class="text-xs">
                                    {{ link.description }}
                                </CardDescription>
                            </div>
                        </CardHeader>
                    </Card>
                </Link>
            </div>
        </section>
    </div>
</template>
