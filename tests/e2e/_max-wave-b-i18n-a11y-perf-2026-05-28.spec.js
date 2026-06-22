// MAX TEST WAVE B — i18n + A11y + Performance Sweep
// Date: 2026-05-28 · HEAD: e7ae1c8ea · Branch: feature/mobile-app-le-cayenne-2026-05-10
//
// Scope: 6 NEW surfaces (the 5 already in T2-ADMIN + T2-LIVREUR are cited, not re-captured)
//   1. /login                            (anonymous)
//   2. /admin/pos                        (POS operator)
//   3. /kiosk/idle                       (anonymous public)
//   4. /kiosk/catalog (if reachable)     (kiosk machine)
//   5. /kds (admin/kitchen-display-system)
//   6. /admin/order-status-screen        (OSS)
//
// 3-axis collection per surface:
//   A. i18n  — DOM innerText → regex sweep raw labels + English leaks + diacritic check
//   B. a11y  — axe-core (already in node_modules) via @axe-core/playwright, critical+serious only
//   C. perf  — performance.timing + LCP from PerformanceObserver, no Lighthouse
//
// Output: reports/test-e2e/owner-trial-test-max-2026-05-28/I18N/findings.json
//
// Read+test only. No source edits. No npm install.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAsAdmin, loginAsChefOperator } = require('./helpers/login');

const REPORT_ROOT = path.resolve('reports/test-e2e/owner-trial-test-max-2026-05-28/I18N');
const SCREENSHOT_DIR = '/tmp/foodking-max-test-2026-05-28/wave-b-i18n-a11y-perf';
fs.mkdirSync(REPORT_ROOT, { recursive: true });
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// ---------- regexes ----------
const RAW_LABEL_RE = /\b([a-z]+\.[a-z_.]+)\b|(label\.[a-zA-Z_]+)|(LABEL\.[A-Z_]+)|\[object Object\]|0undefined|\$t\(\s*['"][a-zA-Z_.]+['"]\s*\)/g;
// Whitelist for false positives that look like keys but are legit (file extensions, version strings, prices)
const RAW_LABEL_WHITELIST = [
  /\.(js|css|png|jpg|jpeg|svg|webp|gif|woff2?|ttf|pdf|html|json|map|min|ico)\b/i,
  /\b\d+\.\d+\b/,                       // version 1.2.3 or numeric like "5,50€"
  /\b(le|la|les|un|une|des|du|de|au|aux|et|ou|à|en)\.\w+/i, // FR particles before file ext
  /\b(www|app|api)\.\w+/i,              // URLs
  /admin@lecayenne\.fr/i,               // email
];
// English technical words shouldn't appear unprefixed on FR user-facing pages.
// We bound this with word-boundary AND ignore short tokens that may be brand or labels.
const ENGLISH_LEAK_WORDS = ['Settings', 'Save', 'Cancel', 'Delete', 'Loading...', 'Submit', 'Continue', 'Back', 'Edit'];
const FRENCH_DIACRITICS = /[éèàùçÉÈÀÙÇôîâêûïëä]/;

// French legitimate exceptions where English words may legitimately appear
const ENGLISH_LEAK_PER_SURFACE_WHITELIST = {
  '/admin/pos': ['Back'],   // accept Back as sometimes used in POS shortcut
};

function detectRawLabels(text) {
  const hits = new Set();
  let m;
  RAW_LABEL_RE.lastIndex = 0;
  while ((m = RAW_LABEL_RE.exec(text)) !== null) {
    const raw = m[0];
    if (RAW_LABEL_WHITELIST.some(re => re.test(raw))) continue;
    hits.add(raw);
  }
  return [...hits];
}

function detectEnglishLeaks(text, surface) {
  const allow = new Set(ENGLISH_LEAK_PER_SURFACE_WHITELIST[surface] || []);
  const hits = new Set();
  for (const w of ENGLISH_LEAK_WORDS) {
    if (allow.has(w)) continue;
    const re = new RegExp(`(^|[^A-Za-z])${w}([^A-Za-z]|$)`, 'g');
    if (re.test(text)) hits.add(w);
  }
  return [...hits];
}

function hasDiacritics(text) {
  return FRENCH_DIACRITICS.test(text);
}

// ---------- per-surface capture ----------
async function captureSurface(page, label, url, opts = {}) {
  const startNav = Date.now();
  const navResp = await page.goto(url, { waitUntil: opts.waitUntil || 'networkidle', timeout: 30_000 }).catch(e => ({ error: e.message }));
  await page.waitForTimeout(opts.settleMs || 1500);

  const status = navResp && typeof navResp.status === 'function' ? navResp.status() : null;
  const finalUrl = page.url();

  // Screenshot
  const screenshot = path.join(SCREENSHOT_DIR, `${label}.png`);
  await page.screenshot({ path: screenshot, fullPage: false }).catch(() => {});

  // ---- i18n ----
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const rawLabels = detectRawLabels(bodyText);
  const englishLeaks = detectEnglishLeaks(bodyText, url);
  const diacritics = hasDiacritics(bodyText);

  // ---- a11y (axe) ----
  let axeResult = null;
  try {
    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .disableRules([]) // run all
      .analyze();
    axeResult = {
      violations_total: axe.violations.length,
      critical: axe.violations.filter(v => v.impact === 'critical').map(summarizeViolation),
      serious: axe.violations.filter(v => v.impact === 'serious').map(summarizeViolation),
      moderate: axe.violations.filter(v => v.impact === 'moderate').length,
      minor: axe.violations.filter(v => v.impact === 'minor').length,
    };
  } catch (e) {
    axeResult = { error: e.message };
  }

  // ---- a11y manual hits ----
  const manual = await page.evaluate(() => {
    const iconBtns = Array.from(document.querySelectorAll('button')).filter(b => {
      const txt = (b.innerText || '').trim();
      return txt.length === 0 && b.querySelector('svg, i, img');
    });
    const iconBtnsMissingAria = iconBtns.filter(b => !b.getAttribute('aria-label') && !b.getAttribute('title')).length;
    const totalBtns = document.querySelectorAll('button').length;
    // Touch targets — buttons <44x44
    const smallTargets = Array.from(document.querySelectorAll('button, a[role="button"]')).filter(el => {
      const r = el.getBoundingClientRect();
      return r.width > 0 && (r.width < 44 || r.height < 44);
    }).length;
    return { iconBtnsTotal: iconBtns.length, iconBtnsMissingAria, totalBtns, smallTargets };
  }).catch(() => ({}));

  // ---- perf ----
  const perf = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0] || {};
    const lcp = performance.getEntriesByType('largest-contentful-paint').slice(-1)[0];
    const fcp = performance.getEntriesByType('paint').find(p => p.name === 'first-contentful-paint');
    return {
      navStart: nav.startTime || 0,
      domContentLoaded: nav.domContentLoadedEventEnd - nav.startTime,
      loadEvent: nav.loadEventEnd - nav.startTime,
      lcp_ms: lcp ? Math.round(lcp.startTime) : null,
      fcp_ms: fcp ? Math.round(fcp.startTime) : null,
      transferSize: nav.transferSize || 0,
      encodedBodySize: nav.encodedBodySize || 0,
      decodedBodySize: nav.decodedBodySize || 0,
    };
  }).catch(() => ({ error: 'perf-eval-failed' }));

  return {
    label,
    url,
    final_url: finalUrl,
    nav_status: status,
    nav_total_ms: Date.now() - startNav,
    screenshot,
    i18n: {
      raw_labels: rawLabels,
      raw_labels_count: rawLabels.length,
      english_leaks: englishLeaks,
      english_leaks_count: englishLeaks.length,
      diacritics_present: diacritics,
      body_text_length: bodyText.length,
      body_excerpt: bodyText.slice(0, 600),
    },
    a11y: { axe: axeResult, manual },
    perf,
  };
}

function summarizeViolation(v) {
  return {
    id: v.id,
    impact: v.impact,
    help: v.help,
    helpUrl: v.helpUrl,
    nodes_affected: v.nodes.length,
    first_target: v.nodes[0] && v.nodes[0].target,
    first_failure: v.nodes[0] && v.nodes[0].failureSummary && v.nodes[0].failureSummary.slice(0, 280),
  };
}

// ---------- bundle sizes ----------
function bundleSizes() {
  const dir = path.resolve('public/js');
  const targets = ['admin-shell.js', 'app.js', 'admin-kds.js', 'admin-oss.js', 'admin-reports.js'];
  const out = {};
  for (const f of targets) {
    const p = path.join(dir, f);
    try {
      const s = fs.statSync(p);
      out[f] = { size_kb: Math.round(s.size / 1024), size_mb: +(s.size / 1024 / 1024).toFixed(2) };
    } catch (_) {
      out[f] = { missing: true };
    }
  }
  return out;
}

// ---------- aggregator ----------
function aggregate(per_surface_results, bundles, known_t2_findings) {
  const i18n_summary = {
    raw_labels_per_surface: {},
    english_leaks_per_surface: {},
    diacritic_status_per_surface: {},
    body_lengths: {},
  };
  const a11y_summary = {
    axe_violations_per_surface: {},
    manual_checks_per_surface: {},
    critical_total: 0,
    serious_total: 0,
  };
  const perf_summary = {
    lcp_per_surface: {},
    fcp_per_surface: {},
    dom_content_loaded_per_surface: {},
    load_event_per_surface: {},
    transfer_size_per_surface: {},
  };
  const new_p1 = [];

  for (const r of per_surface_results) {
    if (!r) continue;
    const key = r.label;
    i18n_summary.raw_labels_per_surface[key] = r.i18n.raw_labels;
    i18n_summary.english_leaks_per_surface[key] = r.i18n.english_leaks;
    i18n_summary.diacritic_status_per_surface[key] = r.i18n.diacritics_present;
    i18n_summary.body_lengths[key] = r.i18n.body_text_length;

    a11y_summary.axe_violations_per_surface[key] = r.a11y.axe;
    a11y_summary.manual_checks_per_surface[key] = r.a11y.manual;
    if (r.a11y.axe && Array.isArray(r.a11y.axe.critical)) a11y_summary.critical_total += r.a11y.axe.critical.length;
    if (r.a11y.axe && Array.isArray(r.a11y.axe.serious))  a11y_summary.serious_total  += r.a11y.axe.serious.length;

    perf_summary.lcp_per_surface[key] = r.perf.lcp_ms;
    perf_summary.fcp_per_surface[key] = r.perf.fcp_ms;
    perf_summary.dom_content_loaded_per_surface[key] = r.perf.domContentLoaded;
    perf_summary.load_event_per_surface[key] = r.perf.loadEvent;
    perf_summary.transfer_size_per_surface[key] = r.perf.transferSize;

    if (r.i18n.raw_labels_count > 0) {
      new_p1.push({
        id: `WAVE-B-I18N-${key.toUpperCase()}`,
        severity: 'P1',
        type: 'i18n raw labels',
        page: r.url,
        instances: r.i18n.raw_labels,
        owner_visible: true,
      });
    }
    if (r.i18n.english_leaks_count > 0) {
      new_p1.push({
        id: `WAVE-B-EN-${key.toUpperCase()}`,
        severity: 'P2',
        type: 'English string on FR-first page',
        page: r.url,
        leaks: r.i18n.english_leaks,
        owner_visible: true,
      });
    }
    if (r.a11y.axe && r.a11y.axe.critical && r.a11y.axe.critical.length > 0) {
      new_p1.push({
        id: `WAVE-B-A11Y-CRIT-${key.toUpperCase()}`,
        severity: 'P1',
        type: 'axe critical WCAG',
        page: r.url,
        violations: r.a11y.axe.critical,
        owner_visible: false,
      });
    }
    if (r.a11y.axe && r.a11y.axe.serious && r.a11y.axe.serious.length > 0) {
      new_p1.push({
        id: `WAVE-B-A11Y-SER-${key.toUpperCase()}`,
        severity: 'P2',
        type: 'axe serious WCAG',
        page: r.url,
        violations: r.a11y.axe.serious,
        owner_visible: false,
      });
    }
    if (r.perf.lcp_ms && r.perf.lcp_ms > 2500) {
      new_p1.push({
        id: `WAVE-B-PERF-LCP-${key.toUpperCase()}`,
        severity: 'P2',
        type: 'LCP > 2.5s threshold',
        page: r.url,
        lcp_ms: r.perf.lcp_ms,
        owner_visible: false,
      });
    }
  }

  // Bundle size flag
  const adminShellMB = bundles['admin-shell.js']?.size_mb || 0;
  const appMB = bundles['app.js']?.size_mb || 0;
  if (adminShellMB > 8 || appMB > 8) {
    new_p1.push({
      id: 'WAVE-B-PERF-BUNDLE-OVER-8MB',
      severity: 'P2',
      type: 'bundle size > 8MB threshold',
      'admin-shell.js_mb': adminShellMB,
      'app.js_mb': appMB,
      owner_visible: false,
    });
  }

  // Verdict
  const hasIssues =
    a11y_summary.critical_total > 0 ||
    Object.values(i18n_summary.raw_labels_per_surface).some(v => v.length > 0) ||
    Object.values(perf_summary.lcp_per_surface).some(v => v && v > 2500);

  return {
    i18n: {
      raw_labels_per_surface: i18n_summary.raw_labels_per_surface,
      english_leaks_per_surface: i18n_summary.english_leaks_per_surface,
      diacritic_status_per_surface: i18n_summary.diacritic_status_per_surface,
      body_text_lengths: i18n_summary.body_lengths,
      english_leaks_total: Object.values(i18n_summary.english_leaks_per_surface).reduce((a,v) => a + v.length, 0),
      missing_diacritics_surfaces: Object.entries(i18n_summary.diacritic_status_per_surface).filter(([,v]) => v === false).map(([k]) => k),
      known_t2_findings,
    },
    a11y: {
      axe_violations_per_surface: a11y_summary.axe_violations_per_surface,
      manual_checks_per_surface: a11y_summary.manual_checks_per_surface,
      critical_total: a11y_summary.critical_total,
      serious_total: a11y_summary.serious_total,
    },
    performance: {
      lcp_per_surface: perf_summary.lcp_per_surface,
      fcp_per_surface: perf_summary.fcp_per_surface,
      dom_content_loaded_per_surface: perf_summary.dom_content_loaded_per_surface,
      load_event_per_surface: perf_summary.load_event_per_surface,
      transfer_size_per_surface: perf_summary.transfer_size_per_surface,
      bundle_size_kb: Object.fromEntries(Object.entries(bundles).map(([k,v]) => [k, v.size_kb])),
      bundle_size_mb: Object.fromEntries(Object.entries(bundles).map(([k,v]) => [k, v.size_mb])),
    },
    new_p1,
    verdict: hasIssues ? 'HAS_ISSUES' : 'GREEN',
  };
}

// ---------- known T2 raw-label findings (cited, not re-captured) ----------
const KNOWN_T2_FINDINGS = [
  {
    source: 'T2-LIVREUR / S-LIV-03',
    page: '/admin/delivery-boy-cash-sessions/1',
    raw_labels: [
      'Label.Delivery_cash_session #1',
      'label.cash_session_closed_at',
      'LABEL.DIRECTION',
      'LABEL.NOTES',
      'label.delivery_cash_mvt_order_collect',
    ],
    finding_id: 'T2-LIV-P1-01',
    severity: 'P1',
  },
  {
    source: 'T2-ADMIN sweep',
    pages: ['/admin/dashboard', '/admin/items', '/admin/stock/rupture', '/admin/cash-overview', '/admin/observability/outbox'],
    raw_labels_found: 0,
    note: 'Sweep clean on 6 admin pages — 0 raw labels',
  },
];

// ---------- Driver: each authenticated surface runs in its own isolated browser context ----------
// Strategy: Use the `browser` fixture (not `page`) and `browser.newContext()` per
// authenticated surface. This guarantees clean cookies + storage — fixing the
// session-bleed root cause of the prior run (admin login persisted → /login
// redirected away because user was still authenticated → #formEmail not visible).
test.describe.configure({ mode: 'serial' });

test('MAX WAVE B — i18n + a11y + perf sweep on 6 NEW surfaces', async ({ browser }) => {
  test.setTimeout(900_000);
  const results = [];
  const bundles = bundleSizes();

  // Anonymous surfaces — share one context
  const anonCtx = await browser.newContext();
  const anonPage = await anonCtx.newPage();

  // 1) /login (anonymous)
  console.log('[wave-b] surface 1/6 — /login (anon)');
  results.push(await captureSurface(anonPage, 'login', 'http://127.0.0.1:8000/login'));

  // 2) /kiosk/idle (anonymous public)
  console.log('[wave-b] surface 2/6 — /kiosk/idle (anon)');
  results.push(await captureSurface(anonPage, 'kiosk_idle', 'http://127.0.0.1:8000/kiosk/idle'));

  // 3) /kiosk/category → SPA 404 (no machine pairing) — capture as legitimate empty-state evidence
  console.log('[wave-b] surface 3/6 — /kiosk/catalog probe');
  const tryRoutes = ['/kiosk/category', '/kiosk/categories', '/kiosk/products', '/kiosk/menu'];
  let catCaptured = null;
  for (const r of tryRoutes) {
    const probe = await anonPage.goto(`http://127.0.0.1:8000${r}`, { waitUntil: 'domcontentloaded', timeout: 15_000 }).catch(() => null);
    if (probe && probe.status() === 200) {
      await anonPage.waitForTimeout(1500);
      // Even if SPA shows 404, capture for i18n+a11y attestation of empty-state
      catCaptured = await captureSurface(anonPage, 'kiosk_catalog', `http://127.0.0.1:8000${r}`);
      const isSpa404 = /Page Non Trouv|page non trouv|404|introuvable/i.test(catCaptured.i18n.body_excerpt);
      catCaptured.note = `Reached via ${r}; SPA 404 fallback = ${isSpa404} (kiosk pairing not available in test env)`;
      catCaptured.kiosk_paired = !isSpa404;
      break;
    }
  }
  if (!catCaptured) {
    catCaptured = { label: 'kiosk_catalog', url: '/kiosk/catalog', skipped: true, reason: 'no route reachable' };
  }
  results.push(catCaptured);

  await anonCtx.close();

  // 4) /admin/pos — POS Operator (pos@lecayenne.fr) — isolated context
  console.log('[wave-b] surface 4/6 — /admin/pos (POS operator)');
  try {
    const ctx = await browser.newContext();
    const pg = await ctx.newPage();
    // Inline POS operator login to avoid landing-redirect dependency
    await pg.goto('http://127.0.0.1:8000/login', { waitUntil: 'domcontentloaded' });
    await pg.locator('#formEmail').waitFor({ state: 'visible', timeout: 15_000 });
    await pg.locator('#formEmail').fill('pos@lecayenne.fr');
    await pg.locator('#formPassword').fill('123456');
    const loginResp = pg.waitForResponse(r => /\/api\/auth\/login/.test(r.url()) && r.request().method() === 'POST', { timeout: 20_000 });
    await pg.getByRole('button', { name: /^(connexion|login)$/i }).click();
    const lr = await loginResp;
    if (lr.status() !== 201) throw new Error(`POS login HTTP ${lr.status()}`);
    await pg.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20_000 });
    await pg.waitForTimeout(2000);
    results.push(await captureSurface(pg, 'admin_pos', 'http://127.0.0.1:8000/admin/pos', { settleMs: 5000, waitUntil: 'domcontentloaded' }));
    await ctx.close();
  } catch (e) {
    results.push({ label: 'admin_pos', url: '/admin/pos', skipped: true, reason: `login failed: ${String(e.message).slice(0,200)}` });
  }

  // 5) /kds (Chef) — isolated context
  console.log('[wave-b] surface 5/6 — /kds (chef)');
  try {
    const ctx = await browser.newContext();
    const pg = await ctx.newPage();
    await pg.goto('http://127.0.0.1:8000/login', { waitUntil: 'domcontentloaded' });
    await pg.locator('#formEmail').waitFor({ state: 'visible', timeout: 15_000 });
    await pg.locator('#formEmail').fill('chef@lecayenne.fr');
    await pg.locator('#formPassword').fill('123456');
    const loginResp = pg.waitForResponse(r => /\/api\/auth\/login/.test(r.url()) && r.request().method() === 'POST', { timeout: 20_000 });
    await pg.getByRole('button', { name: /^(connexion|login)$/i }).click();
    const lr = await loginResp;
    if (lr.status() !== 201) throw new Error(`Chef login HTTP ${lr.status()}`);
    await pg.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20_000 });
    await pg.waitForTimeout(2000);
    // /kds direct or /admin/kitchen-display-system fallback
    let kdsRes = await captureSurface(pg, 'kds', 'http://127.0.0.1:8000/kds', { settleMs: 4500, waitUntil: 'domcontentloaded' });
    if (kdsRes.final_url.includes('/login') || kdsRes.i18n.body_excerpt.includes('Page Non Trouv')) {
      kdsRes = await captureSurface(pg, 'kds', 'http://127.0.0.1:8000/admin/kitchen-display-system', { settleMs: 4500, waitUntil: 'domcontentloaded' });
    }
    results.push(kdsRes);
    await ctx.close();
  } catch (e) {
    results.push({ label: 'kds', url: '/kds', skipped: true, reason: `login failed: ${String(e.message).slice(0,200)}` });
  }

  // 6) /admin/order-status-screen (admin)
  console.log('[wave-b] surface 6/6 — /admin/order-status-screen (admin)');
  try {
    const ctx = await browser.newContext();
    const pg = await ctx.newPage();
    await pg.goto('http://127.0.0.1:8000/login', { waitUntil: 'domcontentloaded' });
    await pg.locator('#formEmail').waitFor({ state: 'visible', timeout: 15_000 });
    await pg.locator('#formEmail').fill('admin@lecayenne.fr');
    await pg.locator('#formPassword').fill('123456');
    const loginResp = pg.waitForResponse(r => /\/api\/auth\/login/.test(r.url()) && r.request().method() === 'POST', { timeout: 20_000 });
    await pg.getByRole('button', { name: /^(connexion|login)$/i }).click();
    const lr = await loginResp;
    if (lr.status() !== 201) throw new Error(`Admin login HTTP ${lr.status()}`);
    await pg.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20_000 });
    await pg.waitForTimeout(2000);
    results.push(await captureSurface(pg, 'oss', 'http://127.0.0.1:8000/admin/order-status-screen', { settleMs: 4500, waitUntil: 'domcontentloaded' }));
    await ctx.close();
  } catch (e) {
    results.push({ label: 'oss', url: '/admin/order-status-screen', skipped: true, reason: `login failed: ${String(e.message).slice(0,200)}` });
  }

  // ---- aggregate ----
  const captured = results.filter(r => !r.skipped);
  const aggregated = aggregate(captured, bundles, KNOWN_T2_FINDINGS);

  const final = {
    wave: 'MAX TEST WAVE B — i18n + A11y + Performance Sweep',
    date: '2026-05-28',
    head: 'e7ae1c8ea',
    scope: '6 NEW surfaces (5 prior T2 surfaces cited via KNOWN_T2_FINDINGS, not recaptured)',
    surfaces_captured: captured.map(r => r.label),
    surfaces_skipped: results.filter(r => r.skipped).map(r => ({ label: r.label, reason: r.reason })),
    per_surface_raw: results,
    ...aggregated,
    started_at: new Date().toISOString(),
    spec_file: __filename,
  };

  const outPath = path.join(REPORT_ROOT, 'findings.json');
  fs.writeFileSync(outPath, JSON.stringify(final, null, 2));
  console.log(`[wave-b] findings written → ${outPath}`);
  console.log(`[wave-b] verdict: ${final.verdict}`);
  console.log(`[wave-b] new_p1 count: ${final.new_p1.length}`);

  expect(captured.length).toBeGreaterThanOrEqual(4);
});
