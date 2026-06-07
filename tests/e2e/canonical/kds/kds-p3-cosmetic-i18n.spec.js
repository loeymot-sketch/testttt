// =============================================================================
// CANONICAL E2E — KDS P3 cosmetic heals: i18n leak guard (browser-visible)
// =============================================================================
// Proves the user-visible half of the P3 breadth-audit KDS heals at the browser
// level. The internal logic (servedAgo rollup math, CTA key-selection) is proven
// deterministically at SOURCE by Vitest (tests/js/kdsCardCtaAndServedAgo.spec.js);
// THIS spec guards that the rebuilt bundle never LEAKS a raw i18n key on the KDS
// surfaces the heals touched. Anti-theater: concrete DOM/text assertions.
//
// Defects (source: P3 breadth audit):
//   • KDS-UI-04 (P3) — KdsOrderCard CTA was always "Prêt" even on a NOUVEAU card
//       whose first tap only advances to EN PRÉPARATION. FIX = state-aware label
//       (label.kds_card_cta_start "Démarrer" on NEW, label.kds_card_cta_ready
//       "Prêt" otherwise). Both visible label AND aria-label.
//   • KDS-UI-05 (P3) — the LEGACY lanes (?v2=0) had hardcoded FR literals for the
//       4 lane empty-states ("Aucune commande …") + the "Imprimer ticket" button.
//       FIX = label.kds_lane_empty_{dinein,online,takeaway,borne} +
//       button.kds_print_ticket (added to all 5 locales).
//
// HARNESS FACTS (worktree pre-cloud-exec):
//   - LIVE server = http://127.0.0.1:8765 (PLAYWRIGHT_BASE_URL). reuseExistingServer.
//   - loginAsAdmin → admin@lecayenne.fr / 123456.
//   - KDS SPA route = /admin/kitchen-display-system (V2 grid default; ?v2=0 legacy).
//   - CHAIN-SAFETY: abort any mutating POST to /api/admin/* so NO order/Z advances.
//
// ⚠️ ORCHESTRATOR — RUN ONLY AFTER THE JS BUNDLE IS REBUILT. The fixes live in
//   source (KdsOrderCard.vue / KdsV2Grid.vue / KitchenDisplaySystemComponent.vue
//   + the 5 locale JSON files); the app serves a precompiled Mix bundle. A stale
//   bundle gives a misleading result.
// =============================================================================

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../../helpers/login');

const KDS_URL_V2 = '/admin/kitchen-display-system?v2=1';
const KDS_URL_LEGACY = '/admin/kitchen-display-system?v2=0';

// The i18n keys the P3 heals introduced/used on KDS surfaces. None of these
// raw strings may ever appear in the rendered DOM (= a failed $t resolution).
const KDS_I18N_KEYS = [
  'label.kds_card_cta_start',
  'label.kds_card_cta_ready',
  'label.kds_lane_empty_dinein',
  'label.kds_lane_empty_online',
  'label.kds_lane_empty_takeaway',
  'label.kds_lane_empty_borne',
  'button.kds_print_ticket',
];

test.use({ viewport: { width: 1440, height: 900 } });
test.setTimeout(120_000);

/** Block every real mutating POST so the NF525 chain never advances. */
async function installChainSafety(page) {
  await page.route('**/*', (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /\/api\/admin\//.test(req.url())) {
      return route.abort();
    }
    return route.continue();
  });
}

async function assertNoRawKeyLeak(page) {
  const bodyText = await page.locator('body').innerText();
  for (const key of KDS_I18N_KEYS) {
    expect(
      bodyText.includes(key),
      `Raw i18n key "${key}" leaked into the KDS DOM — $t failed to resolve (a key present in source but missing/typo'd in the active locale).`
    ).toBe(false);
  }
}

test.describe('KDS P3 cosmetic heals — i18n keys resolve, never leak raw', () => {
  // ---------------------------------------------------------------------------
  // KDS-UI-04 — V2 grid CTA. With cards present the CTA reads "Démarrer"/"Prêt";
  // with an empty board the grid empty-state mounts. Either way NO raw key leaks.
  // ---------------------------------------------------------------------------
  test('KDS-UI-04 V2 grid: CTA + state labels resolve (no raw key leak)', async ({ page }) => {
    await installChainSafety(page);
    await loginAsAdmin(page);
    await page.goto(KDS_URL_V2, { waitUntil: 'domcontentloaded' });

    await expect(
      page.locator('.kds-v2__grid, .kds-v2__empty').first()
    ).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1500);

    // If any active card is rendered, its CTA must show a resolved label and the
    // accessible name must NOT be a raw key.
    const cta = page.locator('[data-testid="kds-card-cta-ready"]').first();
    if (await cta.count()) {
      const aria = await cta.getAttribute('aria-label');
      expect(aria, 'CTA aria-label must be resolved, not a raw key').not.toMatch(/^label\.kds_card_cta_/);
      const txt = (await cta.innerText()).trim();
      expect(txt.length, 'CTA must have visible text').toBeGreaterThan(0);
      expect(txt).not.toMatch(/label\.kds_card_cta_/);
    }

    await assertNoRawKeyLeak(page);
  });

  // ---------------------------------------------------------------------------
  // KDS-UI-05 — legacy lanes. The 4 lane columns each render an empty-state when
  // their list is empty + a print button per card. None may be a hardcoded
  // literal nor a raw key: assert the i18n-resolved copy renders.
  // ---------------------------------------------------------------------------
  test('KDS-UI-05 legacy lanes: empty-states + print button resolve (no raw key leak)', async ({ page }) => {
    await installChainSafety(page);
    await loginAsAdmin(page);
    await page.goto(KDS_URL_LEGACY, { waitUntil: 'domcontentloaded' });

    // The legacy KDS renders the 4 lane column headers (dine-in/online/takeaway/borne).
    // Wait for the legacy board shell to mount.
    await expect(page.locator('.db-card').first()).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1500);

    await assertNoRawKeyLeak(page);

    // At least one lane is empty on a clean board → its resolved empty-state copy
    // must be present (FR "Aucune commande …" OR EN "No … orders in progress.").
    // We assert the RESOLVED copy renders rather than the raw key — proving the
    // hardcoded-literal → i18n-key migration did not break rendering.
    const bodyText = await page.locator('body').innerText();
    const anyResolvedEmptyState =
      /Aucune commande/.test(bodyText) || /orders in progress/i.test(bodyText) ||
      /Bestellungen in Bearbeitung/.test(bodyText);
    expect(
      anyResolvedEmptyState,
      'On a clean legacy board at least one lane empty-state must render its RESOLVED i18n copy (not a raw label.kds_lane_empty_* key).'
    ).toBe(true);
  });
});
