<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();
const page = usePage();

// The server shares the appearance cookie, so for an explicit light/dark choice
// the very first render (SSR + the matching client render) is already correct —
// no snap on refresh. 'system' is unknown server-side and resolves on mount.
const initialDark = (page.props.appearance as string | undefined) === 'dark';

// The resolved appearance depends on matchMedia and localStorage, which the
// server cannot know. Until mounted, render the same light state the server
// rendered; a mismatched first render corrupts hydration of the icon subtree
// (the icon mounts into a detached node and stays invisible).
const mounted = ref(false);

// Transitions stay off until a frame after mount: the first render is always
// light, so enabling them immediately would animate (slide + colour-fade) the
// correction to the real theme on every refresh. Only user flips animate.
const animate = ref(false);

onMounted(() => {
    mounted.value = true;
    requestAnimationFrame(() => {
        animate.value = true;
    });
});

const isDark = computed(() =>
    mounted.value ? resolvedAppearance.value === 'dark' : initialDark,
);

const squashing = ref(false);
let squashTimer: ReturnType<typeof setTimeout> | undefined;

const toggle = () => {
    updateAppearance(isDark.value ? 'light' : 'dark');

    squashing.value = false;
    // Restart the gooey animation on every flip.
    requestAnimationFrame(() => {
        squashing.value = true;
    });

    clearTimeout(squashTimer);
    squashTimer = setTimeout(() => {
        squashing.value = false;
    }, 300);
};

onBeforeUnmount(() => clearTimeout(squashTimer));
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="isDark"
        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        data-test="dark-mode-toggle"
        class="dm-track inline-flex h-8 w-16 shrink-0 items-center overflow-hidden rounded-full border px-1 backdrop-blur-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        :class="[
            animate ? 'transition-colors duration-500' : '',
            isDark
                ? 'border-white/10 bg-slate-800/50'
                : 'border-white/40 bg-sky-200/50',
        ]"
        @click="toggle"
    >
        <span
            class="dm-thumb flex size-6 items-center justify-center"
            :class="animate ? 'dm-animate' : ''"
            :style="{ transform: isDark ? 'translateX(30px)' : 'translateX(0)' }"
        >
            <span
                class="dm-inner flex size-full items-center justify-center rounded-full border border-white/60 bg-gradient-to-br from-white to-white/75"
                :class="[
                    { 'dm-slime': squashing },
                    isDark ? 'dm-glow-dark' : 'dm-glow-light',
                ]"
            >
                <Sun
                    v-if="!isDark"
                    class="dm-icon size-[15px] text-amber-500"
                    :stroke-width="2.5"
                    aria-hidden="true"
                />
                <Moon
                    v-else
                    class="dm-icon size-[15px] text-blue-500"
                    :stroke-width="2.5"
                    aria-hidden="true"
                />
            </span>
        </span>
    </button>
</template>

<style scoped>
/*
 * The thumb is a flex child (vertically centered by the track), so it only
 * ever moves horizontally via translateX. Track inner width is 64 - 2 border
 * - 8 padding = 54px; thumb is 24px, so travel is 30px (dark edge lands at
 * the inner right edge). overflow-hidden on the track is the hard guarantee
 * nothing can escape the bounds.
 */
/* The thumb transform is inlined (see :style) so SSR paints the right position
   with no dependency on scoped-CSS load order. Motion is opt-in: .dm-animate is
   added a frame after mount, so the initial correction never animates. */
.dm-animate {
    transition: transform 0.3s cubic-bezier(0.34, 1.15, 0.64, 1);
}

.dm-inner {
    box-shadow:
        inset 0 1px 1px rgba(255, 255, 255, 0.9),
        0 2px 5px rgba(0, 0, 0, 0.2);
    transform-origin: center;
}

.dm-animate .dm-inner {
    transition: box-shadow 0.3s ease;
}

/* Colored glass glow that tints the thumb by mode. */
.dm-glow-light {
    box-shadow:
        inset 0 1px 1px rgba(255, 255, 255, 0.9),
        0 0 8px rgba(251, 191, 36, 0.55),
        0 2px 5px rgba(0, 0, 0, 0.18);
}

.dm-glow-dark {
    box-shadow:
        inset 0 1px 1px rgba(255, 255, 255, 0.9),
        0 0 8px rgba(59, 130, 246, 0.6),
        0 2px 5px rgba(0, 0, 0, 0.35);
}

/* Gooey squash-and-stretch: horizontal launch, vertical settle. */
.dm-slime {
    animation: dm-slime 0.3s cubic-bezier(0.34, 1.15, 0.64, 1);
}

@keyframes dm-slime {
    0% {
        transform: scale(1, 1);
    }
    35% {
        transform: scale(1.18, 0.85);
    }
    65% {
        transform: scale(0.93, 1.07);
    }
    100% {
        transform: scale(1, 1);
    }
}

/* Icon stays visible the whole time; it only pops in scale + rotation. */
.dm-icon {
    animation: dm-pop 0.3s cubic-bezier(0.34, 1.15, 0.64, 1);
}

@keyframes dm-pop {
    0% {
        transform: scale(0.6) rotate(-90deg);
    }
    60% {
        transform: scale(1.15) rotate(8deg);
    }
    100% {
        transform: scale(1) rotate(0deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .dm-thumb,
    .dm-inner {
        transition: none;
    }

    .dm-slime,
    .dm-icon {
        animation: none;
    }
}
</style>
