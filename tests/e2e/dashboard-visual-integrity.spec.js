// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave B, task DASH-T13
// Dashboard visual integrity at large viewports (1920×1080 + 2560×1440).
//
// PURPOSE: prove /admin/dashboard renders a real, populated dashboard at
// large desktop resolutions — not a blank shell, not a layout that overflows
// horizontally, and with no raw i18n keys / NaN / undefined leaking to screen.
//
// DUAL GUARD:
//   - KPI cards (OverviewComponent — 3 cards keyed by their distinctive bg
//     colors) MUST be present (count===3) AND numerically populated. Values
//     init as `null` in the component and are filled by store dispatches, so
//     a blank shell renders empty <h4>. We assert digit-presence with a retry
//     (toContainText(/\d/)) — a blank dashboard that never populates FAILS.
//   - count !== 3 → the selector is wrong → fix the TEST.
//   - count === 3 but never populates, OR NaN/undefined/raw-key/overflow
//     present → REAL product finding → document (do not fix product here).
//
// Selectors grounded in resources/js/components/admin/dashboard/OverviewComponent.vue
//   bg-[#C81E63] = Total Sales, bg-[#4F35C8] = Total Orders, bg-[#6B21A8] = Total Menu Items
// Login flow reused verbatim from tests/e2e/09-admin-dashboards-ui.spec.js.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'dash-visual');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

// Login flow adapted from spec 09. The submit button label is locale-dependent:
// the live build (FR locale, ADR-007) renders "Connexion" — spec 09's /^login$/i
// matched nothing and hung the click. We match the FR label with an EN fallback,
// scoped to a submit button.
async function loginAsAdmin(page) {
  await page.goto('/login');
  await page.locator('input[type="text"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  const loginBtn = page
    .locator('button[type="submit"]')
    .filter({ hasText: /connexion|login|se connecter/i })
    .first();
  await loginBtn.click();
  await page.waitForURL(/\/admin\//, { timeout: 25_000 });
}

// The 3 KPI value <h4> nodes, keyed by their card's unambiguous bg color.
// `[class*="bg-[#C81E63]"]` is a valid attribute-substring selector — the
// brackets/# live inside the quoted value.
const KPI_VALUE_SELECTOR =
  '[class*="bg-[#C81E63]"] h4, [class*="bg-[#4F35C8]"] h4, [class*="bg-[#6B21A8]"] h4';

// Strings that must NEVER appear in any populated value or visible text.
const BROKEN_VALUE_RE = /\b(NaN|undefined|null)\b|0undefined/;

// Raw unresolved i18n key shape, e.g. "label.total_sales" / "menu.overview".
const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;
// i18n namespaces actually used in this app (label./menu./message./button.…).
// A token matching RAW_KEY_RE with one of these prefixes is a genuine
// unresolved translation; other matches (bare domains, file.ext) are benign.
const I18N_PREFIX_RE = /^(label|menu|message|button|table|placeholder|title|notification|enum|days|column)\./;

/**
 * @param {import('@playwright/test').Page} page
 * @param {{width:number,height:number,name:string}} vp
 */
async function runDashboardIntegrity(page, vp) {
  await page.setViewportSize({ width: vp.width, height: vp.height });

  await loginAsAdmin(page);

  if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  }

  // --- DUAL GUARD step 1: KPI cards present ---------------------------------
  const kpiValues = page.locator(KPI_VALUE_SELECTOR);
  // count===3 proves the selector hit the real Overview cards (not a shell).
  // If this fails the SELECTOR is wrong → fix the test (not a product bug).
  await expect(
    kpiValues,
    'Expected exactly 3 KPI value cards (Total Sales / Orders / Menu Items). ' +
      'count!==3 means the selector is wrong — fix the test.',
  ).toHaveCount(3, { timeout: 20_000 });

  // --- DUAL GUARD step 2: each KPI numerically populated --------------------
  // Values init as `null` → empty <h4>. This retry-wait is the REAL data gate
  // (networkidle is unreliable here — Echo/WS keeps the socket busy by design).
  // A blank dashboard that never populates FAILS here = real finding.
  for (let i = 0; i < 3; i++) {
    const val = kpiValues.nth(i);
    await expect(
      val,
      `KPI card #${i} never populated with a digit at ${vp.name} — ` +
        'blank/failed dashboard is a REAL product finding.',
    ).toContainText(/\d/, { timeout: 15_000 });

    const text = (await val.textContent())?.trim() ?? '';
    // 0 and "0,00 €" are validly populated; only NaN/undefined/null/0undefined fail.
    expect(
      BROKEN_VALUE_RE.test(text),
      `KPI card #${i} value is broken at ${vp.name}: "${text}"`,
    ).toBeFalsy();
  }

  // Settle any remaining widget mounts before scraping the full page text.
  await page.waitForLoadState('networkidle', { timeout: 12_000 }).catch(() => {});
  await page.waitForTimeout(1_500);

  // --- ASSERTION: no broken values anywhere in visible text -----------------
  const visibleText = await page.evaluate(() => document.body.innerText || '');
  expect(
    BROKEN_VALUE_RE.test(visibleText),
    `Found NaN/undefined/null/0undefined in visible dashboard text at ${vp.name}.\n` +
      'Context: ' +
      (visibleText.match(/.{0,40}(NaN|undefined|null|0undefined).{0,40}/) || [''])[0],
  ).toBeFalsy();

  // --- ASSERTION: no raw i18n keys leaking ----------------------------------
  const rawKeys = visibleText
    .split(/\s+/)
    .map((t) => t.trim())
    .filter((t) => t && RAW_KEY_RE.test(t) && I18N_PREFIX_RE.test(t));
  const uniqueRawKeys = [...new Set(rawKeys)];
  expect(
    uniqueRawKeys,
    `Unresolved raw i18n keys visible at ${vp.name}: ${uniqueRawKeys.slice(0, 10).join(', ')}`,
  ).toHaveLength(0);

  // --- ASSERTION: no horizontal overflow ------------------------------------
  // documentElement.scrollWidth vs the actual viewport width + small tolerance.
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    innerWidth: window.innerWidth,
  }));
  const tolerance = 2;
  expect(
    overflow.scrollWidth,
    `Horizontal overflow at ${vp.name}: documentElement.scrollWidth=${overflow.scrollWidth} ` +
      `> viewport ${vp.width} (+${tolerance}px tol). clientWidth=${overflow.clientWidth}, innerWidth=${overflow.innerWidth}.`,
  ).toBeLessThanOrEqual(vp.width + tolerance);

  // --- ASSERTION: no single element grossly wider than the viewport ---------
  // Lenient: only flags elements meaningfully wider than the viewport (>16px),
  // ignoring the html/body roots themselves. Avoids absolute/full-bleed flake.
  const wideEls = await page.evaluate((vw) => {
    const out = [];
    const all = document.body.querySelectorAll('*');
    for (const el of all) {
      const w = el.getBoundingClientRect().width;
      if (w > vw + 16) {
        out.push({
          tag: el.tagName.toLowerCase(),
          cls: (el.className && String(el.className).slice(0, 60)) || '',
          w: Math.round(w),
        });
      }
    }
    return out.slice(0, 8);
  }, vp.width);
  expect(
    wideEls,
    `Element(s) wider than viewport ${vp.width} at ${vp.name}: ` +
      JSON.stringify(wideEls),
  ).toHaveLength(0);

  // --- VISUAL EVIDENCE: full-page screenshot --------------------------------
  await page.screenshot({
    path: path.join(SCREENSHOT_DIR, `dashboard-${vp.name}.png`),
    fullPage: true,
  });
}

test.describe('DASH-T13 — dashboard visual integrity at large viewports', () => {
  test('1920×1080 — KPI populated, no NaN/raw-key, no overflow', async ({ page }) => {
    await runDashboardIntegrity(page, { width: 1920, height: 1080, name: '1920x1080' });
  });

  test('2560×1440 — KPI populated, no NaN/raw-key, no overflow', async ({ page }) => {
    await runDashboardIntegrity(page, { width: 2560, height: 1440, name: '2560x1440' });
  });
});
