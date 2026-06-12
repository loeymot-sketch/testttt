/**
 * [dispute-r1 C-ADV-02 2026-06-12] — promo non persistée au reload (asymétrie
 * avec la fidélité) → remise silencieusement perdue.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (C-borne-edge) : c1-05 promo appliquée (total 0,00 €) →
 * reload → c1-09 même panier 3,00 €, ligne promo disparue. Les paths
 * vuex-persistedstate couvrent loyaltyDiscount/loyaltyCustomer mais AUCUNE
 * clef promo.
 *
 * Invariants :
 *  1. validatePromo (succès) persiste le code en localStorage.
 *  2. clearPromo / reset / kioskLogout purgent la persistance.
 *  3. restorePersistedPromo re-VALIDE serveur (jamais de montant rejoué
 *     localement) et seulement si panier non vide + pas de promo en cours.
 *  4. Un code refusé métier (expiré) est purgé — pas de re-tentative à chaque
 *     reload.
 *  5. KioskCartComponent appelle restorePersistedPromo au mount.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import axios from 'axios';
import { kioskCart, KIOSK_PROMO_STORAGE_KEY } from '../../resources/js/store/modules/kioskCart.js';

function makeContext(items = [{ item_id: 1, quantity: 1, convert_price: 10, total: 10 }]) {
    const commits = [];
    return {
        commits,
        ctx: {
            commit: (type, payload) => commits.push({ type, payload }),
            getters: { subtotal: 10 },
            state: { ...kioskCart.state, items, promoCode: null },
            dispatch: vi.fn(),
        },
    };
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('[C-ADV-02] persistance du code promo', () => {
    it('validatePromo succès → code persisté', async () => {
        const spy = vi.spyOn(axios, 'post').mockResolvedValueOnce({
            data: { status: true, message: 'OK', data: { discount_amount: 2, type: 'amount' } },
        });
        const { ctx } = makeContext();
        const res = await kioskCart.actions.validatePromo(ctx, 'BORNE10');
        spy.mockRestore();

        expect(res.valid).toBe(true);
        expect(window.localStorage.getItem(KIOSK_PROMO_STORAGE_KEY)).toBe('BORNE10');
    });

    it('code refusé métier → persistance purgée (pas de re-tentative en boucle)', async () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'EXPIRE10');
        const spy = vi.spyOn(axios, 'post').mockResolvedValueOnce({
            data: { status: false, message: 'Code expiré' },
        });
        const { ctx } = makeContext();
        const res = await kioskCart.actions.validatePromo(ctx, 'EXPIRE10');
        spy.mockRestore();

        expect(res.valid).toBe(false);
        expect(window.localStorage.getItem(KIOSK_PROMO_STORAGE_KEY)).toBeNull();
    });

    it('clearPromo purge la persistance', () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'BORNE10');
        const { ctx } = makeContext();
        kioskCart.actions.clearPromo(ctx);
        expect(window.localStorage.getItem(KIOSK_PROMO_STORAGE_KEY)).toBeNull();
    });

    it('reset (fin de parcours) purge la persistance', () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'BORNE10');
        const { ctx } = makeContext();
        kioskCart.actions.reset(ctx);
        expect(window.localStorage.getItem(KIOSK_PROMO_STORAGE_KEY)).toBeNull();
    });
});

describe('[C-ADV-02] restorePersistedPromo — re-validation serveur', () => {
    it('panier non vide + code persisté → dispatch validatePromo(code)', async () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'BORNE10');
        const { ctx } = makeContext();
        await kioskCart.actions.restorePersistedPromo(ctx);
        expect(ctx.dispatch).toHaveBeenCalledWith('validatePromo', 'BORNE10');
    });

    it('panier vide → no-op', async () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'BORNE10');
        const { ctx } = makeContext([]);
        const res = await kioskCart.actions.restorePersistedPromo(ctx);
        expect(res).toBeNull();
        expect(ctx.dispatch).not.toHaveBeenCalled();
    });

    it('promo déjà appliquée → no-op (pas de double validation)', async () => {
        window.localStorage.setItem(KIOSK_PROMO_STORAGE_KEY, 'BORNE10');
        const { ctx } = makeContext();
        ctx.state.promoCode = 'BORNE10';
        const res = await kioskCart.actions.restorePersistedPromo(ctx);
        expect(res).toBeNull();
        expect(ctx.dispatch).not.toHaveBeenCalled();
    });

    it('rien de persisté → no-op', async () => {
        const { ctx } = makeContext();
        const res = await kioskCart.actions.restorePersistedPromo(ctx);
        expect(res).toBeNull();
    });
});

describe('[C-ADV-02] câblage composant panier', () => {
    it('KioskCartComponent appelle restorePersistedPromo au mount', () => {
        const src = readFileSync(
            resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskCartComponent.vue'),
            'utf-8',
        );
        expect(src).toMatch(/restorePersistedPromo/);
        expect(src).toMatch(/mounted\(\)\s*{[\s\S]*restorePersistedPromo/);
    });
});
