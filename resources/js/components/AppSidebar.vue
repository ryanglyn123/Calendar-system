<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    FileDiff,
    Globe,
    LayoutGrid,
    Megaphone,
    Presentation,
    Settings2,
} from '@lucide/vue';
import { computed, provide, useTemplateRef } from 'vue';
import logoOnly from '@/../img/Logoonly.png';
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
import { liquidNavKey, useLiquidNav } from '@/composables/useLiquidNav';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import adminAnnouncements from '@/routes/admin/announcements';
import adminBookings from '@/routes/admin/bookings';
import adminEditRequests from '@/routes/admin/edit-requests';
import adminSettings from '@/routes/admin/settings';
import calendarPage from '@/routes/calendar';
import resourcesPage from '@/routes/resources';
import type { NavItem, ResourceTypeNav } from '@/types';

const { user, hasPermission, hasAnyPermission } = useAuth();

const page = usePage();

const { over } = useLiquidNav(useTemplateRef('blob'));

provide(liquidNavKey, over);

// One sidebar section per active resource type (Boardroom, Carpool, ...).
const resourceTypes = computed(
    () => (page.props.resourceSections as ResourceTypeNav[] | undefined) ?? [],
);

const calendarNavItems = computed<NavItem[]>(() => [
    {
        title: 'My Calendar',
        href: dashboard(),
        icon: CalendarDays,
    },
    {
        title: `${page.props.organization.acronym} Events`,
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
]);

const resourceNavItems = computed<NavItem[]>(() =>
    resourceTypes.value.map((type): NavItem => ({
        title: type.name,
        href: resourcesPage.section(type.slug),
        icon: resourceTypeIcon(type),
    })),
);

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
            'users.manage',
            'users.approve',
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
        <SidebarHeader class="px-4 pt-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <!-- One reel mark for both states: it glides between the
                         rail and the wordmark (app.css) while only the words
                         fade. The wordmark's own square is clipped off so
                         two never show. The link's clip keeps the faded
                         wordmark from catching clicks past the narrow rail. -->
                    <Link
                        :href="dashboard()"
                        class="relative flex h-12 items-center overflow-hidden px-1"
                    >
                        <AppLogo
                            data-sidebar-fade
                            class="[clip-path:inset(0_0_0_29px)]"
                        />
                        <!-- 9px in from the link = centred on the rail's
                             45px icon axis. -->
                        <img
                            :src="logoOnly"
                            alt=""
                            data-sidebar-mark
                            class="pointer-events-none absolute inset-y-0 left-[9px] my-auto size-10 object-contain"
                        />
                    </Link>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="relative isolate gap-4 pt-2">
            <!-- The liquid pill: one for every group, under the labels and
                 over the capsules (useLiquidNav). -->
            <span
                ref="blob"
                data-liquid-blob
                class="pointer-events-none absolute top-0 left-0 z-[1] rounded-full will-change-transform"
                aria-hidden="true"
            />
            <NavMain :items="calendarNavItems" label="Calendars" />
            <NavMain
                v-if="resourceNavItems.length > 0"
                :items="resourceNavItems"
                label="Resources"
            />
            <NavMain
                v-if="adminNavItems.length > 0"
                :items="adminNavItems"
                label="Administration"
            />
        </SidebarContent>

        <SidebarFooter class="px-4 pb-4">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
