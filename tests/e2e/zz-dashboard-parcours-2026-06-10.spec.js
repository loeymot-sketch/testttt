// W-B-DASHBOARD — Parcours gérant 100% (GOAL ULTRA-AUDIT TOTAL 2026-06-10)
// Cible : http://127.0.0.1:8766 (clone jetable foodking_e2e — JAMAIS :8765/op)
// Visite chaque page admin pertinente gérant, collecte console errors /
// pageerrors / réponses HTTP >= 400 / labels i18n bruts / marqueurs de crash,
// screenshot fullPage par page + sous-états interactifs sur 4 pages clés.
//
// Run :
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//   tests/e2e/zz-dashboard-parcours-2026-06-10.spec.js --retries=0

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsAdmin } = require('./helpers/login');

const OUT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/validation-100-2026-06-10/dashboard',
);

// Pages réelles extraites de resources/js/router/modules/*.js (aucune invention).
const PAGES = [
  { name: 'dashboard', path: '/admin/dashboard', interact: 'dashboard' },
  { name: 'sales-report', path: '/admin/sales-report', interact: 'filter' },
  { name: 'items-report', path: '/admin/items-report' },
  { name: 'credit-balance-report', path: '/admin/credit-balance-report' },
  { name: 'transactions', path: '/admin/transactions', interact: 'filter' },
  { name: 'historique', path: '/admin/historique', interact: 'filter' },
  { name: 'online-orders', path: '/admin/online-orders' },
  { name: 'pos-orders', path: '/admin/pos-orders' },
  { name: 'pos-orders-tracker', path: '/admin/pos-orders-tracker' },
  { name: 'encaissement', path: '/admin/encaissement' },
  { name: 'cash-overview', path: '/admin/cash-overview' },
  { name: 'cash-sessions-report', path: '/admin/cash-sessions-report' },
  { name: 'stock-rupture', path: '/admin/stock/rupture' },
  { name: 'items', path: '/admin/items' },
  { name: 'categories', path: '/admin/settings/item-categories/list' },
  { name: 'coupons', path: '/admin/coupons' },
  { name: 'offers', path: '/admin/offers' },
  { name: 'employees', path: '/admin/employees' },
  { name: 'customers', path: '/admin/customers' },
  { name: 'administrators', path: '/admin/administrators' },
  { name: 'chefs', path: '/admin/chefs' },
  { name: 'delivery-boys', path: '/admin/delivery-boys' },
  { name: 'subscribers', path: '/admin/subscribers' },
  { name: 'messages', path: '/admin/messages' },
  { name: 'push-notifications', path: '/admin/push-notifications' },
  { name: 'settings-company', path: '/admin/settings/company' },
  { name: 'settings-site', path: '/admin/settings/site' },
  { name: 'settings-order-setup', path: '/admin/settings/order-setup' },
  { name: 'settings-kiosk-setup', path: '/admin/settings/kiosk-setup' },
  { name: 'observability', path: '/admin/observability' },
  { name: 'order-status-screen', path: '/admin/order-status-screen' },
  { name: 'kds', path: '/admin/kitchen-display-system' },
];

// Marqueurs de crash visibles (Laravel error page / boundary Vue / SPA morte)
const CRASH_RE =
  /(Whoops|Server Error|ErrorException|Stack trace|Ce widget a rencontré une erreur|Something went wrong|404\s*\|\s*NOT FOUND|403\s*\|\s*FORBIDDEN|500\s*\|\s*SERVER ERROR)/i;

// Détection labels i18n bruts : tokens type `label.total_sales`, `button.filter`…
const RAW_LABEL_PREFIXES =
  '(label|button|message|menu|validation|placeholder|tooltip)';

function sanitize(s) {
  return String(s).replace(/\s+/g, ' ').slice(0, 300);
}

async function scanPage(page) {
  return page.evaluate((prefixes) => {
    const text = document.body ? document.body.innerText : '';
    const rawLabels = new Set();
    // 1) regex stricte demandée par la mission (ligne entière = token i18n)
    const lineRe = /^[a-z]+\.[a-z_.]+$/;
    // 2) tokens i18n connus n'importe où dans le texte
    const tokenRe = new RegExp('(?:^|[\\s>(])(' + prefixes + '\\.[a-z][a-z0-9_.]+)', 'g');
    for (const line of text.split('\n')) {
      const t = line.trim();
      if (t && lineRe.test(t)) rawLabels.add(t);
    }
    let m;
    while ((m = tokenRe.exec(text)) !== null) rawLabels.add(m[1]);
    return {
      rawLabels: [...rawLabels].slice(0, 30),
      bodySample: text.slice(0, 5000),
      bodyLength: text.length,
    };
  }, RAW_LABEL_PREFIXES);
}

test.describe.serial('W-B Dashboard parcours gérant 100%', () => {
  test('parcours complet pages admin + interactions clés', async ({ page }) => {
    test.setTimeout(1_500_000);
    fs.mkdirSync(OUT_DIR, { recursive: true });

    const results = [];
    let consoleErrors = [];
    let pageErrors = [];
    let badResponses = [];
    const resetCollectors = () => {
      consoleErrors = [];
      pageErrors = [];
      badResponses = [];
    };

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(sanitize(msg.text()));
      }
    });
    page.on('pageerror', (err) => pageErrors.push(sanitize(err.message)));
    page.on('response', (res) => {
      if (res.status() >= 400) {
        badResponses.push(`${res.status()} ${res.request().method()} ${res.url()}`);
      }
    });

    // ---- LOGIN ----
    resetCollectors();
    await loginAsAdmin(page);
    await page.waitForLoadState('networkidle').catch(() => {});

    const shoot = async (file) => {
      await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: true });
    };

    const visit = async (p) => {
      resetCollectors();
      await page.goto(p.path, { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
      await page.waitForTimeout(1800); // widgets async (charts, polls)
      const scan = await scanPage(page);
      const crash = CRASH_RE.exec(scan.bodySample);
      await shoot(`${p.name}.png`);
      const entry = {
        name: p.name,
        path: p.path,
        finalUrl: page.url(),
        consoleErrors: [...consoleErrors],
        pageErrors: [...pageErrors],
        badResponses: [...badResponses],
        rawLabels: scan.rawLabels,
        crashMarker: crash ? crash[0] : null,
        bodyLength: scan.bodyLength,
      };
      results.push(entry);
      return entry;
    };

    // ---- KPI dashboard pour vérif DB (intégrité numérique) ----
    const grabRealtimeKpis = async () =>
      page.evaluate(() => {
        const out = {};
        for (const h3 of document.querySelectorAll('h3')) {
          const t = h3.innerText.trim();
          const h4 = h3.parentElement && h3.parentElement.querySelector('h4');
          if (!h4) continue;
          if (/Chiffre d'Affaires du Jour/i.test(t)) out.daily_sales = h4.innerText.trim();
          if (/Commandes du Jour/i.test(t)) out.daily_orders = h4.innerText.trim();
          if (/Ticket Moyen/i.test(t)) out.average_ticket = h4.innerText.trim();
        }
        return out;
      });

    // ---- INTERACTIONS non-destructives ----
    const interactFilter = async (p) => {
      // FilterComponent générique (.table-filter-btn) — ouvrir / capturer / fermer
      const btn = page.locator('.table-filter-btn').first();
      if (await btn.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await btn.click();
        await page.waitForTimeout(900);
        await shoot(`${p.name}--filter-open.png`);
        // Datepicker éventuel dans le panneau : ouvrir le calendrier + le refermer
        const dp = page.locator('.table-filter-div input.dp__input, .dp__input').first();
        if (await dp.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await dp.click();
          await page.waitForTimeout(700);
          const menuVisible = await page
            .locator('.dp__menu')
            .first()
            .isVisible()
            .catch(() => false);
          if (menuVisible) {
            await shoot(`${p.name}--datepicker-open.png`);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(400);
          }
        }
        await btn.click(); // referme le panneau
        await page.waitForTimeout(500);
      }
    };

    const interactDashboard = async (p) => {
      // Changer la date du widget "Résumé des ventes" (Datepicker uid=salesSummaryDate)
      const dpInput = page.locator('#dp-input-salesSummaryDate');
      if (await dpInput.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await dpInput.scrollIntoViewIfNeeded().catch(() => {});
        await dpInput.click();
        await page.waitForTimeout(700);
        const menu = page.locator('.dp__menu').first();
        if (await menu.isVisible().catch(() => false)) {
          // sélectionne le 1er jour visible puis le dernier (range non-destructif)
          const cells = page.locator('.dp__menu .dp__calendar_item .dp__cell_inner:not(.dp__cell_offset)');
          const n = await cells.count();
          if (n > 1) {
            await cells.first().click();
            await page.waitForTimeout(300);
            await cells.nth(n - 1).click().catch(() => {});
          }
          await page.waitForTimeout(1500);
          await shoot(`${p.name}--sales-summary-range.png`);
        }
      }
      // Statistiques de commandes : ouvrir le datepicker puis Escape (non-destructif)
      const statsDp = page.locator('#dp-input-orderStatisticsDate');
      if (await statsDp.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await statsDp.scrollIntoViewIfNeeded().catch(() => {});
        await statsDp.click();
        await page.waitForTimeout(600);
        if (await page.locator('.dp__menu').first().isVisible().catch(() => false)) {
          await shoot(`${p.name}--order-stats-datepicker.png`);
          await page.keyboard.press('Escape');
          await page.waitForTimeout(400);
        }
      }
    };

    // ---- BOUCLE PRINCIPALE ----
    let kpis = null;
    for (const p of PAGES) {
      const entry = await visit(p);
      if (p.name === 'dashboard') {
        kpis = await grabRealtimeKpis();
        entry.realtimeKpis = kpis;
      }
      if (p.interact === 'filter') {
        await interactFilter(p).catch((e) => {
          entry.interactError = sanitize(e.message);
        });
      } else if (p.interact === 'dashboard') {
        await interactDashboard(p).catch((e) => {
          entry.interactError = sanitize(e.message);
        });
      }
    }

    // ---- RAPPORT JSON ----
    fs.writeFileSync(
      path.join(OUT_DIR, 'parcours-results.json'),
      JSON.stringify({ generatedAt: new Date().toISOString(), kpis, results }, null, 2),
    );

    // ---- ASSERTIONS SOUPLES (le rapport JSON porte le détail) ----
    const crashed = results.filter((r) => r.crashMarker);
    const hardErrors = results.filter((r) => r.pageErrors.length > 0);
    console.log(`\n=== PARCOURS: ${results.length} pages ===`);
    for (const r of results) {
      const flags = [
        r.crashMarker ? `CRASH(${r.crashMarker})` : '',
        r.pageErrors.length ? `pageerrors=${r.pageErrors.length}` : '',
        r.consoleErrors.length ? `console=${r.consoleErrors.length}` : '',
        r.badResponses.length ? `http4xx5xx=${r.badResponses.length}` : '',
        r.rawLabels.length ? `rawLabels=${r.rawLabels.length}` : '',
      ]
        .filter(Boolean)
        .join(' ');
      console.log(`  ${r.name.padEnd(26)} ${flags || 'OK'}`);
    }
    expect(crashed.map((r) => r.name), 'pages avec marqueur de crash').toEqual([]);
    expect(hardErrors.map((r) => r.name), 'pages avec pageerror JS').toEqual([]);
  });
});
