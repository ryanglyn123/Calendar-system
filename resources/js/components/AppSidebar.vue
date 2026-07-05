<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    FileDiff,
    Globe,
    LayoutGrid,
    MailPlus,
    Megaphone,
    MessagesSquare,
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
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuth } from '@/composables/useAuth';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import adminAnnouncements from '@/routes/admin/announcements';
import adminBookings from '@/routes/admin/bookings';
import adminEditRequests from '@/routes/admin/edit-requests';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminSettings from '@/routes/admin/settings';
import adminUsers from '@/routes/admin/users';
import calendarPage from '@/routes/calendar';
import chatPage from '@/routes/chat';
import resourcesPage from '@/routes/resources';
import { useChatStore } from '@/stores/chat';
import type { NavItem, ResourceTypeNav } from '@/types';

const { user, hasPermission, hasAnyPermission } = useAuth();

const page = usePage();
const chat = useChatStore();

// One sidebar section per active resource type (Boardroom, Carpool, ...).
const resourceTypes = computed(
    () => (page.props.resourceTypes as ResourceTypeNav[] | undefined) ?? [],
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'My Calendar',
        href: dashboard(),
        icon: CalendarDays,
    },
    {
        title: 'Public Calendar',
        href: calendarPage.public(),
        icon: Globe,
    },
    ...(user.value?.division
        ? [
              {
                  title: 'Division Calendar',
                  href: calendarPage.division(),
                  icon: Building2,
              } satisfies NavItem,
          ]
        : []),
    {
        title: 'Chats',
        href: chatPage.index(),
        icon: MessagesSquare,
        badge: chat.totalUnread,
    },
    ...resourceTypes.value.map(
        (type): NavItem => ({
            title: type.name,
            href: resourcesPage.section(type.slug),
            icon: resourceTypeIcon(type),
        }),
    ),
]);

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
    <Sidebar collapsible="icon" variant="sidebar" class="!border-r-0">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <Link
                        :href="dashboard()"
                        class="flex h-12 items-center px-1 group-data-[collapsible=icon]:h-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
                    >
                        <AppLogo />
                    </Link>
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
