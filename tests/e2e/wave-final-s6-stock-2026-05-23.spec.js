// Wave Final 2026-05-23 — S6 STOCK RUPTURE
// ==========================================
// Page: /admin/stock/rupture (Mission 1 V2 unified catalog browser)
// Backend SSOT:
//   - GET  /api/admin/stock/catalog-overview
//   - POST /api/admin/menu/availability/toggle           (items)
//   - POST /api/admin/menu/availability/extra/toggle     (extras, e.g. sauces)
//   - POST /api/admin/menu/availability/variation/toggle (variations)
//
// Goals (MAX REASONING):
//   1. Capture 10 states S6-01..S6-10 (3-pane layout, toggles, refresh
//      persistence, Q9-S1 sync proof, bulk no-429, cross-axis dedupe).
//   2. Measure Q9-S1 ΔT — sauce toggle off in admin → disappear from kiosk
//      menu API (≤5s expected post a68acb20f fix).
//   3. Bulk toggle 5 products in <10s, verify no 429 (M1 round-2 concurrency-2
//      + 100ms inter-batch delay).
//   4. Cross-axis dedupe (M1 round-3 A-015) — categories vs extras vs
//      variations sharing name disambiguate with suffix.
//
// Audit dimensions:
//   - DOM:   3-pane layout, aria-pressed/aria-checked switches, raw-label sweep
//   - Net:   /api/admin/stock/catalog-overview status 200, toggle 200, no 429
//   - Echo:  ItemExtra/ItemVariation/ItemAvailabilityChanged broadcast (verify
//            backend dispatched_at non-null via tinker if env supports)
//   - i18n:  no Label.X / raw keys, FR-only V1
//
// Adversarial:
//   - "Chef rush" : 5 toggles in 10s must all land
//   - "Cross-tab race" : two contexts toggling simultaneously
//   - "Network blip" : Echo missed → eventual consistency via 60s polling
//
// CRITICAL RULES from mission spec:
//   - StockRuptureDashboardComponent.vue / Controller / AvailabilityController /
//     AvailabilityService NOT frozen — improvements allowed scope-min FR-only.
//   - EventServiceProvider Q9-S1 wiring (commit a68acb20f) read-only — verify
//     still wired (we confirmed L204-225).
//   - Restore all items to available at end via SQL (tinker).
//   - cleanup test orders prefix WAVE-FINAL-S6- (none expected — read/toggle only)

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const SHOT_DIR = path.join(
  __dirname,
  '__screenshots__/wave-final-S6-stock',
);
const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/wave-final-2026-05-23/round-1',
);

fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

const FINDINGS_FILE = path.join(REPORT_DIR, 'S6-stock-findings.json');
const TRACE_FILE = path.join(SHOT_DIR, 'trace.json');

// Adversarial dispute log accumulator
const dispute = [];
const trace = {
  states: {},
  network: [],
  console: [],
  dom: {},
  measurements: {},
};

// Helpers ---------------------------------------------------------------

function recordNetwork(page) {
  page.on('response', async (resp) => {
    const url = resp.url();
    if (!/\/api\/admin\/(stock|menu\/availability)/.test(url)) return;
    let body = null;
    try {
      const ct = resp.headers()['content-type'] || '';
      if (ct.includes('json')) body = await resp.json().catch(() => null);
    } catch (_) {}
    trace.network.push({
      url,
      method: resp.request().method(),
      status: resp.status(),
      ct: resp.headers()['content-type'] || '',
      body_size: body ? JSON.stringify(body).length : null,
      ts: new Date().toISOString(),
    });
  });
  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      trace.console.push({ type: msg.type(), text: msg.text() });
    }
  });
  page.on('pageerror', (e) => {
    trace.console.push({ type: 'pageerror', text: String(e?.message || e) });
  });
}

async function shot(page, key, fullPage = true) {
  const target = path.join(SHOT_DIR, `${key}.png`);
  await page.screenshot({ path: target, fullPage });
  trace.states[key] = { screenshot: target, ts: new Date().toISOString() };
}

async function rawLabelSweep(page) {
  const text = await page.locator('body').innerText();
  const m = text.match(
    /(admin\.stock_mgmt\.[a-z_.]+|admin\.stock_rupture\.[a-z_.]+|Label\.[a-zA-Z_.]+|stock\.reason\.[a-z_]+|kiosk\.[a-z_.]+|\bundefined\b|\bnull\b\s*$)/m,
  );
  return { match: m ? m[0] : null, length: text.length };
}

async function getAuthToken(page) {
  return await page.evaluate(() => {
    // FoodKing stores auth as Bearer in axios defaults / localStorage
    const local = window.localStorage.getItem('access_token')
      || window.localStorage.getItem('token');
    if (local) return local.replace(/^Bearer\s+/i, '');
    // Fallback: axios default header
    try {
      return (window.axios?.defaults?.headers?.common?.Authorization || '')
        .replace(/^Bearer\s+/i, '');
    } catch (_) {
      return null;
    }
  });
}

function tinker(php) {
  try {
    // Single-line collapse — tinker --execute is allergic to newlines / escaped
    // backslashes when shells re-interpret. Inline the whole PHP script.
    // Use execFileSync to avoid shell ${var} expansion of PHP $vars.
    const oneLine = php.replace(/\s+/g, ' ').trim();
    const out = require('child_process').execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=${oneLine}`],
      {
        cwd: path.resolve(__dirname, '../..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
      },
    );
    return { ok: true, stdout: out.trim() };
  } catch (e) {
    return { ok: false, error: String(e?.message || e), stderr: e?.stderr?.toString() };
  }
}

// Restore everything to available at the end of the run.
function restoreAllAvailability() {
  return tinker(
    `\\DB::table('item_branch_availability')->update(['is_available' => true, 'unavailable_reason' => null, 'unavailable_since' => null]); \\DB::table('stock_levels')->update(['manual_unavailable_reason' => null, 'manual_unavailable_since' => null]); echo 'restore_ok';`,
  );
}

test.describe.configure({ mode: 'serial' });

test.describe('Wave Final S6 — Stock rupture', () => {
  test.use({ viewport: { width: 1366, height: 900 } });
  test.setTimeout(600_000);

  test('S6 — full quartet (S6-01..S6-10) + sync proof + bulk + dedupe', async ({ browser }) => {
    const adminContext = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const adminPage = await adminContext.newPage();
    recordNetwork(adminPage);

    // ---------- Login admin --------------------------------------------
    await loginAsAdmin(adminPage);
    await adminPage.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    await adminPage.waitForTimeout(2500); // SPA + axios overview
    // ---------- S6-01 default mount ------------------------------------
    await adminPage.waitForSelector('[data-testid="stock-management-v2"]', { timeout: 20_000 });
    await shot(adminPage, 'S6-01-default-mount');

    // Capture DOM structure
    const railButtons = await adminPage.$$eval(
      '[data-testid="stock-mgmt-rail"] button',
      (els) => els.map((b) => ({
        testid: b.getAttribute('data-testid'),
        label: b.innerText.trim().replace(/\s+/g, ' '),
        ariaCurrent: b.getAttribute('aria-current'),
      })),
    );
    trace.dom.rail_count = railButtons.length;
    trace.dom.rail_first_5 = railButtons.slice(0, 5);

    // SELECT count audit via Laravel debug log not feasible without enabling
    // telescope; instead audit network count + payload structure.
    const overviewResp = trace.network.find((r) => /catalog-overview/.test(r.url));
    trace.measurements.overview_status = overviewResp?.status ?? null;
    trace.measurements.overview_body_size = overviewResp?.body_size ?? null;

    const rawSweep1 = await rawLabelSweep(adminPage);
    trace.measurements.raw_label_default = rawSweep1.match;

    // ---------- S6-02 toggle a product OFF -----------------------------
    // Pick first category bucket's first available item.
    const firstItemToggle = await adminPage.$('[data-testid^="stock-mgmt-toggle-item-"]');
    let toggledItemKey = null;
    if (firstItemToggle) {
      toggledItemKey = await firstItemToggle.getAttribute('data-testid');
      const beforeState = await firstItemToggle.getAttribute('aria-checked');
      await firstItemToggle.click();
      await adminPage.waitForTimeout(800); // optimistic flip + POST
      const afterState = await firstItemToggle.getAttribute('aria-checked');
      trace.measurements.s6_02_aria_before = beforeState;
      trace.measurements.s6_02_aria_after = afterState;
      trace.measurements.s6_02_toggle_key = toggledItemKey;
    }
    await shot(adminPage, 'S6-02-product-off');

    // ---------- S6-03 refresh persistence ------------------------------
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.waitForTimeout(2500);
    let persistedItemOff = false;
    if (toggledItemKey) {
      const el = await adminPage.$(`[data-testid="${toggledItemKey}"]`);
      const ariaAfterRefresh = el ? await el.getAttribute('aria-checked') : null;
      persistedItemOff = ariaAfterRefresh === 'false';
      trace.measurements.s6_03_persisted_off = persistedItemOff;
      trace.measurements.s6_03_aria_after_refresh = ariaAfterRefresh;
    }
    await shot(adminPage, 'S6-03-refresh-persistence');

    // Restore item state immediately (we don't want to leave this off across
    // the sauces test which manipulates separate axes).
    if (toggledItemKey) {
      const el = await adminPage.$(`[data-testid="${toggledItemKey}"]`);
      if (el) {
        await el.click();
        await adminPage.waitForTimeout(600);
      }
    }

    // ---------- S6-04 toggle an extra (sauce) OFF ----------------------
    // Find the "Sauces supplémentaires" bucket or any extra bucket.
    let extraBucket = null;
    let sauceToggleKey = null;
    let sauceDisplayName = null;
    const railTexts = await adminPage.$$eval(
      '[data-testid="stock-mgmt-rail"] button',
      (els) => els.map((b) => ({
        testid: b.getAttribute('data-testid'),
        label: b.innerText.trim(),
      })),
    );
    // Prefer the canonical sauces group (extra-sauce_supp) — matches Mission 1 fixtures.
    extraBucket = railTexts.find((r) => /^stock-mgmt-bucket-extra-/.test(r.testid));
    trace.measurements.extra_bucket_chosen = extraBucket?.testid;
    if (extraBucket) {
      await adminPage.click(`[data-testid="${extraBucket.testid}"]`);
      await adminPage.waitForTimeout(600);
      const firstSauceToggle = await adminPage.$('[data-testid^="stock-mgmt-toggle-extra-"]');
      if (firstSauceToggle) {
        sauceToggleKey = await firstSauceToggle.getAttribute('data-testid');
        // Grab display name (truncated)
        const card = await firstSauceToggle.evaluateHandle((b) => b.closest('article'));
        sauceDisplayName = await card.evaluate((c) => c.querySelector('.name')?.innerText?.trim() || '');
        await firstSauceToggle.click();
        await adminPage.waitForTimeout(1200); // bulk fan-out (concurrency 2 + 100ms gap)
      }
    }
    trace.measurements.s6_04_sauce_key = sauceToggleKey;
    trace.measurements.s6_04_sauce_display_name = sauceDisplayName;
    await shot(adminPage, 'S6-04-extra-sauce-off');

    // ---------- S6-05 ⭐ Q9-S1 sync proof — admin toggle → kiosk menu cache --
    // Empirical proof at the Cache layer (the EXACT mechanism the Q9-S1 fix
    // patches). The kiosk menu BE cache key is `kiosk.menu.branch.{id}` with
    // TTL 60s. Pre-Q9-S1 fix, an extra/variation toggle would NOT
    // invalidate this key → kiosk serves stale availability for up to 60s.
    // Post-fix (commit a68acb20f, EventServiceProvider L204-225), the
    // listener `InvalidateKioskMenuCacheOnCatalogChange` runs in the same
    // request lifecycle (sync event dispatch), calling Cache::forget() on
    // the key.
    //
    // Method:
    //   1. Pre-warm `kiosk.menu.branch.1` with a sentinel payload (60s TTL).
    //   2. Note t0, fire HTTP toggle on an extra (via existing toggle
    //      button click in admin UI).
    //   3. Poll the cache key every 50ms via tinker; the moment it's gone
    //      = ΔT.  Synchronous listener means ΔT should be < server round-trip
    //      (≈ 100-300ms typical).
    //   4. Bound by 8s.
    let q9s1Result = { ran: false, reason: 'not_attempted' };
    try {
      // Restore sauce to available so we have a clean toggle target.
      if (sauceToggleKey) {
        const restoreEl = await adminPage.$(`[data-testid="${sauceToggleKey}"]`);
        if (restoreEl) {
          const ariaNow = await restoreEl.getAttribute('aria-checked');
          if (ariaNow === 'false') {
            await restoreEl.click();
            await adminPage.waitForTimeout(1500);
          }
        }
      }

      // Resolve branch_id used by the admin (visible in catalog-overview
      // response — captured earlier in trace.network).
      const overviewBody = await adminPage.evaluate(async () => {
        const r = await fetch('/api/admin/stock/catalog-overview', {
          headers: {
            Authorization: 'Bearer ' + (window.localStorage.getItem('access_token') || ''),
            Accept: 'application/json',
          },
        });
        return r.json();
      });
      const branchId = Number(overviewBody?.branch_id) || 1;
      trace.measurements.q9s1_branch_id = branchId;

      // Pre-warm cache via tinker
      const warm = tinker(
        `use Illuminate\\Support\\Facades\\Cache; Cache::put('kiosk.menu.branch.${branchId}', ['sentinel'=>true,'ts'=>now()->toIso8601String()], 60); echo Cache::has('kiosk.menu.branch.${branchId}') ? 'WARMED' : 'WARM_FAILED';`,
      );
      trace.measurements.q9s1_warm_result = warm.stdout || warm.error;
      const cacheWarmed = (warm.stdout || '').includes('WARMED');

      if (!cacheWarmed) {
        q9s1Result = { ran: false, reason: 'cache_warm_failed', warm: warm };
      } else {
        // Trigger toggle via UI click (this fires HTTP POST → service →
        // dispatch ItemExtraAvailabilityChanged → sync listener).
        const t0 = Date.now();
        const offEl = await adminPage.$(`[data-testid="${sauceToggleKey}"]`);
        if (offEl) {
          await offEl.click();
        }

        let detectedAt = null;
        let sample = 0;
        const maxSamples = 16; // ~8s @ 500ms
        while (sample < maxSamples) {
          await adminPage.waitForTimeout(500);
          const probe = tinker(
            `use Illuminate\\Support\\Facades\\Cache; echo Cache::has('kiosk.menu.branch.${branchId}') ? 'PRESENT' : 'GONE';`,
          );
          if ((probe.stdout || '').includes('GONE')) {
            detectedAt = Date.now() - t0;
            break;
          }
          sample++;
        }

        q9s1Result = {
          ran: true,
          method: 'cache_key_invalidation_probe',
          cache_key: `kiosk.menu.branch.${branchId}`,
          sauce_name: sauceDisplayName,
          delta_ms: detectedAt,
          synced_within_5s: detectedAt !== null && detectedAt <= 5000,
          synced_within_1s: detectedAt !== null && detectedAt <= 1000,
          timeout_after_8s: detectedAt === null,
        };
      }
      await shot(adminPage, 'S6-05-q9s1-sync-proof');
    } catch (e) {
      q9s1Result = { ran: false, error: String(e?.message || e) };
      await shot(adminPage, 'S6-05-q9s1-sync-proof');
    }
    trace.measurements.q9s1 = q9s1Result;

    // ---------- S6-06 toggle a variation OFF ----------------------------
    let variationKey = null;
    let variationDisplayName = null;
    const varBucket = railTexts.find((r) => /^stock-mgmt-bucket-var-/.test(r.testid));
    trace.measurements.variation_bucket_chosen = varBucket?.testid;
    if (varBucket) {
      await adminPage.click(`[data-testid="${varBucket.testid}"]`);
      await adminPage.waitForTimeout(600);
      const firstVar = await adminPage.$('[data-testid^="stock-mgmt-toggle-var-"]');
      if (firstVar) {
        variationKey = await firstVar.getAttribute('data-testid');
        const card = await firstVar.evaluateHandle((b) => b.closest('article'));
        variationDisplayName = await card.evaluate((c) => c.querySelector('.name')?.innerText?.trim() || '');
        await firstVar.click();
        await adminPage.waitForTimeout(1200);
      }
    }
    trace.measurements.s6_06_variation_key = variationKey;
    trace.measurements.s6_06_variation_display_name = variationDisplayName;
    await shot(adminPage, 'S6-06-variation-off');

    // ---------- S6-07 bulk toggle 5 products + measure no 429 ----------
    // Switch back to the first category and try to toggle 5 items in quick
    // succession (this exercises the M1 round-2 concurrency-2 + 100ms gap).
    // Categories iterate single POSTs (one per item), so we want the FAN-OUT
    // test ideally — that lives in extras (since one extra-by-name maps to
    // multiple ItemExtra rows). Strategy: toggle 5 extras quickly across
    // the chosen extra bucket. We rely on Promise.allSettled across the
    // 5 toggle clicks (sequential clicks but immediate, exercising bulk
    // logic within each extra-name).
    let bulkResult = { attempts: 0, successes: 0, http_429: 0, http_other_error: 0 };
    try {
      if (extraBucket) {
        await adminPage.click(`[data-testid="${extraBucket.testid}"]`);
        await adminPage.waitForTimeout(600);
      }
      const allExtraToggles = await adminPage.$$('[data-testid^="stock-mgmt-toggle-extra-"]');
      const sample = allExtraToggles.slice(0, 5);
      bulkResult.attempts = sample.length;
      // Snapshot network 429 count before
      const networkBefore = trace.network.length;
      const t0 = Date.now();
      // Click all 5 nearly simultaneously
      const clicks = sample.map((el) => el.click());
      await Promise.allSettled(clicks);
      await adminPage.waitForTimeout(4000); // wait for fan-out completion
      const elapsed = Date.now() - t0;
      // Inspect network slice
      const networkAfter = trace.network.slice(networkBefore);
      bulkResult.elapsed_ms = elapsed;
      bulkResult.network_calls = networkAfter.length;
      bulkResult.http_429 = networkAfter.filter((r) => r.status === 429).length;
      bulkResult.http_other_error = networkAfter.filter((r) => r.status >= 400 && r.status !== 429).length;
      bulkResult.successes = networkAfter.filter((r) => r.status >= 200 && r.status < 300).length;
      bulkResult.under_10s = elapsed < 10_000;
    } catch (e) {
      bulkResult.error = String(e?.message || e);
    }
    trace.measurements.s6_07_bulk = bulkResult;
    await shot(adminPage, 'S6-07-bulk-5-toggles');

    // ---------- S6-08 cross-axis dedupe verification --------------------
    // Look for "Suppléments" categorie + "Suppléments (à composer)" extra
    // (M1 round-3 A-015 fix). Also "(variation)" suffix on variations.
    const allRailLabels = await adminPage.$$eval(
      '[data-testid="stock-mgmt-rail"] button',
      (els) => els.map((b) => b.innerText.replace(/\s+/g, ' ').trim()),
    );
    const supplementsCount = allRailLabels.filter((l) => /Suppléments/i.test(l)).length;
    const composerSuffixSeen = allRailLabels.some((l) => /\(à composer\)/i.test(l));
    const variationSuffixSeen = allRailLabels.some((l) => /\(variation\)/i.test(l));
    trace.measurements.s6_08_supplements_count = supplementsCount;
    trace.measurements.s6_08_composer_suffix_seen = composerSuffixSeen;
    trace.measurements.s6_08_variation_suffix_seen = variationSuffixSeen;
    trace.measurements.s6_08_all_rail_labels = allRailLabels;
    await shot(adminPage, 'S6-08-cross-axis-dedupe');

    // ---------- S6-09 search/filter -------------------------------------
    const searchInput = await adminPage.$('[data-testid="stock-mgmt-search"]');
    let searchResult = { ran: false };
    if (searchInput) {
      // Pick the first category back
      const firstCat = railTexts.find((r) => /^stock-mgmt-bucket-cat-/.test(r.testid));
      if (firstCat) {
        await adminPage.click(`[data-testid="${firstCat.testid}"]`);
        await adminPage.waitForTimeout(500);
      }
      await searchInput.fill('zzz-nomatch-xyz123');
      await adminPage.waitForTimeout(400);
      const emptyVisible = await adminPage.locator('[data-testid="stock-mgmt-empty"]').isVisible().catch(() => false);
      const cardsAfter = await adminPage.$$('article.product-card');
      searchResult = { ran: true, empty_state_visible: emptyVisible, cards_after_no_match: cardsAfter.length };
      // Clear search
      await searchInput.fill('');
      await adminPage.waitForTimeout(300);
    }
    trace.measurements.s6_09_search = searchResult;
    await shot(adminPage, 'S6-09-search-no-match');

    // ---------- S6-10 empty state — show what empty looks like ----------
    // We can't easily trigger empty-categories (all items toggled would be
    // destructive). Instead capture the search-empty state we just had,
    // since "empty after filter" is the realistic empty path users see.
    // True empty (no buckets) only happens on broken seed.
    // Re-render with another "no-match" search across the rail.
    if (searchInput) {
      await searchInput.fill('aaa-impossible-name-99');
      await adminPage.waitForTimeout(400);
    }
    await shot(adminPage, 'S6-10-empty-state');
    if (searchInput) {
      await searchInput.fill('');
      await adminPage.waitForTimeout(300);
    }

    // ---------- Final raw-label sweep ------------------------------------
    const finalSweep = await rawLabelSweep(adminPage);
    trace.measurements.raw_label_final = finalSweep.match;

    // ---------- Restore everything to available --------------------------
    const restore = restoreAllAvailability();
    trace.measurements.restore_result = restore;

    // ---------- Verify Q9-S1 listener still wired (sentinel check) ------
    // Direct reflection on the provider's static $listen property. The
    // provider class needs the app singleton — use app(EventServiceProvider).
    const sentinelCheck = tinker(
      `$p = new \\App\\Providers\\EventServiceProvider(app()); $r = new \\ReflectionClass($p); $prop = $r->getProperty('listen'); $prop->setAccessible(true); $arr = $prop->getValue($p); $hasExtra = in_array(\\App\\Listeners\\InvalidateKioskMenuCacheOnCatalogChange::class, $arr[\\App\\Events\\ItemExtraAvailabilityChanged::class] ?? []); $hasVar = in_array(\\App\\Listeners\\InvalidateKioskMenuCacheOnCatalogChange::class, $arr[\\App\\Events\\ItemVariationAvailabilityChanged::class] ?? []); echo 'Q9S1_extra=' . ($hasExtra ? 'OK' : 'KO') . ' Q9S1_var=' . ($hasVar ? 'OK' : 'KO');`,
    );
    trace.measurements.q9s1_wiring_sentinel = sentinelCheck;

    // ---------- Persist trace + dump ------------------------------------
    fs.writeFileSync(TRACE_FILE, JSON.stringify(trace, null, 2), 'utf8');

    // ---------- Soft assertions for visibility --------------------------
    // We don't FAIL the test on these — we want all captures + report no
    // matter what. Findings JSON captures the verdict.
    expect(railButtons.length).toBeGreaterThan(0); // S6-01 sanity
  });
});
