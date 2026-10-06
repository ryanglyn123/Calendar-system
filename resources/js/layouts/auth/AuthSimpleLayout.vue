<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AuthBackdrop from '@/components/AuthBackdrop.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

// Decorative sample day, laid out around the real current time so there is
// always something done, something live and something next.
const sample = [
    {
        offset: -120,
        minutes: 60,
        label: 'Script lab',
        place: 'Room 3',
        color: '#86C6F5',
    },
    {
        offset: -30,
        minutes: 90,
        label: 'Festival planning',
        place: 'Board room',
        color: '#F5A6D8',
    },
    {
        offset: 75,
        minutes: 60,
        label: 'Location scout',
        place: 'Intramuros',
        color: '#86DBAE',
    },
    {
        offset: 180,
        minutes: 45,
        label: 'Board meeting',
        place: 'Conference hall',
        color: '#EFD95F',
    },
];

// Null until mounted so SSR and the client never disagree about the time.
const now = ref<Date | null>(null);
let anchor = 0;
let timer: number | undefined;

onMounted(() => {
    const d = new Date();
    anchor =
        d.getTime() - (d.getMinutes() % 15) * 60_000 - d.getSeconds() * 1000;
    now.value = d;
    timer = window.setInterval(() => (now.value = new Date()), 1000);
});
onUnmounted(() => window.clearInterval(timer));

const hhmm = (t: number) =>
    new Date(t).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });

const day = computed(() =>
    now.value?.toLocaleDateString('en-PH', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }),
);

const schedule = computed(() => {
    if (!now.value) {
        return [];
    }

    const t = now.value.getTime();
    let nextFound = false;

    return sample.map((e) => {
        const start = anchor + e.offset * 60_000;
        const end = start + e.minutes * 60_000;
        const state = end <= t ? 'done' : start <= t ? 'live' : 'upcoming';
        const isNext = state === 'upcoming' && !nextFound;

        if (isNext) {
            nextFound = true;
        }

        return {
            ...e,
            time: hhmm(start),
            state,
            progress: state === 'live' ? (t - start) / (end - start) : 0,
            badge: isNext ? `in ${Math.ceil((start - t) / 60_000)} min` : null,
        };
    });
});

// The yellow glow follows the pointer like a projector beam.
function spotlight(e: PointerEvent) {
    const el = e.currentTarget as HTMLElement;
    const r = el.getBoundingClientRect();
    el.style.setProperty('--x', `${e.clientX - r.left}px`);
    el.style.setProperty('--y', `${e.clientY - r.top}px`);
}
</script>

<template>
    <div
        class="auth-backdrop flex min-h-svh flex-col items-center justify-center gap-6 p-4 md:p-10"
    >
        <AuthBackdrop />

        <div class="relative z-10 w-full max-w-[60rem]">
            <div
                class="auth-card-enter grid overflow-hidden rounded-3xl border bg-card shadow-sm md:grid-cols-[5fr_6fr]"
            >
                <!-- Brand panel: stays dark in both themes as the anchor. -->
                <aside
                    class="relative hidden flex-col justify-between gap-10 overflow-hidden bg-[oklch(0.2_0.01_90)] p-10 text-[oklch(0.96_0.004_95)] [--x:85%] [--y:90%] md:flex"
                    aria-hidden="true"
                    @pointermove="spotlight"
                >
                    <div
                        class="pointer-events-none absolute inset-0 bg-[radial-gradient(22rem_circle_at_var(--x)_var(--y),oklch(0.87_0.18_100/0.16),transparent_65%)]"
                    />

                    <div
                        class="auth-stagger relative flex items-center justify-between"
                        style="--i: 0"
                    >
                        <AppLogoIcon class="size-11 rounded-xl" />
                        <span
                            v-if="now"
                            class="text-sm font-medium text-[oklch(0.75_0.01_90)] tabular-nums"
                        >
                            {{ hhmm(now.getTime()) }}
                        </span>
                    </div>

                    <div class="auth-stagger relative space-y-3" style="--i: 1">
                        <p
                            class="min-h-5 text-sm font-medium text-[oklch(0.87_0.18_100)]"
                        >
                            {{ day }}
                        </p>
                        <h2
                            class="text-3xl leading-tight font-semibold tracking-tight text-balance"
                        >
                            Every screening, shoot and meeting in one place.
                        </h2>
                    </div>

                    <ul
                        class="auth-stagger relative min-h-[11.5rem] space-y-2 [&:has(li:hover)>li:not(:hover)]:opacity-50"
                        style="--i: 2"
                    >
                        <li
                            v-for="(event, i) in schedule"
                            :key="event.label"
                            class="group relative flex h-10 cursor-default items-center gap-3 overflow-hidden rounded-lg px-3 text-sm font-medium transition-[translate,opacity] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] hover:translate-x-2 motion-reduce:transition-none"
                            :class="
                                event.state === 'done'
                                    ? 'text-[oklch(0.8_0.01_90)]'
                                    : 'text-[oklch(0.2_0.01_90)]'
                            "
                            :style="{
                                background:
                                    event.state === 'done'
                                        ? `color-mix(in oklab, ${event.color} 22%, oklch(0.2 0.01 90))`
                                        : event.color,
                                marginLeft: `${(i % 2) * 1.5}rem`,
                            }"
                        >
                            <span class="w-11 shrink-0 tabular-nums opacity-70">
                                {{ event.time }}
                            </span>

                            <!-- Label swaps for the place on hover. -->
                            <span class="relative h-5 flex-1 overflow-hidden">
                                <span
                                    class="absolute inset-0 truncate transition-[translate,opacity] duration-300 group-hover:-translate-y-full group-hover:opacity-0"
                                >
                                    {{ event.label }}
                                </span>
                                <span
                                    class="absolute inset-0 translate-y-full truncate opacity-0 transition-[translate,opacity] duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                                >
                                    {{ event.place }} · {{ event.minutes }} min
                                </span>
                            </span>

                            <Check
                                v-if="event.state === 'done'"
                                class="size-4 shrink-0 opacity-70"
                            />
                            <span
                                v-else-if="event.state === 'live'"
                                class="flex shrink-0 items-center gap-1.5 text-xs font-semibold"
                            >
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-current"
                                />
                                Now
                            </span>
                            <span
                                v-else-if="event.badge"
                                class="shrink-0 text-xs opacity-70"
                            >
                                {{ event.badge }}
                            </span>

                            <span
                                v-if="event.state === 'live'"
                                class="absolute inset-x-0 bottom-0 h-0.5 origin-left bg-[oklch(0.2_0.01_90/0.35)]"
                                :style="{ scale: `${event.progress} 1` }"
                            />
                        </li>
                    </ul>
                </aside>

                <!-- Form column -->
                <div
                    class="flex flex-col gap-8 p-6 [--ring:oklch(0.62_0.14_80)] sm:p-10 md:p-12"
                >
                    <div class="flex flex-col gap-5">
                        <Link
                            :href="home()"
                            class="auth-stagger w-fit md:hidden"
                            style="--i: 0"
                            aria-label="FDCP Calendar home"
                        >
                            <AppLogoIcon class="size-12 rounded-xl" />
                        </Link>
                        <div class="auth-stagger space-y-1.5" style="--i: 1">
                            <h1
                                class="text-2xl font-semibold tracking-tight text-balance"
                            >
                                {{ title }}
                            </h1>
                            <p
                                v-if="description"
                                class="text-sm text-muted-foreground"
                            >
                                {{ description }}
                            </p>
                        </div>
                    </div>
                    <div class="auth-stagger" style="--i: 2">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
