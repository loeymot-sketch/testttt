/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — full-breadth live sweep.
 *
 * DASH-T10 (dashboard-nav-buttons-reachability.spec.js) proves every
 * SIDEBAR-visible + quick-access target works (32/32). It cannot see the
 * Settings tab-bar's 29 sub-pages or the Users/RBAC/Notifications/Reports
 * list pages that are not top-level sidebar entries. This spec closes that
 * gap: it visits every one of those pages DIRECTLY BY URL (exactly how a
 * V1-hidden-but-code-intact page is meant to be reached — see
 * v1-hidden-modules.js) and applies the SAME working-page guard as DASH-T10:
 *   - not blank, not an error page, not bounced to /login, no JS pageerror,
 *     no raw i18n key leak.
 *
 * READ-ONLY. No mutation, no product source change, no frozen-zone touch.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.resolve(__dirname, '__screenshots__/admin-full-breadth-sweep');

// [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 adversarial-dispute fix]
// Case-INsensitive + allows mixed/camelCase segments — the original
// lowercase-only pattern would never flag "Label.X", the canonical raw-leak
// example named in CLAUDE.md §6, nor camelCase keys like "orderStatus.pending".
const I18N_LEAK_RE = /^[a-zA-Z]+(\.[a-zA-Z_]+){1,4}$/;

// [adversarial-dispute fix] Split into a HARD signature (unambiguous crash
// markers — a real Whoops/Ignition page is always long, so it must NOT be
// length-gated) and a SOFT signature (generic words that could appear
// benignly in legitimate short FR copy, e.g. a "not found" empty-state).
const ERROR_PAGE_HARD_RE = /whoops|ignition|stack trace|symfony\\component\\|illuminate\\/i;
const ERROR_PAGE_SOFT_RE = /server error|exception|419\b|page expired|not found|introuvable|404|500\b/i;

// Every Settings tab-bar sub-page (settingRoutes.js) — includes the 12 pages
// that are V1_HIDDEN_MENU_MODULES (mail, theme, languages, otp, notification,
// notificationAlert, socialMedia, cookies, analytics, timeSlots, sliders,
// pages, smsGateway, paymentGateway, license, role, tax, itemCategories,
// itemAttributes): hidden from the tab bar by design, still must render.
const SETTINGS_PAGES = [
  'company', 'site', 'branches', 'mail', 'order-setup', 'kiosk-setup',
  'loyalty-setup', 'otp', 'notification', 'social-media', 'cookies',
  'analytics', 'theme', 'time-slots', 'sliders', 'currencies',
  'item-categories', 'item-attributes', 'taxes', 'pages', 'role',
  'languages', 'sms-gateway', 'payment-gateway', 'payment-terminals',
  'z-reports', 'printers', 'license', 'notification-alert', 'kiosk-machines',
].map((p) => `/admin/settings/${p}`);

// Users/RBAC + Notifications + Reports pages not covered by DASH-T10's
// sidebar+quick-access enumeration (customers/waiters hidden from V1 nav;
// messages/subscribers under a '#' Communications parent).
const BREADTH_PAGES = [
  '/admin/chefs',
  '/admin/waiters',
  '/admin/customers',
  '/admin/employees',
  '/admin/delivery-boys',
  '/admin/messages',
  '/admin/subscribers',
  '/admin/sales-report',
  '/admin/items-report',
];

const ALL_PAGES = [...SETTINGS_PAGES, ...BREADTH_PAGES];

function slug(href) {
  return href.replace(/^\/+/, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'root';
}

test.describe.serial('Admin full-breadth sweep — every Settings sub-page + RBAC/Notifications/Reports list page', () => {
  test.setTimeout(600_000);

  let snap, dispose, page;
  let routeErrors = [];
  // [adversarial-dispute fix] Track failed XHR/fetch responses per route —
  // the original spec had NO network-response assertion at all, so a page
  // whose data-fetch 500s/404s and is swallowed by a `.catch()` (e.g. an
  // empty-state render) would sail through every other guard undetected.
  let routeFailedResponses = [];

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    const rec = attachMegaAuditRecorder(page, DIR);
    snap = rec.snap;
    dispose = rec.dispose;
    page.on('pageerror', (err) => {
      routeErrors.push(String(err && err.message ? err.message : err).slice(0, 300));
    });
    page.on('response', (res) => {
      const url = res.url();
      if (!/\/api\/admin\//.test(url)) return;
      const status = res.status();
      if (status >= 500) {
        routeFailedResponses.push(`${status} ${url}`);
      }
    });
    await loginAsAdmin(page);
    await expect(page).toHaveURL(/\/admin/, { timeout: 25_000 });
  });

  test.afterAll(async () => {
    if (dispose) dispose();
    if (page) await page.context().close().catch(() => {});
  });

  async function settle() {
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForFunction(() => {
      const el = document.querySelector('.vel-modal, .ve-loading, [class*="loading"][class*="active"]');
      if (!el) return true;
      const cs = window.getComputedStyle(el);
      return cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0';
    }, { timeout: 12_000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 12_000 }).catch(() => {});
    await page.waitForTimeout(1000);
  }

  async function readRoutedContent() {
    return page.evaluate(() => {
      const main = document.querySelector('main.db-main');
      if (!main) {
        return { mainPresent: false, text: (document.body.innerText || '').trim().slice(0, 4000), len: null };
      }
      const clone = main.cloneNode(true);
      clone.querySelectorAll('.db-header, .db-sidebar, header.db-header, aside.db-sidebar')
        .forEach((n) => n.remove());
      const text = (clone.innerText || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
      return { mainPresent: true, text: text.slice(0, 4000), len: text.length };
    });
  }

  async function scanI18nLeak() {
    return page.evaluate((reSrc) => {
      const re = new RegExp(reSrc);
      const out = [];
      const seen = new Set();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        const t = (n.textContent || '').trim();
        if (!t || t.length > 60 || seen.has(t)) continue;
        const el = n.parentElement;
        if (!el) continue;
        const cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') continue;
        if (re.test(t)) { out.push(t); seen.add(t); }
      }
      return out.slice(0, 30);
    }, I18N_LEAK_RE.source);
  }

  test('every settings sub-page + RBAC/notifications/reports page renders as a working page', async () => {
    const results = [];
    const failures = [];

    for (const href of ALL_PAGES) {
      routeErrors = [];
      routeFailedResponses = [];
      const name = slug(href);
      let finalUrl = href;
      let ok = true;
      const reasons = [];

      try {
        await page.goto(href, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        await settle();
        finalUrl = page.url();
      } catch (navErr) {
        ok = false;
        reasons.push(`navigation threw: ${String(navErr.message || navErr).slice(0, 160)}`);
      }

      await snap(name).catch(() => {});

      if (/\/login(?:$|[/?#])/.test(finalUrl)) {
        ok = false;
        reasons.push(`bounced to /login (final=${finalUrl})`);
      }

      const routed = await readRoutedContent();
      // [adversarial-dispute fix] main.db-main is ALWAYS expected to mount on
      // an authenticated /admin/* route (per readRoutedContent's own doc
      // comment: even KDS/OSS full-screen surfaces still render inside it,
      // they just hide the sidebar). The original "record but pass" branch
      // was a silent no-op for exactly the "shell never mounted" failure
      // mode it appeared to guard against — a wrong-template/crashed route
      // with >=40 chars of body text would pass undetected. Absence is now
      // always a hard failure, never conditionally forgiven.
      if (!routed.mainPresent) {
        ok = false;
        reasons.push('admin shell (main.db-main) never mounted');
      } else if (routed.len == null || routed.len < 40) {
        ok = false;
        reasons.push(`routed content blank/near-empty (len=${routed.len}) — shell present but page area empty`);
      }

      if (routeErrors.length) {
        ok = false;
        reasons.push(`JS pageerror(s): ${JSON.stringify(routeErrors.slice(0, 3))}`);
      }

      // [adversarial-dispute fix] server-side 5xx on any admin API call this
      // route triggered — catches the "data fetch failed, swallowed by a
      // .catch(), empty-state rendered" scenario that no other guard here
      // can see (the page LOOKS fine: real shell, real text, no JS error).
      if (routeFailedResponses.length) {
        ok = false;
        reasons.push(`server error response(s): ${JSON.stringify(routeFailedResponses.slice(0, 3))}`);
      }

      const routedText = routed.text || '';
      if (ok && ERROR_PAGE_HARD_RE.test(routedText)) {
        ok = false;
        reasons.push(`hard error-page signature: "${routedText.slice(0, 160)}"`);
      } else if (ok && ERROR_PAGE_SOFT_RE.test(routedText) && routedText.length < 200) {
        ok = false;
        reasons.push(`error-page signature on short content: "${routedText.slice(0, 120)}"`);
      }

      const leaks = await scanI18nLeak();
      if (leaks.length) {
        ok = false;
        reasons.push(`i18n key leak: ${JSON.stringify(leaks.slice(0, 8))}`);
      }

      const reason = reasons.length ? reasons.join(' | ') : 'working page (routed content rendered, no error, no i18n leak)';
      results.push({ route: href, ok, reason, finalUrl });
      if (!ok) failures.push({ route: href, reason });
      console.log(`[BREADTH-SWEEP] ${ok ? 'PASS' : 'FAIL'}  ${href}  -> ${reason}`);
    }

    console.log(`[BREADTH-SWEEP] ${results.filter((r) => r.ok).length}/${results.length} pages reach a working page.`);
    if (failures.length) {
      console.log('[BREADTH-SWEEP] FAILURES:', JSON.stringify(failures, null, 2));
    }

    expect(
      failures,
      `${failures.length}/${results.length} breadth pages failed the working-page guard:\n${JSON.stringify(failures, null, 2)}`,
    ).toEqual([]);
  });
});
