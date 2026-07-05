<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useAuth } from '@/composables/useAuth';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { ADMIN_SETTINGS_SECTIONS } from '@/lib/adminSettingsNav';
import { toUrl } from '@/lib/utils';

const { hasPermission } = useAuth();
const { isCurrentOrParentUrl } = useCurrentUrl();

const sections = computed(() =>
    ADMIN_SETTINGS_SECTIONS.map((section) => ({
        ...section,
        links: section.links.filter((link) => hasPermission(link.permission)),
    })).filter((section) => section.links.length > 0),
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-1 flex-col lg:flex-row">
        <!-- Settings section nav: persistent so sections switch in one click -->
        <aside
            class="shrink-0 border-b px-3 py-3 lg:w-56 lg:overflow-y-auto lg:border-r lg:border-b-0 lg:py-5"
            aria-label="Settings sections"
        >
            <nav class="flex flex-row flex-wrap gap-1 lg:flex-col lg:gap-5">
                <div
                    v-for="section in sections"
                    :key="section.title"
                    class="contents lg:block lg:space-y-0.5"
                >
                    <p
                        class="hidden px-2 pb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase lg:block"
                    >
                        {{ section.title }}
                    </p>
                    <Link
                        v-for="link in section.links"
                        :key="toUrl(link.href)"
                        :href="link.href"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors"
                        :class="
                            isCurrentOrParentUrl(link.href)
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                        "
                    >
                        <component
                            :is="link.icon"
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ link.title }}
                    </Link>
                </div>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </div>
    </div>
</template>
