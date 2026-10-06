<script setup lang="ts">
import {
    defaultDocument,
    defaultWindow,
    useEventListener,
    useMounted,
} from '@vueuse/core';
import { gsap } from 'gsap';
import { computed, nextTick, onBeforeUnmount, shallowRef, watch } from 'vue';
import type { PeekTarget } from '@/composables/useEventPeek';
import {
    peekClose,
    peekClosedInstantly,
    peekTarget,
    setPeekPressing,
} from '@/composables/useEventPeek';
import {
    bookingTone,
    BOOKING_STATE,
    chipFill,
    chipIcon,
    chipState,
} from '@/lib/bookingDisplay';
import {
    addDays,
    eventInk,
    eventWash,
    format,
    isSameDay,
} from '@/lib/calendarMath';

/**
 * The hover peek: the pill itself grows in place to show what it had no
 * room for. It lives at the body so a crowded cell's overflow never clips
 * it, and covers neighbours without taking their clicks. The card stands in
 * for the pill: its first row repeats the pill's own (lead time, icon,
 * title) at the same spot, and the pill hides while the card is up. Laid
 * out once at its final size, a clip-path opens it out of the pill's exact
 * rect while the shadow grows in, so it lifts as it grows.
 */
const EDGE = 8;
const OPEN = 'inset(0px 0px 0px 0px round 8px)';

const shown = shallowRef<PeekTarget | null>(null);
const frame = shallowRef<HTMLElement | null>(null);
const card = shallowRef<HTMLElement | null>(null);
const body = shallowRef<HTMLElement | null>(null);

let tl: gsap.core.Timeline | undefined;
/** The pill's rect as a clip on the card, to close back into. */
let pillClip = OPEN;

const reduced = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Where the pill draws its first row, so the card's lands on top of it. */
type Mirror = {
    lead: string;
    leadSize: string;
    /** The pill's own time label size, for the card's detail rows. */
    metaSize: string;
    gap: string;
    left: number;
    top: number;
};

const mirror = shallowRef<Mirror>({
    lead: '',
    leadSize: '10px',
    metaSize: '12px',
    gap: '6px',
    left: 8,
    top: 4,
});

function measure(anchor: HTMLElement): Mirror {
    const r = anchor.getBoundingClientRect();
    const lead = [
        ...anchor.querySelectorAll<HTMLElement>('[data-peek-lead]'),
    ].find((el) => el.getClientRects().length > 0);
    const meta = [
        ...anchor.querySelectorAll<HTMLElement>('[data-peek-time]'),
    ].find((el) => el.getClientRects().length > 0);
    const icon = anchor.querySelector('svg');
    const title = anchor.querySelector<HTMLElement>('.bk-title');
    const first = (lead ?? icon ?? title)?.getBoundingClientRect();
    const t = title?.getBoundingClientRect();
    const gap = getComputedStyle(
        icon?.parentElement ?? lead?.parentElement ?? anchor,
    ).columnGap;

    return {
        lead: lead?.textContent?.trim() ?? '',
        leadSize: lead ? getComputedStyle(lead).fontSize : '10px',
        metaSize: meta ? getComputedStyle(meta).fontSize : '12px',
        gap: gap === 'normal' ? '6px' : gap,
        left: first ? Math.max(2, first.left - r.left) : 8,
        // Centre the card's 16px first line on the pill's title.
        top: t ? Math.max(2, t.top + t.height / 2 - r.top - 8) : 4,
    };
}

/* The pill under the card hides while the card stands in for it. Opacity,
   not visibility, so it keeps the pointer and its pointerleave. */
let hidden: HTMLElement | null = null;

function hideAnchor(el: HTMLElement): void {
    restoreAnchor();
    el.style.transition = 'none';
    el.style.opacity = '0';
    hidden = el;
}

function restoreAnchor(): void {
    const el = hidden;

    if (!el) {
        return;
    }

    hidden = null;
    el.style.opacity = '';
    void el.offsetWidth; // commit before the pill's own transition returns
    el.style.transition = '';
}

function open(target: PeekTarget): void {
    const f = frame.value;
    const c = card.value;

    if (!f || !c || !target.anchor.isConnected) {
        shown.value = null;

        return;
    }

    const r = target.anchor.getBoundingClientRect();

    // Never smaller than the pill, so the card always covers it; never
    // wider than the room to its right, so the first row stays on the
    // pill's (a long title wraps instead of shoving the card left).
    c.style.minWidth = `${r.width}px`;
    c.style.minHeight = `${r.height}px`;
    c.style.maxWidth = `${Math.max(r.width, Math.min(288, innerWidth - EDGE - r.left))}px`;

    const w = c.offsetWidth;
    const h = c.offsetHeight;
    const left = Math.max(EDGE, Math.min(r.left, innerWidth - EDGE - w));
    const top = Math.max(EDGE, Math.min(r.top, innerHeight - EDGE - h));

    Object.assign(f.style, {
        left: `${left}px`,
        top: `${top}px`,
        width: `${w}px`,
        height: `${h}px`,
    });

    const inset = (n: number) => `${Math.max(0, Math.round(n))}px`;

    pillClip = `inset(${inset(r.top - top)} ${inset(left + w - r.right)} ${inset(top + h - r.bottom)} ${inset(r.left - left)} round 6px)`;

    tl = gsap.timeline();

    if (reduced()) {
        tl.fromTo(
            c,
            { opacity: 0, clipPath: OPEN },
            { opacity: 1, duration: 0.12, ease: 'power1.out' },
        )
            .set(f, { '--lift': 1 }, 0)
            .call(() => hideAnchor(target.anchor));

        return;
    }

    // The card starts as the pill (same rect, same first row, no shadow),
    // then grows out of it and lifts.
    hideAnchor(target.anchor);
    tl.fromTo(
        c,
        { opacity: 1, clipPath: pillClip },
        { clipPath: OPEN, duration: 0.26, ease: 'expo.out' },
        0,
    ).fromTo(
        f,
        { '--lift': 0 },
        { '--lift': 1, duration: 0.26, ease: 'power2.out' },
        0,
    );
}

function close(): void {
    const c = card.value;

    if (!c || peekClosedInstantly) {
        restoreAnchor();
        shown.value = null;

        return;
    }

    const done = () => (shown.value = null);

    tl = gsap.timeline({ onComplete: done });

    if (reduced()) {
        restoreAnchor();
        tl.to(c, { opacity: 0, duration: 0.08 });

        return;
    }

    // Shrink back into the pill, faster than it grew; the pill returns
    // under it and the card fades off.
    tl.to(c, { clipPath: pillClip, duration: 0.13, ease: 'power2.in' }, 0)
        .to(frame.value, { '--lift': 0, duration: 0.13 }, 0)
        .call(restoreAnchor)
        .to(c, { opacity: 0, duration: 0.05 });
}

watch(peekTarget, async (next) => {
    tl?.kill();

    if (!next) {
        close();

        return;
    }

    restoreAnchor();
    mirror.value = measure(next.anchor);
    shown.value = next;
    await nextTick();

    // Another pill may have taken over while the card rendered.
    if (peekTarget.value === next) {
        open(next);
    }
});

/* Anything that moves the pill or starts a click or drag ends the peek at
   once; it would otherwise point at a stale spot or cover the gesture. */
const closeNow = () => peekClose(true);

useEventListener(defaultDocument, 'pointerdown', () => setPeekPressing(true), {
    capture: true,
});
useEventListener(defaultDocument, 'pointerup', () => setPeekPressing(false), {
    capture: true,
});
useEventListener(
    defaultDocument,
    'pointercancel',
    () => setPeekPressing(false),
    {
        capture: true,
    },
);
useEventListener(defaultDocument, 'scroll', closeNow, {
    capture: true,
    passive: true,
});
useEventListener(defaultWindow, 'wheel', closeNow, { passive: true });
useEventListener(defaultWindow, 'resize', closeNow);
useEventListener(defaultWindow, 'blur', closeNow);
useEventListener(defaultDocument, 'keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeNow();
    }
});

onBeforeUnmount(() => {
    tl?.kill();
    restoreAnchor();
    peekClose(true);
});

const occurrence = computed(() => shown.value?.occurrence ?? null);

const when = computed(() => {
    const o = occurrence.value;

    if (!o) {
        return '';
    }

    const start = new Date(o.starts_at);
    const end = new Date(o.ends_at);

    if (o.all_day) {
        const last = addDays(end, -1);

        return isSameDay(start, last) || last < start
            ? `${format(start, 'EEE, MMM d')} · All day`
            : `${format(start, 'MMM d')} – ${format(last, 'MMM d')}`;
    }

    return isSameDay(start, end)
        ? `${format(start, 'HH:mm')} – ${format(end, 'HH:mm')}`
        : `${format(start, 'MMM d, HH:mm')} – ${format(end, 'MMM d, HH:mm')}`;
});

const cardStyle = computed(() => {
    const tone = occurrence.value ? bookingTone(occurrence.value) : null;

    return {
        '--wash': eventWash(tone),
        '--ink': eventInk(tone),
        '--ev': tone ?? '#d4d8dd',
    };
});

const mounted = useMounted();
</script>

<template>
    <!-- Client only: a server-rendered teleport has no body to land in,
         and hydration would trip over it. -->
    <Teleport v-if="mounted" to="body">
        <!-- Visual only: the pill under it keeps the pointer and the screen
             reader already hears its full label. -->
        <div
            v-if="occurrence"
            ref="frame"
            class="ev-peek pointer-events-none fixed z-40"
            aria-hidden="true"
        >
            <div
                ref="card"
                class="absolute top-0 left-0 flex w-max max-w-[min(18rem,calc(100vw-1rem))] flex-col rounded-lg bg-popover"
            >
                <div
                    ref="body"
                    class="flex-1 rounded-lg pb-1.5 text-xs"
                    :class="chipFill(occurrence)"
                    :style="[
                        cardStyle,
                        {
                            paddingTop: `${mirror.top}px`,
                            paddingInline: `${mirror.left}px`,
                        },
                    ]"
                >
                    <!-- bk-label: on a hatched hold, text sits on the flat base.
                         The first row is the pill's own, in the same place. -->
                    <p
                        class="bk-label flex w-fit max-w-full items-start leading-4 font-medium"
                        :style="{ columnGap: mirror.gap }"
                    >
                        <span
                            v-if="mirror.lead"
                            class="shrink-0 font-normal opacity-75"
                            :style="{ fontSize: mirror.leadSize }"
                        >
                            {{ mirror.lead }}
                        </span>
                        <component
                            :is="chipIcon(occurrence)"
                            v-if="chipIcon(occurrence)"
                            class="mt-0.5 size-3 shrink-0"
                        />
                        <span class="bk-title line-clamp-3">
                            {{ occurrence.title }}
                        </span>
                    </p>
                    <p
                        class="bk-label mt-0.5 w-fit max-w-full leading-4 tabular-nums opacity-75"
                        :style="{ fontSize: mirror.metaSize }"
                    >
                        {{ when }}
                    </p>
                    <p
                        v-if="occurrence.booking"
                        class="bk-label mt-0.5 w-fit max-w-full leading-4 opacity-75"
                        :style="{ fontSize: mirror.metaSize }"
                    >
                        {{ chipState(occurrence).trim().replace(/\.$/, '') }}
                        <template
                            v-if="
                                !BOOKING_STATE[occurrence.booking.status].active
                            "
                        >
                            · no longer holds the slot
                        </template>
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
