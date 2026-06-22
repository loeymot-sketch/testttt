import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-GOAL-D1-2026-05-23 | @source Wave Final 2026-05-23 S1-002 P1
 * @reason
 *   The global axios 429 interceptor in resources/js/bootstrap.js surfaces a
 *   customer-facing "Trop de requêtes — patientez Ns" toast whenever any
 *   request returns 429. Kiosk telemetry (`POST /api/frontend/kiosk/event`)
 *   is rate-limited via `throttle:30,1` and a burst from the borne idle
 *   screen triggers the toast — UX regression visible to the customer even
 *   though the call site wraps the request in `.catch(()=>{})`.
 *
 *   The fix adds a telemetry allowlist at the TOP of the 429 branch that
 *   short-circuits the toast emission (rejection still propagates so the
 *   per-call `.catch()` handler can drop the metric event). Owner decision
 *   D1 = fix-now.
 *
 * Sentinel asserts:
 *   - `_TELEMETRY_ALLOWLIST_PATTERNS` constant declared in bootstrap.js
 *   - `/api/frontend/kiosk/event` listed in that allowlist
 *   - `_isTelemetryEndpoint(error)` is invoked inside the 429 branch
 *     (NOT before the status-bucket guard — must remain status-scoped to
 *     avoid swallowing other-status telemetry errors silently).
 */
describe('GOAL-D1 — telemetry 429 allowlist (Wave Final S1-002)', () => {
    const bootstrap = readFileSync(
        resolve(process.cwd(), 'resources/js/bootstrap.js'),
        'utf8'
    );

    it('declares _TELEMETRY_ALLOWLIST_PATTERNS array', () => {
        expect(bootstrap).toMatch(
            /const\s+_TELEMETRY_ALLOWLIST_PATTERNS\s*=\s*\[/
        );
    });

    it('lists frontend/kiosk/event (slash alias) in the telemetry allowlist', () => {
        // [GOAL-D1-FIX 2026-05-23 mega-S1 round-1] Pattern is relative because
        // axios stores baseURL ('/api') SEPARATELY from config.url. At runtime
        // `error.config.url === 'frontend/kiosk/event'` (no /api prefix), so
        // the allowlist must match the relative form. Anchor on the array.
        const allowlistBlock = bootstrap.match(
            /_TELEMETRY_ALLOWLIST_PATTERNS\s*=\s*\[[\s\S]*?\]/
        );
        expect(allowlistBlock).not.toBeNull();
        expect(allowlistBlock[0]).toMatch(/['"]frontend\/kiosk\/event['"]/);
    });

    it('lists frontend/kiosk-event (hyphen legacy alias) in the telemetry allowlist', () => {
        // KioskAppComponent, KioskPayment, KsConsentModal, KsA11ySettings,
        // kioskPrinter, kioskOfflineQueue all call axios.post('frontend/kiosk-event', ...)
        // — the legacy hyphen alias is still live (routes/api.php). Must be allowlisted.
        const allowlistBlock = bootstrap.match(
            /_TELEMETRY_ALLOWLIST_PATTERNS\s*=\s*\[[\s\S]*?\]/
        );
        expect(allowlistBlock).not.toBeNull();
        expect(allowlistBlock[0]).toMatch(/['"]frontend\/kiosk-event['"]/);
    });

    it('lists admin/client-metrics in the telemetry allowlist', () => {
        const allowlistBlock = bootstrap.match(
            /_TELEMETRY_ALLOWLIST_PATTERNS\s*=\s*\[[\s\S]*?\]/
        );
        expect(allowlistBlock).not.toBeNull();
        expect(allowlistBlock[0]).toMatch(/['"]admin\/client-metrics['"]/);
    });

    it('runtime check: _isTelemetryEndpoint matches the three actual call shapes', () => {
        // Behavioural sentinel — extract the allowlist literal from source,
        // simulate config.url shapes that real call sites produce, and assert
        // a substring-includes match. Prevents future regressions like the
        // 2026-05-23 round-1 finding (allowlist had /api prefix; runtime URL
        // had no /api prefix; substring match silently failed).
        const blockMatch = bootstrap.match(
            /_TELEMETRY_ALLOWLIST_PATTERNS\s*=\s*\[([\s\S]*?)\]/
        );
        expect(blockMatch).not.toBeNull();
        const patterns = Array.from(
            blockMatch[1].matchAll(/['"]([^'"]+)['"]/g)
        ).map((m) => m[1]);
        // Three real shapes observed empirically in the kiosk surface.
        const callShapes = [
            'frontend/kiosk-event',     // hyphen, no leading slash (KioskAppComponent)
            '/frontend/kiosk/event',    // slash, leading slash (KioskCashInstruction)
            'frontend/kiosk/event',     // slash, no leading slash (useKioskA11y)
        ];
        for (const shape of callShapes) {
            const hit = patterns.some((p) => shape.includes(p));
            expect(hit, `allowlist must match call shape '${shape}'`).toBe(true);
        }
    });

    it('declares _isTelemetryEndpoint(error) helper', () => {
        expect(bootstrap).toMatch(
            /function\s+_isTelemetryEndpoint\s*\(\s*error\s*\)/
        );
    });

    it('invokes _isTelemetryEndpoint(error) inside the 429 branch (status-scoped)', () => {
        // The guard MUST be inside `else if (status === 429)` — not before
        // the status bucket logic — so that non-429 telemetry errors still
        // surface where appropriate. Match the 429 branch and assert the
        // helper call happens inside it before any other toast logic.
        const branch429 = bootstrap.match(
            /else\s+if\s*\(\s*status\s*===\s*429\s*\)\s*\{[\s\S]*?\}\s*else/
        );
        expect(branch429).not.toBeNull();
        expect(branch429[0]).toMatch(/_isTelemetryEndpoint\s*\(\s*error\s*\)/);
    });

    it('preserves the rejection chain (returns Promise.reject(error) on telemetry)', () => {
        // Critical: must NOT silently swallow. The per-call .catch handler
        // depends on the rejection still propagating.
        const branch429 = bootstrap.match(
            /else\s+if\s*\(\s*status\s*===\s*429\s*\)\s*\{[\s\S]*?\}\s*else/
        );
        expect(branch429).not.toBeNull();
        // Anchor the guard region: if telemetry, immediately reject.
        expect(branch429[0]).toMatch(
            /if\s*\(\s*_isTelemetryEndpoint\s*\(\s*error\s*\)\s*\)\s*\{\s*return\s+Promise\.reject\s*\(\s*error\s*\)\s*;?\s*\}/
        );
    });
});
