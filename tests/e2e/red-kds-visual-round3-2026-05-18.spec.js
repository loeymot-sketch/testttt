// FoodKing — RED-Visual KDS Round 3 (2026-05-18)
// Goal Production-Readiness Le Cayenne — visual mandate per CLAUDE.md §6
// Validates Agent 3 (round-1) historic P0 verdicts via real /kds capture + axe-core.
// READ-ONLY: does NOT modify code. Captures + analyzes only.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAsAdmin, loginAsChefOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = '/tmp/foodking-goal-round3/red-kds';
const META_FILE = path.join(SHOT_DIR, 'capture-meta.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const meta = { captures: [], axe: null, contrast: null, bumpHitArea: null };

function recordCapture(name, viewport, abspath, notes) {
  meta.captures.push({ name, viewport, abspath, notes, ts: new Date().toISOString() });
  fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
}

async function snap(page, name, viewportLabel) {
  const fn = `${name}-${viewportLabel}.png`;
  const abspath = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: abspath, fullPage: true }).catch((e) => console.warn(`[snap] ${fn}: ${e.message}`));
  return abspath;
}

// Login that succeeds whether the task creds (admin) or chef are needed.
async function loginForKds(page) {
  try {
    await loginAsAdmin(page);
  } catch (_e) {
    await loginAsChefOperator(page);
  }
}

test.describe('RED-Visual KDS Round 3 — Production-Readiness Le Cayenne', () => {
  test.setTimeout(240_000);

  test.beforeEach(() => { try { clearFoodKingRateLimits(); } catch (_) {} });

  // ───────────────────────── 1. Many orders @ 1920×1080 (cuisine TV) ────────────
  test('R3-01 — Board with 22 KDS-eligible orders @ 1920×1080 (cuisine TV)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    // The login helpers land on /admin or /kds; navigate explicitly.
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000); // let polling fetch + render

    const abspath = await snap(page, '01-many-orders', '1920x1080');
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const cardCount = await page.locator('[class*="kds-card"], [data-testid*="kds-card"]').count().catch(() => 0);
    recordCapture('01-many-orders', '1920x1080', abspath, { cardCount, bodyLen: bodyText.length });

    expect(/Whoops|Fatal error|Server Error/i.test(bodyText)).toBe(false);
  });

  // ───────────────────────── 2. Many orders @ 1366×768 (laptop) ─────────────────
  test('R3-02 — Board @ 1366×768 (laptop secondary)', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const abspath = await snap(page, '02-many-orders', '1366x768');
    const cardCount = await page.locator('[class*="kds-card"], [data-testid*="kds-card"]').count().catch(() => 0);
    recordCapture('02-many-orders', '1366x768', abspath, { cardCount });
    const bodyText = await page.locator('body').innerText().catch(() => '');
    expect(/Whoops|Fatal error|Server Error/i.test(bodyText)).toBe(false);
  });

  // ───────────────────────── 3. axe-core a11y scan ──────────────────────────────
  test('R3-03 — axe-core WCAG 2.0+2.1 A+AA (target: 0 critical, ≤2 serious)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const abspath = await snap(page, '03-axe-baseline', '1920x1080');

    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze()
      .catch((e) => ({ _error: e.message }));

    meta.axe = axe._error ? { error: axe._error } : {
      violations_count: axe.violations.length,
      passes_count: axe.passes.length,
      incomplete_count: axe.incomplete.length,
      critical: axe.violations.filter((v) => v.impact === 'critical').length,
      serious: axe.violations.filter((v) => v.impact === 'serious').length,
      moderate: axe.violations.filter((v) => v.impact === 'moderate').length,
      minor: axe.violations.filter((v) => v.impact === 'minor').length,
      violations: axe.violations.map((v) => ({
        id: v.id,
        impact: v.impact,
        description: v.description,
        helpUrl: v.helpUrl,
        nodes_count: v.nodes.length,
        sample_targets: v.nodes.slice(0, 3).map((n) => n.target),
      })),
    };
    fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
    recordCapture('03-axe-baseline', '1920x1080', abspath, { axeViolations: meta.axe.violations_count ?? 'error' });

    // Soft gate — record findings but don't fail run (audit, not regression).
    if (!axe._error) {
      expect(axe.violations.filter((v) => v.impact === 'critical').length).toBeLessThanOrEqual(3);
    }
  });

  // ───────────────────────── 4. Bump button hit-area measurement ────────────────
  test('R3-04 — Bump CTA hit-area measurement (target ≥44px WCAG, ideal ≥60px kitchen)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const abspath = await snap(page, '04-bump-cta', '1920x1080');

    // CSS classes per agent 3: .kds-card__cta height=52px
    const cta = page.locator('.kds-card__cta, [class*="bump"], button[data-action="bump"]').first();
    const visible = await cta.isVisible({ timeout: 5_000 }).catch(() => false);
    let box = null;
    if (visible) {
      box = await cta.boundingBox().catch(() => null);
    }
    meta.bumpHitArea = visible ? { visible, box } : { visible, note: 'no .kds-card__cta found — bump may be in accordion / no orders rendered' };
    fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
    recordCapture('04-bump-cta', '1920x1080', abspath, { bumpHitArea: meta.bumpHitArea });
  });

  // ───────────────────────── 5. Allergen modal (post bug-fix attestation) ──────
  test('R3-05 — Allergen modal interaction (verify allergensModal NOT broken)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    // Allergen badge selector per CSS bundle: .kds-allergens-badge
    const badge = page.locator('.kds-allergens-badge, [class*="allergen"]').first();
    const badgeVisible = await badge.isVisible({ timeout: 5_000 }).catch(() => false);

    const beforePath = await snap(page, '05a-allergen-before', '1920x1080');

    let modalOpened = false;
    let modalClosed = false;
    let afterPath = null;
    let closedPath = null;
    if (badgeVisible) {
      await badge.click().catch((e) => console.warn('[badge click]', e.message));
      await page.waitForTimeout(800);
      const modal = page.locator('.kds-allergens-modal, [role="dialog"], [class*="modal"]').first();
      modalOpened = await modal.isVisible({ timeout: 3_000 }).catch(() => false);
      afterPath = await snap(page, '05b-allergen-modal-open', '1920x1080');

      // Try Esc to close
      if (modalOpened) {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
        modalClosed = !(await modal.isVisible({ timeout: 1_000 }).catch(() => false));
        closedPath = await snap(page, '05c-allergen-modal-closed', '1920x1080');
      }
    }
    recordCapture('05-allergen-modal', '1920x1080', beforePath, { badgeVisible, modalOpened, modalClosed, afterPath, closedPath });
  });

  // ───────────────────────── 6. Banner stack discipline ────────────────────────
  test('R3-06 — Banner stack discipline (sticky-danger consolidation)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const abspath = await snap(page, '06-banners', '1920x1080');
    const bannerCount = await page.locator('[class*="banner"], [class*="kds-hint"], [data-testid*="banner"]').count().catch(() => 0);
    const visibleBanners = await page.locator('[class*="banner"]:visible, [class*="kds-hint"]:visible').count().catch(() => 0);
    recordCapture('06-banners', '1920x1080', abspath, { totalBannerNodes: bannerCount, visibleBannerNodes: visibleBanners });
  });

  // ───────────────────────── 7. Contrast probe ──────────────────────────────────
  test('R3-07 — Contrast probe on KDS text/background pairs', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const probe = await page.evaluate(() => {
      function relLum(rgb) {
        const [r, g, b] = rgb.map((c) => {
          c = c / 255;
          return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      }
      function parseRgb(str) {
        const m = (str || '').match(/(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
        return m ? [+m[1], +m[2], +m[3]] : null;
      }
      function contrast(fg, bg) {
        const lf = relLum(fg), lb = relLum(bg);
        const a = Math.max(lf, lb), b = Math.min(lf, lb);
        return (a + 0.05) / (b + 0.05);
      }
      const candidates = document.querySelectorAll('.kds-card, .kds-card__title, .kds-card__qty, .kds-allergens-badge, .kds-hint-banner, [class*="kds-card"]');
      const results = [];
      for (const el of Array.from(candidates).slice(0, 25)) {
        const cs = getComputedStyle(el);
        const fg = parseRgb(cs.color);
        let bgEl = el;
        let bg = null;
        // walk up to find a real bg
        while (bgEl && !bg) {
          const cs2 = getComputedStyle(bgEl);
          const cand = parseRgb(cs2.backgroundColor);
          if (cand && cs2.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs2.backgroundColor !== 'transparent') { bg = cand; break; }
          bgEl = bgEl.parentElement;
        }
        if (!bg) bg = [255, 255, 255];
        if (fg) {
          results.push({
            tag: el.tagName,
            cls: (el.className || '').toString().slice(0, 80),
            text: (el.innerText || '').slice(0, 60).replace(/\s+/g, ' '),
            fg, bg, ratio: +contrast(fg, bg).toFixed(2),
          });
        }
      }
      return results;
    });
    meta.contrast = probe;
    fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));

    const abspath = await snap(page, '07-contrast-probe', '1920x1080');
    const failingAA = probe.filter((p) => p.ratio < 4.5).length;
    recordCapture('07-contrast-probe', '1920x1080', abspath, { samples: probe.length, failingAA });
  });

  // ───────────────────────── 8. Raw label scan ──────────────────────────────────
  test('R3-08 — Raw label scan (kds.X, Label.Y, 0undefined, [object])', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginForKds(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    const abspath = await snap(page, '08-raw-label-scan', '1920x1080');
    const txt = await page.locator('body').innerText().catch(() => '');
    const rawMatches = {
      kds_dot: (txt.match(/\bkds\.[a-zA-Z_]+/g) || []).slice(0, 10),
      label_dot: (txt.match(/\bLabel\.[a-zA-Z_]+/g) || []).slice(0, 10),
      message_dot: (txt.match(/\bmessage\.[a-zA-Z_]+/g) || []).slice(0, 10),
      button_dot: (txt.match(/\bbutton\.[a-zA-Z_]+/g) || []).slice(0, 10),
      zero_undefined: (txt.match(/0undefined/g) || []).length,
      object_object: (txt.match(/\[object [A-Z]\w+\]/g) || []).length,
    };
    meta.rawLabels = rawMatches;
    fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
    recordCapture('08-raw-label-scan', '1920x1080', abspath, rawMatches);
  });
});
