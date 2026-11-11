import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Front-end unit tests (tests/js). Kept apart from vite.config.ts, whose Laravel and Wayfinder
// plugins need the PHP app and are not needed to test components.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.ts'],
    },
});
