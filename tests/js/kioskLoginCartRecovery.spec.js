/**
 * [dispute-r1 C-ADV-08 2026-06-12] — échec auth terminal → reset borne
 * complet, panier client DÉTRUIT, seul un toast technique 6 s.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (C-borne-edge) : rotation/expiration du token machine
 * (TTL 480 min) en pleine commande → app.js CLEAR_KIOSK_TOKEN + push
 * /kiosk/login → auto-relogin OK → replace systématique vers kiosk.idle dont
 * le mount dispatch kioskCart/reset → la commande composée du client
 * disparaît sans écran d'excuse (captures c1c-05/06 = écran Bienvenue).
 *
 * Fix minimal (non frozen) : après un re-login réussi, si le panier persisté
 * contient des articles, KioskLoginComponent rend le client à SON panier
 * (?recovered=1) ; le panier affiche un toast FR « commande conservée ».
 *
 * Invariants :
 *  1. relogin OK + panier non vide → replace kiosk.cart (recovered=1).
 *  2. relogin OK + panier vide → comportement historique (kiosk.idle).
 *  3. CLEAR_KIOSK_TOKEN ne touche jamais les items (le panier EST récupérable).
 *  4. clef FR kiosk.session_recovered_cart présente + toast câblé au mount
 *     panier.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import KioskLoginComponent from '../../resources/js/components/frontend/kiosk/KioskLoginComponent.vue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import frMessages from '../../resources/js/languages/fr.json';
import enMessages from '../../resources/js/languages/en.json';

function makeVm({ cartCount = 0, loginRejects = null } = {}) {
    const vm = {
        loading: false,
        error: null,
        setupRequired: false,
        retryTimer: null,
        retryAttempts: 3,
        kioskLogin: loginRejects
            ? vi.fn().mockRejectedValue(loginRejects)
            : vi.fn().mockResolvedValue({}),
        $router: { replace: vi.fn() },
        $store: { getters: { 'kioskCart/count': cartCount } },
        $t: (key) => `i18n:${key}`,
        scheduleRetry: vi.fn(),
    };
    for (const name of ['getAutoCredentials', 'startAutoLogin']) {
        vm[name] = KioskLoginComponent.methods[name].bind(vm);
    }
    return vm;
}

describe('[C-ADV-08] re-login machine sans destruction du panier', () => {
    beforeEach(() => {
        window.foodkingConfig = { kioskAutoLogin: { username: 'kiosk-1', password: 'pw' } };
    });
    afterEach(() => {
        delete window.foodkingConfig;
    });

    it('relogin OK + panier en cours → retour PANIER (?recovered=1), pas idle', async () => {
        const vm = makeVm({ cartCount: 3 });
        await vm.startAutoLogin();
        expect(vm.$router.replace).toHaveBeenCalledWith({
            name: 'kiosk.cart',
            query: { recovered: '1' },
        });
    });

    it('relogin OK + panier vide → comportement historique (idle)', async () => {
        const vm = makeVm({ cartCount: 0 });
        await vm.startAutoLogin();
        expect(vm.$router.replace).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    });

    it('CLEAR_KIOSK_TOKEN ne détruit JAMAIS les items du panier', () => {
        const state = {
            ...JSON.parse(JSON.stringify(kioskCart.state)),
            items: [{ item_id: 1, quantity: 2, convert_price: 5, total: 10 }],
            kioskToken: 'stale-token',
        };
        kioskCart.mutations.CLEAR_KIOSK_TOKEN(state);
        expect(state.kioskToken).toBeNull();
        expect(state.items).toHaveLength(1);
    });

    it('clef FR/EN session_recovered_cart présente + toast câblé au mount panier', () => {
        expect(frMessages.kiosk.session_recovered_cart).toMatch(/conservée/i);
        expect(typeof enMessages.kiosk.session_recovered_cart).toBe('string');
        const cartSrc = readFileSync(
            resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskCartComponent.vue'),
            'utf-8',
        );
        expect(cartSrc).toMatch(/recovered.*===.*'1'/);
        expect(cartSrc).toMatch(/session_recovered_cart/);
    });
});
