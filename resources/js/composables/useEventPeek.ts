import { shallowRef } from 'vue';
import type { EventOccurrence } from '@/types/calendar';

/**
 * The hover peek on calendar pills: one app-wide, drawn by EventPeek.
 *
 * Pills call peekEnter / peekFocus / peekClose. A pill marked
 * data-peek="clipped" only peeks when something on it is cut off; every
 * other pill peeks to show what it has no room for (the end time, the full
 * title, the booking state).
 */
export type PeekTarget = { anchor: HTMLElement; occurrence: EventOccurrence };

export const peekTarget = shallowRef<PeekTarget | null>(null);

/** Set when the last close should skip the exit animation. */
export let peekClosedInstantly = false;

const INTENT_MS = 140; // a pointer just crossing the grid opens nothing
const WARM_MS = 400; // once one peek has shown, the next pill answers at once

let timer: number | undefined;
let closedAt = -Infinity;
let pressing = false;

const worthPeeking = (anchor: HTMLElement) =>
    anchor.dataset.peek !== 'clipped' ||
    !anchor.querySelector('[data-peek-time]') ||
    [...anchor.querySelectorAll<HTMLElement>('.truncate')].some(
        (el) => el.scrollWidth > el.clientWidth + 1,
    );

/** Mouse hover only: touch has no hover, and a tap opens the full card. */
export function peekEnter(event: PointerEvent, occurrence: EventOccurrence) {
    const anchor = event.currentTarget as HTMLElement;

    if (event.pointerType !== 'mouse' || pressing || !worthPeeking(anchor)) {
        return;
    }

    const warm =
        peekTarget.value !== null || performance.now() - closedAt < WARM_MS;

    window.clearTimeout(timer);
    timer = window.setTimeout(
        () => (peekTarget.value = { anchor, occurrence }),
        warm ? 0 : INTENT_MS,
    );
}

/** Keyboard focus peeks too; the focus a mouse click leaves behind does not. */
export function peekFocus(event: FocusEvent, occurrence: EventOccurrence) {
    const anchor = (event.currentTarget as HTMLElement).closest<HTMLElement>(
        '[data-event]',
    );

    if (
        !anchor ||
        !(event.target as Element).matches(':focus-visible') ||
        !worthPeeking(anchor)
    ) {
        return;
    }

    window.clearTimeout(timer);
    peekTarget.value = { anchor, occurrence };
}

/**
 * @param instantly skip the exit: the pill moved (scroll, resize) or the
 *                  pointer went down on it (a click or drag is starting)
 */
export function peekClose(instantly = false) {
    window.clearTimeout(timer);

    if (peekTarget.value) {
        peekClosedInstantly = instantly;
        peekTarget.value = null;
        closedAt = performance.now();
    }
}

/** While a button is held (click, drag) no pill peeks. */
export function setPeekPressing(value: boolean) {
    pressing = value;

    if (value) {
        peekClose(true);
    }
}
