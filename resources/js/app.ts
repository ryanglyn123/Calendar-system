import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { initializeTheme } from '@/composables/useAppearance';
import AdminSettingsLayout from '@/layouts/admin/SettingsLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

// Echo (websockets) is created lazily in @/echo.ts by the chat store —
// never at module load, which would break SSR hydration.

const appName = import.meta.env.VITE_APP_NAME || 'FDCP Calendar';

// Admin settings area: every configuration section shares one persistent
// sidebar so switching sections never goes through the hub page.
const adminSettingsArea =
    /^admin\/(settings|calendars|event-types|holidays|working-hours|resource-types|resources|divisions|roles|health|audit|users|invitations)\//;

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case adminSettingsArea.test(name):
                return [AppLayout, AdminSettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.use(createPinia());
    },
    progress: {
        color: '#2A2925',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
