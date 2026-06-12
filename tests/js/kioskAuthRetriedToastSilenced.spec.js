/**
 * [dispute-r1 D-006 + C-ADV-05 2026-06-12] — toast technique « Session
 * rafraîchie automatiquement » visible du CLIENT borne.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial : le toast (émis par KioskAppComponent:380, frozen, sur
 * l'event kiosk-auth-retried) apparaissait en plein parcours client (d3-07
 * pendant le drawer a11y ; c1-11 où il CHEVAUCHE le CTA upsell « Non merci,
 * continuer sans (29s) »). Un message technique de session n'a rien à faire
 * sur une surface client. Le retry a RÉUSSI → rien à dire.
 *
 * Fix frozen-safe : helpers/kioskAuthInterceptor.js (déjà capture-phase devant
 * le listener bubble du shell) avale désormais TOUS les kiosk-auth-retried
 * (console.debug pour l'audit). kiosk-auth-failed (borne déconnectée,
 * actionnable) reste délivré + débouncé comme avant.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    installKioskAuthInterceptor,
    _resetKioskAuthInterceptorForTests,
} from '../../resources/js/helpers/kioskAuthInterceptor';

describe('[D-006/C-ADV-05] kiosk-auth-retried silencieux côté client', () => {
    beforeEach(() => {
        _resetKioskAuthInterceptorForTests();
        // install est idempotent (garde window.__kioskAuthInterceptorInstalled)
        installKioskAuthInterceptor();
    });

    it('kiosk-auth-retried n’atteint JAMAIS le listener bubble du shell (toast supprimé)', () => {
        const shellListener = vi.fn(); // simule KioskAppComponent._authRetriedListener
        window.addEventListener('kiosk-auth-retried', shellListener);

        window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: { url: '/api/frontend/menu', status: 401 } }));

        expect(shellListener).not.toHaveBeenCalled();
        window.removeEventListener('kiosk-auth-retried', shellListener);
    });

    it('le retry silencieux reste auditable en console.debug', () => {
        const debugSpy = vi.spyOn(console, 'debug').mockImplementation(() => {});
        window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: { url: '/x', status: 401 } }));
        expect(debugSpy).toHaveBeenCalledWith(
            expect.stringContaining('session auto-refreshed'),
            expect.anything(),
        );
        debugSpy.mockRestore();
    });

    it('kiosk-auth-failed (actionnable) est TOUJOURS délivré au shell (1er du burst)', () => {
        const shellListener = vi.fn();
        window.addEventListener('kiosk-auth-failed', shellListener);

        window.dispatchEvent(new CustomEvent('kiosk-auth-failed', { detail: { status: 401 } }));
        expect(shellListener).toHaveBeenCalledTimes(1);

        // burst : le doublon dans la fenêtre 1500ms est débouncé (comportement
        // Wave Y D1 préservé).
        window.dispatchEvent(new CustomEvent('kiosk-auth-failed', { detail: { status: 401 } }));
        expect(shellListener).toHaveBeenCalledTimes(1);
        window.removeEventListener('kiosk-auth-failed', shellListener);
    });
});
