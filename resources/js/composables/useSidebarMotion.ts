import { gsap } from 'gsap';
import type { Ref } from 'vue';
import { onBeforeUnmount, watch } from 'vue';
import {
    SIDEBAR_WIDTH,
    SIDEBAR_WIDTH_ICON,
} from '@/components/ui/sidebar/utils';

const px = (rem: string) =>
    parseFloat(rem) *
    parseFloat(getComputedStyle(document.documentElement).fontSize);

/**
 * Moves the desktop sidebar between full width and the icon rail.
 *
 * CSS owns both resting states (keyed on data-collapsible), so first paint,
 * a restored cookie and the mobile sheet need no script. On a toggle this
 * pins what is on screen, lets the attribute flip underneath, tweens to the
 * new state, then clears its inline styles so CSS takes over again.
 *
 * Inside the root: [data-sidebar-track] elements carry the width (the panel
 * and the spacer that pushes the page) and [data-sidebar-fade] elements are
 * hidden in the rail. The logo mark ([data-sidebar-mark]) never fades; it
 * glides on its own CSS transition (app.css).
 */
export function useSidebarMotion(
    root: Readonly<Ref<HTMLElement | null>>,
    state: Readonly<Ref<'expanded' | 'collapsed'>>,
): void {
    let ctx: gsap.Context | undefined;

    watch(state, (next) => {
        const el = root.value;

        // Mobile shows a sheet instead; it has its own transition.
        if (!el) {
            return;
        }

        // Stop without reverting, so a reversal starts from wherever the
        // previous run left things rather than jumping to an end state.
        ctx?.kill();

        ctx = gsap.context(() => {
            const track = gsap.utils.toArray<HTMLElement>(
                '[data-sidebar-track]',
                el,
            );
            const fades = gsap.utils.toArray<HTMLElement>(
                '[data-sidebar-fade]',
                el,
            );
            const all = [...track, ...fades];
            const handBack = () =>
                gsap.set(all, { clearProps: 'width,opacity,transform' });

            const collapsing = next === 'collapsed';

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                // No travel: the rail snaps to size and only the words fade
                // in when it opens.
                handBack();

                if (!collapsing) {
                    gsap.fromTo(
                        fades,
                        { opacity: 0 },
                        { opacity: 1, duration: 0.15, clearProps: 'opacity' },
                    );
                }

                return;
            }

            // Runs before Vue flips data-collapsible; pin the current look so
            // the flip changes nothing until the tweens move it.
            const from = track[0]?.offsetWidth ?? 0;
            const to = px(collapsing ? SIDEBAR_WIDTH_ICON : SIDEBAR_WIDTH);
            const travel =
                Math.abs(to - from) /
                (px(SIDEBAR_WIDTH) - px(SIDEBAR_WIDTH_ICON));

            gsap.set(track, { width: from });
            gsap.set(fades, {
                opacity: (_i: number, t: Element) =>
                    Number(gsap.getProperty(t, 'opacity')),
            });

            const tl = gsap.timeline({ onComplete: handBack });

            if (collapsing) {
                // Words leave first and fast, then the panel closes in
                // behind them.
                tl.to(
                    fades,
                    { opacity: 0, x: -4, duration: 0.1, ease: 'power1.out' },
                    0,
                ).to(
                    track,
                    {
                        width: to,
                        duration: 0.1 + 0.16 * travel,
                        ease: 'power2.inOut',
                    },
                    0.02,
                );
            } else {
                // The panel answers the click at once; the words follow in
                // reading order once there is room for them.
                tl.to(
                    track,
                    {
                        width: to,
                        duration: 0.12 + 0.18 * travel,
                        ease: 'power3.out',
                    },
                    0,
                ).fromTo(
                    fades,
                    {
                        // A fully hidden word slides 6px; one caught
                        // mid-fade starts from about where it is.
                        x: (_i: number, t: Element) =>
                            -6 * (1 - Number(gsap.getProperty(t, 'opacity'))),
                    },
                    {
                        opacity: 1,
                        x: 0,
                        duration: 0.22,
                        ease: 'power2.out',
                        stagger: { amount: 0.06 },
                    },
                    0.08 * travel,
                );
            }
        }, el);
    });

    onBeforeUnmount(() => ctx?.revert());
}
