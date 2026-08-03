/**
 * posWizardOnionCuit.spec.js
 * -----------------------------------------------------------------------------
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] W3 §B — « Oignons cuits » :
 * l'owner cochait « oignons cuits » en note libre. Nouvel extra gratuit DATA
 * (« Oignons cuits », 0 €) qui matche isCruditeName → toggle crudités, MAIS :
 *
 *  1. défaut NON inclus (contrairement aux crudités classiques incluses par
 *     défaut) — état 'removed' au premier rendu, jamais sérialisé si intouché
 *  2. exclusivité mutuelle avec l'oignon CRU : cocher l'un décoche l'autre
 *     (défaut global = cru coché)
 *  3. aperçu ticket : listé dans GARNITURES seulement si coché ; JAMAIS dans
 *     SANS quand il est simplement à son défaut (off)
 *  4. sérialisation panier (syncAndSubmit → checkboxes Vue) : coche l'extra
 *     « Oignons cuits » et décoche « Oignon » quand le cuit est choisi
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem, tick } from './posWizardHarness.js';

function garnBtn(wizard, key) {
    return wizard.querySelector('.garniture-toggle-btn[data-garniture="' + key + '"]');
}

const KEY_CRU = 'c_51'; // extra « Oignon » (id 51)
const KEY_CUIT = 'c_60'; // extra « Oignons cuits » (id 60)

describe('pos-wizard — oignon cru↔cuit (LOCK_POSWIZARD_KIOSKWIZARD_OWNER8)', () => {
    it('défaut : cru INCLUS, cuit NON inclus (removed)', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        expect(wizard).toBeTruthy();
        expect(garnBtn(wizard, KEY_CRU).classList.contains('included')).toBe(true);
        expect(garnBtn(wizard, KEY_CUIT).classList.contains('removed')).toBe(true);
        expect(garnBtn(wizard, KEY_CUIT).classList.contains('included')).toBe(false);
    });

    it('cocher « Oignons cuits » décoche « Oignon » (et inversement)', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });

        garnBtn(wizard, KEY_CUIT).click();
        await tick(10);
        expect(garnBtn(wizard, KEY_CUIT).classList.contains('included')).toBe(true);
        expect(garnBtn(wizard, KEY_CRU).classList.contains('removed')).toBe(true);

        garnBtn(wizard, KEY_CRU).click();
        await tick(10);
        expect(garnBtn(wizard, KEY_CRU).classList.contains('included')).toBe(true);
        expect(garnBtn(wizard, KEY_CUIT).classList.contains('removed')).toBe(true);
    });

    it('les autres crudités (Salade/Tomates) restent incluses par défaut et insensibles à l\'exclusivité', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        garnBtn(wizard, KEY_CUIT).click();
        await tick(10);
        expect(garnBtn(wizard, 'c_52').classList.contains('included')).toBe(true);
        expect(garnBtn(wizard, 'c_53').classList.contains('included')).toBe(true);
    });

    it('aperçu ticket par défaut : Oignon (cru) listé dans les crudités, « Oignons cuits » ABSENT', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        const ticket = wizard.querySelector('.ticket-content').textContent;
        // buildTicketInstruction : crudités incluses = ligne « - Oignon, Salade, Tomates »
        expect(ticket).not.toContain('Oignons cuits');
        expect(ticket).toMatch(/- [^\n]*Oignon/);
    });

    it('aperçu ticket cuit coché : « Oignons cuits » listé, le cru (Oignon nu) disparaît', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        garnBtn(wizard, KEY_CUIT).click();
        await tick(10);
        const ticket = wizard.querySelector('.ticket-content').textContent;
        expect(ticket).toMatch(/- [^\n]*Oignons cuits/);
        // « Oignon » cru seul (non suivi de « s cuits ») ne doit plus apparaître
        expect(ticket).not.toMatch(/Oignon(?!s cuits)/);
    });

    it('décocher le cuit ne re-coche PAS le cru automatiquement (sans oignon du tout = légitime)', async () => {
        const { wizard } = await mountPosWizard({ itemData: cayenneLikeItem() });
        garnBtn(wizard, KEY_CUIT).click();
        await tick(10);
        garnBtn(wizard, KEY_CUIT).click(); // off
        await tick(10);
        expect(garnBtn(wizard, KEY_CUIT).classList.contains('removed')).toBe(true);
        expect(garnBtn(wizard, KEY_CRU).classList.contains('removed')).toBe(true);
    });

    it('sérialisation panier : cuit choisi → checkbox « Oignons cuits » cochée, « Oignon » décochée', async () => {
        const originalBodyHtml = [
            '<div class="extra"><label>Oignon</label><input type="checkbox" class="custom-checkbox-field" value="51" checked></div>',
            '<div class="extra"><label>Salade</label><input type="checkbox" class="custom-checkbox-field" value="52" checked></div>',
            '<div class="extra"><label>Tomates</label><input type="checkbox" class="custom-checkbox-field" value="53" checked></div>',
            '<div class="extra"><label>Oignons cuits</label><input type="checkbox" class="custom-checkbox-field" value="60"></div>',
            '<div class="extra"><label>Cheddar</label><input type="checkbox" class="custom-checkbox-field" value="61"></div>',
        ].join('');
        const { modal, wizard } = await mountPosWizard({ itemData: cayenneLikeItem(), originalBodyHtml });

        // Compo minimale requise : 1 viande + 1 sauce (canProceedFromStep viande_sauce)
        wizard.querySelector('.viande-btn.plus[data-viande="v_9001"]').click();
        await tick(10);
        wizard.querySelector('[data-type="sauce"][data-id="s_9101"]').click();
        await tick(10);
        // Choix owner : oignons CUITS
        garnBtn(wizard, KEY_CUIT).click();
        await tick(10);

        wizard.querySelector('[data-action="add-to-cart"]').click();
        await tick(500); // syncAndSubmit (sync ~0ms) + submit 180ms + close 200ms

        const cb = (v) => modal.querySelector('.custom-checkbox-field[value="' + v + '"]');
        expect(cb('60').checked, 'Oignons cuits coché').toBe(true);
        expect(cb('51').checked, 'Oignon (cru) décoché').toBe(false);
        expect(cb('52').checked, 'Salade incluse par défaut').toBe(true);
        expect(cb('53').checked, 'Tomates incluses par défaut').toBe(true);
        expect(cb('61').checked, 'Cheddar payant intouché').toBe(false);
    });

    it('sérialisation panier par défaut (intouché) : « Oignons cuits » JAMAIS coché', async () => {
        const originalBodyHtml = [
            '<div class="extra"><label>Oignon</label><input type="checkbox" class="custom-checkbox-field" value="51" checked></div>',
            '<div class="extra"><label>Salade</label><input type="checkbox" class="custom-checkbox-field" value="52" checked></div>',
            '<div class="extra"><label>Tomates</label><input type="checkbox" class="custom-checkbox-field" value="53" checked></div>',
            '<div class="extra"><label>Oignons cuits</label><input type="checkbox" class="custom-checkbox-field" value="60"></div>',
        ].join('');
        const { modal, wizard } = await mountPosWizard({ itemData: cayenneLikeItem(), originalBodyHtml });

        wizard.querySelector('.viande-btn.plus[data-viande="v_9001"]').click();
        await tick(10);
        wizard.querySelector('[data-type="sauce"][data-id="s_9101"]').click();
        await tick(10);
        wizard.querySelector('[data-action="add-to-cart"]').click();
        await tick(500);

        const cb = (v) => modal.querySelector('.custom-checkbox-field[value="' + v + '"]');
        expect(cb('60').checked, 'Oignons cuits reste décoché').toBe(false);
        expect(cb('51').checked, 'Oignon (cru) reste coché').toBe(true);
    });
});
