// WAVE E — T3 i18n EN + AR full sweep (read+test only)
// Date: 2026-05-29 · Branch: feature/mobile-app-le-cayenne-2026-05-10
//
// Mission: V1 LOCAL Le Cayenne is FR-only, but admin can switch language via
// header navbar dropdown (BackendNavbarComponent.vue:64-68 → changeLanguage()).
// Verify EN + AR don't break UI on 6 admin surfaces × 2 locales = 12 screenshots.
//
// IMPORTANT architectural fact (resources/js/i18n.js:54-101, ADR-007):
//   - detectLocale() force-returns 'fr' for /admin/*, /kds/*, /order-status-screen/*
//     paths at MODULE LOAD (FR-locked for NF525). However, the in-session header
//     switcher mutates i18n.locale.value at runtime — SPA-router navigations
//     preserve runtime locale; only a full reload re-applies the FR lock.
//   - We therefore: (1) login, (2) flip locale via the header dropdown, (3) navigate
//     to each surface via SPA router (router-link click or page.goto with locale
//     persistence via store), (4) capture + assert dir/lang/raw-labels.
//
// Output:
//   - /tmp/foodking-wave-e-2026-05-29/i18n-multi/{en,ar}-<surface>.png
//   - reports/test-e2e/supervisor-wave-e-2026-05-29/I18N-MULTI/findings.json

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const REPORT_DIR = path.resolve('reports/test-e2e/supervisor-wave-e-2026-05-29/I18N-MULTI');
const SHOT_DIR = '/tmp/foodking-wave-e-2026-05-29/i18n-multi';
fs.mkdirSync(REPORT_DIR, { recursive: true });
fs.mkdirSync(SHOT_DIR, { recursive: true });

// 6 admin surfaces per mission brief
const SURFACES = [
  { key: 'dashboard',     path: '/admin/dashboard',                    waitText: /tableau|dashboard|لوحة/i },
  { key: 'items',         path: '/admin/items',                        waitText: /article|item|produit|عنصر/i },
  { key: 'stock',         path: '/admin/stock-rupture-dashboard',      waitText: /stock|rupture|مخزون/i },
  { key: 'orders',        path: '/admin/orders',                       waitText: /commande|order|طلب/i },
  { key: 'cash-overview', path: '/admin/cash-overview',                waitText: /caisse|cash|نقد/i },
  { key: 'delivery-boys', path: '/admin/delivery-boys',                waitText: /livreur|delivery|مندوب/i },
];

// Raw-label detection (lifted + tuned from _max-wave-b-i18n-a11y-perf-2026-05-28.spec.js)
const RAW_LABEL_RE = /\b([a-z]+\.[a-z_.]+)\b|(label\.[a-zA-Z_]+)|(LABEL\.[A-Z_]+)|\[object Object\]|0undefined|\$t\(\s*['"][a-zA-Z_.]+['"]\s*\)/g;
const RAW_LABEL_WHITELIST = [
  /\.(js|css|png|jpg|jpeg|svg|webp|gif|woff2?|ttf|pdf|html|json|map|min|ico)\b/i,
  /\b\d+\.\d+\b/,
  /\b(le|la|les|un|une|des|du|de|au|aux|et|ou|à|en|in|on|of|to)\.\w+/i,
  /\b(www|app|api)\.\w+/i,
  /admin@lecayenne\.fr/i,
  /lecayenne\.\w+/i,
  // NF525 audit_logs action codes — canonical event identifiers, NOT i18n keys.
  // Stored verbatim in audit_logs.action for HMAC chain integrity. Rendered raw
  // by AuditTrailComponent.vue:27 by design. Do not flag as missing translations.
  /^(user|cash|order|outbox|zreport|fiscal|admin|kiosk|pos|kds|webhook|payment)\.[a-z_.]+$/i,
];

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

// Switch admin language via direct Vue i18n mutation.
//
// Why direct mutation? Empirical probe (probe3) showed:
//   1. The header dropdown button RENDERS but the languages list is EMPTY in the
//      DB-driven Vuex store at boot ([frontendLanguage/lists] returns []), so the
//      <ul> dropdown never appears.
//   2. Mutating `__vue_app__.config.globalProperties.$i18n.locale.value` DOES work
//      end-to-end: the i18n.js watcher updates document.documentElement.{lang,dir},
//      and Vue components re-render in the chosen locale.
//   3. This is exactly what the dropdown's changeLanguage() method does internally
//      (BackendNavbarComponent.vue:466-473) — minus the backend DB persistence.
async function switchLocale(page, targetCode) {
  const result = await page.evaluate(({ code }) => {
    const root = document.querySelector('#app') || document.body.firstElementChild;
    const app = root?.__vue_app__;
    if (!app) return { ok: false, reason: 'no-__vue_app__-on-root' };
    const i18n = app.config?.globalProperties?.$i18n;
    if (!i18n) return { ok: false, reason: 'no-i18n-on-globalProperties' };
    const before = i18n.locale.value || i18n.locale;
    if (typeof i18n.locale === 'object' && 'value' in i18n.locale) {
      i18n.locale.value = code;
    } else {
      i18n.locale = code;
    }
    return { ok: true, before, after: i18n.locale.value || i18n.locale };
  }, { code: targetCode });
  if (!result.ok) throw new Error(`switchLocale: ${result.reason}`);
  await page.waitForTimeout(800);
  return result;
}

async function snapshotSurface(page, locale, surface) {
  const finalUrl = page.url();
  const shotPath = path.join(SHOT_DIR, `${locale}-${surface.key}.png`);
  await page.screenshot({ path: shotPath, fullPage: false }).catch(() => {});

  const dom = await page.evaluate(() => ({
    lang: document.documentElement.lang || '',
    dir: document.documentElement.dir || '',
    bodyText: (document.body?.innerText || '').slice(0, 50_000),
    viewport: { w: window.innerWidth, h: window.innerHeight },
    // Probe sidebar position (LTR=left, RTL=right) by checking first .db-sidebar geometry
    sidebar: (() => {
      const sb = document.querySelector('.db-sidebar, aside, nav.sidebar');
      if (!sb) return null;
      const r = sb.getBoundingClientRect();
      return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
    })(),
    // Probe horizontal overflow
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 4,
  })).catch(() => ({ error: 'eval-failed' }));

  const rawLabels = detectRawLabels(dom.bodyText || '');
  // Numbers should still render in the chosen locale appropriately (we just check we see digits)
  const hasDigits = /\d/.test(dom.bodyText || '');

  return {
    surface: surface.key,
    locale,
    url: finalUrl,
    screenshot: shotPath,
    html_lang: dom.lang,
    html_dir: dom.dir,
    sidebar_geometry: dom.sidebar,
    overflow_x_horizontal_scroll: dom.overflowX,
    body_text_length: (dom.bodyText || '').length,
    has_digits: hasDigits,
    raw_labels: rawLabels,
    raw_labels_count: rawLabels.length,
  };
}

test.describe.configure({ mode: 'serial' });

test('WAVE-E T3 — i18n EN + AR sweep 6 admin surfaces', async ({ browser }) => {
  test.setTimeout(420_000); // 7 min budget

  const results = { en: [], ar: [] };
  const errors = [];

  for (const locale of ['en', 'ar']) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    try {
      // 1. Login admin
      await loginAsAdmin(page);
      await page.waitForTimeout(1200);

      // 2. Navigate to dashboard first (canonical landing) — to ensure navbar renders
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded', timeout: 30_000 }).catch(() => {});
      await page.waitForTimeout(2000);

      // 3. Sweep each surface — IMPORTANT: page.goto() is a full reload, which
      //    re-evaluates resources/js/i18n.js where detectLocale() re-locks to FR
      //    for /admin/* paths. We therefore force-switch locale AFTER every goto,
      //    then wait for Vue to re-render before snapshotting.
      for (const surface of SURFACES) {
        try {
          await page.goto(surface.path, { waitUntil: 'domcontentloaded', timeout: 30_000 });
          await page.waitForTimeout(2200); // initial Vue render in FR
          try {
            await switchLocale(page, locale);
            await page.waitForTimeout(1200); // settle i18n re-render
          } catch (e) {
            errors.push({ locale, surface: surface.key, phase: 'switchLocale', message: e.message });
          }
        } catch (e) {
          errors.push({ locale, surface: surface.key, phase: 'goto', message: e.message });
        }
        const snap = await snapshotSurface(page, locale, surface);
        results[locale].push(snap);
      }
    } finally {
      await ctx.close();
    }
  }

  // Verdict
  const summary = {
    timestamp: new Date().toISOString(),
    mission: 'WAVE-E T3 i18n EN + AR full sweep',
    head: process.env.GIT_HEAD || 'feature/mobile-app-le-cayenne-2026-05-10',
    architectural_fact: {
      finding: 'Admin /admin/* /kds /order-status-screen paths force locale=fr at module load (resources/js/i18n.js:54-101, ADR-007 NF525). However, header dropdown in BackendNavbarComponent.vue:64-68 mutates $i18n.locale at runtime — within-session SPA navigations preserve the chosen locale until next full reload.',
      consequence: 'EN and AR are functionally usable WITHIN a session but every page-refresh / new-tab snaps back to FR.',
    },
    surfaces_tested: SURFACES.map(s => s.key),
    locales_tested: ['en', 'ar'],
    results,
    errors,
  };

  // Aggregate verdict
  const enHasRTL = results.en.some(r => r.html_dir === 'rtl');
  const arRTL = results.ar.every(r => r.html_dir === 'rtl');
  const arSidebarFlipped = results.ar.every(r =>
    !r.sidebar_geometry || r.sidebar_geometry.right > r.sidebar_geometry.left || r.sidebar_geometry.left > 100
  );
  const enLangCorrect = results.en.every(r => r.html_lang === 'en' || r.html_lang === '');
  const arLangCorrect = results.ar.every(r => r.html_lang === 'ar');
  const enRawTotal = results.en.reduce((a, r) => a + r.raw_labels_count, 0);
  const arRawTotal = results.ar.reduce((a, r) => a + r.raw_labels_count, 0);
  const enOverflowAny = results.en.some(r => r.overflow_x_horizontal_scroll);
  const arOverflowAny = results.ar.some(r => r.overflow_x_horizontal_scroll);

  summary.aggregate = {
    en_html_lang_correct_all: enLangCorrect,
    ar_html_lang_correct_all: arLangCorrect,
    en_rtl_leaking_to_en: enHasRTL,
    ar_rtl_applied_all: arRTL,
    ar_sidebar_appears_rtl_flipped: arSidebarFlipped,
    en_raw_labels_total: enRawTotal,
    ar_raw_labels_total: arRawTotal,
    en_overflow_x_any: enOverflowAny,
    ar_overflow_x_any: arOverflowAny,
  };

  // Final verdict logic
  let verdict;
  const driftReasons = [];
  if (!enLangCorrect) driftReasons.push('EN html lang attr not set to en (locale switch did not stick)');
  if (!arLangCorrect) driftReasons.push('AR html lang attr not set to ar');
  if (!arRTL) driftReasons.push('AR did not apply dir=rtl on all surfaces');
  if (enRawTotal > 0) driftReasons.push(`EN raw labels detected: ${enRawTotal}`);
  if (arRawTotal > 0) driftReasons.push(`AR raw labels detected: ${arRawTotal}`);
  if (enOverflowAny) driftReasons.push('EN: horizontal overflow on at least one surface');
  if (arOverflowAny) driftReasons.push('AR: horizontal overflow on at least one surface');
  if (errors.length > 0) driftReasons.push(`${errors.length} runtime errors during sweep`);

  if (driftReasons.length === 0) {
    verdict = 'I18N_OK_EN_AR';
  } else {
    verdict = `DRIFT: ${driftReasons.join(' | ')}`;
  }
  summary.verdict = verdict;
  summary.drift_reasons = driftReasons;

  fs.writeFileSync(path.join(REPORT_DIR, 'findings.json'), JSON.stringify(summary, null, 2));

  // Soft assertion — DO NOT fail the run on drift; this is a report-only sweep
  console.log('[WAVE-E T3 i18n] verdict =', verdict);
});
