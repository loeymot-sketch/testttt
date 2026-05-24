import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    /**
     * L5.1 — resolve.extensions élargi pour matcher la résolution webpack
     * de prod : certains composants (OSS, KDS, admin) utilisent des imports
     * sans suffixe (`from "./Foo"`). Sans cet override, Vite/Vitest échoue
     * sur Failed-to-resolve. Aucun impact prod (webpack non concerné).
     */
    resolve: {
        extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json', '.vue'],
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
        globals: true,
        /** Webpack `require.context` (resources/js/i18n.js) — absent sous Vitest. */
        setupFiles: ['./tests/js/kioskRtl-require-context-polyfill.js'],
    },
});
