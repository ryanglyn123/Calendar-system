<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { inject, ref } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { liquidNavKey } from '@/composables/useLiquidNav';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

// An item is also current on the pages under it (a request opened at
// /admin/bookings/25 is still Booking Approvals), unless another item lives
// under it: /admin stays exact so Admin Dashboard doesn't claim every page.
const isCurrent = (item: NavItem) => {
    const path = toUrl(item.href);
    const parent = props.items.some((other) =>
        toUrl(other.href).startsWith(`${path}/`),
    );

    return (
        isCurrentUrl(item.href) || (!parent && isCurrentOrParentUrl(`${path}/`))
    );
};

// The sidebar's liquid pill can light another item than the current page's.
const over = inject(liquidNavKey, ref(null));

const lit = (item: NavItem) =>
    over.value ? over.value === toUrl(item.href) : isCurrent(item);
</script>

<template>
    <!-- Orbit rail: each group is one capsule of round buttons that stretch
         into labelled pills when the sidebar is expanded. The geometry is the
         same in both states; the rail is the expanded layout narrowed, so the
         pills shrink to circles and the icons never move.
         The sidebar's liquid pill slides between the capsule and its items,
         so neither may form a stacking context of its own (no backdrop blur
         here), and the items sit above the pill. -->
    <SidebarGroup class="px-4 py-0">
        <SidebarGroupLabel class="sr-only">{{ label }}</SidebarGroupLabel>
        <SidebarMenu
            class="rounded-[29px] bg-white/60 p-1.5 shadow-[0_6px_20px_-10px_rgb(15_40_60/0.25)] inset-ring inset-ring-black/7 dark:bg-sidebar-foreground/4 dark:shadow-none dark:inset-ring-sidebar-foreground/10"
        >
            <SidebarMenuItem
                v-for="item in items"
                :key="item.title"
                class="z-[2]"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="lit(item)"
                    :tooltip="item.title"
                    class="group/orbit h-[46px] gap-3 py-0 pr-4 pl-1.5 font-medium text-sidebar-foreground/85 hover:bg-white/80 dark:text-sidebar-foreground/75 dark:hover:bg-sidebar-foreground/6"
                >
                    <!-- Hover prefetch: the page request starts before the
                         click, which hides most of the server round trip. -->
                    <Link
                        :href="item.href"
                        prefetch
                        cache-for="15s"
                        :aria-current="isCurrent(item) ? 'page' : undefined"
                    >
                        <span
                            class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-sidebar-foreground/8 group-data-[active=true]/orbit:bg-white/18 dark:bg-sidebar-foreground/6"
                        >
                            <component :is="item.icon" class="size-[18px]" />
                        </span>
                        <!-- Faded, not removed, in the rail: the link keeps
                             its accessible name. -->
                        <span data-sidebar-fade>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
