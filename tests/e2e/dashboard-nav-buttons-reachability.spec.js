/**
 * GOAL_MGMT_TESTPLAN — Wave B — task DASH-T10 ⭐  (2026-06-03)
 * Spec: tests/e2e/dashboard-nav-buttons-reachability.spec.js
 * Dir:  tests/e2e/__screenshots__/dash-nav/
 *
 * OWNER'S #1 STATED FEAR: "does every nav button lead to a working page?"
 *
 * MISSION: log in as admin, enumerate EVERY sidebar nav link AND every dashboard
 * quick-access tile FROM THE LIVE DOM (the real permission- + V1-hidden-filtered
 * set the owner actually sees — no hardcoded route list), visit each, and assert
 * it lands on a WORKING page:
 *   - NOT blank / white (real routed content rendered, not just the always-present shell)
 *   - NOT an error / exception / 500 / 404 page
 *   - NOT a bounce to /login
 *   - NOT raw i18n keys leaking (e.g. menu.foo, label.bar.baz)
 * Capture a screenshot (4-file quartet via mega-audit recorder) per destination.
 *
 * --- STRUCTURE NOTE (verified 2026-06-03 against this codebase) ---
 * DefaultComponent.vue:18-22 — the admin <router-view> renders INSIDE <main class="db-main">
 * as a SIBLING of <header class="db-header"> (navbar) and <aside class="db-sidebar">
 * (sidebar). So `.db-main` innerText INCLUDES the sidebar/navbar labels — measuring
 * it whole would let any blank-main route pass VACUOUSLY (defeating the whole task).
 * => We measure ROUTED CONTENT ONLY: clone .db-main, strip .db-header + .db-sidebar,
 *    read what remains. This is heading-agnostic, so it also handles KDS / OSS
 *    (BackendMenuComponent.vue:3 hides the sidebar on those two full-screen surfaces,
 *    but the routed surface is still the third .db-main child) without special-casing.
 *
 * DUAL GUARD (advisor-locked):
 *   - A route rendering only the shell with blank routed content = HARD FAIL (not vacuous pass).
 *   - A genuinely orphan / broken nav target = REAL FINDING: reported with route + exact
 *     reason. We do NOT fix product code (owner gate G4 decides wire-vs-remove).
 *   - A route that legitimately needs data and shows an HONEST empty-state = PASS (noted).
 *
 * READ-ONLY: navigates + clicks nav links only. No mutation. No product source change.
 * Does NOT click the EOD-PDF button (fires a real download). No frozen-zone touch.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.resolve(__dirname, '__screenshots__/dash-nav');

// i18n-leak detector: a visible text node that is a bare translation key
// (matches the measured-P1 rule from Wave E: ^[a-z]+(\.[a-z_]+){1,4}$).
const I18N_LEAK_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;

// Literal error-page signatures (Laravel Ignition / generic). Only treated as a
// failure when the routed content is ALSO short — bare "erreur" is benign FR copy.
const ERROR_PAGE_RE = /whoops|server error|ignition|exception|stack trace|419\b|page expired|not found|introuvable|404|500\b/i;

function slug(href) {
  return href.replace(/^\/+/, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'root';
}

test.describe.serial('DASH-T10 — every nav button leads to a working page', () => {
  test.setTimeout(420_000);

  let snap, dispose, page;
  // Per-route JS pageerror collector (the recorder's buffers are private to it).
  let routeErrors = [];

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    const rec = attachMegaAuditRecorder(page, DIR);
    snap = rec.snap;
    dispose = rec.dispose;
    page.on('pageerror', (err) => {
      routeErrors.push(String(err && err.message ? err.message : err).slice(0, 300));
    });

    // SPEC-INTEGRITY: login must succeed (loginAsAdmin throws on its own otherwise).
    await loginAsAdmin(page);
    await expect(page).toHaveURL(/\/admin/, { timeout: 25_000 });
  });

  test.afterAll(async () => {
    if (dispose) dispose();
    if (page) await page.context().close().catch(() => {});
  });

  /**
   * Wait for the full-screen LoadingComponent spinner (vue-element-loading) to
   * detach, then for network to settle (KDS/OSS websockets never idle → catch).
   */
  async function settle() {
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    // vue-element-loading renders a .vel-modal overlay while :active=true.
    await page.waitForFunction(() => {
      const el = document.querySelector('.vel-modal, .ve-loading, [class*="loading"][class*="active"]');
      if (!el) return true;
      const cs = window.getComputedStyle(el);
      return cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0';
    }, { timeout: 12_000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 12_000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }

  /**
   * Read ROUTED-CONTENT-ONLY text: clone .db-main, strip the navbar (.db-header)
   * and sidebar (.db-sidebar), return the remaining innerText (trimmed). On the
   * KDS/OSS surfaces the sidebar is hidden but the surface still lives in .db-main.
   * Returns { ok, text, len, mainPresent }.
   */
  async function readRoutedContent() {
    return page.evaluate(() => {
      const main = document.querySelector('main.db-main');
      if (!main) {
        // Some surfaces (e.g. a hard error/login bounce) may not mount .db-main.
        return { mainPresent: false, text: (document.body.innerText || '').trim().slice(0, 4000), len: null };
      }
      const clone = main.cloneNode(true);
      clone.querySelectorAll('.db-header, .db-sidebar, header.db-header, aside.db-sidebar')
        .forEach((n) => n.remove());
      const text = (clone.innerText || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
      return { mainPresent: true, text: text.slice(0, 4000), len: text.length };
    });
  }

  // Scan visible DOM for bare i18n key leaks (routed area only is hard; we scan
  // full visible DOM but keys are global anyway — a leak anywhere is a defect).
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

  // ---------------------------------------------------------------------------
  // STEP 1 — enumerate the real nav targets FROM THE LIVE DOM.
  // ---------------------------------------------------------------------------
  test('enumerate + visit every sidebar link & dashboard quick-access tile', async () => {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('main.db-main')).toBeVisible({ timeout: 30_000 });
    await settle();

    // Sidebar links: .db-sidebar-nav-menu anchors → /admin/* (excludes the logo
    // in .db-sidebar-header and the '#' parent toggle <button>s, which are not <a>).
    // Quick-access tiles: the dashboard <nav aria-label="…quick access…"> block.
    const targets = await page.evaluate(() => {
      const map = new Map(); // href -> Set(sources)
      const add = (href, source) => {
        if (!href) return;
        // normalize to pathname so query/hash don't fragment the set
        let p;
        try { p = new URL(href, window.location.origin).pathname; } catch (_) { p = href; }
        if (!/^\/admin\//.test(p)) return;
        if (!map.has(p)) map.set(p, new Set());
        map.get(p).add(source);
      };
      // sidebar
      document.querySelectorAll('.db-sidebar-nav a.db-sidebar-nav-menu, .db-sidebar-nav a[href^="/admin/"]')
        .forEach((a) => add(a.getAttribute('href'), 'sidebar'));
      // dashboard quick-access — the nav block right under the heading
      document.querySelectorAll('nav[aria-label] a[href^="/admin/"]')
        .forEach((a) => add(a.getAttribute('href'), 'quick-access'));
      return Array.from(map.entries()).map(([href, srcs]) => ({ href, sources: Array.from(srcs) }));
    });

    // ANTI-VACUOUS: enumeration must not silently return ~nothing.
    console.log(`[DASH-T10] enumerated ${targets.length} nav target(s):`);
    targets.forEach((t) => console.log(`   ${t.href}  (${t.sources.join('+')})`));
    expect(
      targets.length,
      `enumeration must find a real nav set (got ${targets.length}); sidebar/quick-access selectors may be stale`,
    ).toBeGreaterThan(12);

    // -------------------------------------------------------------------------
    // STEP 2 — visit each target, snap, then evaluate the working-page guard.
    // -------------------------------------------------------------------------
    const results = []; // { route, ok, reason, sources, finalUrl }
    const failures = [];

    for (const { href, sources } of targets) {
      routeErrors = []; // reset per-route JS error collector
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

      // SNAP BEFORE ASSERT — quartet on disk regardless of outcome.
      await snap(name).catch(() => {});

      // (a) bounce to /login = broken / unauthorized.
      if (/\/login(?:$|[/?#])/.test(finalUrl)) {
        ok = false;
        reasons.push(`bounced to /login (final=${finalUrl})`);
      }

      // (b) routed content presence — THE core dual guard.
      const routed = await readRoutedContent();
      if (!routed.mainPresent && ok) {
        // .db-main never mounted: only acceptable if body itself has real content
        // (it should not, for an admin route). Treat short body as blank.
        const blen = (routed.text || '').length;
        if (blen < 40) { ok = false; reasons.push('admin shell (main.db-main) never mounted; body near-empty'); }
        else { reasons.push('main.db-main absent but body had content (recorded)'); }
      } else if (routed.mainPresent && (routed.len == null || routed.len < 40)) {
        ok = false;
        reasons.push(`routed content blank/near-empty (len=${routed.len}) — shell present but page area empty`);
      }

      // (c) JS pageerror during this route load = strong signal.
      if (routeErrors.length) {
        ok = false;
        reasons.push(`JS pageerror(s): ${JSON.stringify(routeErrors.slice(0, 3))}`);
      }

      // (d) literal error-page signature ONLY when content is also short
      //     (avoid false-fail on benign FR "erreur" labels on a populated page).
      const routedText = routed.text || '';
      if (ok && ERROR_PAGE_RE.test(routedText) && routedText.length < 200) {
        ok = false;
        reasons.push(`error-page signature on short content: "${routedText.slice(0, 120)}"`);
      }

      // (e) raw i18n key leak in visible DOM.
      const leaks = await scanI18nLeak();
      if (leaks.length) {
        ok = false;
        reasons.push(`i18n key leak: ${JSON.stringify(leaks.slice(0, 8))}`);
      }

      const reason = reasons.length ? reasons.join(' | ') : 'working page (routed content rendered, no error, no i18n leak)';
      results.push({ route: href, ok, reason, sources, finalUrl });
      if (!ok) failures.push({ route: href, reason });
      console.log(`[DASH-T10] ${ok ? 'PASS' : 'FAIL'}  ${href}  -> ${reason}`);
    }

    // -------------------------------------------------------------------------
    // STEP 3 — durable JSON summary, then ONE final hard assert.
    // -------------------------------------------------------------------------
    const summary = {
      generated_at: new Date().toISOString(),
      total: results.length,
      working: results.filter((r) => r.ok).length,
      broken: failures.length,
      results,
    };
    fs.writeFileSync(path.join(DIR, 'nav-reachability-summary.json'), JSON.stringify(summary, null, 2));
    console.log(`\n[DASH-T10] ${summary.working}/${summary.total} nav targets reach a working page.`);
    if (failures.length) {
      console.log('[DASH-T10] BROKEN/ORPHAN nav targets:');
      failures.forEach((f) => console.log(`   - ${f.route}: ${f.reason}`));
    }

    expect(
      failures,
      `nav targets that did NOT reach a working page:\n${failures.map((f) => `  ${f.route} :: ${f.reason}`).join('\n')}`,
    ).toEqual([]);
  });
});
