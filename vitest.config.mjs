import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
        globals: true,
        /** Webpack `require.context` (resources/js/i18n.js) — absent sous Vitest. */
        setupFiles: ['./tests/js/kioskRtl-require-context-polyfill.js'],
    },
});
