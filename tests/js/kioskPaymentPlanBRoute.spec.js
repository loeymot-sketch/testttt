/**
 * [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route ALL kiosk payments to counter.
 *
 * Owner mandate Le Cayenne V1 LOCAL: tous les paiements de la borne passent par
 * la caisse. La borne crée une commande PENDING_COUNTER (CASH_ON_DELIVERY=1) et
 * la caisse choisit espèces (ouvre tiroir) OU carte (imprime ticket + encaisse
 * manuellement terminal). Cash flow kiosk→caisse reste permanent même quand les
 * terminals seront câblés au TPE.
 *
 * This spec follows the established static-source-grep pattern used by sister
 * specs (kioskCounterPaymentFlow.spec.js, etc.) — verifies the source code
 * asserts the contract without depending on a full Vue mount of a 1000+ LOC
 * component (the component depends on Vuex, vue-router, axios, kioskHardware,
 * useKioskSpeech, etc., which would each need elaborate mocking just to assert
 * the template/data contract).
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const root = resolve(process.cwd());
const read = (rel) => readFileSync(resolve(root, rel), 'utf-8');

describe('Z1 Plan B — kiosk payment routes all to counter', () => {
    it('config/kiosk.php exposes the payment_route_all_to_counter flag (default true)', () => {
        const source = read('config/kiosk.php');

        // Flag declaration with env override
        expect(source).toMatch(/KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER/);
        expect(source).toMatch(/payment_route_all_to_counter/);
        // Default true (Plan B is the owner-mandated V1 baseline)
        expect(source).toMatch(/env\('KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER',\s*true\)/);
    });

    it('master.blade.php injects window.foodkingConfig.kiosk.paymentRouteAllToCounter', () => {
        const source = read('resources/views/master.blade.php');

        // The flag is exposed under the kiosk namespace so other kiosk flags
        // can be grouped there without polluting the root foodkingConfig.
        expect(source).toMatch(/kiosk:\s*{/);
        expect(source).toMatch(/paymentRouteAllToCounter:\s*@json\(\(bool\)\s*config\('kiosk\.payment_route_all_to_counter'/);
    });

    it('KioskPaymentComponent reads paymentRouteAllToCounter from foodkingConfig.kiosk', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

        // Computed property
        expect(source).toMatch(/paymentRouteAllToCounter\s*\(\)\s*{/);
        expect(source).toMatch(/window\?\.foodkingConfig\?\.kiosk\?\.paymentRouteAllToCounter/);
    });

    it('KioskPaymentComponent hides the method selection UI when flag is on', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

        // The methods grid is gated off by !paymentRouteAllToCounter so the
        // customer never sees card/cash/tr radio selection. The Vue directive
        // appears BEFORE the class attribute on the same element.
        expect(source).toMatch(/!paymentRouteAllToCounter[\s\S]{0,200}kiosk-pay-methods-outer/);
        // Header, amount-card, confirm CTA are also suppressed.
        expect(source).toMatch(/!paymentRouteAllToCounter[\s\S]{0,200}kiosk-pay-header/);
        expect(source).toMatch(/!paymentRouteAllToCounter[\s\S]{0,200}kiosk-pay-confirm/);
    });

    it('KioskPaymentComponent renders a dedicated counter-route screen when flag is on', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

        // Dedicated screen with data-testid hooks for E2E + a11y attributes
        expect(source).toMatch(/data-testid="kiosk-payment-counter-route"/);
        expect(source).toMatch(/data-testid="kiosk-payment-counter-title"/);
        expect(source).toMatch(/data-testid="kiosk-payment-counter-confirm"/);
        // Reuses the existing total i18n key for consistency
        expect(source).toMatch(/kiosk\.pay_screen\.counter_route_title/);
        expect(source).toMatch(/kiosk\.pay_screen\.counter_route_sub/);
        expect(source).toMatch(/kiosk\.pay_screen\.counter_route_confirm_btn/);
    });

    it('KioskPaymentComponent auto-submits with method=cash (CASH_ON_DELIVERY=1 backend)', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

        // confirmCounterRoute pins method='cash' and delegates to confirmPayment
        // which routes to processCashPayment → no TPE, navigation to
        // kiosk.cash-instruction (already wired by B5b counter-payment flow).
        expect(source).toMatch(/confirmCounterRoute\s*\(\)\s*{[\s\S]{0,400}this\.method\s*=\s*['"]cash['"]/);
        expect(source).toMatch(/confirmCounterRoute[\s\S]{0,400}this\.confirmPayment\(\)/);

        // The existing confirmPayment routes 'cash' to processCashPayment which
        // never opens a drawer at the kiosk — sister spec
        // kioskCounterPaymentFlow.spec.js already locks that contract.
        expect(source).toMatch(/this\.method\s*===\s*['"]cash['"][\s\S]{0,500}cash-instruction/);
    });

    it('French i18n keys for counter-route are present in resources/js/languages/fr.json', () => {
        const source = read('resources/js/languages/fr.json');

        // "Veuillez payer à la caisse" — the exact French message owner asked for
        expect(source).toMatch(/"counter_route_sub":\s*"Veuillez payer à la caisse"/);
        expect(source).toMatch(/"counter_route_title":\s*"Paiement à la caisse"/);
        expect(source).toMatch(/"counter_route_confirm_btn"/);
        expect(source).toMatch(/"counter_route_processing"/);
    });
});
