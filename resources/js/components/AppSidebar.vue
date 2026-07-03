<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    CalendarRange,
    CarFront,
    ClipboardCheck,
    FileDiff,
    LayoutGrid,
    MailPlus,
    Megaphone,
    Presentation,
    ScrollText,
    Settings2,
    ShieldCheck,
    Tags,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuth } from '@/composables/useAuth';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import adminAnnouncements from '@/routes/admin/announcements';
import adminAudit from '@/routes/admin/audit';
import adminBookings from '@/routes/admin/bookings';
import adminCalendars from '@/routes/admin/calendars';
import adminDivisions from '@/routes/admin/divisions';
import adminEditRequests from '@/routes/admin/edit-requests';
import adminEventTypes from '@/routes/admin/event-types';
import adminHealth from '@/routes/admin/health';
import adminHolidays from '@/routes/admin/holidays';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminResourceTypes from '@/routes/admin/resource-types';
import adminResources from '@/routes/admin/resources';
import adminRoles from '@/routes/admin/roles';
import adminSettings from '@/routes/admin/settings';
import adminUsers from '@/routes/admin/users';
import adminWorkingHours from '@/routes/admin/working-hours';
import bookingsPage from '@/routes/bookings';
import type { NavItem } from '@/types';

const { hasPermission, hasAnyPermission } = useAuth();

const mainNavItems: NavItem[] = [
    {
        title: 'Calendar',
        href: dashboard(),
        icon: CalendarDays,
    },
    {
        title: 'Bookings',
        href: bookingsPage.index(),
        icon: ClipboardCheck,
    },
];

const adminNavItems = computed<NavItem[]>(() => {
    if (!hasPermission('admin.access')) {
        return [];
    }

    const items: NavItem[] = [
        {
            title: 'Admin Dashboard',
            href: admin.dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (hasAnyPermission('users.manage', 'users.approve')) {
        items.push(
            {
                title: 'Users',
                href: adminUsers.index(),
                icon: Users,
            },
            {
                title: 'Pending Registrations',
                href: adminPending.index(),
                icon: UserCheck,
            },
        );
    }

    if (hasPermission('users.manage')) {
        items.push({
            title: 'Invitations',
            href: adminInvitations.index(),
            icon: MailPlus,
        });
    }

    if (hasPermission('divisions.manage')) {
        items.push({
            title: 'Divisions',
            href: adminDivisions.index(),
            icon: Building2,
        });
    }

    if (hasPermission('calendars.manage')) {
        items.push({
            title: 'Calendars',
            href: adminCalendars.index(),
            icon: CalendarRange,
        });
    }

    if (hasPermission('event-types.manage')) {
        items.push({
            title: 'Event Types',
            href: adminEventTypes.index(),
            icon: Tags,
        });
    }

    if (hasPermission('bookings.approve')) {
        items.push(
            {
                title: 'Booking Approvals',
                href: adminBookings.index(),
                icon: Presentation,
            },
            {
                title: 'Change Requests',
                href: adminEditRequests.index(),
                icon: FileDiff,
            },
        );
    }

    if (hasPermission('resource-types.manage')) {
        items.push({
            title: 'Resource Types',
            href: adminResourceTypes.index(),
            icon: CarFront,
        });
    }

    if (hasPermission('resources.manage')) {
        items.push({
            title: 'Resources',
            href: adminResources.index(),
            icon: Presentation,
        });
    }

    if (hasPermission('holidays.manage')) {
        items.push({
            title: 'Holidays',
            href: adminHolidays.index(),
            icon: CalendarOff,
        });
    }

    if (hasPermission('working-hours.manage')) {
        items.push({
            title: 'Working Hours',
            href: adminWorkingHours.index(),
            icon: CalendarClock,
        });
    }

    if (hasPermission('roles.manage')) {
        items.push({
            title: 'Roles & Permissions',
            href: adminRoles.index(),
            icon: ShieldCheck,
        });
    }

    if (hasPermission('announcements.manage')) {
        items.push({
            title: 'Announcements',
            href: adminAnnouncements.index(),
            icon: Megaphone,
        });
    }

    if (hasPermission('audit.view')) {
        items.push({
            title: 'Audit Log',
            href: adminAudit.index(),
            icon: ScrollText,
        });
    }

    if (hasPermission('settings.manage')) {
        items.push(
            {
                title: 'System Health',
                href: adminHealth.index(),
                icon: Activity,
            },
            {
                title: 'Settings',
                href: adminSettings.edit(),
                icon: Settings2,
            },
        );
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length > 0"
                :items="adminNavItems"
                label="Administration"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
