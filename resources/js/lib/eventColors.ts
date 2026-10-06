/**
 * Google Calendar's event palette, in its own order (two rows of 13 and 11).
 * Events, calendars and categories pick from it; a custom color is allowed.
 */
export const EVENT_COLORS = [
    { hex: '#AD1457', name: 'Beetroot' },
    { hex: '#D81B60', name: 'Cherry blossom' },
    { hex: '#E67C73', name: 'Flamingo' },
    { hex: '#D50000', name: 'Tomato' },
    { hex: '#F4511E', name: 'Tangerine' },
    { hex: '#EF6C00', name: 'Pumpkin' },
    { hex: '#F09300', name: 'Mango' },
    { hex: '#F6BF26', name: 'Banana' },
    { hex: '#E4C441', name: 'Citron' },
    { hex: '#C0CA33', name: 'Avocado' },
    { hex: '#7CB342', name: 'Pistachio' },
    { hex: '#0B8043', name: 'Basil' },
    { hex: '#33B679', name: 'Sage' },
    { hex: '#009688', name: 'Eucalyptus' },
    { hex: '#039BE5', name: 'Peacock' },
    { hex: '#4285F4', name: 'Cobalt' },
    { hex: '#7986CB', name: 'Lavender' },
    { hex: '#3F51B5', name: 'Blueberry' },
    { hex: '#B39DDB', name: 'Wisteria' },
    { hex: '#9E69AF', name: 'Amethyst' },
    { hex: '#8E24AA', name: 'Grape' },
    { hex: '#795548', name: 'Cocoa' },
    { hex: '#616161', name: 'Graphite' },
    { hex: '#A79B8E', name: 'Birch' },
] as const;

/** Google's default calendar color. */
export const DEFAULT_COLOR = '#039BE5';

/** "Peacock" for a palette color, else the hex itself. */
export function colorName(hex: string | null | undefined): string {
    if (!hex) {
        return 'Default';
    }

    return (
        EVENT_COLORS.find((c) => c.hex.toLowerCase() === hex.toLowerCase())
            ?.name ?? hex.toUpperCase()
    );
}

export type { ColorLabel } from '@/types/auth';

/** Everyone starts from Google's palette: every color, no names, all shown. */
export function defaultLabels(): {
    color: string;
    name: string | null;
    hidden: boolean;
}[] {
    return EVENT_COLORS.map((c) => ({
        color: c.hex,
        name: null,
        hidden: false,
    }));
}

export const sameColor = (
    a: string | null | undefined,
    b: string | null | undefined,
) => !!a && !!b && a.toLowerCase() === b.toLowerCase();
