<script setup lang="ts">
/**
 * Decorative backdrop for auth screens: a faint, oversized month grid with
 * pastel "event pills" drifting in slow loops — the product breathing behind
 * the sign-in card. Purely presentational; animations are transform/opacity
 * only and collapse to a static composition under prefers-reduced-motion.
 */
type ScenePill = {
    /** viewport-relative position */
    x: string;
    y: string;
    w: number;
    color: string;
    /** drift loop duration (s) — varied so motion never syncs */
    dur: number;
    delay: number;
    /** blurred pills read as a farther depth plane */
    soft?: boolean;
    o?: number;
};

// The live pastel event palette, placed around the edges so the
// center stays calm for the card. Two depth planes: crisp and soft.
const pills: ScenePill[] = [
    { x: '6%', y: '14%', w: 150, color: '#86C6F5', dur: 17, delay: -3 },
    { x: '13%', y: '38%', w: 104, color: '#F5A6D8', dur: 21, delay: -9 },
    { x: '4%', y: '66%', w: 170, color: '#86DBAE', dur: 19, delay: -6 },
    { x: '16%', y: '86%', w: 118, color: '#EFD95F', dur: 23, delay: -14 },
    { x: '78%', y: '11%', w: 132, color: '#BFA8F5', dur: 20, delay: -5 },
    { x: '86%', y: '34%', w: 96, color: '#EFD95F', dur: 16, delay: -11 },
    { x: '80%', y: '60%', w: 164, color: '#86C6F5', dur: 22, delay: -2 },
    { x: '73%', y: '84%', w: 122, color: '#F7A79B', dur: 18, delay: -8 },
    // far plane
    {
        x: '30%',
        y: '7%',
        w: 88,
        color: '#F5CD7E',
        dur: 26,
        delay: -4,
        soft: true,
        o: 0.55,
    },
    {
        x: '58%',
        y: '90%',
        w: 96,
        color: '#BFA8F5',
        dur: 28,
        delay: -12,
        soft: true,
        o: 0.5,
    },
    {
        x: '92%',
        y: '76%',
        w: 80,
        color: '#86DBAE',
        dur: 24,
        delay: -7,
        soft: true,
        o: 0.5,
    },
    {
        x: '2%',
        y: '90%',
        w: 84,
        color: '#86C6F5',
        dur: 27,
        delay: -10,
        soft: true,
        o: 0.45,
    },
];

const wash = (color: string) =>
    `linear-gradient(180deg, color-mix(in oklab, ${color} 82%, white) 0%, ${color} 100%)`;
</script>

<template>
    <div class="auth-scene" aria-hidden="true">
        <div class="auth-scene-grid" />
        <span
            v-for="(pill, i) in pills"
            :key="i"
            class="auth-scene-pill"
            :class="{ 'is-soft': pill.soft }"
            :style="{
                left: pill.x,
                top: pill.y,
                width: `${pill.w}px`,
                background: wash(pill.color),
                opacity: pill.o ?? 0.9,
                animationDuration: `${pill.dur}s`,
                animationDelay: `${pill.delay}s`,
            }"
        />
    </div>
</template>
