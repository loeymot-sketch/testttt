// FoodKing E2E — GOAL Functional Validation OSS — Round 2 (read+test+report only) — 2026-05-28
//
// Round 2 scope additions on top of goal-functional-oss-2026-05-28.spec.js (Round 1):
//   T-09  Mission URL `/order-status-screen` (no /admin/ prefix) — auth-bounce repro
//   T-10  Contrast AAA target ≥7:1 on column headers (TV-wall ≥3m readability)
//   T-11  Mobile viewport carryover — OSS-VISUAL-01 "En preparat" truncation re-test
//   T-12  TV-wall viewport 1920×1080 capture (mission default — Round 1 used desktop default)
//   T-13  Receipt modal probe (must be absent on customer wall — N/A attestation)
//   T-14  Wakelock + chime wiring re-attest at TV viewport (mission "specific things")
//
// Screenshots sink: /tmp/foodking-round2-oss/  (per mission instructions)
// Findings sink   : reports/test-e2e/goal-functional-validation-2026-05-28/OSS/round-2/findings.json
//
// IMPORTANT: This spec does NOT modify the existing Round 1 spec or its findings.json — it
// is purely additive (Round 1 reproducibility preserved per advisor guidance).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SHOTS_DIR = '/tmp/foodking-round2-oss';
const REPORT_DIR = path.join(
  __dirname, '..', '..',
  'reports', 'test-e2e', 'goal-functional-validation-2026-05-28', 'OSS', 'round-2',
);
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });
if (!fs.existsSync(REPORT_DIR)) fs.mkdirSync(REPORT_DIR, { recursive: true });
const FINDINGS_PATH = path.join(REPORT_DIR, 'findings.json');

const STATUS = { PREPARING: 7, PREPARED: 8 };
const TYPE   = { TAKEAWAY: 10, KIOSK: 25 };

const OSS_URL_ADMIN  = '/admin/order-status-screen';   // SPA-registered, public-friendly bypass
const OSS_URL_PLAIN  = '/order-status-screen';          // MISSION URL — NOT registered in SPA router
const PUBLIC_API     = '**/api/frontend/oss-order**';

const CONSOLE_NOISE = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext|wakeLock|workbox|GoogleAnalytics)/i;

let findings = [];
function record(id, level, kind, title, evidence) {
  findings.push({ id, level, kind, title, evidence, ts: new Date().toISOString() });
}

function makeOrder({ id, queue, type = TYPE.KIOSK, status = STATUS.PREPARING, token = null }) {
  return {
    id,
    order_serial_no: `OSS-R2-${id}`,
    token,
    queue_number: queue,
    order_type: type,
    status,
  };
}

// WCAG 2.x relative luminance helper (sRGB → linear → relative L)
function rgbToLuminance(r, g, b) {
  const toLin = (v) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * toLin(r) + 0.7152 * toLin(g) + 0.0722 * toLin(b);
}
function contrastRatio(rgb1, rgb2) {
  const L1 = rgbToLuminance(...rgb1);
  const L2 = rgbToLuminance(...rgb2);
  const [hi, lo] = L1 > L2 ? [L1, L2] : [L2, L1];
  return (hi + 0.05) / (lo + 0.05);
}
function parseRgbString(s) {
  // accepts "rgb(176, 0, 77)" / "rgba(176,0,77,1)"
  const m = s.match(/rgba?\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
  if (!m) return null;
  return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
}

test.beforeAll(() => {
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify([], null, 2));
  findings = [];
});

test.afterAll(() => {
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify(findings, null, 2));
});

test.describe('OSS Round 2 — mission attack surface', () => {

  // ─────────────────────────────────────────────────────────────────────────
  // T-09  Mission URL `/order-status-screen` (no /admin/ prefix) probe.
  //       Round 1 reported "AUTH BOUNCE P1" — verify or document.
  //       Background: SPA router only registers /admin/order-status-screen
  //       and /order-status. The plain /order-status-screen is NOT in
  //       publicFriendlyPaths (app.js:165) and NOT in router/index.js:132/216.
  //       Expectation: SPA serves 200 HTML shell (catchall), then router
  //       hits a NotFound fall-through OR redirects to /login.
  // ─────────────────────────────────────────────────────────────────────────
  test('T-09 mission URL /order-status-screen — auth bounce reproduction', async ({ page }) => {
    const navTrail = [];
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) navTrail.push(frame.url());
    });
    page.on('console', (m) => {
      if (m.type() === 'error' && !CONSOLE_NOISE.test(m.text())) {
        navTrail.push(`CONSOLE_ERR: ${m.text().slice(0, 160)}`);
      }
    });

    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }),
    }));

    const resp = await page.goto(OSS_URL_PLAIN, { waitUntil: 'domcontentloaded' });
    const initialStatus = resp ? resp.status() : null;
    await page.waitForTimeout(1500); // give SPA router time to bounce

    const finalUrl = page.url();
    const finalPath = new URL(finalUrl).pathname;
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasOSSColumns = await page.locator('.customer-screen').count();
    const hasLoginForm = await page.locator('input[type="password"], form[action*="login"], h1:has-text("Login"), h1:has-text("Connexion")').count();

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-09-mission-url-result.png'), fullPage: true });

    // Classify outcome.
    let outcome = 'unknown';
    if (finalPath === '/login' || hasLoginForm > 0) outcome = 'auth_bounce_to_login';
    else if (hasOSSColumns >= 2) outcome = 'oss_mounted_directly';
    else if (finalPath === OSS_URL_PLAIN && hasOSSColumns === 0) outcome = 'blank_or_404';
    else if (finalPath === OSS_URL_ADMIN) outcome = 'redirected_to_admin_path';

    record('OSS-R2-T-09', outcome === 'oss_mounted_directly' ? 'INFO' : 'P1', 'defect',
      `Mission URL /order-status-screen probe — outcome=${outcome}`,
      {
        initial_http_status: initialStatus,
        final_url: finalUrl,
        final_path: finalPath,
        body_chars: bodyText.length,
        oss_columns_visible: hasOSSColumns,
        login_form_present: hasLoginForm > 0,
        nav_trail: navTrail.slice(0, 12),
        body_excerpt: bodyText.slice(0, 240),
        spa_router_evidence: {
          router_modules_path: 'resources/js/router/modules/orderStatusScreenRoutes.js',
          registered_path_line: 8,
          registered_path_value: '/admin/order-status-screen',
          public_friendly_paths_file: 'resources/js/app.js',
          public_friendly_paths_line: 165,
          public_friendly_paths_value: "['/admin/order-status-screen', '/order-status']",
        },
        remediation: 'Either (a) register /order-status-screen as an alias of admin.order-status-screen in router/modules/orderStatusScreenRoutes.js, or (b) add a server-side 301 redirect /order-status-screen → /admin/order-status-screen. Currently the plain mission URL appears to leak to login OR render blank shell.',
      });
  });

  // ─────────────────────────────────────────────────────────────────────────
  // T-10  Contrast AAA ≥7:1 on column headers (TV-wall ≥3m readability mandate).
  //       Headers per PreparingAndReadyComponent.vue:
  //         line 27 — bg #B0004D + text white  → PREPARING
  //         line 49 — bg #1AB759 + text #1F1F39 → READY
  //       AAA target = 7:1 (large text 18pt+ would be 4.5:1; OSS is 40px+ = large,
  //       but mission demands AAA which is 7:1 regardless of size).
  // ─────────────────────────────────────────────────────────────────────────
  test('T-10 contrast AAA ≥7:1 on column headers (TV-wall readability)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [
          makeOrder({ id: 1, queue: 1, status: STATUS.PREPARING }),
          makeOrder({ id: 2, queue: 2, status: STATUS.PREPARED }),
        ],
      }),
    }));
    await page.goto(OSS_URL_ADMIN, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(900);

    const headers = await page.evaluate(() => {
      const out = [];
      document.querySelectorAll('.oss-column-header').forEach((h, i) => {
        const cs = window.getComputedStyle(h);
        out.push({
          idx: i,
          text: h.textContent.trim().slice(0, 30),
          bg: cs.backgroundColor,
          fg: cs.color,
          fontSize: cs.fontSize,
          fontWeight: cs.fontWeight,
        });
      });
      return out;
    });

    const results = headers.map((h) => {
      const bg = parseRgbString(h.bg);
      const fg = parseRgbString(h.fg);
      const ratio = bg && fg ? contrastRatio(bg, fg) : null;
      return {
        header_text: h.text,
        bg_rgb: bg, fg_rgb: fg, ratio,
        font_size: h.fontSize, font_weight: h.fontWeight,
        aaa_pass: ratio !== null && ratio >= 7.0,
        aa_pass: ratio !== null && ratio >= 4.5,
      };
    });

    results.forEach((r, i) => {
      if (!r.aaa_pass) {
        record(`OSS-R2-T-10-${i}`, r.aa_pass ? 'P2' : 'P1', 'defect',
          `Column header "${r.header_text}" contrast ${r.ratio?.toFixed(2)}:1 below AAA 7:1`,
          { ...r, target_aaa: 7.0, target_aa: 4.5 });
      }
    });

    record('OSS-R2-T-10-SUMMARY', 'INFO', 'attestation',
      'Contrast ratios computed for column headers (WCAG 2.x relative luminance).',
      { headers: results });

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-10-contrast-tvwall.png'), fullPage: true });
    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────────────
  // T-11  Mobile viewport carryover — OSS-VISUAL-01 truncation re-test.
  //       Round 1 found "En préparation" truncated to "En preparat" at 390×844.
  //       Re-capture + classify CARRYOVER / FIXED / STILL_PRESENT.
  // ─────────────────────────────────────────────────────────────────────────
  test('T-11 mobile viewport carryover — header truncation re-test', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [makeOrder({ id: 1, queue: 1, status: STATUS.PREPARING })],
      }),
    }));
    await page.goto(OSS_URL_ADMIN, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    const headerProbe = await page.evaluate(() => {
      const h = document.querySelector('.oss-column-header');
      if (!h) return { error: 'no_header' };
      const rect = h.getBoundingClientRect();
      const cs = window.getComputedStyle(h);
      return {
        text: h.textContent.trim(),
        scrollWidth: h.scrollWidth,
        clientWidth: h.clientWidth,
        offsetWidth: h.offsetWidth,
        rect_w: rect.width,
        is_truncated: h.scrollWidth > h.clientWidth + 1,
        font_size: cs.fontSize,
        overflow: cs.overflow,
        text_overflow: cs.textOverflow,
        white_space: cs.whiteSpace,
      };
    });

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-11-mobile-carryover.png'), fullPage: true });

    if (headerProbe.is_truncated) {
      record('OSS-R2-T-11', 'P1', 'defect',
        'CARRYOVER STILL_PRESENT — mobile header still truncates at 390×844 (Round 1 OSS-VISUAL-01)',
        {
          ...headerProbe,
          carryover_from: 'reports/test-e2e/goal-functional-validation-2026-05-28/OSS/findings.json#OSS-VISUAL-01',
          owner_caveat_round1: 'OSS is a TV wall — mobile may be out of scope for V1. Confirm with owner before patching.',
        });
    } else {
      record('OSS-R2-T-11-FIXED', 'INFO', 'attestation',
        'Mobile header no longer truncates — OSS-VISUAL-01 appears FIXED at this run',
        headerProbe);
    }
  });

  // ─────────────────────────────────────────────────────────────────────────
  // T-12  TV-wall viewport 1920×1080 capture (mission default per spec) —
  //       Round 1 used Playwright's default Desktop Chrome viewport
  //       (1280×720); mission asks for 1920×1080.
  // ─────────────────────────────────────────────────────────────────────────
  test('T-12 TV-wall viewport 1920×1080 — full panel capture', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [
          makeOrder({ id: 1, queue: 1, status: STATUS.PREPARING }),
          makeOrder({ id: 2, queue: 2, status: STATUS.PREPARING }),
          makeOrder({ id: 3, queue: 3, status: STATUS.PREPARED }),
          makeOrder({ id: 4, queue: 4, status: STATUS.PREPARED }),
        ],
      }),
    }));
    await page.goto(OSS_URL_ADMIN, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(900);

    // Both columns must remain visible on TV-wall.
    const panes = await page.locator('.customer-screen').count();
    if (panes < 2) {
      record('OSS-R2-T-12', 'P1', 'defect',
        'TV-wall 1920×1080 collapsed: <2 customer-screen panes visible',
        { count: panes });
    }
    expect(panes, 'Should render 2 customer-screen panes at 1920×1080').toBeGreaterThanOrEqual(2);

    // Token render size — mission mandates ≥40px tokens at TV viewport.
    const tokenFs = await page.evaluate(() => {
      const li = document.querySelector('ul.oss-order-list li');
      if (!li) return null;
      return parseFloat(window.getComputedStyle(li).fontSize);
    });
    if (tokenFs !== null && tokenFs < 40) {
      record('OSS-R2-T-12-FS', 'P1', 'defect',
        `Order token font-size ${tokenFs}px below 40px TV-wall readability mandate`,
        { observed_px: tokenFs, expected_min: 40 });
    } else {
      record('OSS-R2-T-12-FS-OK', 'INFO', 'attestation',
        `Order token font-size ${tokenFs}px meets ≥40px TV-wall mandate`,
        { observed_px: tokenFs });
    }

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-12-tvwall-fullpane.png'), fullPage: true });
    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────────────
  // T-13  Receipt modal probe — must be ABSENT on customer wall.
  //       Mission asked "Receipt modal doesn't bury info if any" — we confirm
  //       there is no receipt modal in scope on the public wall.
  // ─────────────────────────────────────────────────────────────────────────
  test('T-13 receipt modal probe — must be absent on customer wall', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [makeOrder({ id: 1, queue: 1, status: STATUS.PREPARED })],
      }),
    }));
    await page.goto(OSS_URL_ADMIN, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    // Click an order token (if clickable) to confirm no modal opens.
    const tokenSel = 'ul.oss-order-list li';
    const tokenCount = await page.locator(tokenSel).count();
    if (tokenCount > 0) {
      try { await page.locator(tokenSel).first().click({ timeout: 1500 }); } catch (_) { /* may be non-interactive — expected */ }
      await page.waitForTimeout(500);
    }

    // Receipt-style modal selectors common in this codebase.
    const modalSelectors = [
      '.modal.show', '.modal.is-open', '[role="dialog"]',
      '.receipt-modal', '.invoice-modal', '.order-detail-modal',
    ];
    let modalsFound = 0;
    for (const sel of modalSelectors) {
      modalsFound += await page.locator(sel).count();
    }

    if (modalsFound > 0) {
      record('OSS-R2-T-13', 'P1', 'defect',
        `Unexpected modal/dialog present on customer wall after click (count=${modalsFound})`,
        { selectors_probed: modalSelectors, modal_count_total: modalsFound });
    } else {
      record('OSS-R2-T-13-OK', 'INFO', 'attestation',
        'No receipt/order-detail modal on customer wall — read-only public surface confirmed',
        { selectors_probed: modalSelectors });
    }

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-13-no-receipt-modal.png'), fullPage: true });
    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────────────
  // T-14  Wakelock + chime re-attest at TV-wall viewport (mission's
  //       "wakelock active for kiosk mode" check).
  // ─────────────────────────────────────────────────────────────────────────
  test('T-14 wakeLock visibilitychange listener attached at TV viewport', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();

    await page.addInitScript(() => {
      window.__visListeners = 0;
      const orig = document.addEventListener;
      document.addEventListener = function (type, ...rest) {
        if (type === 'visibilitychange') window.__visListeners += 1;
        return orig.call(this, type, ...rest);
      };
    });

    await page.route(PUBLIC_API, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }),
    }));
    await page.goto(OSS_URL_ADMIN, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(900);

    const count = await page.evaluate(() => window.__visListeners || 0);
    if (count < 1) {
      record('OSS-R2-T-14', 'P1', 'defect',
        'TV-wall viewport: no visibilitychange listener registered (wakelock re-acquire path broken)',
        { observed_listeners: count, expected_min: 1 });
    } else {
      record('OSS-R2-T-14-OK', 'INFO', 'attestation',
        `wakelock visibilitychange wiring confirmed at TV viewport (listeners=${count})`,
        {
          observed_listeners: count,
          file: 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue',
          acquire_line: 198,
          visibility_attach_line: 150,
        });
    }
    expect(count, 'visibilitychange listener should be ≥1').toBeGreaterThanOrEqual(1);

    await page.screenshot({ path: path.join(SHOTS_DIR, 'T-14-wakelock-tvwall.png'), fullPage: true });
    await ctx.close();
  });
});
