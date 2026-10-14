<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isMobile, state } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <!-- Orbit rail account capsule: ringed round avatar; just
                         the avatar when collapsed. 9px padding makes the
                         capsule 58px tall, so in the 58px-wide rail it is a
                         circle with the avatar centred on the icon axis. -->
                    <SidebarMenuButton
                        size="lg"
                        class="h-auto bg-white/60 p-[9px] pr-3 shadow-[0_6px_20px_-10px_rgb(15_40_60/0.25)] inset-ring inset-ring-black/7 backdrop-blur-md data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground dark:bg-sidebar-foreground/4 dark:shadow-none dark:inset-ring-sidebar-foreground/10 [&_[data-sidebar-fade]]:text-[13px] [&_[data-sidebar-fade]>span]:line-clamp-2 [&_[data-sidebar-fade]>span]:whitespace-normal [&_[data-slot=avatar-fallback]]:rounded-full [&_[data-slot=avatar]]:size-10 [&_[data-slot=avatar]]:rounded-full [&_[data-slot=avatar]]:ring-2 [&_[data-slot=avatar]]:ring-sidebar-primary"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" />
                        <ChevronsUpDown
                            class="ml-auto size-4"
                            data-sidebar-fade
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
