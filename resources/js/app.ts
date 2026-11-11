import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'MR Pride';

createInertiaApp({
    title: (title) => (title === 'Page Not Found' ? 'Page Not Found' : (title ? `${title} - ${appName}` : appName)),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'NotFound':
            case name === 'Forbidden':
            case name === 'Maintenance':
            case name === 'Products/Index':
            case name === 'Company/Index':
            case name === 'Contact/Index':
            case name === 'Quotations/Create':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
            case name === 'RecycleBin/Index':
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#F8B803',
        showSpinner: true,
    },
});

// Set light/dark mode on page load
initializeTheme();

// Listen for flash toast data from the server
initializeFlashToast();
