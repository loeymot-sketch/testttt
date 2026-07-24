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

        // [VIANDE-UI 2026-07-23] Structure = tuiles carrées (grande image) au lieu de lignes.
        // L'image réelle (img.viande-img via _renderViandeVisual) est PRÉSERVÉE.
        const rows = Array.from(wizard.querySelectorAll('.wizard-viande-tile'));
        const poulet = rows.find((r) => r.textContent.includes('Poulet mariné'));
        expect(poulet, 'tuile Poulet mariné').toBeTruthy();

        const img = poulet.querySelector('img.viande-img');
        expect(img, 'image réelle rendue').toBeTruthy();
        expect(img.getAttribute('src')).toContain('viande-poulet.png');
    });

    it('variation SANS thumb → repli pastille emoji (aucune <img>)', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        const rows = Array.from(wizard.querySelectorAll('.wizard-viande-tile'));
        const mex = rows.find((r) => r.textContent.includes('Mexicanos'));
        expect(mex, 'tuile Mexicanos').toBeTruthy();
        expect(mex.querySelector('img.viande-img')).toBeNull();
        expect(mex.querySelector('.viande-emoji'), 'pastille emoji conservée').toBeTruthy();
    });

    it('supplément viande UNIFIÉ sur les tuiles : plus de section/toggle séparé', async () => {
        // [VIANDE-SUPPL UNIFIÉ 2026-07-24] Le supplément viande est désormais géré par les MÊMES
        // tuiles (clic au-delà de l'inclus) — l'ancienne section dépliable + toggle sont supprimés.
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        expect(wizard.querySelector('.wizard-viande-suppl-row'), 'ancienne ligne suppl supprimée').toBeNull();
        expect(wizard.querySelector('.viande-suppl-toggle'), 'ancien toggle suppl supprimé').toBeNull();
        // Le hint « au-delà : +2,50 / viande » signale le supplément inline.
        expect(wizard.querySelector('.viande-hint')?.textContent || '').toMatch(/2[.,]50/);
        // Les tuiles viande restent la voie unique (image réelle préservée).
        expect(wizard.querySelectorAll('.wizard-viande-tile').length).toBeGreaterThan(0);
    });
});
