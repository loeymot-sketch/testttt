/**
 * [dispute-r1 C-RED-03 2026-06-12] — /kiosk/payment SANS timeout d'inactivité
 * en Plan B → borne bloquée sur la commande du client parti.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (C-borne-edge) : le timer global du shell exclut
 * kiosk.payment (noTimerRoutes, rationale TPE physique — AUDIT-52-BUG3). Or en
 * Plan B (kiosk.payment_route_all_to_counter=true) il n'y a PAS de TPE : 25 s
 * sans overlay ni redirect prouvés live (r4-payment-25s-sans-timer.png) ; le
 * client suivant n'avait qu'à toucher « Confirmer » pour envoyer la commande
 * abandonnée en caisse. KioskAppComponent étant frozen, le timer est porté par
 * KioskPaymentComponent (non frozen), actif UNIQUEMENT en Plan B.
 *
 * Invariants :
 *  1. Plan B + écran au repos → warn « Toujours là ? » à (idleMs − confirmMs),
 *     reset complet (panier vidé + retour idle) à idleMs.
 *  2. Timer JAMAIS armé pendant submitting/submitted (commande en route).
 *  3. Hors Plan B → aucun timer local (le rationale TPE du shell reste vrai).
 *  4. L'overlay réutilise KioskInactivityOverlayComponent (libellés FR déjà
 *     healés W4-K3).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';

const paySrc = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
    'utf-8',
);

function makeVm({ planB = true, idleMs = 12000, confirmMs = 4000 } = {}) {
    const vm = {
        paymentRouteAllToCounter: planB,
        planBIdleMs: idleMs,
        planBConfirmMs: confirmMs,
        planBStillHere: false,
        submitting: false,
        submitted: false,
        _planBWarnTimer: null,
        _planBIdleTimer: null,
        $store: { dispatch: vi.fn() },
        $router: { push: vi.fn() },
    };
    for (const name of [
        '_startPlanBIdleTimer',
        '_clearPlanBIdleTimer',
        '_onPlanBActivity',
        'onPlanBInactivityStay',
        'onPlanBInactivityLeave',
    ]) {
        vm[name] = KioskPaymentComponent.methods[name].bind(vm);
    }
    return vm;
}

describe('[C-RED-03] timer d’inactivité local Plan B sur /kiosk/payment', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('warn à idleMs−confirmMs puis reset panier + retour idle à idleMs', () => {
        const vm = makeVm({ idleMs: 12000, confirmMs: 4000 });
        vm._startPlanBIdleTimer();

        vi.advanceTimersByTime(7999);
        expect(vm.planBStillHere).toBe(false);
        vi.advanceTimersByTime(1);
        expect(vm.planBStillHere).toBe(true);

        vi.advanceTimersByTime(4000);
        expect(vm.$store.dispatch).toHaveBeenCalledWith('kioskCart/reset');
        expect(vm.$router.push).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    });

    it('une interaction relance la fenêtre (pas de warn si le client touche l’écran)', () => {
        const vm = makeVm({ idleMs: 12000, confirmMs: 4000 });
        vm._startPlanBIdleTimer();

        vi.advanceTimersByTime(6000);
        vm._onPlanBActivity(); // touch → restart
        vi.advanceTimersByTime(6000); // 6s < nouveau warnAt 8s
        expect(vm.planBStillHere).toBe(false);
        expect(vm.$router.push).not.toHaveBeenCalled();
    });

    it('« Je suis là » ferme l’overlay et ré-arme le timer', () => {
        const vm = makeVm({ idleMs: 12000, confirmMs: 4000 });
        vm._startPlanBIdleTimer();
        vi.advanceTimersByTime(8000);
        expect(vm.planBStillHere).toBe(true);

        vm.onPlanBInactivityStay();
        expect(vm.planBStillHere).toBe(false);
        // le panier n'a PAS été vidé
        expect(vm.$store.dispatch).not.toHaveBeenCalled();
        // et le timer repart
        vi.advanceTimersByTime(8000);
        expect(vm.planBStillHere).toBe(true);
    });

    it('JAMAIS armé pendant submitting (commande en route vers la caisse)', () => {
        const vm = makeVm();
        vm.submitting = true;
        vm._startPlanBIdleTimer();
        vi.advanceTimersByTime(60000);
        expect(vm.planBStillHere).toBe(false);
        expect(vm.$router.push).not.toHaveBeenCalled();
    });

    it('hors Plan B → aucun timer local (rationale TPE du shell préservé)', () => {
        const vm = makeVm({ planB: false });
        vm._startPlanBIdleTimer();
        vi.advanceTimersByTime(600000);
        expect(vm.planBStillHere).toBe(false);
        expect(vm.$store.dispatch).not.toHaveBeenCalled();
    });
});

describe('[C-RED-03] câblage template + lifecycle', () => {
    it('l’overlay KioskInactivityOverlayComponent est rendu gaté Plan B', () => {
        expect(paySrc).toMatch(/<KioskInactivityOverlayComponent[\s\S]{0,200}v-if="paymentRouteAllToCounter"|v-if="paymentRouteAllToCounter"[\s\S]{0,200}KioskInactivityOverlayComponent/);
        expect(paySrc).toMatch(/@stay="onPlanBInactivityStay"/);
        expect(paySrc).toMatch(/@leave="onPlanBInactivityLeave"/);
    });

    it('les listeners d’activité sont retirés au démontage (pas de fuite)', () => {
        expect(paySrc).toMatch(/removeEventListener\('pointerdown', this\._planBActivityHandler\)/);
        expect(paySrc).toMatch(/removeEventListener\('touchstart', this\._planBActivityHandler\)/);
        expect(paySrc).toMatch(/removeEventListener\('keydown', this\._planBActivityHandler\)/);
    });

    it('le watcher submitting coupe puis ré-arme le timer', () => {
        expect(paySrc).toMatch(/submitting\(value\)\s*{[\s\S]{0,200}_clearPlanBIdleTimer[\s\S]{0,100}_startPlanBIdleTimer/);
    });
});
