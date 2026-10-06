<script setup lang="ts" generic="T extends string">
import { useEventListener, useResizeObserver } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { spring } from '@/lib/spring';

/**
 * Segmented switch whose selection is a soft pill that moves like liquid:
 * it stretches along its travel, wobbles as it lands and swells while held.
 * Press the selected segment and drag to slide it; it settles on whichever
 * segment it is let go over. The buttons stay plain buttons, so keyboard
 * and screen readers see an ordinary group.
 */
const props = defineProps<{
    options: { value: T; label: string; key?: string }[];
    label?: string;
}>();

const model = defineModel<T>({ required: true });

const root = ref<HTMLElement | null>(null);
const thumb = ref<HTMLElement | null>(null);

/** False until measured; the selected button paints its own pill till then (SSR). */
const ready = ref(false);

/** The segment under the pill mid-drag, lit before it is chosen. */
const over = ref<number | null>(null);

const index = computed(() =>
    props.options.findIndex((o) => o.value === model.value),
);
const lit = computed(() => over.value ?? index.value);

// Position, width, stretch and press, each a spring with its velocity and
// target. Plain object: it changes every frame and nothing renders from it.
const s = {
    x: 0,
    vx: 0,
    tx: 0,
    w: 0,
    vw: 0,
    tw: 0,
    st: 0,
    vst: 0,
    p: 0,
    vp: 0,
};

let held = false;
let moved = false;
let justDragged = false;
let grab = 0;
let startX = 0;
let pointer = -1;
let raf = 0;
let last = 0;

/** The most the pill slides past either end while dragged, in px. */
const SLIP = 8;

const segments = () =>
    Array.from(
        root.value?.querySelectorAll<HTMLElement>('[data-segment]') ?? [],
    );

const aim = (i: number) => {
    const el = segments()[i];

    if (el) {
        s.tx = el.offsetLeft;
        s.tw = el.offsetWidth;
    }
};

const paint = () => {
    if (!thumb.value) {
        return;
    }

    // Fast travel stretches the pill and thins it; holding it swells it.
    const sx = 1 + s.st + 0.06 * s.p;
    const sy = 1 - 0.45 * s.st + 0.12 * s.p;

    thumb.value.style.width = `${s.w}px`;
    thumb.value.style.transform = `translateX(${s.x}px) scale(${sx}, ${sy})`;
};

const frame = (now: number) => {
    const dt = Math.max(0, Math.min((now - last) / 1000, 1 / 30));

    last = now;

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        Object.assign(s, {
            x: s.tx,
            w: s.tw,
            vx: 0,
            vw: 0,
            st: 0,
            vst: 0,
            p: 0,
            vp: 0,
        });
        paint();
        raf = 0;

        return;
    }

    // Tighter under the finger, looser when it flies home on its own.
    const [k, c] = held ? [900, 48] : [380, 24];

    [s.x, s.vx] = spring(s.x, s.vx, s.tx, k, c, dt);
    [s.w, s.vw] = spring(s.w, s.vw, s.tw, 380, 24, dt);
    // Underdamped, so the stretch overshoots into a squash as the pill brakes.
    [s.st, s.vst] = spring(
        s.st,
        s.vst,
        Math.min(Math.abs(s.vx) / 3000, 0.28),
        520,
        14,
        dt,
    );
    [s.p, s.vp] = spring(s.p, s.vp, held ? 1 : 0, 520, 22, dt);

    const settled =
        !held &&
        Math.abs(s.tx - s.x) + Math.abs(s.tw - s.w) < 0.3 &&
        Math.abs(s.vx) + Math.abs(s.vw) < 2 &&
        Math.abs(s.st) + Math.abs(s.p) < 0.002 &&
        Math.abs(s.vst) + Math.abs(s.vp) < 0.02;

    if (settled) {
        Object.assign(s, {
            x: s.tx,
            w: s.tw,
            vx: 0,
            vw: 0,
            st: 0,
            vst: 0,
            p: 0,
            vp: 0,
        });
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

/** Jump straight to the selection: first measure, resizes, late fonts. */
const place = () => {
    if (held) {
        return;
    }

    aim(index.value);
    Object.assign(s, { x: s.tx, w: s.tw, vx: 0, vw: 0 });
    paint();
    ready.value = true;
};

useResizeObserver(root, place);

watch(index, (i) => {
    aim(i);
    kick();
});

/* ---- Dragging ------------------------------------------------------------- */

const onDown = (e: PointerEvent, i: number) => {
    justDragged = false;

    // Only the selected segment carries the pill, one pointer at a time;
    // others are plain taps.
    if (held || i !== index.value || e.button !== 0 || !root.value) {
        return;
    }

    held = true;
    moved = false;
    pointer = e.pointerId;
    startX = e.clientX;
    // Measured from the segment pressed, not from where the pill is: if it
    // is still sliding in from the last tap, a quick tap that slips a few px
    // would otherwise read as a drag to whatever it was passing.
    aim(i);
    grab = e.clientX - root.value.getBoundingClientRect().left - s.tx;
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    kick();
};

const onMove = (e: PointerEvent) => {
    if (!held || !root.value || e.pointerId !== pointer) {
        return;
    }

    if (!moved && Math.abs(e.clientX - startX) < 3) {
        return;
    }

    moved = true;

    const segs = segments();
    const end = segs[segs.length - 1];
    const min = segs[0].offsetLeft;
    const max = end.offsetLeft + end.offsetWidth - s.tw;
    const x = e.clientX - root.value.getBoundingClientRect().left - grab;
    const pull = x < min ? x - min : x > max ? x - max : 0;

    // Past either end it rubber-bands: a quarter of the pull at first,
    // never more than SLIP, so it can't be dragged out of the track.
    s.tx =
        Math.min(Math.max(x, min), max) +
        (SLIP * pull) / (Math.abs(pull) + 4 * SLIP);

    const centre = s.tx + s.tw / 2;
    const dist = (el: HTMLElement) =>
        Math.abs(el.offsetLeft + el.offsetWidth / 2 - centre);
    const nearest = segs.reduce(
        (best, el, j) => (dist(el) < dist(segs[best]) ? j : best),
        0,
    );

    over.value = nearest;
    s.tw = segs[nearest].offsetWidth;
};

/** End the press. Dropped mid-drag it selects; cancelled it goes back. */
const end = (drop: boolean) => {
    if (!held) {
        return;
    }

    held = false;

    if (moved) {
        // The click that follows lands on the button the drag began on.
        justDragged = true;
    }

    if (drop && moved && over.value !== null) {
        aim(over.value);
        model.value = props.options[over.value].value;
    } else {
        aim(index.value);
    }

    over.value = null;
    kick();
};

const onUp = (e: PointerEvent) => {
    if (e.pointerId === pointer) {
        end(true);
    }

    // Clicks follow pointerup within the same task; any later one is real.
    if (justDragged) {
        setTimeout(() => (justDragged = false));
    }
};

// A touch the browser takes over, Escape, or leaving the window puts the
// pill back rather than selecting where it happened to be.
useEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key === 'Escape' && held) {
        end(false);
    }
});
useEventListener('blur', () => end(false));

const pick = (value: T) => {
    if (justDragged) {
        justDragged = false;

        return;
    }

    model.value = value;
};

onBeforeUnmount(() => cancelAnimationFrame(raf));
</script>

<template>
    <div
        ref="root"
        class="relative inline-flex items-center gap-0.5 rounded-full bg-muted p-0.5 select-none"
        role="group"
        :aria-label="label ?? 'View'"
    >
        <span
            ref="thumb"
            class="pointer-events-none absolute top-0.5 left-0 h-7 rounded-full bg-card shadow-[0_0_0_1px_var(--border),0_1px_3px_-1px_oklch(0.25_0.02_250/0.18)] will-change-transform"
            :class="{ invisible: !ready }"
            aria-hidden="true"
        />
        <button
            v-for="(option, i) in options"
            :key="option.value"
            type="button"
            data-segment
            class="relative inline-flex h-7 min-w-14 touch-pan-y items-center justify-center rounded-full px-3 text-[13px] font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
            :class="[
                lit === i ? 'text-foreground' : 'text-muted-foreground',
                // Mid-drag the captured button keeps :hover; only the pill lights.
                over === null && lit !== i && 'hover:text-foreground',
                !ready &&
                    model === option.value &&
                    'bg-card shadow-[0_0_0_1px_var(--border)]',
            ]"
            :aria-pressed="model === option.value"
            :title="
                option.key
                    ? `${option.label} (${option.key.toUpperCase()})`
                    : undefined
            "
            @click="pick(option.value)"
            @pointerdown="onDown($event, i)"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="end(false)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
