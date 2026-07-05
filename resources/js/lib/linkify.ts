export type LinkifySegment =
    | { type: 'text'; value: string }
    | { type: 'link'; value: string; href: string };

const URL_PATTERN = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+\.[^\s<>"']{2,})/g;

/**
 * Split message text into text/link segments the template renders as
 * elements — never v-html, so there is no injection surface at all.
 */
export function linkify(text: string): LinkifySegment[] {
    const segments: LinkifySegment[] = [];
    let cursor = 0;

    for (const match of text.matchAll(URL_PATTERN)) {
        const index = match.index ?? 0;

        if (index > cursor) {
            segments.push({ type: 'text', value: text.slice(cursor, index) });
        }

        // Trailing punctuation reads as prose, not as part of the URL.
        let url = match[0];
        const trailing = url.match(/[.,!?)\]]+$/);

        if (trailing) {
            url = url.slice(0, -trailing[0].length);
        }

        segments.push({
            type: 'link',
            value: url,
            href: url.startsWith('www.') ? `https://${url}` : url,
        });

        cursor = index + url.length;
    }

    if (cursor < text.length) {
        segments.push({ type: 'text', value: text.slice(cursor) });
    }

    return segments;
}
