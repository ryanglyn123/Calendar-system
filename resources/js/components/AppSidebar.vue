<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    ClipboardCheck,
    FileDiff,
    LayoutGrid,
    MailPlus,
    Megaphone,
    Presentation,
    Settings2,
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
import adminBookings from '@/routes/admin/bookings';
import adminEditRequests from '@/routes/admin/edit-requests';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminSettings from '@/routes/admin/settings';
import adminUsers from '@/routes/admin/users';
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

    if (hasPermission('announcements.manage')) {
        items.push({
            title: 'Announcements',
            href: adminAnnouncements.index(),
            icon: Megaphone,
        });
    }

    if (
        hasAnyPermission(
            'divisions.manage',
            'calendars.manage',
            'event-types.manage',
            'resource-types.manage',
            'resources.manage',
            'holidays.manage',
            'working-hours.manage',
            'roles.manage',
            'audit.view',
            'settings.manage',
        )
    ) {
        items.push({
            title: 'Settings',
            href: adminSettings.index(),
            icon: Settings2,
        });
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
