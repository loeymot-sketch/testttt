/**
 * posWizardBridgeExtraQuantity.spec.js
 * -----------------------------------------------------------------------------
 * [VIANDE-SUPPL-CHARGE 2026-07-01 — LOCK_POS_WIZARD_VIANDE_SUPPL_CHARGE_2026-07-01.md]
 *
 * Verrouille le pont non-frozen ItemComponent.onWizardBridgeExtra : il doit
 * lire data-wizard-qty (posé par pos-wizard.js sur la checkbox de l'extra
 * « Viande supplémentaire ») et appliquer setExtraQuantity(N), sinon binaire (1).
 *
 * Régression ciblée : le fantôme « +2,50€/viande affiché mais 0 facturé » est
 * corrigé en sérialisant N viandes suppl. vers l'extra facturé (2,50€ × N).
 */
import { describe, it, expect } from 'vitest';
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

const onWizardBridgeExtra = ItemComponent.methods.onWizardBridgeExtra;

function makeCtx() {
    const calls = [];
    return {
        calls,
        setExtraQuantity(extra, quantity) { this.calls.push({ id: extra.id, quantity }); },
    };
}
function evt(checked, qtyAttr) {
    return {
        target: {
            checked,
            getAttribute: (k) => (k === 'data-wizard-qty' ? qtyAttr : null),
        },
    };
}

describe('ItemComponent.onWizardBridgeExtra — quantité viande supplémentaire', () => {
    it('coché avec data-wizard-qty=3 → setExtraQuantity(extra, 3)', () => {
        const ctx = makeCtx();
        onWizardBridgeExtra.call(ctx, { id: 395, name: 'Viande supplémentaire' }, evt(true, '3'));
        expect(ctx.calls).toEqual([{ id: 395, quantity: 3 }]);
    });

    it('coché sans data-wizard-qty → binaire 1 (Cheddar & co inchangés)', () => {
        const ctx = makeCtx();
        onWizardBridgeExtra.call(ctx, { id: 53, name: 'Cheddar' }, evt(true, null));
        expect(ctx.calls).toEqual([{ id: 53, quantity: 1 }]);
    });

    it('décoché → setExtraQuantity(extra, 0)', () => {
        const ctx = makeCtx();
        onWizardBridgeExtra.call(ctx, { id: 395, name: 'Viande supplémentaire' }, evt(false, '3'));
        expect(ctx.calls).toEqual([{ id: 395, quantity: 0 }]);
    });

    it('data-wizard-qty invalide (0 / NaN) → retombe sur 1', () => {
        const ctx = makeCtx();
        onWizardBridgeExtra.call(ctx, { id: 395, name: 'Viande supplémentaire' }, evt(true, '0'));
        onWizardBridgeExtra.call(ctx, { id: 395, name: 'Viande supplémentaire' }, evt(true, 'abc'));
        expect(ctx.calls).toEqual([{ id: 395, quantity: 1 }, { id: 395, quantity: 1 }]);
    });
});
