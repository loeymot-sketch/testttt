/**
 * BAD-MOOD AUDIT 3 — Visual Walk 2026-05-25
 *
 * Hostile auditor : walk 7 critical surfaces, capture screenshots,
 * extract console errors, verify text rendering.
 *
 * Output: reports/audits/BAD-MOOD-AUDIT-3-VISUAL/
 *
 * Defensive: each surface in its own try/catch so one hang doesn't kill the audit.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login } = require('../e2e/helpers/login');

const OUTPUT_DIR = path.join(
  __dirname,
  '..',
  '..',
  'reports',
  'audits',
  'BAD-MOOD-AUDIT-3-VISUAL'
);

if (!fs.existsSync(OUTPUT_DIR)) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

const RAW_LABEL_PATTERNS = [
  /label\.[a-z][a-z0-9_.]+/i,
  /\bkiosk\.[a-z][a-z0-9_.]+/i,
  /\badmin\.[a-z][a-z0-9_.]+/i,
  /\bpos\.[a-z][a-z0-9_.]+/i,
  /\b0undefined\b/i,
  /\bNaN\s*€/,
  /\[object Object\]/,
];

const auditResults = {
  auditor: 'Bad-mood AUDIT-3 visual',
  timestamp: new Date().toISOString(),
  surfaces_audited: 0,
  all_render: true,
  per_surface: {},
  raw_labels_found: [],
  console_errors_total: 0,
  broken_layouts: [],
  missing_features_visible: [],
  overall_verdict: 'PENDING',
};

function checkRawLabels(text) {
  if (!text) return [];
  const found = [];
  for (const pattern of RAW_LABEL_PATTERNS) {
    const matches = text.match(new RegExp(pattern.source, pattern.flags + 'g'));
    if (matches) {
      for (const m of matches) {
        if (m.length < 80) found.push(m);
      }
    }
  }
  return [...new Set(found)].slice(0, 20);
}

function setSurface(key, result) {
  auditResults.per_surface[key] = result;
  auditResults.surfaces_audited++;
  if (!result.renders) auditResults.all_render = false;
  auditResults.console_errors_total += result.console_errors || 0;
  if (result.raw_labels && result.raw_labels.length) {
    auditResults.raw_labels_found.push({ surface: key, labels: result.raw_labels });
  }
}

function writeOut() {
  // Final verdict
  let allGreen = true;
  let anyRed = false;
  for (const s of Object.values(auditResults.per_surface)) {
    if (s.verdict === 'RED') anyRed = true;
    if (s.verdict !== 'GREEN') allGreen = false;
  }
  auditResults.overall_verdict = anyRed
    ? 'CRITICAL-FAIL'
    : allGreen
    ? 'PRODUCTION-VISUAL-OK'
    : 'UI-REGRESSIONS';

  const outFile = path.join(OUTPUT_DIR, 'VISUAL-VERDICT.json');
  fs.writeFileSync(outFile, JSON.stringify(auditResults, null, 2));
  console.log('AUDIT_OUTPUT_FILE=' + outFile);
  console.log('OVERALL_VERDICT=' + auditResults.overall_verdict);
}

async function captureSurface(context, key, url, opts = {}) {
  const page = await context.newPage();
  const consoleErrors = [];
  const networkErrors = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 300));
  });
  page.on('pageerror', (err) => {
    consoleErrors.push(`PAGEERROR: ${err.message.slice(0, 300)}`);
  });
  page.on('requestfailed', (req) => {
    const u = req.url();
    if (u.startsWith('chrome-extension://')) return;
    if (u.includes('hot-update.json')) return;
    networkErrors.push(`${req.method()} ${u} :: ${req.failure()?.errorText || 'failed'}`);
  });

  let renders = true;
  const issues = [];
  let rawLabels = [];
  let screenshot = null;
  let httpStatus = null;

  try {
    const resp = await page.goto(url, {
      waitUntil: opts.waitUntil || 'domcontentloaded',
      timeout: opts.gotoTimeout || 30000,
    });
    httpStatus = resp ? resp.status() : null;
    if (resp && !resp.ok() && resp.status() !== 401 && resp.status() !== 302) {
      renders = false;
      issues.push(`HTTP ${resp.status()} from ${url}`);
    }
    await page.waitForTimeout(opts.settleMs || 3000);

    if (opts.analyze) {
      try {
        const r = await opts.analyze(page);
        if (r) {
          if (r.issues) issues.push(...r.issues);
          if (r.rawLabels) rawLabels = rawLabels.concat(r.rawLabels);
          if (r.renders === false) renders = false;
          if (r.extra) {
            Object.assign(auditResults.per_surface[key] || {}, r.extra);
          }
        }
      } catch (e) {
        issues.push(`Analyze exception: ${e.message.slice(0, 200)}`);
      }
    }
    const filename = `${key}.png`;
    await page.screenshot({ path: path.join(OUTPUT_DIR, filename), fullPage: opts.fullPage || false });
    screenshot = filename;
  } catch (e) {
    renders = false;
    issues.push(`EXCEPTION: ${e.message.slice(0, 300)}`);
    try {
      const filename = `${key}-error.png`;
      await page.screenshot({ path: path.join(OUTPUT_DIR, filename) });
      screenshot = filename;
    } catch (_e) {}
  }

  let verdict;
  if (!renders) verdict = 'RED';
  else if (consoleErrors.length > 0 || networkErrors.length > 0 || issues.length > 0)
    verdict = 'AMBER';
  else if (rawLabels.length > 0) verdict = 'AMBER';
  else verdict = 'GREEN';

  setSurface(key, {
    url,
    http_status: httpStatus,
    renders,
    screenshot,
    console_errors: consoleErrors.length,
    console_error_samples: consoleErrors.slice(0, 10),
    network_errors: networkErrors.length,
    network_error_samples: networkErrors.slice(0, 10),
    raw_labels: rawLabels,
    issues_found: issues,
    verdict,
  });

  await page.close().catch(() => {});
}

test.describe.configure({ mode: 'serial' });

test('BAD-MOOD AUDIT 3 — Visual walk 7 surfaces', async ({ browser }) => {
  test.setTimeout(900_000);

  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
  });

  try {
    // 1) Kiosk idle (anonymous)
    await captureSurface(context, 'kiosk_idle', 'http://127.0.0.1:8000/kiosk/idle', {
      settleMs: 3500,
      analyze: async (page) => {
        const bodyText = await page.evaluate(() => document.body.innerText || '');
        const rawLabels = checkRawLabels(bodyText);
        const issues = [];
        const hasHero =
          bodyText.toLowerCase().includes('bienvenue') ||
          bodyText.toLowerCase().includes('commencer') ||
          bodyText.toLowerCase().includes('start') ||
          bodyText.toLowerCase().includes('emporter');
        if (!hasHero) issues.push('Kiosk hero/CTA text not detected');
        const cayenne = bodyText.toLowerCase().includes('cayenne');
        if (!cayenne) issues.push('Branding "Le Cayenne" not visible on idle');
        return { issues, rawLabels };
      },
    });

    // 2) Login (just render)
    await captureSurface(context, 'login', 'http://127.0.0.1:8000/login', {
      settleMs: 2000,
      analyze: async (page) => {
        const issues = [];
        const emailCount = await page.locator('#formEmail').count();
        const passwordCount = await page.locator('#formPassword').count();
        if (!emailCount) issues.push('Login #formEmail not found');
        if (!passwordCount) issues.push('Login #formPassword not found');
        const bodyText = await page.evaluate(() => document.body.innerText || '');
        const rawLabels = checkRawLabels(bodyText);
        return { issues, rawLabels };
      },
    });

    // Now perform real login on a shared page for authenticated surfaces
    const authPage = await context.newPage();
    let loggedIn = false;
    try {
      await login(authPage, ADMIN_EMAIL, ADMIN_PASSWORD);
      loggedIn = true;
      auditResults.per_surface.login.login_ok = true;
      auditResults.per_surface.login.login_redirect_url = authPage.url();
      await authPage.screenshot({ path: path.join(OUTPUT_DIR, 'login-post-submit.png') });
    } catch (e) {
      auditResults.per_surface.login.login_ok = false;
      auditResults.per_surface.login.issues_found.push(
        `Login flow failed: ${e.message.slice(0, 250)}`
      );
      auditResults.per_surface.login.verdict = 'RED';
    }
    await authPage.close().catch(() => {});

    // 3) POS (post-login, authenticated context shares cookies/localStorage)
    if (loggedIn) {
      await captureSurface(context, 'pos', 'http://127.0.0.1:8000/admin/pos', {
        settleMs: 5000,
        gotoTimeout: 45000,
        analyze: async (page) => {
          const bodyText = await page.evaluate(() => document.body.innerText || '');
          const rawLabels = checkRawLabels(bodyText);
          const issues = [];
          const hasPosShell =
            bodyText.toLowerCase().includes('caisse') ||
            bodyText.toLowerCase().includes('panier') ||
            bodyText.toLowerCase().includes('total') ||
            bodyText.toLowerCase().includes('encaisser') ||
            bodyText.toLowerCase().includes('catégorie');
          if (!hasPosShell) issues.push('POS shell text signals not detected');
          const catCount = await page
            .locator('[data-pos-category], .pos-category, [data-test-pos-category], [class*="pos-cat"]')
            .count();
          const itemCount = await page
            .locator('[data-pos-item], .pos-item, [data-test-pos-item], [class*="pos-item"]')
            .count();
          return {
            issues,
            rawLabels,
            extra: { pos_categories_in_dom: catCount, pos_items_in_dom: itemCount },
          };
        },
      });

      // 4) KDS - try kitchen-display-system route
      await captureSurface(
        context,
        'kds',
        'http://127.0.0.1:8000/admin/kitchen-display-system',
        {
          settleMs: 5000,
          gotoTimeout: 45000,
          analyze: async (page) => {
            const bodyText = await page.evaluate(() => document.body.innerText || '');
            const rawLabels = checkRawLabels(bodyText);
            const issues = [];
            const kdsHint =
              bodyText.toLowerCase().includes('en attente') ||
              bodyText.toLowerCase().includes('en cours') ||
              bodyText.toLowerCase().includes('historique') ||
              bodyText.toLowerCase().includes('cuisine') ||
              bodyText.toLowerCase().includes('kitchen') ||
              bodyText.toLowerCase().includes('bump');
            if (!kdsHint) issues.push('KDS text signals not detected');
            // Look for +N en attente chip
            const chipCount = await page
              .locator('text=/\\+\\s*\\d+\\s*en attente/i, [data-kds-pending-chip], .kds-pending-chip')
              .count()
              .catch(() => 0);
            return {
              issues,
              rawLabels,
              extra: { kds_pending_chip_count: chipCount },
            };
          },
        }
      );

      // 5) OSS = order-status-screen
      await captureSurface(
        context,
        'oss',
        'http://127.0.0.1:8000/admin/order-status-screen',
        {
          settleMs: 5000,
          gotoTimeout: 45000,
          analyze: async (page) => {
            const bodyText = await page.evaluate(() => document.body.innerText || '');
            const rawLabels = checkRawLabels(bodyText);
            const issues = [];
            const ossHint =
              bodyText.toLowerCase().includes('prêt') ||
              bodyText.toLowerCase().includes('ready') ||
              bodyText.toLowerCase().includes('en cours') ||
              bodyText.toLowerCase().includes('preparing') ||
              bodyText.toLowerCase().includes('commande');
            if (!ossHint) issues.push('OSS text signals not detected');
            return { issues, rawLabels };
          },
        }
      );
    } else {
      // Skip authed surfaces but still record
      for (const k of ['pos', 'kds', 'oss']) {
        setSurface(k, {
          url: `http://127.0.0.1:8000/admin/...`,
          renders: false,
          screenshot: null,
          console_errors: 0,
          console_error_samples: [],
          network_errors: 0,
          network_error_samples: [],
          raw_labels: [],
          issues_found: ['Skipped — login flow failed upstream'],
          verdict: 'RED',
        });
      }
    }

    // 6) Healthz (anonymous, JSON)
    await captureSurface(context, 'healthz', 'http://127.0.0.1:8000/api/healthz', {
      settleMs: 500,
      analyze: async (page) => {
        const text = await page.evaluate(() => document.body.innerText || '');
        const issues = [];
        let json = null;
        try {
          json = JSON.parse(text);
        } catch (e) {
          issues.push(`Healthz did not return parseable JSON: ${text.slice(0, 200)}`);
        }
        const extra = {};
        if (json) {
          extra.healthz_payload = json;
          if (json.status !== 'ok') issues.push(`Healthz status != ok (got: ${json.status})`);
          if (json.checks && typeof json.checks === 'object') {
            for (const [name, val] of Object.entries(json.checks)) {
              const stat = typeof val === 'object' ? val.status : val;
              if (typeof stat === 'string' && stat !== 'ok')
                issues.push(`Healthz check '${name}' = ${JSON.stringify(val).slice(0, 100)}`);
            }
          }
        }
        return { issues, rawLabels: checkRawLabels(text), extra };
      },
    });

    // 7) Gap-decisions static HTML page
    await captureSurface(
      context,
      'gap_decisions',
      'http://127.0.0.1:8000/gap-decisions-2026-05-25.html',
      {
        settleMs: 2500,
        analyze: async (page) => {
          const bodyText = await page.evaluate(() => document.body.innerText || '');
          const rawLabels = checkRawLabels(bodyText);
          const issues = [];
          // Multiple card-selector candidates
          const candidates = ['.card', '[data-card]', '[data-gap-card]', '[data-decision]', 'article'];
          const counts = {};
          for (const sel of candidates) {
            counts[sel] = await page.locator(sel).count().catch(() => 0);
          }
          const maxCount = Math.max(...Object.values(counts));
          if (maxCount === 0) issues.push('gap-decisions: zero cards rendered (no selector matched)');
          else if (maxCount < 25)
            issues.push(`gap-decisions: only ${maxCount} cards rendered (expected ~30)`);
          // Filter elements
          const filterEls = await page.locator('input, select, button').count().catch(() => 0);
          // localStorage support test
          let lsOk = false;
          try {
            const lsResult = await page.evaluate(() => {
              try {
                localStorage.setItem('__audit_test__', '1');
                const v = localStorage.getItem('__audit_test__');
                localStorage.removeItem('__audit_test__');
                return v === '1';
              } catch (_) {
                return false;
              }
            });
            lsOk = lsResult;
          } catch (_e) {}
          return {
            issues,
            rawLabels,
            extra: { cards_in_dom_by_selector: counts, interactive_count: filterEls, localStorage_ok: lsOk },
          };
        },
      }
    );
  } finally {
    await context.close().catch(() => {});
    writeOut();
  }
});
