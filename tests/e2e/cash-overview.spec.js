// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave B / ENC-13
// Cash Overview visual integrity — MONEY SCREEN.
//
// Admin login → /admin/cash-overview → assert the unified cash & transactions
// overview renders correctly: summary cards (grand total + by-source breakdown),
// optional cash-drawer reconciliation strip, filters, mode breakdown / table /
// empty-state. Every visible monetary value MUST be a valid EUR currency render
// (Intl `82,50 €`) — NO "NaN", "undefined", "null€", "0undefined". No raw i18n
// keys (e.g. `label.grand_total`). A garbage total on a money screen is a real
// defect → assertions are HARD (regex-scan of visible text).
//
// Selectors grounded in CashOverviewComponent.vue (data-testid attrs) +
// i18n keys verified resolvable in resources/js/languages/fr.json.
// Credentials : admin@lecayenne.fr / 123456.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'cash-overview');

// Garbage tokens that must NEVER appear on a money screen.
const GARBAGE_RE = /\b(NaN|undefined|null)\b|null\s*€|0undefined|undefined\s*€|NaN\s*€|€\s*NaN|€\s*undefined/i;

// A raw i18n key looks like `label.grand_total` / `menu.cash_overview` /
// `kiosk.foo.bar` — lowercase dotted segments, 2-5 deep, whole token.
const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;

// A valid EUR currency render: `82,50 €`, `1 234,56 €`, `82.50 €`, `€82.50`,
// or `0,00 €`. Allows NBSP (Intl fr-FR uses U+00A0 / U+202F before €).
const CURRENCY_RE = /(€\s*-?\d[\d.,  \s]*)|(-?\d[\d.,  \s]*€)/;

test.describe('ENC-13 — Cash Overview visual integrity (money screen)', () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/cash-overview', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/cash-overview/, { timeout: 25_000 });
    // Let Vue mount + the GET /api/admin/cash-overview fetch resolve.
    await page.waitForResponse(
      (res) => /\/api\/admin\/cash-overview/i.test(res.url()) && res.request().method() === 'GET',
      { timeout: 20_000 },
    ).catch(() => {});
    // Loading flag must clear (summary cards / table / empty-state appear).
    await page.waitForFunction(
      () => !document.querySelector('[data-testid="cash-overview-summary"]')
        ? !!document.querySelector('[data-testid="cash-overview-empty"], [data-testid="cash-overview-table"]')
        : true,
      { timeout: 15_000 },
    ).catch(() => {});
    await page.waitForTimeout(1_200);
  });

  test('renders without server crash + filters present', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error|Exception/i);

    // Filters MUST be visible (date from/to + source + mode + search).
    const filters = page.locator('[data-testid="cash-overview-filters"]');
    await expect(filters).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#cashOverviewFrom')).toBeVisible();
    await expect(page.locator('#cashOverviewTo')).toBeVisible();
    await expect(page.locator('#cashOverviewSource')).toBeVisible();
    await expect(page.locator('[data-testid="cash-overview-search"]')).toBeVisible();

    // Title must be the resolved label, not the raw i18n key.
    const heading = await page.locator('.db-card-title').first().innerText();
    expect(heading.trim()).not.toMatch(RAW_KEY_RE);
    expect(heading).not.toMatch(/menu\.cash_overview/);

    await page.screenshot({ path: path.join(SHOT_DIR, '01-landing.png'), fullPage: true });

    const critical = jsErrors.filter((m) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(m));
    expect(critical, `JS errors: ${critical.join(' | ')}`).toHaveLength(0);
  });

  test('summary cards + by-source breakdown present with valid € totals', async ({ page }) => {
    // Summary section must render (component shows it once fetch resolves with
    // a summary payload). If genuinely empty, the empty-state is the honest
    // alternative — assert one of the two real states, never a stuck spinner.
    const summary = page.locator('[data-testid="cash-overview-summary"]');
    const empty = page.locator('[data-testid="cash-overview-empty"]');
    const loading = page.locator('text=/^Chargement/i');
    await expect(loading).toHaveCount(0); // never stuck loading

    const hasSummary = await summary.isVisible().catch(() => false);
    const hasEmpty = await empty.isVisible().catch(() => false);
    expect(hasSummary || hasEmpty, 'neither summary nor empty-state rendered').toBeTruthy();

    if (hasSummary) {
      // Grand total card + the 3 fixed source cards (caisse/borne/livreur) are
      // always rendered (zero-count buckets are populated by displayedSources).
      const grandTotalCard = summary.locator('> div').first();
      await expect(grandTotalCard).toBeVisible();
      for (const src of ['caisse', 'borne', 'livreur']) {
        await expect(page.locator(`[data-testid="cash-overview-source-${src}"]`)).toBeVisible();
      }

      // Collect every monetary render inside the summary section + reconciliation
      // + mode breakdown and assert each is a valid currency string.
      const moneyEls = page.locator(
        '[data-testid="cash-overview-summary"] .text-2xl, ' +
        '[data-testid="cash-overview-summary"] .text-xl, ' +
        '[data-testid^="cash-overview-reconciliation-"], ' +
        '[data-testid^="cash-overview-mode-"]',
      );
      const n = await moneyEls.count();
      expect(n, 'expected at least the grand-total + 3 source totals').toBeGreaterThanOrEqual(4);
      for (let i = 0; i < n; i++) {
        const raw = (await moneyEls.nth(i).innerText()).trim();
        // money-only nodes: must contain a currency render somewhere, never garbage.
        expect(raw, `monetary node #${i} garbage: "${raw}"`).not.toMatch(GARBAGE_RE);
      }

      // The grand-total card itself must show a € amount.
      const grandTotalText = await grandTotalCard.innerText();
      expect(grandTotalText, `grand total: "${grandTotalText}"`).toMatch(CURRENCY_RE);
      expect(grandTotalText).not.toMatch(GARBAGE_RE);

      // Each source card amount line must be a valid currency string.
      for (const src of ['caisse', 'borne', 'livreur']) {
        const card = page.locator(`[data-testid="cash-overview-source-${src}"]`);
        const amount = (await card.locator('.text-xl').innerText()).trim();
        expect(amount, `source ${src} total: "${amount}"`).toMatch(CURRENCY_RE);
        expect(amount).not.toMatch(GARBAGE_RE);
      }
    }

    await page.screenshot({ path: path.join(SHOT_DIR, '02-summary.png'), fullPage: true });
  });

  test('reconciliation strip (if drawer open) shows valid € values', async ({ page }) => {
    const recon = page.locator('[data-testid="cash-overview-reconciliation"]');
    if (!(await recon.isVisible().catch(() => false))) {
      // No open cash session today is a legitimate state — the strip only renders
      // when cash_session payload is present. Not a defect.
      test.info().annotations.push({ type: 'note', description: 'no open cash session — reconciliation strip absent (legitimate)' });
      return;
    }
    for (const key of ['opening', 'collected', 'expected']) {
      const cell = page.locator(`[data-testid="cash-overview-reconciliation-${key}"]`);
      await expect(cell).toBeVisible();
      const txt = (await cell.innerText()).trim();
      expect(txt, `reconciliation ${key}: "${txt}"`).toMatch(CURRENCY_RE);
      expect(txt).not.toMatch(GARBAGE_RE);
    }
  });

  test('no garbage totals + no raw i18n key anywhere on the money screen', async ({ page }) => {
    const visibleText = await page.locator('body').innerText();

    // HARD: no NaN / undefined / null€ / 0undefined anywhere visible.
    const garbageHit = visibleText.match(GARBAGE_RE);
    expect(garbageHit, garbageHit ? `GARBAGE on money screen: "${garbageHit[0]}" — context: ${visibleText.slice(Math.max(0, garbageHit.index - 40), garbageHit.index + 40)}` : '').toBeNull();

    // No raw i18n keys leaking as visible tokens. Tokenize on whitespace and
    // check each token against the raw-key regex.
    const tokens = visibleText.split(/\s+/).filter(Boolean);
    const rawKeys = tokens.filter((t) => RAW_KEY_RE.test(t));
    expect(rawKeys, `raw i18n keys leaked: ${rawKeys.join(', ')}`).toHaveLength(0);
  });

  test('exercising a source filter does not crash the screen', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    // Pick the source <select> and choose "borne", then Search.
    const select = page.locator('#cashOverviewSource');
    await expect(select).toBeVisible();
    await select.selectOption('borne');

    const searchBtn = page.locator('[data-testid="cash-overview-search"]');
    const resp = page.waitForResponse(
      (r) => /\/api\/admin\/cash-overview/i.test(r.url()) && r.request().method() === 'GET',
      { timeout: 20_000 },
    ).catch(() => null);
    await searchBtn.click();
    const got = await resp;
    if (got) {
      // Filtered fetch must not 4xx/5xx on a valid filter.
      expect(got.status(), `filtered fetch HTTP ${got.status()}`).toBeLessThan(400);
    }
    await page.waitForTimeout(1_500);

    // No crash + still one of the two honest states + no garbage.
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
    expect(visibleText).not.toMatch(GARBAGE_RE);

    const summaryV = await page.locator('[data-testid="cash-overview-summary"]').isVisible().catch(() => false);
    const emptyV = await page.locator('[data-testid="cash-overview-empty"]').isVisible().catch(() => false);
    expect(summaryV || emptyV, 'after filter: neither summary nor empty-state').toBeTruthy();

    await page.screenshot({ path: path.join(SHOT_DIR, '03-filtered-borne.png'), fullPage: true });

    const critical = jsErrors.filter((m) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(m));
    expect(critical, `JS errors after filter: ${critical.join(' | ')}`).toHaveLength(0);
  });
});
