import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('staff-only login landing', () => {
    it('uses the authenticated role landing instead of hardcoding dashboard', () => {
        const source = readFileSync(resolve(process.cwd(), 'resources/js/router/index.js'), 'utf8');

        expect(source).toContain('const authenticatedStaffLanding');
        expect(source).toContain('store.getters.authDefaultPermission?.url');
        expect(source).toContain('isStaffOnly() ? authenticatedStaffLanding() : { name: "frontend.home" }');
        expect(source).not.toContain('isStaffOnly() ? "admin.dashboard" : "frontend.home"');
    });
});
