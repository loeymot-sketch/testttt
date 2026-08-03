import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = process.cwd();

function read(file) {
    return fs.readFileSync(path.join(root, file), 'utf8');
}

describe('POS wizard composer profile runtime contract', () => {
    it('exposes composer_profile in POS and normal item resources', () => {
        const itemResource = read('app/Http/Resources/ItemResource.php');
        const normalResource = read('app/Http/Resources/NormalItemResource.php');

        expect(itemResource).toContain('"composer_profile"');
        expect(normalResource).toContain('"composer_profile"');
        expect(itemResource).toContain('composerProfilePayload');
        expect(normalResource).toContain('composerProfilePayload');
    });

    it('keeps POS item grids consuming the item payload without a separate heuristic layer', () => {
        const source = read('resources/js/components/admin/pos/PosComponent.vue');

        // [POS-V4-UNIFIED-CATEGORY-VIEW-2026-05-02] La vue landing/best-sellers a été supprimée
        // au profit d'un seul rail catégories + grille produits unifié. La grille consomme
        // donc uniquement `items` (catalogue branch_id-scoped via posCategory + item stores).
        // [GOAL-2026-05-29] F-4 featured-categories first-page filter: the grid now
        // binds the filtered :items="displayedItems" (still the item payload, no
        // separate heuristic layer — line 29 still guards against detectTemplateFromName).
        expect(source).toContain(':items="displayedItems"');
        expect(source).not.toContain('detectTemplateFromName');
    });

    it('keeps the POS-kiosk wrapper delegated to the shared Kiosk wizard runtime', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue');

        expect(source).toContain('<KioskWizardComponent v-bind="$attrs" />');
        expect(source).toContain('import KioskWizardComponent');
    });
});
