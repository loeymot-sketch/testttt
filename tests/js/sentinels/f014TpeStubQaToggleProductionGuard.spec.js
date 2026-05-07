import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID F-014 — TPE Stub QA Toggle (dev/staging only)
 * @source plans/PLAN_AUDIT_F014_TPE_STUB_QA_TOGGLE_2026-05-07.md
 * @sprint S5
 *
 * Verrou structural anti-régression : KioskPaymentComponent.vue _invokeTpe DOIT
 * exposer un toggle dev/staging `?tpe_force=declined|timeout` permettant aux QA
 * d'exercer les paths d'erreur sans hardware réel. Le toggle DOIT être garde-foué
 * par `process.env.NODE_ENV !== 'production'` (replace build-time webpack →
 * elimination dead-code en prod) afin qu'aucun query param ne puisse forcer un
 * decline/timeout dans une borne livrée.
 *
 * Le toggle est placé AVANT la branche `!kioskHardware.isKioskBridge()` afin de
 * fonctionner aussi en mode bridge réel (utile pour tester un decline TPE depuis
 * une borne de staging configurée bridge=true).
 *
 * Cross-check F-002 : la branche `tpe_force=declined` retourne `amount_cents_approved`
 * pour rester cohérente avec le contract bridge introduit par F-002.
 */
describe('F-014 — TPE stub QA toggle production guard (frontend)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
        'utf8',
    );

    it('production guard literal `process.env.NODE_ENV !== \'production\'` present in _invokeTpe', () => {
        // The guard MUST be literal so webpack DefinePlugin can replace at build time
        // and dead-code-eliminate the toggle entirely in production bundles.
        expect(source).toMatch(/process\.env\.NODE_ENV\s*!==\s*['"]production['"]/);
    });

    it('toggle reads `tpe_force` query param via URLSearchParams', () => {
        expect(source).toMatch(/URLSearchParams[\s\S]{0,200}?\.get\(['"]tpe_force['"]\)/);
    });

    it('declined branch returns approved:false with QA marker', () => {
        // The forced-decline path must produce { approved: false } so processCardPayment
        // throws and the user lands on KioskErrorPaymentRefusedComponent.
        expect(source).toMatch(/['"]forced_decline_qa['"]/);
        expect(source).toMatch(/['"]QA_FORCE_DECLINED['"]/);
    });

    it('timeout branch throws TPE_TIMEOUT (mirrors natural Promise.race timeout)', () => {
        // Throwing TPE_TIMEOUT directly mirrors the reject path of the global
        // Promise.race timeout in processCardPayment — no need to wait 120s.
        expect(source).toMatch(/['"]tpe_force['"][\s\S]{0,800}?TPE_TIMEOUT/);
    });

    it('declined branch echoes amount_cents_approved (F-002 cross-contract)', () => {
        // Forced-decline must still respect F-002 amount_cents_approved contract:
        // the backend never sees this declined amount (no payment-confirm fired
        // because approved:false), but downstream tests/utilities should be able
        // to assert the same shape as the real bridge.
        expect(source).toMatch(/forced_decline_qa[\s\S]{0,400}?amount_cents_approved/);
    });

    it('toggle placed BEFORE isKioskBridge stub branch (so it works for both stub and bridge)', () => {
        const tpeForceIdx = source.indexOf("'tpe_force'") >= 0
            ? source.indexOf("'tpe_force'")
            : source.indexOf('"tpe_force"');
        const stubBranchIdx = source.indexOf('!kioskHardware.isKioskBridge()');
        expect(tpeForceIdx).toBeGreaterThan(0);
        expect(stubBranchIdx).toBeGreaterThan(0);
        // Find the _invokeTpe occurrence (definition, not call) and assert tpe_force is between
        // the definition and the stub branch.
        const invokeDefIdx = source.indexOf('async _invokeTpe(');
        expect(invokeDefIdx).toBeGreaterThan(0);
        // tpe_force must appear AFTER _invokeTpe definition AND BEFORE the stub branch
        // inside _invokeTpe (the second isKioskBridge occurrence is in cancelCardPayment).
        const stubBranchInsideInvoke = source.indexOf('!kioskHardware.isKioskBridge()', invokeDefIdx);
        expect(stubBranchInsideInvoke).toBeGreaterThan(invokeDefIdx);
        expect(tpeForceIdx).toBeGreaterThan(invokeDefIdx);
        expect(tpeForceIdx).toBeLessThan(stubBranchInsideInvoke);
    });

    it('comment trail references AUDIT-F-014 for traceability', () => {
        expect(source).toContain('AUDIT-F-014');
    });

    it('window guard present (prevents SSR/Node crash if module imported in non-browser context)', () => {
        // typeof window !== 'undefined' must wrap the URLSearchParams access.
        expect(source).toMatch(/typeof\s+window\s*!==\s*['"]undefined['"]/);
    });
});
