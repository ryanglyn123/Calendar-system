<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

const props = defineProps<{
    status: number;
}>();

// Rendered without the app layout: the error may come from a route that
// never reached the middleware that shares auth, so nothing here may rely
// on page props beyond `status`.
const copy = computed(() => {
    switch (props.status) {
        case 403:
            return {
                title: 'Access denied',
                body: 'Your account is not allowed to open this page. If you think it should be, ask an administrator to check your role.',
            };
        case 404:
            return {
                title: 'Page not found',
                body: 'The link may be out of date, or the item was removed. The calendar is still where you left it.',
            };
        case 503:
            return {
                title: 'Down for maintenance',
                body: 'The calendar is being updated. Please try again in a few minutes.',
            };
        default:
            return {
                title: 'Something went wrong',
                body: 'An unexpected error stopped this request. It has been logged; please try again, and contact MIS if it keeps happening.',
            };
    }
});

const goBack = () => window.history.back();
</script>

<template>
    <Head :title="copy.title" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-8 bg-background px-6 py-12 text-foreground"
    >
        <AppLogoIcon class="size-12 rounded-xl" aria-hidden="true" />

        <div class="max-w-md space-y-3 text-center">
            <p class="text-sm font-medium text-muted-foreground tabular-nums">
                Error {{ status }}
            </p>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ copy.title }}
            </h1>
            <p class="text-sm leading-relaxed text-muted-foreground">
                {{ copy.body }}
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-center gap-3">
            <Button variant="outline" @click="goBack">
                <ArrowLeft aria-hidden="true" />
                Go back
            </Button>
            <Button as-child>
                <Link :href="dashboard()">
                    <CalendarDays aria-hidden="true" />
                    Back to the calendar
                </Link>
            </Button>
        </div>
    </main>
</template>
