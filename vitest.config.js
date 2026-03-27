import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
        globals: true,
    },
});
