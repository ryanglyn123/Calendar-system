<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { spring } from '@/lib/spring';

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

// Motion stays off until a frame after mount: the first render is always
// light, so animating immediately would slide the correction to the real
// theme on every refresh. Only user flips animate.
const animate = ref(false);

const isDark = computed(() =>
    mounted.value ? resolvedAppearance.value === 'dark' : initialDark,
);

/* ---- Liquid thumb ------------------------------------------------------ */
// The thumb moves like liquid: it stretches along its slide, wobbles as it
// lands and swells while held. Press and drag it either way; let go past
// the middle and the theme follows. A plain tap or Space still flips it.

const thumb = ref<HTMLElement | null>(null);
const inner = ref<HTMLElement | null>(null);

/** Track inner width 54px (64 - 2 border - 8 padding) less the 24px thumb. */
const TRAVEL = 30;

/** The most the thumb slides past either end while dragged, in px. */
const SLIP = 6;

/** The side the thumb is dragged to, shown before the theme follows. */
const preview = ref<boolean | null>(null);
const showDark = computed(() => preview.value ?? isDark.value);

// Position, stretch and press, each a spring with its velocity. Plain
// object: it changes every frame and nothing renders from it.
const s = { x: 0, vx: 0, tx: 0, st: 0, vst: 0, p: 0, vp: 0 };

let press: {
    pointer: number;
    startX: number;
    from: number;
    dragging: boolean;
} | null = null;

// The click that follows a drag lands on the switch and must not flip it.
let justDragged = false;
let raf = 0;
let last = 0;

const aim = () => (s.tx = isDark.value ? TRAVEL : 0);

const rest = () =>
    Object.assign(s, { x: s.tx, vx: 0, st: 0, vst: 0, p: 0, vp: 0 });

const paint = () => {
    if (!thumb.value || !inner.value) {
        return;
    }

    // Held, it widens into a pill; sliding fast stretches and thins it.
    thumb.value.style.transform = `translateX(${s.x}px)`;
    inner.value.style.transform = `scale(${1 + s.st + 0.25 * s.p}, ${1 - 0.45 * s.st + 0.1 * s.p})`;
};

const frame = (now: number) => {
    const dt = Math.max(0, Math.min((now - last) / 1000, 1 / 30));

    last = now;

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        rest();
        paint();
        raf = press ? requestAnimationFrame(frame) : 0;

        return;
    }

    // Tighter under the pointer, looser when it slides on its own.
    const [k, c] = press?.dragging ? [900, 48] : [380, 24];

    [s.x, s.vx] = spring(s.x, s.vx, s.tx, k, c, dt);
    // Underdamped, so the stretch overshoots into a squash as it brakes.
    [s.st, s.vst] = spring(
        s.st,
        s.vst,
        Math.min(Math.abs(s.vx) / 1500, 0.3),
        520,
        14,
        dt,
    );
    [s.p, s.vp] = spring(s.p, s.vp, press ? 1 : 0, 520, 22, dt);

    const settled =
        !press &&
        Math.abs(s.tx - s.x) < 0.2 &&
        Math.abs(s.vx) < 2 &&
        Math.abs(s.st) + Math.abs(s.p) < 0.002 &&
        Math.abs(s.vst) + Math.abs(s.vp) < 0.02;

    if (settled) {
        rest();
    }

    paint();
    raf = settled ? 0 : requestAnimationFrame(frame);
};

const kick = () => {
    if (!raf) {
        last = performance.now();
        raf = requestAnimationFrame(frame);
    }
};

onMounted(() => {
    mounted.value = true;
    // Script owns the transform from here; it was inlined only for SSR.
    nextTick(() => {
        aim();
        rest();
        paint();
    });
    requestAnimationFrame(() => {
        animate.value = true;
    });
});

// Flips from anywhere (this switch, the appearance settings) slide it,
// except the first correction to the real theme.
watch(isDark, () => {
    aim();

    if (animate.value) {
        kick();
    } else {
        rest();
        paint();
    }
});

const toggle = () => {
    if (justDragged) {
        justDragged = false;

        return;
    }

    updateAppearance(isDark.value ? 'light' : 'dark');
};

const onDown = (e: PointerEvent) => {
    justDragged = false;

    if (press || e.button !== 0) {
        return;
    }

    press = {
        pointer: e.pointerId,
        startX: e.clientX,
        // From where the thumb is headed, not where it is mid-slide: a quick
        // second tap that slips would otherwise pick a side by accident.
        from: s.tx,
        dragging: false,
    };
    kick();
};

const onMove = (e: PointerEvent) => {
    if (!press || e.pointerId !== press.pointer) {
        return;
    }

    const dx = e.clientX - press.startX;

    if (!press.dragging) {
        // The thumb travels only 30px, so a tap may slip a little and still
        // be a tap that flips it.
        if (Math.abs(dx) < 6) {
            return;
        }

        press.dragging = true;
        (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    }

    // Past either end it rubber-bands, a quarter of the pull at first and
    // never more than SLIP.
    const x = press.from + dx;
    const over = x < 0 ? x : x > TRAVEL ? x - TRAVEL : 0;

    s.tx =
        Math.min(Math.max(x, 0), TRAVEL) +
        (SLIP * over) / (Math.abs(over) + 4 * SLIP);
    preview.value = s.tx > TRAVEL / 2;
    kick();
};

/** End the press. Dropped mid-drag it picks the side; cancelled it goes back. */
const end = (drop: boolean) => {
    if (!press) {
        return;
    }

    const { dragging } = press;
    const dark = preview.value;

    press = null;
    preview.value = null;

    if (dragging) {
        justDragged = true;

        if (drop && dark !== null && dark !== isDark.value) {
            updateAppearance(dark ? 'dark' : 'light');
        }
    }

    aim();
    kick();
};

const onUp = (e: PointerEvent) => {
    if (press && e.pointerId === press.pointer) {
        end(true);
    }

    // Clicks follow pointerup within the same task; any later one is real.
    if (justDragged) {
        setTimeout(() => (justDragged = false));
    }
};

useEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key === 'Escape' && press) {
        end(false);
    }
});
useEventListener('blur', () => end(false));

onBeforeUnmount(() => cancelAnimationFrame(raf));
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="isDark"
        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        data-test="dark-mode-toggle"
        class="dm-track inline-flex h-8 w-16 shrink-0 touch-pan-y items-center overflow-hidden rounded-full border px-1 backdrop-blur-md select-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        :class="[
            animate ? 'transition-colors duration-500' : '',
            showDark
                ? 'border-white/10 bg-slate-800/50'
                : 'border-white/40 bg-sky-200/50',
        ]"
        @click="toggle"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="end(false)"
    >
        <!-- Inlined only for SSR, so the first paint sits on the right side;
             once mounted the liquid thumb script owns the transform. -->
        <span
            ref="thumb"
            class="dm-thumb flex size-6 items-center justify-center"
            :style="
                mounted
                    ? undefined
                    : {
                          transform: isDark
                              ? `translateX(${TRAVEL}px)`
                              : 'translateX(0)',
                      }
            "
        >
            <span
                ref="inner"
                class="dm-inner flex size-full items-center justify-center rounded-full border border-white/60 bg-gradient-to-br from-white to-white/75"
                :class="[
                    animate ? 'dm-animate' : '',
                    showDark ? 'dm-glow-dark' : 'dm-glow-light',
                ]"
            >
                <Sun
                    v-if="!showDark"
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
 * nothing can escape the bounds, even mid-stretch.
 */
.dm-inner {
    box-shadow:
        inset 0 1px 1px rgba(255, 255, 255, 0.9),
        0 2px 5px rgba(0, 0, 0, 0.2);
    transform-origin: center;
}

.dm-inner.dm-animate {
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
    .dm-inner {
        transition: none;
    }

    .dm-icon {
        animation: none;
    }
}
</style>
