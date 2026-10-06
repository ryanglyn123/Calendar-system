import type { Auth } from '@/types/auth';
import type { ReviewSummary } from '@/types/desk';

declare module '*.png' {
    const src: string;
    export default src;
}

declare module '*.svg' {
    const src: string;
    export default src;
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            organization: { name: string; acronym: string };
            auth: Auth;
            sidebarOpen: boolean;
            calendarPanelWidth: number | null;
            calendarPanelHidden: boolean;
            googleCalendar: {
                configured: boolean;
                connected: boolean;
                needs_reconnect: boolean;
            } | null;
            reviewQueue: ReviewSummary | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
