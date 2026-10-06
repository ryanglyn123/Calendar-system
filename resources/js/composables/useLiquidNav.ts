import { router, usePage } from '@inertiajs/vue3';
import {
    useEventListener,
    useMutationObserver,
    useResizeObserver,
} from '@vueuse/core';
import type { InjectionKey, Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { spring } from '@/lib/spring';

/**
 * The href of the nav item lit in place of the current page's: the one under
 * the pill mid-drag, or where a visit is headed. Null means the current page.
 */
export const liquidNavKey: InjectionKey<Readonly<Ref<string | null>>> =
    Symbol('liquidNav');

const ITEM = '[data-sidebar="menu-button"]';
const GROUP = '[data-sidebar="group"]';

/** Pointer travel past a group's end at which the clinging pill is fully taut. */
const TAUT = 60;

/** How far a fully taut pill stretches, as a share of its height. */
const CLING = 0.35;

/** The most a clinging pill slides past its group's end, in px. */
const SLIP = 14;

/** How close to the nav's top or bottom edge a drag starts scrolling it. */
const EDGE = 32;

/** Page visits the pill follows: not polls, prefetches, partial reloads or form posts. */
const isPageVisit = (visit: {
    method: string;
    async: boolean;
    prefetch: boolean;
    only: string[];
}) =>
    visit.method === 'get' &&
    !visit.async &&
    !visit.prefetch &&
    visit.only.length === 0;

/**
 * One pill for the whole sidebar nav that moves like liquid. It flies to
 * whichever item is being visited, stretching along its travel and wobbling
 * as it lands. Press the lit item and drag to slide it: past a group's end
 * it clings and stretches toward the pointer, and pulled far enough it lets
 * go into the next group. Dropped on an item, it visits that page; Escape,
 * leaving the window or a cancelled touch puts it back.
 *
 * The blob is the first child of the nav's scroll root. While it runs the
 * root carries [data-liquid] ("drag" mid-drag) and app.css moves the pill
 * look off the items onto the blob; without it (SSR, no lit item) the items
 * paint their own.
 */
export function useLiquidNav(blob: Readonly<Ref<HTMLElement | null>>) {
    const page = usePage();
    const over = ref<string | null>(null);

    // Position, velocity stretch, press and cling, each a spring with its
    // velocity and target. Plain object: it changes every frame and nothing
    // renders from it.
    const s = {
        y: 0,
        vy: 0,
        ty: 0,
        st: 0,
        vst: 0,
        p: 0,
        vp: 0,
        tn: 0,
        vtn: 0,
        ttn: 0,
    };
    let box = { x: 0, w: 0, h: 0 };

    /** The press on the lit item, from pointerdown until it ends. */
    let press: {
        pointer: number;
        home: string | null;
        group: number;
        grab: number;
        startX: number;
        startY: number;
        lastY: number;
        dragging: boolean;
    } | null = null;

    /** The network visit the pill is flying toward, so only it lands it. */
    let heading: { id: string; path: string } | null = null;

    // Set when a drag ends: the click that may follow lands on the item the
    // drag began on and must not visit it.
    let swallow = false;
    let raf = 0;
    let last = 0;

    const root = () => blob.value?.parentElement ?? null;

    /** Items in the root's scrolled coordinates, with their group's index. */
    const measure = () => {
        const el = root();

        if (!el) {
            return [];
        }

        const origin = el.getBoundingClientRect();
        const groups = Array.from(el.querySelectorAll(GROUP));

        return Array.from(el.querySelectorAll<HTMLAnchorElement>(ITEM)).map(
            (a) => {
                const r = a.getBoundingClientRect();

                return {
                    a,
                    top: r.top - origin.top + el.scrollTop,
                    x: r.left - origin.left,
                    w: r.width,
                    h: r.height,
                    g: groups.indexOf(a.closest(GROUP)!),
                };
            },
        );
    };

    type Item = ReturnType<typeof measure>[number];

    const aim = (item: Item) => {
        s.ty = item.top;
        box = { x: item.x, w: item.w, h: item.h };
    };

    const lit = () => measure().find((i) => i.a.dataset.active === 'true');

    const showing = () => root()?.dataset.liquid !== undefined;

    const paint = () => {
        if (!blob.value) {
            return;
        }

        const taut = Math.abs(s.tn);
        const sx = 1 - 0.1 * s.st - 0.06 * taut + 0.03 * s.p;
        const sy = 1 + s.st + CLING * taut + 0.1 * s.p;
        // The cling stretches toward the pull with the far edge held, like a
        // drop hanging on before it lets go.
        const y = s.y + (CLING / 2) * s.tn * box.h;

        blob.value.style.width = `${box.w}px`;
        blob.value.style.height = `${box.h}px`;
        blob.value.style.transform = `translate(${box.x}px, ${y}px) scale(${sx}, ${sy})`;
    };

    const rest = () =>
        Object.assign(s, {
            y: s.ty,
            vy: 0,
            st: 0,
            vst: 0,
            p: 0,
            vp: 0,
            tn: 0,
            vtn: 0,
        });

    /** Follow the pointer: within the lit group, clinging past its ends. */
    const track = (clientY: number) => {
        const el = root();

        if (!press || !el) {
            return;
        }

        const items = measure();
        const span = (g: number) => {
            const tops = items.filter((i) => i.g === g).map((i) => i.top);

            return tops.length > 0 ? [tops[0], tops[tops.length - 1]] : null;
        };
        const own = span(press.group);

        // The group went away under the drag (the nav re-rendered).
        if (!own) {
            return;
        }

        const want =
            clientY -
            el.getBoundingClientRect().top +
            el.scrollTop -
            press.grab;
        let [min, max] = own;

        // Pulled most of the way across the gap, it lets go into the next
        // group. Going back takes the same pull the other way.
        const step = want > max ? 1 : want < min ? -1 : 0;
        const next = step === 0 ? null : span(press.group + step);

        if (next) {
            const gap = step > 0 ? next[0] - max : min - next[1];
            const past = step > 0 ? want - max : min - want;

            if (past > gap * 0.75) {
                press.group += step;
                [min, max] = next;
            }
        }

        const pull = want > max ? want - max : want < min ? want - min : 0;
        const group = press.group;

        // With no group beyond, the nav's own edge is the limit: the cling
        // shrinks to the room left so the edge never cuts it off.
        const h = items[0].h;
        const room = pull < 0 ? min : el.scrollHeight - (max + h);
        const give =
            pull !== 0 && !span(group + Math.sign(pull))
                ? Math.max(0, Math.min(1, (room - 2) / (SLIP + CLING * h)))
                : 1;

        // Rubber band: a quarter of the pull at first, never past SLIP.
        s.ty =
            Math.min(Math.max(want, min), max) +
            (give * SLIP * pull) / (Math.abs(pull) + 4 * SLIP);
        s.ttn = give * Math.max(-1, Math.min(1, pull / TAUT));

        const near = items
            .filter((i) => i.g === group)
            .reduce((a, b) =>
                Math.abs(b.top - s.ty) < Math.abs(a.top - s.ty) ? b : a,
            );

        over.value = near.a.getAttribute('href');
    };

    /** Near the nav's top or bottom edge a drag scrolls it, if it scrolls. */
    const edgeScroll = (dt: number) => {
        const el = root();

        if (!press?.dragging || !el || el.scrollHeight <= el.clientHeight) {
            return;
        }

        const r = el.getBoundingClientRect();
        const y = press.lastY;
        const push =
            y < r.top + EDGE
                ? y - (r.top + EDGE)
                : y > r.bottom - EDGE
                  ? y - (r.bottom - EDGE)
                  : 0;
        const before = el.scrollTop;

        // The rail clips rather than scrolls; scrollTop would still move it.
        if (push !== 0 && getComputedStyle(el).overflowY !== 'hidden') {
            el.scrollTop += push * 12 * dt;

            if (el.scrollTop !== before) {
                track(y);
            }
        }
    };

    const frame = (now: number) => {
        const dt = Math.max(0, Math.min((now - last) / 1000, 1 / 30));

        last = now;
        edgeScroll(dt);

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            // No springs: it sits where it is aimed, following a drag as is.
            rest();
            paint();
            raf = press ? requestAnimationFrame(frame) : 0;

            return;
        }

        // Tighter under the pointer, looser when it flies on its own.
        const [k, c] = press ? [900, 48] : [380, 30];

        [s.y, s.vy] = spring(s.y, s.vy, s.ty, k, c, dt);
        // Underdamped, so the stretch overshoots into a squash as it brakes.
        [s.st, s.vst] = spring(
            s.st,
            s.vst,
            Math.min(Math.abs(s.vy) / 4000, 0.3),
            520,
            14,
            dt,
        );
        [s.p, s.vp] = spring(s.p, s.vp, press ? 1 : 0, 520, 22, dt);
        [s.tn, s.vtn] = spring(s.tn, s.vtn, s.ttn, 600, 16, dt);

        const settled =
            !press &&
            Math.abs(s.ty - s.y) < 0.3 &&
            Math.abs(s.vy) < 2 &&
            Math.abs(s.st) + Math.abs(s.p) + Math.abs(s.tn) < 0.002 &&
            Math.abs(s.vst) + Math.abs(s.vp) + Math.abs(s.vtn) < 0.02;

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

    /** Show the blob on the lit item, or hand back to the items if none is lit. */
    const settle = (snap: boolean) => {
        const el = root();

        if (!el || press) {
            return;
        }

        const item = lit();

        if (!item) {
            delete el.dataset.liquid;

            return;
        }

        // Coming back from hidden, it appears in place rather than flying in.
        const hidden = !showing();

        aim(item);
        el.dataset.liquid = '';

        if (snap || hidden) {
            rest();
            paint();
        } else {
            kick();
        }
    };

    // First measure, the rail collapsing and expanding, window resizes.
    useResizeObserver(root, () => settle(true));

    // Items coming or going (a division assigned, a resource type added)
    // move the rest; the pill follows its item.
    useMutationObserver(root, () => settle(false), {
        childList: true,
        subtree: true,
    });

    // Every page change lands the pill: back and forward, and visits served
    // from the prefetch cache, which fire no start or finish at all. So this,
    // not finish, is what hands the lit item back to the page; otherwise a
    // drop or click answered from the cache leaves it lit forever. Only a
    // network visit still on its way elsewhere keeps its target lit.
    watch(
        () => page.url,
        (url) => {
            if (
                press ||
                (heading &&
                    new URL(url, window.location.origin).pathname !==
                        heading.path)
            ) {
                return;
            }

            heading = null;
            over.value = null;
            requestAnimationFrame(() => settle(false));
        },
        { flush: 'post' },
    );

    /* ---- Dragging --------------------------------------------------------- */

    /** End the press. Dropped mid-drag it visits; cancelled it goes home. */
    const end = (drop: boolean) => {
        const el = root();

        if (!press || !el) {
            return;
        }

        const { dragging, home } = press;

        press = null;
        s.ttn = 0;

        if (!dragging) {
            kick();

            return;
        }

        swallow = true;
        el.dataset.liquid = '';

        const target = over.value;

        if (drop && target && target !== home) {
            router.visit(target);
        } else {
            over.value = null;
        }

        // Re-aim once the lit item is back on the page's own (or the target).
        requestAnimationFrame(() => settle(false));
    };

    useEventListener(root, 'pointerdown', (e: PointerEvent) => {
        swallow = false;

        // One pointer drives it; a second finger is ignored.
        if (press) {
            return;
        }

        const el = root();
        const a = (e.target as Element).closest<HTMLElement>(ITEM);
        const item = measure().find((i) => i.a === a);

        // Only the lit item carries the pill, only with a plain primary
        // press; a modified click (new tab) stays a plain link.
        if (
            !el ||
            !item ||
            !showing() ||
            a?.dataset.active !== 'true' ||
            e.button !== 0 ||
            e.ctrlKey ||
            e.metaKey ||
            e.shiftKey ||
            e.altKey
        ) {
            return;
        }

        // The press holds the pill on the item pressed, even if it is still
        // flying in from the last click; measured from where the pill is in
        // flight, a fast click that slips a few px would read as a drag to
        // whichever item it was passing, and visit that.
        aim(item);
        press = {
            pointer: e.pointerId,
            home: item.a.getAttribute('href'),
            group: item.g,
            grab:
                e.clientY -
                el.getBoundingClientRect().top +
                el.scrollTop -
                item.top,
            startX: e.clientX,
            startY: e.clientY,
            lastY: e.clientY,
            dragging: false,
        };
        kick();
    });

    useEventListener(root, 'pointermove', (e: PointerEvent) => {
        const el = root();

        if (!press || !el || e.pointerId !== press.pointer) {
            return;
        }

        press.lastY = e.clientY;

        if (!press.dragging) {
            // Either direction starts it, so a sideways slip off the item
            // can't leave the press hanging uncaptured.
            if (
                Math.hypot(e.clientX - press.startX, e.clientY - press.startY) <
                3
            ) {
                return;
            }

            press.dragging = true;
            el.setPointerCapture(e.pointerId);
            el.dataset.liquid = 'drag';
        }

        track(e.clientY);
        kick();
    });

    useEventListener(root, 'pointerup', (e: PointerEvent) => {
        if (press && e.pointerId === press.pointer) {
            end(true);
        }

        // Clicks follow pointerup within the same task; any later one is real.
        if (swallow) {
            setTimeout(() => (swallow = false));
        }
    });

    // The browser took the gesture (a scroll) or yanked the root's capture.
    // A touch is first captured by the link itself, and that capture bubbles
    // its loss up here when the drag takes it over; that one is not an end.
    useEventListener(root, 'pointercancel', (e: PointerEvent) => {
        if (e.pointerId === press?.pointer) {
            end(false);
        }
    });
    useEventListener(root, 'lostpointercapture', (e: PointerEvent) => {
        if (e.target === root() && e.pointerId === press?.pointer) {
            end(false);
        }
    });

    useEventListener('blur', () => end(false));

    useEventListener('keydown', (e: KeyboardEvent) => {
        if (e.key === 'Escape' && press) {
            end(false);
        } else {
            // A key press (Enter on a link) is never the drag's own click.
            swallow = false;
        }
    });

    useEventListener(
        root,
        'click',
        (e: MouseEvent) => {
            if (swallow) {
                swallow = false;
                e.preventDefault();
                e.stopPropagation();
            }
        },
        { capture: true },
    );

    // Links drag natively; the pill's own item must not.
    useEventListener(root, 'dragstart', (e: DragEvent) => {
        if (press) {
            e.preventDefault();
        }
    });

    /* ---- Visits ----------------------------------------------------------- */

    const offs: VoidFunction[] = [];

    onMounted(() => {
        offs.push(
            // The pill leaves as the visit starts rather than when the page
            // arrives, so a click answers at once.
            router.on('start', (event) => {
                const visit = event.detail.visit;
                const item = measure().find(
                    (i) => new URL(i.a.href).pathname === visit.url.pathname,
                );

                if (!item || press || !isPageVisit(visit)) {
                    return;
                }

                heading = { id: visit.id, path: visit.url.pathname };
                over.value = item.a.getAttribute('href');

                if (showing()) {
                    aim(item);
                    kick();
                }
            }),
            // Failed or cancelled (landing is the page watch's job), the lit
            // item is the page's own again. A visit cut off by a newer one
            // leaves the pill to it.
            router.on('finish', (event) => {
                if (event.detail.visit.id !== heading?.id) {
                    return;
                }

                heading = null;
                over.value = null;
                requestAnimationFrame(() => settle(false));
            }),
        );
    });

    onBeforeUnmount(() => {
        cancelAnimationFrame(raf);
        offs.forEach((off) => off());
    });

    return { over };
}
