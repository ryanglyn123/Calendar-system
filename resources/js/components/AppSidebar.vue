<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    CalendarRange,
    LayoutGrid,
    MailPlus,
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
import adminCalendars from '@/routes/admin/calendars';
import adminDivisions from '@/routes/admin/divisions';
import adminEventTypes from '@/routes/admin/event-types';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminRoles from '@/routes/admin/roles';
import adminUsers from '@/routes/admin/users';
import type { NavItem } from '@/types';

const { hasPermission, hasAnyPermission } = useAuth();

const mainNavItems: NavItem[] = [
    {
        title: 'Calendar',
        href: dashboard(),
        icon: CalendarDays,
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

    if (hasPermission('roles.manage')) {
        items.push({
            title: 'Roles & Permissions',
            href: adminRoles.index(),
            icon: ShieldCheck,
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
