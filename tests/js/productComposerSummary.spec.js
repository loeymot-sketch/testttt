import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const productComposerSummaryPath = resolve(
    process.cwd(),
    'resources/js/components/admin/items/ProductComposerSummaryComponent.vue',
);
const itemShowPath = resolve(
    process.cwd(),
    'resources/js/components/admin/items/ItemShowComponent.vue',
);

const readSource = (path) => readFileSync(path, 'utf8');

describe('ProductComposerSummaryComponent static contract', () => {
    it('exposes the composition summary and backend pricing authority', () => {
        const source = readSource(productComposerSummaryPath);

        expect(source).toContain('Composition');
        expect(source).toContain('PricingService');
        expect(source.toLowerCase()).toContain('variations');
        expect(source.toLowerCase()).toContain('extras');
        expect(source.toLowerCase()).toContain('addons');
    });

    it('surfaces composer selection constraints from backend payload fields', () => {
        const source = readSource(productComposerSummaryPath);

        expect(source).toContain('min_select');
        expect(source).toContain('max_select');
        expect(source).toContain('allow_repeat');
    });

    it('wires ItemShowComponent to the composition tab and summary component', () => {
        const source = readSource(itemShowPath);

        expect(source).toContain('#composition');
        expect(source).toMatch(
            /import\s+ProductComposerSummaryComponent\s+from\s+["']\.\/ProductComposerSummaryComponent(?:\.vue)?["'];?/,
        );
    });
});
