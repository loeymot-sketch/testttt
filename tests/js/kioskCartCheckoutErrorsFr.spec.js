/**
 * [dispute-r1 D-002 + C-ADV-01 2026-06-12] — erreurs réseau/throttle FR sur le
 * chemin panier borne.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial :
 *  - D-002 (P1) : checkout panier offline → toast brut « Network Error » (EN)
 *    (KioskCartComponent.proceedToUpsell catch). Le mapping FR n'existait que
 *    sur l'écran paiement (heal K10, périmètre trop étroit).
 *  - C-ADV-01 (P1) : 429 sur frontend/promo/validate → inline brut
 *    « Too Many Attempts. » (EN) sous le champ code promo
 *    (kioskCart.validatePromo catch → err.response.data.message verbatim).
 *
 * Invariants verrouillés :
 *  1. validatePromo mappe 429 → 'kiosk.promo.error.too_many',
 *     pas-de-response → 'kiosk.promo.error.network',
 *     autre exception serveur → 'kiosk.promo.error.server'
 *     (plus AUCUN message d'exception framework verbatim).
 *  2. Les clefs kiosk.promo.error.* existent en FR (elles étaient référencées
 *     mais ABSENTES de fr.json → fuite de clef brute possible).
 *  3. proceedToUpsell mappe les erreurs réseau → kiosk.network_lost_cart (FR)
 *     et 429 → error.kiosk_rate_limited, sans toucher au panier.
 */
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

function makeContext(subtotal = 10) {
    const commits = [];
    return {
        commits,
        ctx: {
            commit: (type, payload) => commits.push({ type, payload }),
            getters: { subtotal },
            state: { ...kioskCart.state },
        },
    };
}

function promoErrorCommit(commits) {
    return commits.find((c) => c.type === 'SET_PROMO_ERROR');
}

describe('[C-ADV-01] validatePromo — exceptions HTTP mappées vers des clefs i18n', () => {
    async function runWithRejection(rejection) {
        const { ctx, commits } = makeContext();
        const original = globalThis.window?.axios;
        // validatePromo utilise le module axios importé ; on intercepte au
        // niveau du prototype partagé : axios.post est la même fonction
        // référencée par l'import du module dans jsdom (setup expose axios).
        const axiosModule = (await import('axios')).default;
        const spy = vi.spyOn(axiosModule, 'post').mockRejectedValueOnce(rejection);
        try {
            const res = await kioskCart.actions.validatePromo(ctx, 'BORNE10');
            return { res, commits };
        } finally {
            spy.mockRestore();
            if (original !== undefined) globalThis.window.axios = original;
        }
    }

    it('429 → kiosk.promo.error.too_many (jamais « Too Many Attempts. » verbatim)', async () => {
        const err = new Error('Request failed with status code 429');
        err.response = { status: 429, data: { message: 'Too Many Attempts.' } };
        const { res, commits } = await runWithRejection(err);

        expect(res.valid).toBe(false);
        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.too_many');
    });

    it('coupure réseau (pas de response) → kiosk.promo.error.network', async () => {
        const err = new Error('Network Error');
        err.code = 'ERR_NETWORK';
        const { commits } = await runWithRejection(err);

        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.network');
    });

    it('500 → kiosk.promo.error.server (pas de message framework verbatim)', async () => {
        const err = new Error('Request failed with status code 500');
        err.response = { status: 500, data: { message: 'Server Error' } };
        const { commits } = await runWithRejection(err);

        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.server');
    });
});

describe('[C-ADV-01] clefs kiosk.promo.error.* présentes (FR + parité EN)', () => {
    const KEYS = ['empty', 'invalid', 'network', 'too_many', 'server'];

    it.each(KEYS)('fr.json kiosk.promo.error.%s existe et est FR', (key) => {
        const value = fr?.kiosk?.promo?.error?.[key];
        expect(typeof value).toBe('string');
        expect(value.length).toBeGreaterThan(3);
    });

    it.each(KEYS)('en.json kiosk.promo.error.%s existe (parité)', (key) => {
        const value = en?.kiosk?.promo?.error?.[key];
        expect(typeof value).toBe('string');
    });

    it('le mapping 429 FR parle bien d’attente (copy validée verdict)', () => {
        expect(fr.kiosk.promo.error.too_many).toMatch(/tentatives/i);
        expect(fr.kiosk.promo.error.too_many).toMatch(/patientez/i);
    });
});

describe('[D-002] proceedToUpsell — erreur réseau au checkout panier → FR + panier conservé', () => {
    function makeVm({ quoteRejection }) {
        const toasts = [];
        const vm = {
            quoteLoading: false,
            cartCount: 2,
            quoteError: null,
            upsellShown: false,
            shouldSkipKioskUpsell: false,
            orderType: 10,
            pruneUnavailableLines: vi.fn(),
            quoteOrder: vi.fn().mockRejectedValue(quoteRejection),
            markUpsellShown: vi.fn(),
            showToast: (msg, type, ttl) => toasts.push({ msg, type, ttl }),
            $router: { push: vi.fn() },
            $t: (key) => `i18n:${key}`,
        };
        return { vm, toasts };
    }

    it('coupure réseau → message FR kiosk.network_lost_cart, jamais « Network Error » brut', async () => {
        const err = new Error('Network Error');
        err.code = 'ERR_NETWORK';
        err.request = {};
        const { vm, toasts } = makeVm({ quoteRejection: err });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.quoteError).toBe('i18n:kiosk.network_lost_cart');
        expect(toasts[0]?.msg).toBe('i18n:kiosk.network_lost_cart');
        expect(vm.quoteError).not.toMatch(/Network Error/);
        // Aucune navigation ni reset : le panier reste intact à l'écran.
        expect(vm.$router.push).not.toHaveBeenCalled();
        expect(vm.quoteLoading).toBe(false);
    });

    it('429 quote → copy FR error.kiosk_rate_limited (pas « Too Many Attempts. »)', async () => {
        const err = new Error('Request failed with status code 429');
        err.response = { status: 429, data: { message: 'Too Many Attempts.' } };
        const { vm } = makeVm({ quoteRejection: err });

        await KioskCartComponent.methods.proceedToUpsell.call(vm);

        expect(vm.quoteError).toBe('i18n:error.kiosk_rate_limited');
    });

    it('la clef FR kiosk.network_lost_cart existe et mentionne la conservation du panier', () => {
        expect(typeof fr.kiosk.network_lost_cart).toBe('string');
        expect(fr.kiosk.network_lost_cart).toMatch(/panier/i);
        expect(typeof en.kiosk.network_lost_cart).toBe('string');
    });
});
