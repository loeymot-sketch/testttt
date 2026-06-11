/**
 * [UIUX-W4 K4 2026-06-11] — Catégorie vide = cul-de-sac écran blanc.
 *
 * Volet backend : KioskMenuService filtre désormais les catégories sans
 * item visible (tests/Feature/Kiosk/KioskMenuEmptyCategoryTest.php).
 *
 * Volet front (ce spec, défense contre un cache menu stale) :
 * KioskCategoriesComponent doit rendre un état vide FR + CTA retour vers
 * une catégorie peuplée quand `catalogProducts` est vide, au lieu d'une
 * grille blanche sans issue. Vérification source-level (pattern
 * kioskIdleWarningEvent.spec.js — pas de mount du composant lourd vuex)
 * + clés i18n présentes dans les 5 locales.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import ar from '../../resources/js/languages/ar.json';
import de from '../../resources/js/languages/de.json';
import bn from '../../resources/js/languages/bn.json';

const SRC = fs.readFileSync(
    path.join(
        __dirname,
        '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue',
    ),
    'utf8',
);

describe('Kiosk empty-category defensive state (K4)', () => {
    it('renders an empty state when catalogProducts is empty', () => {
        expect(SRC).toContain('data-testid="kiosk-products-empty"');
        expect(SRC).toMatch(/v-if="catalogProducts\.length === 0"/);
        // La grille ne doit rendre que dans la branche non-vide.
        expect(SRC).toMatch(/v-else class="kiosk-product-grid"/);
    });

    it('offers a CTA back to a populated category', () => {
        expect(SRC).toContain('data-testid="kiosk-products-empty-back"');
        expect(SRC).toContain('selectCategory(firstPopulatedCategory)');
        expect(SRC).toContain('firstPopulatedCategory()');
    });

    it('uses i18n keys (no hardcoded copy)', () => {
        expect(SRC).toContain("$t('kiosk.catalog.category_empty')");
        expect(SRC).toContain("$t('kiosk.catalog.category_empty_cta')");
    });

    it('defines kiosk.catalog.category_empty(+_cta) in the 5 locales', () => {
        for (const [code, messages] of Object.entries({ fr, en, ar, de, bn })) {
            expect(messages.kiosk?.catalog?.category_empty, `${code} category_empty`).toBeTruthy();
            expect(messages.kiosk?.catalog?.category_empty_cta, `${code} category_empty_cta`).toBeTruthy();
        }
        // FR réellement FR + EN réellement traduit (pas de stale-copy FR→EN).
        expect(fr.kiosk.catalog.category_empty).toMatch(/Aucun produit/);
        expect(en.kiosk.catalog.category_empty).not.toBe(fr.kiosk.catalog.category_empty);
    });
});
