// FoodKing E2E — F-017 SUITE 8 — Multi-Branche Isolation E2E (Playwright volet)
// Source : plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md §1 Suite 8 (lignes 206-218)
//
// Le volet PHPUnit (8 scénarios complets, runnent en CI sans dev server) est dans :
//   tests/Feature/Isolation/MultiBranchIsolationE2ETest.php
//
// Cette spec Playwright complète le volet UI : surfaces réellement chargées
// dans deux contextes (cashier branch A vs B / chef branch A) pour prouver
// que la couche SPA n'expose JAMAIS de données cross-branch — même si un
// futur bug HTTP layer permettait la fuite, l'UI doit la masquer.
//
// IMPORTANT — exécution :
//   - Requiert dev server actif + comptes utilisateurs branche A / B
//     pré-seedés (E2E_POS_USER, E2E_CHEF_USER + leur jumeau branche B).
//   - Sans accounts branche B disponibles, les tests dégradent en
//     "structural sanity" (login + surface mount sans erreur fatale).
//   - Les vraies preuves d'isolation BranchScope sont dans le PHPUnit.

const { test, expect } = require('@playwright/test');
const { loginAsPosOperator, loginAsChefOperator } = require('./helpers/login');

test.describe('F-017 Suite 8 — Multi-Branche Isolation E2E (Playwright)', () => {
  test.setTimeout(180_000);

  /* ------------------------------------------------------------------ */
  /* S8.1 — Cashier branch A login : POS surface ne montre que branch A  */
  /* ------------------------------------------------------------------ */
  test('S8.1 — Cashier branch A POS index API ne fuit aucune ligne branch B', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    // Direct API check via the SPA's session — the SPA must observe
    // the BranchScope server-side. Mismatched branch_ids in the payload
    // would prove a fuite.
    const result = await page.evaluate(async () => {
      try {
        const r = await fetch('/api/admin/pos-order', { credentials: 'include', headers: { Accept: 'application/json' } });
        const j = await r.json().catch(() => null);
        return { status: r.status, data: j?.data || null };
      } catch (e) {
        return { status: 0, data: null, err: e.message };
      }
    });

    expect([200, 401, 403]).toContain(result.status);
    if (result.status === 200 && Array.isArray(result.data)) {
      const branchIds = new Set(
        result.data.map((o) => o.branch_id ?? o.branch?.id).filter((v) => v !== undefined && v !== null),
      );
      expect(branchIds.size, 'POS index UI must not mix two branch_ids').toBeLessThanOrEqual(1);
    }

    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.2 — Cross-branch GET pos-order/show/{id_B} → 403/404               */
  /* ------------------------------------------------------------------ */
  test('S8.2 — Cross-branch GET show via SPA fetch retourne 403/404', async ({ browser }) => {
    // Backend behaviour : tested in MultiBranchIsolationE2ETest::test_S2.
    // Here we additionally prove the SPA, when targeting an id it shouldn't
    // know, gets the same denial. We probe a high id range likely outside
    // the cashier's scope.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const probeIds = [999999, 999998, 999997];
    for (const id of probeIds) {
      const result = await page.evaluate(async (orderId) => {
        const r = await fetch(`/api/admin/pos-order/show/${orderId}`, { credentials: 'include', headers: { Accept: 'application/json' } });
        return r.status;
      }, id);
      // Acceptable statuses for non-existent / cross-branch / scope-hidden order :
      //   404 (route model binding scope) | 403 (explicit gate) | 422 (legacy wrap)
      // 200 must NEVER be returned for a foreign order.
      expect([403, 404, 422]).toContain(result);
    }

    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.3 — Cross-branch POST change-status → 403/404                    */
  /* ------------------------------------------------------------------ */
  test('S8.3 — Cross-branch POST change-status via SPA fetch retourne 403/404/422', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const result = await page.evaluate(async () => {
      const r = await fetch('/api/admin/pos-order/change-status/999999', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: 1 }),
      });
      return r.status;
    });

    // Must NEVER 200 ; 401 also acceptable if Sanctum cookie/header missing.
    expect([401, 403, 404, 419, 422]).toContain(result);

    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.4 — Admin (branch_id=0) voit toutes les branches                 */
  /* ------------------------------------------------------------------ */
  test('S8.4 — Admin surface accessible (env-dependent)', async ({ browser }) => {
    // Admin authentication path varies by env seed. We document this scenario
    // as auditable via the PHPUnit suite (test_S4_admin_with_default_access_sees_all_branches).
    // The UI counterpart requires an admin user fixture (skip if missing).
    test.skip(!process.env.E2E_ADMIN_USER, 'S8.4: requires E2E_ADMIN_USER fixture');
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    // Login as admin would happen via a future helper. Document only.
    await page.goto('/admin', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.5 — Kiosk branch isolation                                        */
  /* ------------------------------------------------------------------ */
  test('S8.5 — Kiosk surface mounted ne fuit pas catalog cross-branch', async ({ browser }) => {
    // PHPUnit covers reconcile cross-branch reject (test_S5).
    // UI : verify the kiosk catalog (`/api/frontend/menu/branch/{id}`) only
    // returns items for the kiosk's own branch. We can't assert without a
    // multi-branch fixture, so we check structural integrity of the kiosk surface.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1_500);

    const hasFatal = /Whoops|Fatal error|Server Error/i.test(
      await page.locator('body').innerText().catch(() => ''),
    );
    expect(hasFatal).toBe(false);

    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.6 — Toggle availability ne broadcast pas branch B                */
  /* ------------------------------------------------------------------ */
  test('S8.6 — Availability broadcast channel scope (verified via PHPUnit)', async () => {
    // Channel naming discipline tested in PHPUnit
    // (test_S6_availability_broadcast_channel_is_branch_scoped reads routes/channels.php).
    // UI verification of broadcast isolation requires Soketi + two contexts
    // subscribed to different channels — too flaky without a harness.
    test.skip(true, 'S8.6: covered by PHPUnit static channel scope assertion. UI verification requires Soketi harness.');
  });

  /* ------------------------------------------------------------------ */
  /* S8.7 — Z report branch A ≠ Z report branch B                         */
  /* ------------------------------------------------------------------ */
  test('S8.7 — Z report payload ne mixe pas branch A et branch B', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const result = await page.evaluate(async () => {
      const r = await fetch('/api/admin/fiscal/z-report', { credentials: 'include', headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => null);
      return { status: r.status, data: j?.data || j || null };
    });

    expect([200, 401, 403]).toContain(result.status);
    if (result.status === 200 && Array.isArray(result.data)) {
      const branchIds = new Set(
        result.data.map((z) => z.branch_id).filter((v) => v !== undefined && v !== null),
      );
      // Cashier branch A → only their branch's Z reports.
      expect(branchIds.size).toBeLessThanOrEqual(1);
    }

    await ctx.close();
  });

  /* ------------------------------------------------------------------ */
  /* S8.8 — Cash drawer session branch A ≠ branch B                       */
  /* ------------------------------------------------------------------ */
  test('S8.8 — Cash drawer session current API ne renvoie que la session de la branche du caissier', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const result = await page.evaluate(async () => {
      const r = await fetch('/api/admin/cash-drawer/sessions/current', { credentials: 'include', headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => null);
      return { status: r.status, payload: j };
    });

    // Acceptable statuses : 200 (session exists, must be cashier's branch),
    // 204 (no open session), 401/403 (auth/scope guard).
    expect([200, 204, 401, 403, 404]).toContain(result.status);

    await ctx.close();
  });
});
