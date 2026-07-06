/**
 * posWizardMeatImages.spec.js
 * -----------------------------------------------------------------------------
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] §4.4 — mandat owner « Mets-moi
 * des images réelles » : les cartes viande du wizard caisse affichent l'image
 * réelle de la variation quand elle existe (ItemVariation::thumb →
 * config/menu_images.viandes, visuels owner 2026-06-24), sinon repli pastille
 * emoji EXACTEMENT comme avant (aucune invention).
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem } from './posWizardHarness.js';

describe('pos-wizard — images réelles viandes (LOCK_POSWIZARD_KIOSKWIZARD_OWNER8)', () => {
    it('variation avec thumb → <img.viande-img> rendue avec la vraie source', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        expect(wizard).toBeTruthy();

        const rows = Array.from(wizard.querySelectorAll('.wizard-viande-row'));
        const poulet = rows.find((r) => r.textContent.includes('Poulet mariné'));
        expect(poulet, 'ligne Poulet mariné').toBeTruthy();

        const img = poulet.querySelector('img.viande-img');
        expect(img, 'image réelle rendue').toBeTruthy();
        expect(img.getAttribute('src')).toContain('viande-poulet.png');
    });

    it('variation SANS thumb → repli pastille emoji (aucune <img>)', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        const rows = Array.from(wizard.querySelectorAll('.wizard-viande-row'));
        const mex = rows.find((r) => r.textContent.includes('Mexicanos'));
        expect(mex, 'ligne Mexicanos').toBeTruthy();
        expect(mex.querySelector('img.viande-img')).toBeNull();
        expect(mex.querySelector('.viande-emoji'), 'pastille emoji conservée').toBeTruthy();
    });

    it('lignes viande supplémentaire : même règle image/repli', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        const supplRows = Array.from(wizard.querySelectorAll('.wizard-viande-suppl-row'));
        expect(supplRows.length).toBeGreaterThan(0);

        const poulet = supplRows.find((r) => r.textContent.includes('Poulet mariné'));
        expect(poulet.querySelector('img.viande-img')).toBeTruthy();

        const mex = supplRows.find((r) => r.textContent.includes('Mexicanos'));
        expect(mex.querySelector('img.viande-img')).toBeNull();
        expect(mex.querySelector('.viande-emoji')).toBeTruthy();
    });
});
