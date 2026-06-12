/**
 * [HEAL dispute-r3 C-R2-NEW-2 2026-06-12] — rupture produit en session borne.
 * -----------------------------------------------------------------------------
 * Round-2 adversarial (C30) : item passé indisponible mi-session → checkout
 * rejeté en boucle « Article 34 indisponible dans le catalogue. Commande
 * rejetée. » — ID interne exposé, ligne JAMAIS marquée/retirée, 2e checkout =
 * même boucle (cul-de-sac sur le chemin d'achat).
 *
 * Invariants verrouillés :
 *  1. Mutation MARK_LINE_UNAVAILABLE : marque toutes les lignes du même
 *     item_id + invalide l'orderQuote.
 *  2. proceedToUpsell sur 422 { code: ITEM_UNAVAILABLE, item_id, item_name } :
 *     dispatch markLineUnavailable + message FR avec le NOM + aucune
 *     navigation (panier conservé).
 *  3. Re-checkout BLOQUÉ tant qu'une ligne marquée reste (pas de re-quote en
 *     boucle) — message explicite.
 *  4. Clefs i18n FR présentes + parité EN.
 */
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

describe('[C-R2-NEW-2] MARK_LINE_UNAVAILABLE — marquage de ligne en rupture', () => {
    function makeState() {
        return {
            ...kioskCart.state,
            items: [
                { item_id: 34, name: 'Grande Frites', quantity: 1, total: 4.0 },
                { item_id: 35, name: 'Coca-Cola 33cl', quantity: 2, total: 3.0 },
                { item_id: 34, name: 'Grande Frites', quantity: 1, total: 4.0, instruction: 'sans sel' },
            ],
            orderQuote: { total_ttc: 11.0 },
        };
    }

    it('marque TOUTES les lignes du même item_id et invalide le quote', () => {
        const state = makeState();
        kioskCart.mutations.MARK_LINE_UNAVAILABLE(state, 34);

        expect(state.items[0].unavailable).toBe(true);
        expect(state.items[2].unavailable).toBe(true);
        expect(state.items[1].unavailable).toBeFalsy();
        expect(state.orderQuote).toBeNull();
    });

    it('item_id inconnu → aucun marquage, pas de crash', () => {
        const state = makeState();
        kioskCart.mutations.MARK_LINE_UNAVAILABLE(state, 999);
        expect(state.items.every((l) => !l.unavailable)).toBe(true);
    });

    it("l'action markLineUnavailable commit la mutation", () => {
        const commits = [];
        kioskCart.actions.markLineUnavailable({ commit: (t, p) => commits.push({ t, p }) }, 34);
        expect(commits).toContainEqual({ t: 'MARK_LINE_UNAVAILABLE', p: 34 });
    });
});

describe('[C-R2-NEW-2] proceedToUpsell — 422 ITEM_UNAVAILABLE structuré', () => {
    function makeVm({ quoteRejection, cartItems } = {}) {
        const toasts = [];
        const vm = {
            quoteLoading: false,
            cartCount: (cartItems || [{}]).length,
            cartItems: cartItems || [{ item_id: 34, name: 'Grande Frites' }],
            quoteError: null,
            upsellShown: false,
            shouldSkipKioskUpsell: false,
            orderType: 10,
            pruneUnavailableLines: vi.fn(),
            markLineUnavailable: vi.fn(),
            quoteOrder: quoteRejection ? vi.fn().mockRejectedValue(quoteRejection) : vi.fn().mockResolvedValue({}),
            markUpsellShown: vi.fn(),
            showToast: (msg, type, ttl) => toasts.push({ msg, type, ttl }),
            $router: { push: vi.fn() },
            $t: (key, params) => (params && params.name ? `i18n:${key}:${params.name}` : `i18n:${key}`),
        };
        return { vm, toasts };
    }

    it('marque la ligne fautive + message FR avec le NOM + aucune navigation', async () => {
        const err = new Error('Request failed with status code 422');
        err.response = {
            status: 422,
            data: {
                status: false,
                code: 'ITEM_UNAVAILABLE',
                item_id: 34,
                item_name: 'Grande Frites',
                message: '« Grande Frites » est indisponible pour le moment. Retirez cet article du panier pour continuer.',
            },
        };
        const { vm, toasts } = makeVm({ quoteRejection: err });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.markLineUnavailable).toHaveBeenCalledWith(34);
        expect(vm.quoteError).toBe('i18n:kiosk.item_unavailable_in_cart:Grande Frites');
        expect(toasts[0]?.msg).toContain('Grande Frites');
        expect(vm.quoteError).not.toMatch(/Article 34/);
        expect(vm.$router.push).not.toHaveBeenCalled();
        expect(vm.quoteLoading).toBe(false);
    });

    it('422 ITEM_UNAVAILABLE sans item_name → fallback message backend (déjà FR + nom)', async () => {
        const err = new Error('Request failed with status code 422');
        err.response = {
            status: 422,
            data: {
                code: 'ITEM_UNAVAILABLE',
                item_id: 34,
                item_name: null,
                message: 'Un article du panier (réf. 34) est introuvable au catalogue. Retirez-le pour continuer.',
            },
        };
        const { vm } = makeVm({ quoteRejection: err });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.markLineUnavailable).toHaveBeenCalledWith(34);
        expect(vm.quoteError).toBe('Un article du panier (réf. 34) est introuvable au catalogue. Retirez-le pour continuer.');
    });

    it('re-checkout BLOQUÉ tant qu\'une ligne marquée reste (pas de re-quote en boucle)', async () => {
        const { vm, toasts } = makeVm({
            cartItems: [
                { item_id: 34, name: 'Grande Frites', unavailable: true },
                { item_id: 35, name: 'Coca-Cola 33cl' },
            ],
        });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.quoteOrder).not.toHaveBeenCalled();
        expect(vm.quoteError).toBe('i18n:kiosk.unavailable_line_blocking');
        expect(toasts.length).toBeGreaterThan(0);
        expect(vm.$router.push).not.toHaveBeenCalled();
        expect(vm.quoteLoading).toBe(false);
    });

    it('422 générique (sans code) → comportement historique inchangé (message verbatim)', async () => {
        const err = new Error('Request failed with status code 422');
        err.response = { status: 422, data: { message: 'Quote expirée.' } };
        const { vm } = makeVm({ quoteRejection: err });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.markLineUnavailable).not.toHaveBeenCalled();
        expect(vm.quoteError).toBe('Quote expirée.');
    });
});

describe('[C-R2-NEW-2] clefs i18n présentes (FR + parité EN)', () => {
    const KEYS = ['item_unavailable_in_cart', 'unavailable_line_blocking', 'unavailable_badge', 'unavailable_remove_cta'];

    it.each(KEYS)('fr.json kiosk.%s existe et est FR', (key) => {
        const value = fr?.kiosk?.[key];
        expect(typeof value).toBe('string');
        expect(value.length).toBeGreaterThan(3);
    });

    it.each(KEYS)('en.json kiosk.%s existe (parité)', (key) => {
        const value = en?.kiosk?.[key];
        expect(typeof value).toBe('string');
    });

    it('la copy FR du marquage porte le nom et demande le retrait', () => {
        expect(fr.kiosk.item_unavailable_in_cart).toMatch(/\{name\}/);
        expect(fr.kiosk.item_unavailable_in_cart).toMatch(/retirez/i);
        expect(fr.kiosk.unavailable_badge.length).toBeLessThan(20);
    });
});
