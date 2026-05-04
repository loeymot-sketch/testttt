import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('Legacy admin items root redirect to Studio', () => {
    it('itemRoutes parent redirects to admin.items.studio', () => {
        const content = readFileSync(
            resolve(process.cwd(), 'resources/js/router/modules/itemRoutes.js'),
            'utf-8',
        );
        expect(content).toMatch(/redirect[\s\S]*admin\.items\.studio/);
    });
});
