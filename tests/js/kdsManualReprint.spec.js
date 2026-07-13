import { describe, it, expect, vi, beforeEach } from 'vitest';

// [KITCHEN-PRINT-RESILIENCE 2026-07-13] alertService mocké : on prouve que la
// réimpression manuelle émet un toast INFO sur succès et ERREUR sur pont injoignable.
vi.mock('../../resources/js/services/alertService', () => ({
  default: { info: vi.fn(), error: vi.fn(), success: vi.fn(), warning: vi.fn(), successFlip: vi.fn() },
}));

import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';
import KdsV2Grid from '../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import { markKitchenPrinted, hasKitchenPrinted, _resetPrintedKitchen, getKitchenFailed, setKitchenFailed } from '../../resources/js/helpers/kitchenLocalPrinter';
import alertService from '../../resources/js/services/alertService';

const flush = () => new Promise((r) => setTimeout(r, 0));

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
        // [SUPERVISOR-HEAL] la réimpression réussie purge la liste d'échec + marque imprimé.
        _kitchenFailedPrint: [],
        _removeKitchenFailed: KitchenDisplaySystemComponent.methods._removeKitchenFailed,
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

// ─────────────────────────────────────────────────────────────────────────────
// [KITCHEN-PRINT-RESILIENCE 2026-07-13] Ticket cuisine jamais perdu en silence :
// échec → NON marqué imprimé + listé en échec ; retry auto ressort le ticket quand
// le pont revient ; succès direct → marqué ; garde in-flight bloque le double-envoi.
const retryFailed = KitchenDisplaySystemComponent.methods.retryFailedKitchenTickets;
const reprintM = KitchenDisplaySystemComponent.methods.reprintKitchenTicket;

function rctx(overrides = {}) {
    return {
        autoPrintKitchen: true,
        _kitchenInFlight: new Set(),
        _kitchenFailedPrint: [],
        orders: [],
        _addKitchenFailed: KitchenDisplaySystemComponent.methods._addKitchenFailed,
        _removeKitchenFailed: KitchenDisplaySystemComponent.methods._removeKitchenFailed,
        autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: true }),
        $t: (k) => k,
        ...overrides,
    };
}

describe('KDS — résilience impression cuisine (jamais perdue en silence)', () => {
    beforeEach(() => {
        _resetPrintedKitchen();
        try { window.localStorage.clear(); } catch (_) { /* noop */ }
        alertService.info.mockClear();
        alertService.error.mockClear();
    });

    it('(a) échec auto-print → id NON marqué imprimé + ajouté à _kitchenFailedPrint', async () => {
        const c = rctx({ autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: false, retriable: true }) });
        autoPrintNew.call(c, [{ id: 8001 }]);
        await flush();
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledTimes(1);
        expect(hasKitchenPrinted(8001)).toBe(false); // PAS marqué → réessayable
        expect(c._kitchenFailedPrint).toContain('8001');
        expect(c._kitchenInFlight.has('8001')).toBe(false); // in-flight relâché
    });

    it('(c) succès direct → id marqué imprimé, absent de _kitchenFailedPrint', async () => {
        const c = rctx({ autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: true }) });
        autoPrintNew.call(c, [{ id: 8002 }]);
        await flush();
        expect(hasKitchenPrinted(8002)).toBe(true);
        expect(c._kitchenFailedPrint).not.toContain('8002');
    });

    it('(b) retry qui réussit → marque + retire de _kitchenFailedPrint', async () => {
        const order = { id: 8003 };
        const c = rctx({
            orders: [order],
            _kitchenFailedPrint: ['8003'],
            autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: true }),
        });
        retryFailed.call(c);
        await flush();
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledWith(order);
        expect(hasKitchenPrinted(8003)).toBe(true);
        expect(c._kitchenFailedPrint).not.toContain('8003');
    });

    it('(d) [SUPERVISOR-HEAL] réimpression MANUELLE réussie → purge _kitchenFailedPrint + marque imprimé (pas de doublon au retry auto)', async () => {
        const order = { id: 8020, queue_number: 'A0040' };
        const c = rctx({ _kitchenFailedPrint: ['8020'], autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: true }) });
        await reprintM.call(c, order);
        // Le ticket est sorti → l'id quitte la liste d'échec ET est marqué imprimé, donc le
        // retry auto (20s) ne ressortira PAS un 2e ticket.
        expect(c._kitchenFailedPrint).not.toContain('8020');
        expect(hasKitchenPrinted(8020)).toBe(true);
    });

    it('(e) [SUPERVISOR-HEAL] échec PERSISTÉ : le seed du backlog n\'imprime PAS un ticket en échec au reload (jamais perdu)', () => {
        setKitchenFailed(['8021']); // un échec avant le reload (persisté localStorage)
        const seed = KitchenDisplaySystemComponent.methods._seedKitchenPrintedBacklogOnce;
        const c = { _kitchenBacklogSeeded: false, _kitchenFailedPrint: [], orders: [{ id: 8021 }, { id: 8022 }] };
        seed.call(c);
        // 8022 (imprimé avant) = seedé « imprimé » ; 8021 (en échec) = EXCLU du seed + restauré
        // dans _kitchenFailedPrint → réessayé par le retry, jamais perdu au reload.
        expect(hasKitchenPrinted(8022)).toBe(true);
        expect(hasKitchenPrinted(8021)).toBe(false);
        expect(c._kitchenFailedPrint).toContain('8021');
    });

    it('(f) [SUPERVISOR-HEAL] liste d\'échec persistée survit au reload (roundtrip localStorage)', () => {
        setKitchenFailed(['1', '2', '3']);
        expect(getKitchenFailed()).toEqual(['1', '2', '3']);
    });

    it('(b2) retry purge les ids de commandes disparues du board (jamais réimprimé)', () => {
        const c = rctx({ orders: [], _kitchenFailedPrint: ['9999'] });
        retryFailed.call(c);
        expect(c.autoPrintKitchenTicket).not.toHaveBeenCalled();
        expect(c._kitchenFailedPrint).not.toContain('9999');
    });

    it('(d) garde in-flight bloque le double-envoi en burst (watch re-tire pendant l\'await)', async () => {
        let resolve;
        const pending = new Promise((r) => { resolve = r; });
        const c = rctx({ autoPrintKitchenTicket: vi.fn().mockReturnValue(pending) });
        autoPrintNew.call(c, [{ id: 8004 }]); // 1er envoi (in-flight)
        autoPrintNew.call(c, [{ id: 8004 }]); // 2e pendant l'await → bloqué
        expect(c.autoPrintKitchenTicket).toHaveBeenCalledTimes(1);
        resolve({ ok: true });
        await flush();
        expect(hasKitchenPrinted(8004)).toBe(true);
    });

    it('(e) reprint échec (pont KO) → toast ERREUR, ne marque rien', async () => {
        const c = ctx({ autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: false, retriable: true }) });
        await reprintM.call(c, { id: 9001, queue_number: 'A0001' });
        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(alertService.info).not.toHaveBeenCalled();
        expect(hasKitchenPrinted(9001)).toBe(false);
    });

    it('(e2) reprint succès → toast INFO, pas d\'erreur', async () => {
        const c = ctx({ autoPrintKitchenTicket: vi.fn().mockResolvedValue({ ok: true }) });
        await reprintM.call(c, { id: 9002, queue_number: 'A0002' });
        expect(alertService.info).toHaveBeenCalledTimes(1);
        expect(alertService.error).not.toHaveBeenCalled();
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
