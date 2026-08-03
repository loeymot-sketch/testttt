import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
    path.join(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8'
);

// [OWNER8-W2 2026-07-06] Le catalogue boissons du POS doit SURVIVRE aux re-fetch
// d'items par catégorie (clic « Sandwichs » vidait item/lists de toute boisson →
// data-pos-drinks-catalog="[]" → le wizard n'avait aucune liste à proposer).
describe('POS drinksCatalog persistence contract', () => {
    it('serves the last non-empty snapshot when the live derivation is empty', () => {
        expect(source).toContain('drinksCatalogLive');
        expect(source).toContain('drinksCatalogCache');
        expect(source).toMatch(/if \(live\.length > 0\) return live;\s*\n\s*return this\.drinksCatalogCache;/);
    });

    it('caches every non-empty live snapshot via a watcher (read-only, no pricing)', () => {
        expect(source).toMatch(/drinksCatalogLive\(list\) \{\s*\n\s*if \(Array\.isArray\(list\) && list\.length > 0\) \{\s*\n\s*this\.drinksCatalogCache = list;/);
    });

    it('still feeds the wizard through the DOM attribute wiring', () => {
        expect(source).toContain(':drinks-catalog="drinksCatalog"');
    });
});
