import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationData } from 'pusher-js/types/src/core/auth/options';
import { api } from '@/lib/api';

type ReverbEcho = Echo<'reverb'>;

let instance: ReverbEcho | null = null;

/**
 * Lazy, browser-only Echo singleton. Never constructed at module load:
 * the SSR bundle imports app modules, and touching window there corrupts
 * hydration (see the DarkModeToggle incident).
 */
export function getEcho(): ReverbEcho | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (instance === null) {
        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        instance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS:
                (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            // Route channel authorization through the app's fetch client so
            // the XSRF cookie handling stays in one place.
            authorizer: (channel: { name: string }) => ({
                authorize: (
                    socketId: string,
                    callback: (
                        error: Error | null,
                        data: ChannelAuthorizationData | null,
                    ) => void,
                ) => {
                    api.post<ChannelAuthorizationData>('/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channel.name,
                    })
                        .then((data) => callback(null, data))
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });

        (window as unknown as { Echo: ReverbEcho }).Echo = instance;
    }

    return instance;
}
