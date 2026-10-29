import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppCardLayout from '@/layouts/AppCardLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Tickora';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Signed-in people always keep the app sidebar, including while they
    // browse events, check out or confirm their password. Guests see the
    // public website layout.
    layout: (name, page) => {
        const signedIn = Boolean(
            (page.props as { auth?: { user?: unknown } }).auth?.user,
        );

        switch (true) {
            case name.startsWith('public/'):
            case name.startsWith('checkout/'):
                return signedIn ? AppLayout : PublicLayout;
            case name.startsWith('sandbox/'):
                // Stands in for a payment provider's own page: no app chrome.
                return undefined;
            case name === 'auth/ConfirmPassword':
                return signedIn ? AppCardLayout : AuthLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#6D4AFF',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
