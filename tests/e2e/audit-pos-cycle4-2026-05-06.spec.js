// FoodKing E2E — AUDIT POS CYCLE 4 (2026-05-06)
// Tests post-paiement : ticket réel + duplicata + KDS broadcast + drawer + Pusher subscribe
// Prérequis : order #188 créé via tinker (Tacos M 6.50€).
// Wizard popup PROTÉGÉ.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-2026-05-06/cycle4');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const ORDER_ID = parseInt(process.env.AUDIT_C4_ORDER_ID || '188', 10);

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function recordFinding(step, slug, state, severity, note, screenshotPath) {
  findings.push({ step, slug, state, severity, note, screenshot: screenshotPath, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const filename = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fullPath = path.join(SHOT_DIR, filename);
  await page.screenshot({ path: fullPath, fullPage: true }).catch((e) => {
    console.warn(`[c4] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT POS CYCLE 4 — Post-paiement complet', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('C4-01 — Print receipt API (1ère impression)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    // Récupérer le token d'auth depuis localStorage / cookies pour appeler l'API
    const apiResult = await page.evaluate(async (orderId) => {
      let token = '';
      try {
        const vuex = JSON.parse(window.localStorage.getItem('vuex') || '{}');
        token = vuex?.auth?.authToken || vuex?.kioskCart?.kioskToken || '';
      } catch (_) {}
      // x-api-key from window config (injected by master.blade.php)
      const apiKey = (window.API_KEY) || (document.querySelector('meta[name="api-key"]')?.content) || '';
      try {
        const resp = await fetch(`/api/admin/pos/orders/${orderId}/print-receipt`, {
          method: 'POST',
          headers: {
            'Authorization': token ? `Bearer ${token}` : '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'x-api-key': apiKey,
          },
        });
        const text = await resp.text();
        let body = null;
        try { body = JSON.parse(text); } catch (_) {}
        return { status: resp.status, body, raw: text.slice(0, 500) };
      } catch (e) {
        return { error: e.message };
      }
    }, ORDER_ID);

    fs.writeFileSync(path.join(SHOT_DIR, 'C4-01-print-receipt-1st.json'), JSON.stringify(apiResult, null, 2));

    const ok = apiResult.status === 200 && apiResult.body?.is_duplicata === false;
    recordFinding('C4-01', 'print-receipt', '1st', ok ? 'OK' : 'P1',
      `status=${apiResult.status}, is_duplicata=${apiResult.body?.is_duplicata}, count=${apiResult.body?.receipt_print_count}`,
      '');
  });

  test('C4-02 — Print receipt API (2e impression = DUPLICATA)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const apiResult = await page.evaluate(async (orderId) => {
      let token = '';
      try {
        const vuex = JSON.parse(window.localStorage.getItem('vuex') || '{}');
        token = vuex?.auth?.authToken || vuex?.kioskCart?.kioskToken || '';
      } catch (_) {}
      // x-api-key from window config (injected by master.blade.php)
      const apiKey = (window.API_KEY) || (document.querySelector('meta[name="api-key"]')?.content) || '';
      try {
        const resp = await fetch(`/api/admin/pos/orders/${orderId}/print-receipt`, {
          method: 'POST',
          headers: {
            'Authorization': token ? `Bearer ${token}` : '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'x-api-key': apiKey,
          },
        });
        const text = await resp.text();
        let body = null;
        try { body = JSON.parse(text); } catch (_) {}
        return { status: resp.status, body, raw: text.slice(0, 500) };
      } catch (e) {
        return { error: e.message };
      }
    }, ORDER_ID);

    fs.writeFileSync(path.join(SHOT_DIR, 'C4-02-print-receipt-2nd.json'), JSON.stringify(apiResult, null, 2));

    const ok = apiResult.status === 200 && apiResult.body?.is_duplicata === true && apiResult.body?.receipt_print_count >= 2;
    recordFinding('C4-02', 'print-receipt', '2nd-duplicata', ok ? 'OK' : 'P1',
      `status=${apiResult.status}, is_duplicata=${apiResult.body?.is_duplicata}, count=${apiResult.body?.receipt_print_count} (must be >= 2 + duplicata=true)`,
      '');
  });

  test('C4-03 — Tracker live + Pusher Echo subscribe detection', async ({ page }) => {
    const wsConnections = [];
    const pusherEvents = [];

    page.on('websocket', (ws) => {
      wsConnections.push({ url: ws.url(), opened_at: Date.now() });
      ws.on('framesent', (frameData) => {
        const payload = String(frameData.payload || '').slice(0, 500);
        if (/subscribe|channel/i.test(payload)) {
          pusherEvents.push({ type: 'send', payload, ts: Date.now() });
        }
      });
      ws.on('framereceived', (frameData) => {
        const payload = String(frameData.payload || '').slice(0, 500);
        if (/connection_established|subscription_succeeded/i.test(payload)) {
          pusherEvents.push({ type: 'recv', payload, ts: Date.now() });
        }
      });
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(3_500); // laisser Echo se connecter

    const trackerBtn = page.locator('[data-testid="pos-tracker-open"]').first();
    if (await trackerBtn.isVisible().catch(() => false)) {
      await trackerBtn.click();
      await page.waitForTimeout(2_000);
    }

    const shot = await snap(page, 3, 'tracker-pusher', 'live');
    fs.writeFileSync(path.join(SHOT_DIR, 'C4-03-pusher-events.json'), JSON.stringify({
      websockets: wsConnections,
      events_sample: pusherEvents.slice(0, 30),
    }, null, 2));

    const wsOk = wsConnections.length > 0;
    const subscribeOk = pusherEvents.some((e) => /subscribe/i.test(e.payload));

    recordFinding('C4-03', 'pusher-websocket', wsOk ? 'connected' : 'no-ws', wsOk ? 'OK' : 'P2',
      `WebSockets: ${wsConnections.length}, subscribe events: ${pusherEvents.filter((e) => /subscribe/i.test(e.payload)).length}`, shot);
    recordFinding('C4-03', 'pusher-subscribe', subscribeOk ? 'detected' : 'absent', subscribeOk ? 'OK' : 'INFO',
      `Pusher subscribe frames captured: ${subscribeOk}`, shot);
  });

  test('C4-04 — Open cash drawer (no-sale)', async ({ page }) => {
    let drawerXhr = null;
    page.on('response', (resp) => {
      const url = resp.url();
      if (/\/api\/pos\/cash-drawer\/open/.test(url)) {
        drawerXhr = { status: resp.status(), url, ts: Date.now() };
      }
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(2_000);

    const shotBefore = await snap(page, 4, 'no-sale', 'before-click');

    const noSaleBtn = page.locator('[data-testid="pos-no-sale"]').first();
    if (!(await noSaleBtn.isVisible({ timeout: 3_000 }).catch(() => false))) {
      recordFinding('C4-04', 'no-sale-button', 'invisible', 'P2', 'Bouton no-sale invisible', shotBefore);
      return;
    }

    await noSaleBtn.click();
    await page.waitForTimeout(2_000);

    const shotAfter = await snap(page, 4, 'no-sale', 'after-click');

    if (drawerXhr) {
      recordFinding('C4-04', 'drawer-api', 'called', drawerXhr.status >= 200 && drawerXhr.status < 400 ? 'OK' : 'P1',
        `POST /api/pos/cash-drawer/open status=${drawerXhr.status}`, shotAfter);
    } else {
      recordFinding('C4-04', 'drawer-api', 'not-called', 'P2',
        'Aucun appel /api/pos/cash-drawer/open — bouton no-sale peut-être ouvre un confirm modal d\'abord', shotAfter);
      // Tenter de chercher un modal confirm
      const confirmBtn = page.locator('button').filter({ hasText: /confirmer|ouvrir|ok|oui/i }).first();
      if (await confirmBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
        await confirmBtn.click();
        await page.waitForTimeout(1_500);
        const shotConfirm = await snap(page, 4, 'no-sale', 'after-confirm');
        if (drawerXhr) {
          recordFinding('C4-04', 'drawer-api-via-confirm', 'called', 'OK',
            `POST /api/pos/cash-drawer/open après confirm status=${drawerXhr.status}`, shotConfirm);
        }
      }
    }
  });

  test('C4-05 — POS orders list (historique) + show order détail', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_000);

    // Naviguer vers historique POS orders
    await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    const shotList = await snap(page, 5, 'pos-orders', 'list');

    // Vérifier non-erreur
    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal error|Server Error|500/i.test(visibleText);
    recordFinding('C4-05', 'pos-orders-list', hasError ? 'error' : 'loaded', hasError ? 'P1' : 'OK',
      `Historique POS orders: ${hasError ? 'ERREUR détectée' : 'page chargée OK'}`, shotList);

    // Tenter ouvrir détail order
    await page.goto(`/admin/pos-orders/show/${ORDER_ID}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    const shotShow = await snap(page, 5, 'pos-orders', `show-${ORDER_ID}`);

    const showText = await page.locator('body').innerText().catch(() => '');
    const showError = /Whoops|Fatal error|Server Error|500|404|Not Found/i.test(showText);
    recordFinding('C4-05', 'pos-orders-show', showError ? 'error' : 'loaded',
      showError ? 'P1' : 'OK',
      `Show order #${ORDER_ID}: ${showError ? 'ERREUR' : 'détail chargé'}`, shotShow);
  });

  test('C4-06 — Synthèse cycle 4 + INDEX', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_000);

    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT POS CYCLE 4 — Captures Index 2026-05-06', '', `Total findings cycle 4: ${findings.length}`, `Order ID utilisé: #${ORDER_ID}`, '', '| Step | Slug | State | Severity | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(0);
  });
});
