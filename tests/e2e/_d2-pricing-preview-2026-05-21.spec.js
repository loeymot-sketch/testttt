/**
 * D2 — Pricing-preview SSOT regression spec (Wave Y Round 1 F2 closer).
 *
 * Mission: QUICK-MISSION FIX D2 — Le Cayenne V2 (2026-05-21).
 *
 * ## Finding after diagnosis (advisor-confirmed)
 *
 * The Wave Y "F2 — pricing/preview returns HTTP 422 for some compositions"
 * is NOT a backend bug. The endpoint `POST /api/frontend/pricing/preview`
 * correctly rejects compositions that include a stock-ruptured variation
 * (e.g. Algérienne, variation id=281 for Cayenne / 311 for Tacos — both
 * have `stock_levels.on_hand=0` in the Le Cayenne V2 baseline). The
 * rejection comes from `ChoiceAvailabilityResolver::assertSelectionsOrderable`
 * which is the same NF525 SSOT enforcement that runs at `POST /order`.
 *
 * The user-facing path that triggers the 422 is the F3 click-guard leak
 * (sauce step let the user select an "Épuisé"-badged sauce as the first
 * sauce). F3 is closed by `_d3-oos-sauce-guard-2026-05-21.spec.js`. This
 * spec covers the pricing-preview contract assertions independently of
 * the click-guard:
 *
 *   1. Multi-sauce composition (Tacos + 3 available sauces) → 200.
 *      Confirms the wizard's "first-sauce only" payload contract still
 *      works after the Wave Y reseed.
 *
 *   2. Single available sauce (Cayenne + 1 available sauce) → 200 with
 *      numeric integrity (line_subtotal === base price).
 *
 *   3. Stock-ruptured sauce (Cayenne + Algérienne 281) → 422 with the
 *      SSOT message "indisponible pour cette branche (stock_rupture)".
 *      This is the correct NF525-preserving rejection.
 *
 *   4. /menu projection guard — variation 281 must carry `is_available:
 *      false`. If this regresses, the OOS click-guard reads stale state
 *      and the wizard would let the user click Algérienne again.
 *
 *   5. Numeric stability across two consecutive preview calls (same
 *      payload → same total). Proves no random drift.
 *
 * ## NF525 verdict (claimed at end of spec)
 *
 *   Triple defense intact:
 *     - Preview rejects stock-ruptured sauces (this spec).
 *     - Order create rejects same (covered by Feature/MultiVariationValidationTest +
 *       Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest).
 *     - Frontend click-guard rejects in UI (covered by d3 spec).
 *
 *   "Tarif rafraîchi localement" toast is a UX-clarity issue only, NOT
 *   an NF525 risk — composition_snapshot at order creation re-anchors
 *   the price via the same `PricingService::calculateOrder` path.
 *
 * Auth: uses an existing Sanctum kiosk:order token rather than the
 * Vue wizard so the spec stays narrow and deterministic (no
 * dependency on CORS / kiosk-login race).
 */
const { test, expect, request: pwRequest } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/d2-pricing-preview-2026-05-21';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

const API_KEY = process.env.API_KEY || 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(60_000);

/**
 * Mint a fresh Sanctum kiosk:order token via `php artisan tinker` so the
 * spec does not depend on the kiosk-login flow (CORS / token-sprawl
 * issues we want to keep out of this contract test).
 *
 * Reads back the most recently-created KioskMachine user — same user the
 * idle screen logs in as.
 */
function mintKioskToken() {
    // Single-line PHP to keep shell quoting simple. The Sanctum token name
    // 'd2-spec' is rotated so re-runs don't pile up tokens.
    const script = '$km=App\\Models\\KioskMachine::query()->orderByDesc("id")->first(); '
        + 'if(!$km){echo "NO_KIOSK_MACHINE";exit(1);} '
        + '$u=App\\Models\\User::find($km->user_id); '
        + '$u->tokens()->where("name","d2-spec")->delete(); '
        + 'echo $u->createToken("d2-spec",["kiosk:order"])->plainTextToken;';
    const out = execSync(`php artisan tinker --execute='${script}'`, {
        cwd: process.cwd(),
        encoding: 'utf8',
        shell: '/bin/bash',
    }).trim();
    // tinker may print extra newlines / warnings — grab the last non-empty line.
    const lines = out.split('\n').map((l) => l.trim()).filter(Boolean);
    const tok = lines[lines.length - 1];
    if (!tok || tok.includes('NO_KIOSK_MACHINE') || !/^\d+\|[A-Za-z0-9]+$/.test(tok)) {
        throw new Error('Could not mint kiosk token — got: ' + JSON.stringify(out));
    }
    return tok;
}

test.describe('D2 — POST /api/frontend/pricing/preview contract', () => {
    let token;
    let api;

    test.beforeAll(async () => {
        token = mintKioskToken();
        console.log('[D2] minted kiosk:order token (head):', token.slice(0, 12) + '...');
        api = await pwRequest.newContext({
            baseURL: BASE,
            extraHTTPHeaders: {
                Authorization: `Bearer ${token}`,
                'x-api-key': API_KEY,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
        });
    });

    test.afterAll(async () => {
        if (api) await api.dispose();
    });

    test('case-1 — Cayenne + 1 available sauce (Andalouse 282) returns 200', async () => {
        const res = await api.post('/api/frontend/pricing/preview', {
            data: {
                items: [{
                    item_id: 22,
                    quantity: 1,
                    item_variations: [{ id: 1 }, { id: 282 }],
                }],
            },
        });
        expect(res.status(), 'pricing/preview 200 for Cayenne + 1 available sauce').toBe(200);
        const body = await res.json();
        expect(body.status).toBe(true);
        expect(body.data.lines).toHaveLength(1);
        // Sandwich Cayenne base = 7.40 (post Wave Y reseed). Free first sauce.
        expect(body.data.lines[0].item_id).toBe(22);
        expect(Number(body.data.lines[0].line_subtotal)).toBeCloseTo(7.40, 2);
        expect(Number(body.data.total)).toBeCloseTo(7.40, 2);
        fs.writeFileSync(path.join(OUT, 'case-1-200-body.json'), JSON.stringify(body, null, 2));
    });

    test('case-2 — Tacos + 1 available sauce (Andalouse 312) returns 200', async () => {
        // The kiosk wizard only ever sends the FIRST sauce as item_variations.
        // Extra sauces (2nd, 3rd) are encoded as price surcharge client-side via
        // `sauceVariationSurcharge` and emitted as instruction text + cart total.
        // So even when the user picks 3 sauces, the preview payload still has
        // only 1 sauce variation. Reproduce that contract here.
        const res = await api.post('/api/frontend/pricing/preview', {
            data: {
                items: [{
                    item_id: 26,
                    quantity: 1,
                    item_variations: [{ id: 43 }, { id: 312 }],
                }],
            },
        });
        expect(res.status(), 'pricing/preview 200 for Tacos + 1 sauce').toBe(200);
        const body = await res.json();
        expect(body.status).toBe(true);
        expect(Number(body.data.total)).toBeCloseTo(6.90, 2);
        fs.writeFileSync(path.join(OUT, 'case-2-200-body.json'), JSON.stringify(body, null, 2));
    });

    test('case-3 — Cayenne + stock-ruptured sauce (Algérienne 281) returns 422 with SSOT message', async () => {
        const res = await api.post('/api/frontend/pricing/preview', {
            data: {
                items: [{
                    item_id: 22,
                    quantity: 1,
                    item_variations: [{ id: 1 }, { id: 281 }],
                }],
            },
        });
        expect(res.status(), 'pricing/preview MUST 422 on stock_rupture sauce — this IS the NF525 invariant').toBe(422);
        const body = await res.json();
        expect(body.status).toBe(false);
        expect(body.message).toMatch(/indisponible pour cette branche|stock_rupture/i);
        fs.writeFileSync(path.join(OUT, 'case-3-422-body.json'), JSON.stringify(body, null, 2));
    });

    test('case-4 — /menu projection marks Algérienne (281) as is_available=false', async () => {
        const res = await api.get('/api/frontend/menu');
        expect(res.status()).toBe(200);
        const body = await res.json();
        const cayenne = body.data.items.find((i) => i.id === 22);
        expect(cayenne, 'Sandwich Cayenne must appear in /menu projection').toBeTruthy();
        const algerienne = (cayenne.variations || []).find((v) => v.id === 281);
        expect(algerienne, 'Algérienne (id=281) must appear in Cayenne variations').toBeTruthy();
        expect(algerienne.is_available, 'Algérienne MUST be projected as unavailable').toBe(false);
        expect(algerienne.unavailable_reason).toBe('stock_rupture');
    });

    test('case-5 — numeric stability: two consecutive preview calls yield identical total', async () => {
        const payload = {
            items: [{
                item_id: 22,
                quantity: 1,
                item_variations: [{ id: 1 }, { id: 282 }],
            }],
        };
        const r1 = await api.post('/api/frontend/pricing/preview', { data: payload });
        const r2 = await api.post('/api/frontend/pricing/preview', { data: payload });
        expect(r1.status()).toBe(200);
        expect(r2.status()).toBe(200);
        const b1 = await r1.json();
        const b2 = await r2.json();
        expect(b1.data.total).toBe(b2.data.total);
        expect(b1.data.subtotal).toBe(b2.data.subtotal);
    });
});
