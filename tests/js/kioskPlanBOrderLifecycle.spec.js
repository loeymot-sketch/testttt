/**
 * [dispute-r1 D-003 + ADV-F-P1-1 2026-06-12] — cycle de vie commande Plan B borne.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial :
 *  - D-003 (P1, D-borne-robustesse) : le panier n'était JAMAIS vidé après le
 *    POST /frontend/order réussi (entrée cash-instruction). La clé
 *    d'idempotence restait figée → re-validation (même panier modifié,
 *    probe D-003b) = « Request failed with status code 409 » brut EN,
 *    aucune issue avant le reset idle.
 *  - ADV-F-P1-1 (P1, F-design-vision) : l'écran Plan B « Paiement à la
 *    caisse » n'avait AUCUN contrôle d'échappement (header back gaté
 *    !paymentRouteAllToCounter) → client piégé.
 *
 * Invariants verrouillés :
 *  1. KioskCashInstructionComponent vide le panier au mount (kioskCart/reset
 *     → items + idempotencyKey + promo + loyalty nettoyés, kioskToken intact).
 *  2. CTA explicite « Retour à l'accueil » (clef cta_back_home FR/EN).
 *  3. KioskPaymentComponent : 409 → message FR order_conflict + retour idle
 *     (jamais le message axios brut), sans empiler le compteur de refus TPE.
 *  4. Bloc counter-route : bouton retour panier présent.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import axios from 'axios';
import { readFileSync } from 'fs';
import { resolve } from 'path';

import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';
import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';
import KsButton from '../../resources/js/components/frontend/kiosk/ds/KsButton.vue';
import KsCard from '../../resources/js/components/frontend/kiosk/ds/KsCard.vue';
import KsPriceLine from '../../resources/js/components/frontend/kiosk/ds/KsPriceLine.vue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import frMessages from '../../resources/js/languages/fr.json';
import enMessages from '../../resources/js/languages/en.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

const paySrc = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
    'utf-8',
);

beforeEach(() => {
    vi.spyOn(axios, 'post').mockResolvedValue({ data: { status: true } });
});

function mountCashInstruction(storeDispatch) {
    return mount(KioskCashInstructionComponent, {
        props: { orderNumber: 'A0042', orderTotal: 9.5, autoRedirectSeconds: 0 },
        global: {
            plugins: [i18n],
            components: { KsButton, KsCard, KsPriceLine },
            stubs: { RouterLink: true },
            mocks: {
                $router: { push: vi.fn().mockResolvedValue() },
                $store: { dispatch: storeDispatch },
            },
        },
    });
}

describe('[D-003] cash-instruction vide le panier à l’entrée', () => {
    it('dispatch kioskCart/reset au mount', () => {
        const dispatch = vi.fn();
        mountCashInstruction(dispatch);
        expect(dispatch).toHaveBeenCalledWith('kioskCart/reset');
    });

    it('RESET store nettoie la clé d’idempotence ET les items (pas le token machine)', () => {
        const state = {
            ...JSON.parse(JSON.stringify(kioskCart.state)),
            items: [{ item_id: 1, quantity: 1, convert_price: 5, total: 5 }],
            idempotencyKey: 'stale-key-409',
            kioskToken: 'machine-token',
            promoCode: 'BORNE10',
            loyaltyDiscount: 2,
        };
        kioskCart.mutations.RESET(state);
        expect(state.items).toEqual([]);
        expect(state.idempotencyKey).toBeNull();
        expect(state.promoCode).toBeNull();
        expect(state.loyaltyDiscount).toBe(0);
        // Le token machine est géré par CLEAR_KIOSK_TOKEN, jamais par RESET.
        expect(state.kioskToken).toBe('machine-token');
    });
});

describe('[D-003/ADV-F-P1-1] CTA explicite « Retour à l’accueil »', () => {
    it('le bouton affiche « Retour à l’accueil » (plus « J’ai compris » ambigu)', () => {
        const wrapper = mountCashInstruction(vi.fn());
        const btn = wrapper.find('[data-testid="kiosk-cash-cta-understood"]');
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toContain('Retour à l\'accueil');
    });

    it('clefs cta_back_home présentes FR + EN', () => {
        expect(frMessages.kiosk.cash_instruction.cta_back_home).toBe('Retour à l\'accueil');
        expect(typeof enMessages.kiosk.cash_instruction.cta_back_home).toBe('string');
    });
});

describe('[D-003] KioskPaymentComponent — 409 → FR + retour accueil', () => {
    function makeVm({ rejection }) {
        const toasts = [];
        const vm = {
            method: 'cash',
            submitting: false,
            submitted: false,
            error: null,
            tpeWaiting: false,
            tpeCanCancel: false,
            paymentFailureCount: 0,
            orderType: 10,
            _lastOrder: null,
            $options: { MAX_PAYMENT_FAILURES: 2 },
            isElectronicMethodBlocked: () => false,
            offlinePaymentMessage: () => 'offline',
            refreshQuote: vi.fn().mockRejectedValue(rejection),
            submitOrder: vi.fn(),
            showToast: (msg, type, ttl) => toasts.push({ msg, type, ttl }),
            $router: { push: vi.fn() },
            $t: (key) => `i18n:${key}`,
            $store: { state: { kioskCart: {} } },
        };
        return { vm, toasts };
    }

    it('409 → message order_conflict (FR), push kiosk.idle, compteur de refus TPE intact', async () => {
        const err = new Error('Request failed with status code 409');
        err.response = { status: 409, data: { message: 'Conflict' } };
        const { vm, toasts } = makeVm({ rejection: err });

        await KioskPaymentComponent.methods.confirmPayment.call(vm);

        expect(vm.error).toBe('i18n:kiosk.pay_screen.order_conflict');
        expect(toasts[0]?.msg).toBe('i18n:kiosk.pay_screen.order_conflict');
        expect(vm.error).not.toMatch(/Request failed/);
        expect(vm.$router.push).toHaveBeenCalledWith({ name: 'kiosk.idle' });
        // Un 409 n'est PAS un refus de paiement : il ne doit pas pousser vers
        // l'écran payment-refused au 2e essai.
        expect(vm.paymentFailureCount).toBe(0);
    });

    it('clef order_conflict présente FR + EN', () => {
        expect(frMessages.kiosk.pay_screen.order_conflict).toMatch(/déjà/i);
        expect(typeof enMessages.kiosk.pay_screen.order_conflict).toBe('string');
    });
});

describe('[ADV-F-P1-1] bloc counter-route — échappatoire présente', () => {
    it('bouton retour panier présent dans le bloc Plan B', () => {
        expect(paySrc).toMatch(/data-testid="kiosk-payment-counter-back"/);
        expect(paySrc).toMatch(/counter_route_back/);
        // Il navigue vers le panier (replace pour ne pas empiler l'historique).
        expect(paySrc).toMatch(/kiosk-payment-counter-back[\s\S]{0,400}?/);
        expect(paySrc).toMatch(/\$router\.replace\(\{ name: 'kiosk\.cart' \}\)/);
    });

    it('clef counter_route_back présente FR + EN', () => {
        expect(frMessages.kiosk.pay_screen.counter_route_back).toBe('Retour au panier');
        expect(typeof enMessages.kiosk.pay_screen.counter_route_back).toBe('string');
    });
});
