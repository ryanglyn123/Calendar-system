<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();

const isDark = computed(() => resolvedAppearance.value === 'dark');

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
        class="dm-track inline-flex h-8 w-16 shrink-0 items-center overflow-hidden rounded-full border px-1 backdrop-blur-md transition-colors duration-500 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        :class="
            isDark
                ? 'border-white/10 bg-slate-800/50'
                : 'border-white/40 bg-sky-200/50'
        "
        @click="toggle"
    >
        <span
            class="dm-thumb flex size-6 items-center justify-center"
            :class="isDark ? 'dm-thumb-dark' : 'dm-thumb-light'"
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
.dm-thumb {
    transition: transform 0.3s cubic-bezier(0.34, 1.15, 0.64, 1);
}

.dm-thumb-light {
    transform: translateX(0);
}

.dm-thumb-dark {
    transform: translateX(30px);
}

.dm-inner {
    box-shadow:
        inset 0 1px 1px rgba(255, 255, 255, 0.9),
        0 2px 5px rgba(0, 0, 0, 0.2);
    transition: box-shadow 0.3s ease;
    transform-origin: center;
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
