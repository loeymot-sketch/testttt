/**
 * kioskWizardOnionCuit.spec.js
 * -----------------------------------------------------------------------------
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] Côté BORNE — même contrat que
 * le wizard caisse :
 *  - initGarnitures : « Oignons cuits » démarre NON inclus (défaut = cru coché)
 *  - updateSelection('garnitures', …) : cocher cuit décoche cru et inversement
 *  - le step Garnitures (non-frozen) applique la même exclusivité localement
 *
 * Tests unitaires sur les méthodes (pas de mount complet — le wizard borne est
 * couvert par sa suite dédiée, non régressée par ailleurs).
 */
import { describe, it, expect } from 'vitest';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepGarnitures from '../../resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue';

const M = KioskWizardComponent.methods;

const EXTRAS = [
    { id: 51, name: 'Oignon', convert_price: 0 },
    { id: 52, name: 'Salade', convert_price: 0 },
    { id: 60, name: 'Oignons cuits', convert_price: 0 },
    { id: 61, name: 'Cheddar', convert_price: 1 },
    { id: 62, name: 'Oignons frits', convert_price: 0.9 },
];

function ctx(garnitures = {}) {
    return {
        resolvedItem: { extras: EXTRAS },
        selections: { garnitures },
        serverPreviewTotal: null,
        isOnionCuitExtraName: M.isOnionCuitExtraName,
        applyOnionCruCuitExclusivity: M.applyOnionCruCuitExclusivity,
    };
}

describe('KioskWizardComponent — oignon cru↔cuit (LOCK_POSWIZARD_KIOSKWIZARD_OWNER8)', () => {
    it('initGarnitures : cru/salade inclus par défaut, « Oignons cuits » NON inclus, payants ignorés', () => {
        const c = ctx();
        M.initGarnitures.call(c);
        expect(c.selections.garnitures[51]).toBe(true);
        expect(c.selections.garnitures[52]).toBe(true);
        expect(c.selections.garnitures[60]).toBe(false);
        expect(c.selections.garnitures).not.toHaveProperty('61'); // payant ≠ garniture
        expect(c.selections.garnitures).not.toHaveProperty('62');
    });

    it('isOnionCuitExtraName : cuits oui, cru/frits non', () => {
        expect(M.isOnionCuitExtraName('Oignons cuits')).toBe(true);
        expect(M.isOnionCuitExtraName('oignon cuit')).toBe(true);
        expect(M.isOnionCuitExtraName('Oignon')).toBe(false);
        expect(M.isOnionCuitExtraName('Oignons frits')).toBe(false);
    });

    it('updateSelection(garnitures) : cocher cuit décoche cru', () => {
        const c = ctx({ 51: true, 52: true, 60: false });
        M.updateSelection.call(c, 'garnitures', { 51: true, 52: true, 60: true });
        expect(c.selections.garnitures[60]).toBe(true);
        expect(c.selections.garnitures[51]).toBe(false, 'cru décoché par exclusivité');
        expect(c.selections.garnitures[52]).toBe(true, 'salade intacte');
    });

    it('updateSelection(garnitures) : re-cocher cru décoche cuit (retour au défaut)', () => {
        const c = ctx({ 51: false, 52: true, 60: true });
        M.updateSelection.call(c, 'garnitures', { 51: true, 52: true, 60: true });
        expect(c.selections.garnitures[51]).toBe(true);
        expect(c.selections.garnitures[60]).toBe(false);
    });

    it('décocher (rien de nouvellement coché) → aucune exclusivité appliquée', () => {
        const c = ctx({ 51: true, 52: true, 60: false });
        M.updateSelection.call(c, 'garnitures', { 51: false, 52: true, 60: false });
        expect(c.selections.garnitures[51]).toBe(false);
        expect(c.selections.garnitures[60]).toBe(false, 'cuit ne se re-coche pas tout seul');
    });

    it('« Oignons frits » (payant, supplément) n\'est jamais affecté par l\'exclusivité', () => {
        const c = ctx({ 51: true, 60: false });
        M.updateSelection.call(c, 'garnitures', { 51: true, 60: true, 62: true });
        expect(c.selections.garnitures[62]).toBe(true, 'frits payant hors du groupe exclusif');
    });
});

describe('KioskStepGarnitures — exclusivité locale (UI immédiate, non-frozen)', () => {
    function stepCtx(local) {
        const emitted = [];
        return {
            emitted,
            localSelections: { ...local },
            garnitureList: [
                { id: 51, name: 'Oignon', is_available: true },
                { id: 52, name: 'Salade', is_available: true },
                { id: 60, name: 'Oignons cuits', is_available: true },
            ],
            garnitureFilterAllowed: () => true,
            isGarnitureOos: () => false,
            userInteracted: false,
            $emit(event, key, value) { this.emitted.push({ event, key, value }); },
        };
    }
    const toggle = KioskStepGarnitures.methods.toggleGarniture;

    it('taper « Oignons cuits » coche cuit ET décoche cru localement + émet la map exclusive', () => {
        const c = stepCtx({ 51: true, 52: true, 60: false });
        toggle.call(c, 60);
        expect(c.localSelections[60]).toBe(true);
        expect(c.localSelections[51]).toBe(false);
        expect(c.localSelections[52]).toBe(true);
        expect(c.emitted[0].value).toEqual({ 51: false, 52: true, 60: true });
    });

    it('re-taper « Oignon » (cru) décoche cuit', () => {
        const c = stepCtx({ 51: false, 52: true, 60: true });
        toggle.call(c, 51);
        expect(c.localSelections[51]).toBe(true);
        expect(c.localSelections[60]).toBe(false);
    });
});
