import { describe, it, expect, vi, beforeEach } from 'vitest';
import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';
import KdsV2Grid from '../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import { markKitchenPrinted, hasKitchenPrinted } from '../../resources/js/helpers/kitchenLocalPrinter';

// [KDS-REPRINT 2026-07-13] La réimpression MANUELLE du ticket cuisine doit TOUJOURS
// ressortir le ticket : elle appelle autoPrintKitchenTicket() DIRECTEMENT, donc elle
// n'est NI gatée par le toggle « Impression auto » (autoPrintKitchen), NI soumise à
// la dé-dup (hasKitchenPrinted) qui vit dans autoPrintNewKitchenTickets.
const reprint = KitchenDisplaySystemComponent.methods.reprintKitchenTicket;
const autoPrintNew = KitchenDisplaySystemComponent.methods.autoPrintNewKitchenTickets;

function ctx(overrides = {}) {
    return {
        autoPrintKitchen: true,
        autoPrintKitchenTicket: vi.fn().mockResolvedValue(undefined),
        // stub i18n + toast (retour visuel best-effort)
        $t: (k) => k,
        ...overrides,
    };
}

describe('KDS — réimpression MANUELLE du ticket cuisine (bouton 🖨️)', () => {
    beforeEach(() => {
        try { window.localStorage.clear(); } catch (_) { /* noop */ }
    });

    it('réimprime MÊME si le toggle « Impression auto » est OFF', async () => {
        const c = ctx({ autoPrintKitchen: false });
        await reprint.call(c, { id: 5561, queue_number: 'A0033' });
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledTimes(1);
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledWith({ id: 5561, queue_number: 'A0033' });
    });

    it('réimprime MÊME si la commande est déjà marquée imprimée (bypass dé-dup)', async () => {
        markKitchenPrinted(5561);
        expect(hasKitchenPrinted(5561)).toBe(true); // déjà imprimée
        const c = ctx();
        await reprint.call(c, { id: 5561, queue_number: 'A0033' });
        // La réimpression ressort quand même le ticket.
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledTimes(1);
    });

    it('CONTRE-EXEMPLE : autoPrintNewKitchenTickets, lui, N\'imprime PAS dans ces conditions', () => {
        // (a) toggle OFF → aucun envoi
        const cOff = ctx({ autoPrintKitchen: false });
        autoPrintNew.call(cOff, [{ id: 7001 }]);
        expect(cOff.autoPrintKitchenTicket).not.toHaveBeenCalled();
        // (b) toggle ON mais id déjà imprimé → dé-dup bloque
        markKitchenPrinted(7002);
        const cDup = ctx({ autoPrintKitchen: true });
        autoPrintNew.call(cDup, [{ id: 7002 }]);
        expect(cDup.autoPrintKitchenTicket).not.toHaveBeenCalled();
    });

    it('ignore un ordre invalide (pas d\'id) sans throw', async () => {
        const c = ctx();
        await reprint.call(c, null);
        await reprint.call(c, { queue_number: 'X' });
        expect(c.autoPrintKitchenTicket).not.toHaveBeenCalled();
    });

    it('le retour visuel (toast) ne bloque JAMAIS l\'impression si alertService jette', async () => {
        const c = ctx({ $t: () => { throw new Error('i18n boom'); } });
        await expect(reprint.call(c, { id: 42, queue_number: 'A0001' })).resolves.toBeUndefined();
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledTimes(1);
    });
});

describe('KDS — câblage de l\'événement reprint (carte V2 → grille → orchestrateur)', () => {
    it('KdsOrderCard déclare et peut émettre \'reprint\'', () => {
        expect(KdsOrderCard.emits).toContain('reprint');
    });
    it('KdsV2Grid relaie \'reprint\' avec l\'ordre', () => {
        expect(KdsV2Grid.emits).toContain('reprint');
    });
});
