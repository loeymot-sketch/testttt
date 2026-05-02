import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('auth 401 interceptors', () => {
    it('dispatch root logout action because auth module is not namespaced', () => {
        const files = [
            'resources/js/app.js',
            'resources/js/pos-app.js',
        ];

        for (const file of files) {
            const source = readFileSync(resolve(process.cwd(), file), 'utf8');

            expect(source).toContain("store.dispatch('logout')");
            expect(source).not.toContain("store.dispatch('auth/logout')");
        }
    });
});
