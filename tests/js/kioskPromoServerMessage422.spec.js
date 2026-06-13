/**
 * [INT BV-RED-02 2026-06-13] validatePromo — préserver le message serveur 422.
 * -----------------------------------------------------------------------------
 * Constat audit (promo dormante) : quand le serveur refuse un code promo via une
 * réponse de VALIDATION 422 (« Ce code promotionnel n'est pas encore actif. »),
 * axios lève une exception → on tombait dans le catch `else` qui écrasait le
 * message serveur clair par la clef générique 'kiosk.promo.error.server'
 * (« Une erreur est survenue, réessayez. »). Le client ne savait donc PAS
 * pourquoi son code était refusé.
 *
 * Le chemin métier non-exception (status:false, 200) surface déjà
 * res.data.message localisé ; un 422 de validation porte la MÊME sémantique
 * (refus métier explicite) et doit donc aussi surfacer son message.
 *
 * Invariants verrouillés :
 *  1. 422 AVEC message serveur → SET_PROMO_ERROR = ce message verbatim
 *     (le composant rend `$te(promoError) ? $t : promoError` → message brut FR).
 *  2. 422 SANS message → repli sur la clef i18n 'kiosk.promo.error.server'.
 *  3. Les autres exceptions (429 / réseau / 5xx) restent inchangées.
 */
import { describe, it, expect, vi } from 'vitest';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';

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

const promoErrorCommit = (commits) => commits.find((c) => c.type === 'SET_PROMO_ERROR');

async function runWithRejection(rejection) {
    const { ctx, commits } = makeContext();
    const axiosModule = (await import('axios')).default;
    const spy = vi.spyOn(axiosModule, 'post').mockRejectedValueOnce(rejection);
    try {
        const res = await kioskCart.actions.validatePromo(ctx, 'DORMANT10');
        return { res, commits };
    } finally {
        spy.mockRestore();
    }
}

describe('[BV-RED-02] validatePromo — message serveur 422 préservé', () => {
    it('422 avec message → surface le message serveur localisé, PAS la clef générique', async () => {
        const serverMsg = "Ce code promotionnel n'est pas encore actif.";
        const err = new Error('Request failed with status code 422');
        err.response = { status: 422, data: { message: serverMsg } };

        const { res, commits } = await runWithRejection(err);

        expect(res.valid).toBe(false);
        const committed = promoErrorCommit(commits)?.payload;
        expect(committed).toBe(serverMsg);
        expect(committed).not.toBe('kiosk.promo.error.server');
    });

    it('422 sans message → repli sur la clef i18n générique', async () => {
        const err = new Error('Request failed with status code 422');
        err.response = { status: 422, data: {} };

        const { commits } = await runWithRejection(err);
        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.server');
    });

    it('429 reste mappé sur too_many (non-régression)', async () => {
        const err = new Error('429');
        err.response = { status: 429, data: { message: 'Too Many Attempts.' } };
        const { commits } = await runWithRejection(err);
        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.too_many');
    });

    it('coupure réseau reste mappée sur network (non-régression)', async () => {
        const err = new Error('Network Error'); // pas de err.response
        const { commits } = await runWithRejection(err);
        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.network');
    });

    it('5xx reste mappé sur la clef générique (non-régression)', async () => {
        const err = new Error('500');
        err.response = { status: 500, data: { message: 'Server Error' } };
        const { commits } = await runWithRejection(err);
        expect(promoErrorCommit(commits)?.payload).toBe('kiosk.promo.error.server');
    });
});
