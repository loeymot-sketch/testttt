import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        // Laravel-mix permits extensionless `.vue` imports
        // (e.g. `import LoadingComponent from "../components/LoadingComponent"`).
        // Vite/vitest by default does NOT auto-resolve `.vue`; we extend
        // the standard list to keep parity with prod build.
        extensions: ['.mjs', '.js', '.mts', '.ts', '.jsx', '.tsx', '.json', '.vue'],
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
        globals: true,
    },
});
