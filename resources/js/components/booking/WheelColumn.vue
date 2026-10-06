<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';

export type WheelItem = {
    value: number;
    label: string;
    /** Still pickable, but drawn quieter: it would make the time unusable. */
    dim?: boolean;
};

const props = defineProps<{ items: WheelItem[]; label: string }>();

const model = defineModel<number>({ required: true });

/** Row height in px: matches the h-[34px] rows. Seven rows show. */
const ROW = 34;

const el = useTemplateRef<HTMLDivElement>('el');
/** Live scroll position, in px, driving the curved look of the rows. */
const offset = ref(0);

const clampIndex = (i: number) =>
    Math.min(Math.max(i, 0), props.items.length - 1);

const index = computed(() =>
    clampIndex(props.items.findIndex((item) => item.value === model.value)),
);

const reducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const coarsePointer = window.matchMedia('(pointer: coarse)').matches;

const rowAt = () => clampIndex(Math.round((el.value?.scrollTop ?? 0) / ROW));

/** Where a wheel notch, key, or click is sending the column. */
let target: number | null = null;
let settleTimer: ReturnType<typeof setTimeout> | undefined;
let lastRow = -1;
/** The user is turning it (not us syncing), so ticks are felt. */
let userDriven = false;

function glideTo(i: number): void {
    const node = el.value;

    if (!node) {
        return;
    }

    target = clampIndex(i);

    if (Math.round(node.scrollTop) === target * ROW) {
        settle();

        return;
    }

    node.scrollTo({
        top: target * ROW,
        behavior: reducedMotion() ? 'auto' : 'smooth',
    });
}

/** Commit the row under the band once the column comes to rest. */
function settle(): void {
    const node = el.value;

    if (!node || drag) {
        return;
    }

    clearTimeout(settleTimer);
    node.style.scrollSnapType = '';
    target = null;
    userDriven = false;

    const item = props.items[rowAt()];

    if (item && item.value !== model.value) {
        model.value = item.value;
    }
}

function onScroll(): void {
    const node = el.value;

    if (!node) {
        return;
    }

    offset.value = node.scrollTop;

    const row = rowAt();

    if (row !== lastRow) {
        // A light tick per row, like a detent. Phones that support it
        // (Android) buzz; everything else simply ignores it.
        if (lastRow !== -1 && userDriven && coarsePointer) {
            navigator.vibrate?.(4);
        }

        lastRow = row;
    }

    clearTimeout(settleTimer);
    settleTimer = setTimeout(settle, 140);
}

function onTouchStart(): void {
    userDriven = true;
}

/* ---- Mouse wheel and trackpad: one notch, one row ------------------ */

let wheelCarry = 0;

function onWheel(event: WheelEvent): void {
    event.preventDefault();
    userDriven = true;

    const delta = event.deltaMode === 1 ? event.deltaY * 16 : event.deltaY;
    let steps: number;

    if (Math.abs(delta) >= 50) {
        // A mouse wheel notch.
        steps = Math.sign(delta);
        wheelCarry = 0;
    } else {
        // Trackpad: follow the fingers a row at a time.
        wheelCarry += delta;
        steps = Math.trunc(wheelCarry / 22);
        wheelCarry -= steps * 22;
    }

    if (steps !== 0) {
        glideTo((target ?? rowAt()) + steps);
    }
}

/* ---- Mouse drag: grab and flick, like a finger --------------------- */

let drag: {
    startY: number;
    startTop: number;
    lastY: number;
    lastT: number;
    velocity: number;
    moved: boolean;
} | null = null;

function onPointerDown(event: PointerEvent): void {
    // Touch and pen scroll natively (with momentum); only mice need help.
    if (event.pointerType !== 'mouse' || event.button !== 0 || !el.value) {
        return;
    }

    drag = {
        startY: event.clientY,
        startTop: el.value.scrollTop,
        lastY: event.clientY,
        lastT: event.timeStamp,
        velocity: 0,
        moved: false,
    };
    userDriven = true;
    el.value.setPointerCapture(event.pointerId);
    el.value.style.scrollSnapType = 'none';
}

function onPointerMove(event: PointerEvent): void {
    if (!drag || !el.value) {
        return;
    }

    const dy = event.clientY - drag.startY;

    if (Math.abs(dy) > 3) {
        drag.moved = true;
    }

    el.value.scrollTop = drag.startTop - dy;

    const dt = event.timeStamp - drag.lastT;

    if (dt > 0) {
        drag.velocity = (event.clientY - drag.lastY) / dt;
    }

    drag.lastY = event.clientY;
    drag.lastT = event.timeStamp;
}

function onPointerUp(event: PointerEvent): void {
    if (!drag || !el.value) {
        return;
    }

    const { moved, velocity } = drag;
    const node = el.value;

    drag = null;

    if (!moved) {
        // A click: step to the row that was clicked.
        const rect = node.getBoundingClientRect();
        const rows = Math.round(
            (event.clientY - (rect.top + rect.height / 2)) / ROW,
        );
        glideTo(rowAt() + rows);

        return;
    }

    // Let a flick coast a few rows, then land on one.
    glideTo(Math.round((node.scrollTop - velocity * 160) / ROW));
}

/* ---- Keyboard ------------------------------------------------------ */

const KEY_STEPS: Record<string, number> = {
    ArrowUp: -1,
    ArrowDown: 1,
    PageUp: -5,
    PageDown: 5,
};

function onKeydown(event: KeyboardEvent): void {
    let next: number | null = null;

    if (event.key in KEY_STEPS) {
        next = (target ?? index.value) + KEY_STEPS[event.key];
    } else if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = props.items.length - 1;
    }

    if (next !== null) {
        event.preventDefault();
        glideTo(next);
    }
}

/* ---- Sync from outside (the day strip, the other column) ----------- */

watch(model, () => {
    if (drag || target !== null || !el.value) {
        return;
    }

    if (rowAt() !== index.value) {
        glideTo(index.value);
    }
});

onMounted(() => {
    if (!el.value) {
        return;
    }

    el.value.scrollTop = index.value * ROW;
    offset.value = el.value.scrollTop;
    lastRow = index.value;
    // Not every browser has scrollend yet; the scroll timer covers them.
    el.value.addEventListener('scrollend', settle);
});

onBeforeUnmount(() => {
    clearTimeout(settleTimer);
    el.value?.removeEventListener('scrollend', settle);
});

/** Rows curve away from the band like the face of a drum. */
function rowStyle(i: number) {
    const distance = i - offset.value / ROW;
    const away = Math.min(Math.abs(distance), 3.2);

    return {
        opacity: String(1 - away * 0.25),
        transform: `perspective(420px) rotateX(${(-distance * 19).toFixed(1)}deg)`,
    };
}
</script>

<template>
    <div
        ref="el"
        role="spinbutton"
        tabindex="0"
        :aria-label="label"
        :aria-valuenow="model"
        :aria-valuetext="items[index]?.label"
        class="wheel-column relative h-[238px] cursor-grab touch-pan-y snap-y snap-mandatory overflow-y-scroll overscroll-contain rounded-xl outline-none select-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
        @scroll.passive="onScroll"
        @wheel="onWheel"
        @touchstart.passive="onTouchStart"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
        @keydown="onKeydown"
    >
        <div class="py-[102px]">
            <div
                v-for="(item, i) in items"
                :key="item.value"
                class="flex h-[34px] snap-center items-center justify-center text-xl tabular-nums"
                :class="
                    item.dim ? 'text-muted-foreground/70' : 'text-foreground'
                "
                :style="rowStyle(i)"
                aria-hidden="true"
            >
                {{ item.label }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.wheel-column {
    scrollbar-width: none;
    /* Rows fade out toward the rim of the drum. */
    mask-image: linear-gradient(
        to bottom,
        transparent,
        black 32%,
        black 68%,
        transparent
    );
}

.wheel-column::-webkit-scrollbar {
    display: none;
}
</style>
