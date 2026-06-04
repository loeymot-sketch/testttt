// WAVE E — T3 A11y Keyboard-only Navigation (read+test only)
// Date: 2026-05-29 · Branch: feature/mobile-app-le-cayenne-2026-05-10
//
// Mission: Navigate POS + KDS + Kiosk + Admin uniquement au clavier.
// Verify focus order + visible focus + no traps + Enter/Space activation +
// axe-core ARIA compliance.
//
// Output:
//   - Screenshots focus state: /tmp/foodking-wave-e-2026-05-29/a11y-kb/
//   - Findings JSON:    reports/test-e2e/supervisor-wave-e-2026-05-29/A11Y-KB/findings.json
//
// Read+test only. No source edits. Single spec, one test() per surface to keep
// failures isolated. Per surface: Tab N times, check focus-visible + trap +
// Enter activation, run axe-core critical+serious, save screenshot mid-Tab.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const AxeBuilder = require('@axe-core/playwright').default;
const {
  loginAsAdmin,
  loginAsChefOperator,
  loginAsPosOperator,
  loginAsKiosk,
} = require('./helpers/login');

const REPORT_ROOT = path.resolve(
  'reports/test-e2e/supervisor-wave-e-2026-05-29/A11Y-KB',
);
const SCREENSHOT_DIR = '/tmp/foodking-wave-e-2026-05-29/a11y-kb';
fs.mkdirSync(REPORT_ROOT, { recursive: true });
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// Per-surface findings collected here, written by afterAll in serial mode.
const FINDINGS = {
  meta: {
    spec: '_wave-e-t3-a11y-keyboard-2026-05-29.spec.js',
    generated_at: new Date().toISOString(),
    base_url: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000',
    branch: process.env.GIT_BRANCH || 'feature/mobile-app-le-cayenne-2026-05-10',
    scope:
      'Keyboard-only navigation (Tab/Shift+Tab/Enter/Space/Escape) + focus-visible + trap detection + axe-core ARIA',
  },
  surfaces: {},
};

// ---------- focus probe (runs in page) ----------
// Walk N Tab presses. At each step, capture the activeElement's tag, role,
// label, and computed focus styling. Detect trap (same element twice in a row
// after Tab) and cycle (activeElement returns to first).
async function tabWalk(page, opts = {}) {
  const maxSteps = opts.maxSteps || 80;
  const sequence = [];

  // Kick focus into the document body first so the first Tab lands deterministic.
  await page.evaluate(() => {
    if (document.body) document.body.focus({ preventScroll: true });
    if (document.activeElement && document.activeElement !== document.body) {
      try { document.activeElement.blur(); } catch (_) {}
    }
    document.body.tabIndex = -1; // make body focusable so initial focus is stable
  });

  for (let i = 0; i < maxSteps; i++) {
    await page.keyboard.press('Tab');
    // Wait for any focus listener side-effect to settle (popups, focus
    // managers). Stay short to keep the walk under budget.
    await page.waitForTimeout(40);

    const snap = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) {
        return { i: -1, tag: 'BODY', role: null, label: null, text: null, styles: null };
      }
      const cs = window.getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return {
        tag: el.tagName,
        type: el.getAttribute('type'),
        id: el.id || null,
        name: el.getAttribute('name'),
        role: el.getAttribute('role'),
        aria_label: el.getAttribute('aria-label'),
        aria_labelledby: el.getAttribute('aria-labelledby'),
        tabindex: el.getAttribute('tabindex'),
        // Truncate visible text to 60 chars (focus order debug only)
        text: (el.innerText || el.value || '').toString().slice(0, 60),
        disabled: el.disabled || el.getAttribute('aria-disabled') === 'true',
        href: el.getAttribute('href'),
        styles: {
          outlineStyle: cs.outlineStyle,
          outlineWidth: cs.outlineWidth,
          outlineColor: cs.outlineColor,
          boxShadow: cs.boxShadow,
          // visibility checks
          visibility: cs.visibility,
          display: cs.display,
          width: rect.width,
          height: rect.height,
        },
        // Selector path (compact CSS path, best-effort)
        path: (() => {
          const segs = [];
          let n = el;
          for (let d = 0; d < 5 && n && n.tagName; d++) {
            let s = n.tagName.toLowerCase();
            if (n.id) { s += `#${n.id}`; segs.unshift(s); break; }
            if (n.className && typeof n.className === 'string') {
              s += '.' + n.className.trim().split(/\s+/).slice(0, 2).join('.');
            }
            segs.unshift(s);
            n = n.parentElement;
          }
          return segs.join(' > ');
        })(),
      };
    });

    sequence.push({ step: i, ...snap });

    // Trap detection: same focusable two consecutive Tabs (excluding BODY).
    if (i > 0) {
      const prev = sequence[i - 1];
      const sameAsPrev =
        snap.tag !== 'BODY' &&
        prev.tag !== 'BODY' &&
        prev.path === snap.path &&
        prev.text === snap.text;
      if (sameAsPrev) {
        sequence[i].trap_suspected_with_prev = true;
      }
    }
  }
  return sequence;
}

// Classify focus-visible per WCAG 2.4.7 (Focus Visible).
// PASS: outlineStyle !== 'none' AND outlineWidth > 0, OR boxShadow contains a
// ring (heuristic: includes 'rgb' or 'inset' AND at least 2px spread).
function focusVisibleOk(styles) {
  if (!styles) return false;
  const outlineOk =
    styles.outlineStyle &&
    styles.outlineStyle !== 'none' &&
    styles.outlineWidth &&
    parseFloat(styles.outlineWidth) > 0;
  if (outlineOk) return true;
  const bs = styles.boxShadow || '';
  if (bs === 'none' || !bs) return false;
  // Tailwind focus rings render like "rgb(...) 0px 0px 0px 2px"
  return /\d+px\s+\d+px\s+\d+px\s+[12-9]\d*px/.test(bs) || /inset/.test(bs);
}

// Identify the activeElement as interactive (worth Focus Visible check)
function isInteractive(snap) {
  if (!snap || snap.tag === 'BODY') return false;
  const t = snap.tag.toUpperCase();
  if (['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA'].includes(t)) return true;
  const r = (snap.role || '').toLowerCase();
  if (['button', 'link', 'menuitem', 'tab', 'option', 'checkbox', 'radio'].includes(r)) return true;
  if (snap.tabindex !== null && snap.tabindex !== undefined && snap.tabindex !== '-1') return true;
  return false;
}

// ---------- per-surface audit ----------
async function auditSurface(page, label) {
  const sequence = await tabWalk(page, { maxSteps: 80 });

  // Filter to actual focus stops (exclude BODY landings caused by traversal end).
  const stops = sequence.filter((s) => s.tag !== 'BODY');
  const interactiveStops = stops.filter((s) => isInteractive(s));

  // Focus-visible counts
  const focusVisiblePassing = interactiveStops.filter((s) => focusVisibleOk(s.styles)).length;
  const focusInvisible = interactiveStops
    .filter((s) => !focusVisibleOk(s.styles))
    .map((s) => ({
      step: s.step,
      tag: s.tag,
      path: s.path,
      text: s.text,
      role: s.role,
      aria_label: s.aria_label,
    }));

  // Trap detection: any two consecutive identical paths after Tab
  const trapHits = sequence.filter((s) => s.trap_suspected_with_prev).map((s) => ({
    step: s.step,
    path: s.path,
    text: s.text,
  }));

  // Cycle detection: first interactive equals last interactive path → wrap OK
  let wrapped = false;
  if (interactiveStops.length >= 2) {
    const first = interactiveStops[0];
    const last = interactiveStops[interactiveStops.length - 1];
    wrapped = first.path === last.path || interactiveStops.length >= 60;
  }

  // Take a focus screenshot mid-walk
  const shotPath = path.join(SCREENSHOT_DIR, `${label}.png`);
  try {
    await page.screenshot({ path: shotPath, fullPage: false });
  } catch (e) {
    // continue
  }

  // Enter activation probe: Tab back to first focusable, press Enter, capture URL/DOM delta
  let enterActivation = null;
  try {
    // Reset focus then jump to first interactive
    await page.evaluate(() => document.body && document.body.focus({ preventScroll: true }));
    await page.keyboard.press('Tab');
    const beforeUrl = page.url();
    const beforeDom = await page.evaluate(() => document.body.innerText.length);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(400);
    const afterUrl = page.url();
    const afterDom = await page.evaluate(() => document.body.innerText.length);
    enterActivation = {
      url_changed: beforeUrl !== afterUrl,
      dom_size_delta: afterDom - beforeDom,
      activated: beforeUrl !== afterUrl || Math.abs(afterDom - beforeDom) > 20,
    };
  } catch (e) {
    enterActivation = { error: e.message };
  }

  // Escape probe: press Esc, see if anything labeled modal/dialog closes
  let escapeProbe = null;
  try {
    const before = await page.evaluate(() =>
      document.querySelectorAll('[role="dialog"], .modal.show, .modal-open').length,
    );
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    const after = await page.evaluate(() =>
      document.querySelectorAll('[role="dialog"], .modal.show, .modal-open').length,
    );
    escapeProbe = { dialogs_before: before, dialogs_after: after, esc_closed_modal: after < before };
  } catch (e) {
    escapeProbe = { error: e.message };
  }

  // axe-core run (critical + serious only, scoped to WCAG 2.1 AA)
  let axe = null;
  try {
    const axeResult = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    const summarize = (v) => ({
      id: v.id,
      impact: v.impact,
      help: v.help,
      helpUrl: v.helpUrl,
      nodes: v.nodes.slice(0, 3).map((n) => ({
        target: n.target,
        failureSummary: (n.failureSummary || '').slice(0, 240),
      })),
    });
    axe = {
      total: axeResult.violations.length,
      critical: axeResult.violations.filter((v) => v.impact === 'critical').map(summarize),
      serious: axeResult.violations.filter((v) => v.impact === 'serious').map(summarize),
      moderate_count: axeResult.violations.filter((v) => v.impact === 'moderate').length,
      minor_count: axeResult.violations.filter((v) => v.impact === 'minor').length,
    };
  } catch (e) {
    axe = { error: e.message };
  }

  const finding = {
    label,
    url: page.url(),
    screenshot: shotPath,
    tab_walk: {
      steps_total: sequence.length,
      stops_total: stops.length,
      interactive_stops: interactiveStops.length,
      focus_visible_pass: focusVisiblePassing,
      focus_visible_fail: focusInvisible.length,
      no_focus_visible_samples: focusInvisible.slice(0, 10),
      trap_hits: trapHits,
      wrapped_to_first: wrapped,
    },
    enter_activation: enterActivation,
    escape_probe: escapeProbe,
    axe,
  };

  FINDINGS.surfaces[label] = finding;
  return finding;
}

// ---------- tests ----------
test.describe.configure({ mode: 'serial' });

test('admin login (anonymous)', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);
  await auditSurface(page, '01-login');
});

test('admin POS cashier flow', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.waitForTimeout(800);
  await auditSurface(page, '02-admin-pos');
});

test('KDS chef bump CTAs', async ({ page }) => {
  await loginAsChefOperator(page);
  await page.waitForTimeout(800);
  await auditSurface(page, '03-kds');
});

test('admin items catalog', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await auditSurface(page, '04-admin-items');
});

test('admin stock rupture dashboard', async ({ page }) => {
  await loginAsAdmin(page);
  // Prefer CLAUDE.md canonical surface form
  await page.goto('/admin/stock-rupture-dashboard', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await auditSurface(page, '05-admin-stock-rupture');
});

test('kiosk idle screen', async ({ page }) => {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await auditSurface(page, '06-kiosk-idle');
});

test('kiosk catalog', async ({ page }) => {
  await loginAsKiosk(page).catch(() => {});
  // From idle, advance to catalog by Enter on the idle CTA if present
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(600);
  // Try canonical catalog routes used by SPA
  const catalogTried = [];
  for (const r of ['/kiosk/menu', '/kiosk/catalog', '/kiosk/order-type']) {
    catalogTried.push(r);
    await page.goto(r, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    const onLogin = /\/kiosk\/login/.test(page.url());
    const onIdle = /\/kiosk\/idle/.test(page.url());
    if (!onLogin && !onIdle) break;
  }
  await page.waitForTimeout(600);
  await auditSurface(page, '07-kiosk-catalog');
});

test('kiosk wizard (composer step)', async ({ page }) => {
  // Best-effort: navigate to a wizard URL if reachable, else degrade gracefully
  await loginAsKiosk(page).catch(() => {});
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);
  // Try clicking the first item card via keyboard from catalog
  await page.goto('/kiosk/menu', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(700);
  // Tab to first card and Enter to open wizard if applicable
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(800);
  await auditSurface(page, '08-kiosk-wizard');
});

test.afterAll(async () => {
  // Compute verdict
  let trapsFound = 0;
  let focusInvisibleSurfaces = 0;
  let axeCritical = 0;
  let axeSerious = 0;
  for (const [, f] of Object.entries(FINDINGS.surfaces)) {
    if (f.tab_walk && f.tab_walk.trap_hits && f.tab_walk.trap_hits.length > 0) trapsFound++;
    if (f.tab_walk && f.tab_walk.focus_visible_fail > 0) focusInvisibleSurfaces++;
    if (f.axe && Array.isArray(f.axe.critical)) axeCritical += f.axe.critical.length;
    if (f.axe && Array.isArray(f.axe.serious)) axeSerious += f.axe.serious.length;
  }

  const verdict = trapsFound > 0 ? 'TRAPS_FOUND' : 'KB_NAV_OK';

  FINDINGS.summary = {
    surfaces_audited: Object.keys(FINDINGS.surfaces).length,
    surfaces_with_traps: trapsFound,
    surfaces_with_focus_invisible: focusInvisibleSurfaces,
    axe_critical_total: axeCritical,
    axe_serious_total: axeSerious,
    verdict,
  };

  const out = path.join(REPORT_ROOT, 'findings.json');
  fs.writeFileSync(out, JSON.stringify(FINDINGS, null, 2));
  // Also write a compact CSV-ish summary for human triage
  const summaryLines = [
    `WAVE E — T3 A11y Keyboard-only Navigation`,
    `Generated: ${FINDINGS.meta.generated_at}`,
    `Verdict: ${verdict}`,
    ``,
    `surface\tinteractive_stops\tfocus_visible_pass\tfocus_visible_fail\ttraps\twrapped\taxe_critical\taxe_serious`,
  ];
  for (const [k, f] of Object.entries(FINDINGS.surfaces)) {
    const tw = f.tab_walk || {};
    const a = f.axe || {};
    summaryLines.push(
      [
        k,
        tw.interactive_stops || 0,
        tw.focus_visible_pass || 0,
        tw.focus_visible_fail || 0,
        (tw.trap_hits || []).length,
        tw.wrapped_to_first ? 'yes' : 'no',
        Array.isArray(a.critical) ? a.critical.length : 0,
        Array.isArray(a.serious) ? a.serious.length : 0,
      ].join('\t'),
    );
  }
  fs.writeFileSync(path.join(REPORT_ROOT, 'summary.tsv'), summaryLines.join('\n'));
});
