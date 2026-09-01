/**
 * WAVE A — Admin dashboard capture (Grok e2e-hunter, 2026-08-29).
 *
 * Isolated Playwright CLI only. Does not open /admin/pos payment, kiosk,
 * or create orders. Does not invent products.
 *
 * Quartet per state: full-page PNG + DOM html + console.json (error/warn)
 * + network.json (4xx/5xx + requestfailed).
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const OUT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/grok-dashboard-2026-08-29/round-1/wave-A',
);

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

fs.mkdirSync(OUT_DIR, { recursive: true });

function isWsOrDebugbar(url) {
  const u = String(url || '');
  return (
    /^wss?:/i.test(u)
    || /_debugbar|clockwork|telescope|horizon/i.test(u)
    || /:6001\b|:8080\/app\//i.test(u)
    || /\/app\/[A-Za-z0-9]/i.test(u)
    || /pusher|reverb|soketi/i.test(u)
  );
}

async function expandInnerOverflow(page) {
  await page.evaluate(() => {
    const nodes = Array.from(document.querySelectorAll('html, body, main, #app, .db-main, .db-container, .db-content, [class*="db-"]'));
    const extras = Array.from(document.querySelectorAll('body *')).filter((el) => {
      const s = window.getComputedStyle(el);
      const oy = s.overflowY;
      return (oy === 'auto' || oy === 'scroll' || s.overflow === 'auto' || s.overflow === 'scroll')
        && el.scrollHeight > el.clientHeight + 20;
    });
    const seen = new Set();
    for (const el of [...nodes, ...extras]) {
      if (!el || seen.has(el)) continue;
      seen.add(el);
      el.style.setProperty('overflow', 'visible', 'important');
      el.style.setProperty('overflow-y', 'visible', 'important');
      el.style.setProperty('height', 'auto', 'important');
      el.style.setProperty('max-height', 'none', 'important');
    }
    document.documentElement.style.setProperty('height', 'auto', 'important');
    document.body.style.setProperty('height', 'auto', 'important');
  });
}

function attachQuartet(page) {
  const consoleBuffer = [];
  const networkBuffer = [];
  const allNetwork = [];

  const onConsole = (msg) => {
    const type = msg.type();
    if (!['error', 'warning', 'assert'].includes(type)) return;
    const text = msg.text();
    if (/WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i.test(text)) return;
    if (/^Pusher\s*:/i.test(text)) return;
    consoleBuffer.push({
      level: type,
      text: text.substring(0, 4000),
      location: msg.location(),
      ts: Date.now(),
    });
  };
  const onPageError = (err) => {
    consoleBuffer.push({
      level: 'pageerror',
      text: String(err && err.message ? err.message : err).substring(0, 4000),
      stack: String(err && err.stack ? err.stack : '').substring(0, 6000),
      ts: Date.now(),
    });
  };
  const onResponse = (resp) => {
    const status = resp.status();
    if (status < 400) return;
    const url = resp.url();
    const row = {
      kind: 'http',
      url: url.substring(0, 500),
      method: resp.request().method(),
      status,
      resourceType: resp.request().resourceType(),
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: Date.now(),
    };
    networkBuffer.push(row);
    allNetwork.push(row);
  };
  const onRequestFailed = (req) => {
    const url = req.url();
    const failure = req.failure();
    const row = {
      kind: 'failed',
      url: url.substring(0, 500),
      method: req.method(),
      status: 0,
      resourceType: req.resourceType(),
      errorText: failure ? failure.errorText : 'requestfailed',
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: Date.now(),
    };
    networkBuffer.push(row);
    allNetwork.push(row);
  };

  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);
  page.on('requestfailed', onRequestFailed);

  async function snap(name) {
    const base = path.join(OUT_DIR, name);
    await expandInnerOverflow(page);
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${base}.png`, fullPage: true });
    const html = await page.content();
    fs.writeFileSync(`${base}.dom.html`, html.substring(0, 2_500_000));
    fs.writeFileSync(`${base}.console.json`, JSON.stringify(consoleBuffer, null, 2));
    fs.writeFileSync(`${base}.network.json`, JSON.stringify(networkBuffer, null, 2));
    const copiedNet = networkBuffer.slice();
    const copiedCon = consoleBuffer.slice();
    networkBuffer.length = 0;
    consoleBuffer.length = 0;
    return { network: copiedNet, console: copiedCon };
  }

  function dispose() {
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
    page.off('requestfailed', onRequestFailed);
  }

  return { snap, dispose, allNetwork };
}

async function visibleSidebarLabels(page) {
  return page.evaluate(() => {
    const aside = document.querySelector('aside.db-sidebar, aside, [class*="sidebar"]');
    if (!aside) return { source: 'none', labels: [] };
    const labels = [];
    const seen = new Set();
    const push = (raw) => {
      const t = String(raw || '').replace(/\s+/g, ' ').trim();
      if (!t || t.length > 80) return;
      const key = t.toLowerCase();
      if (seen.has(key)) return;
      seen.add(key);
      labels.push(t);
    };
    aside.querySelectorAll('a, button.db-sidebar-nav-title, .db-sidebar-nav-menu, span.text-base').forEach((el) => {
      if (!(el instanceof HTMLElement)) return;
      const style = window.getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden') return;
      push(el.innerText);
    });
    return { source: aside.className || 'aside', labels };
  });
}

test.describe.configure({ timeout: 180_000, retries: 0 });

test.describe('WAVE A — Admin dashboard capture', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  test('login → dashboard KPIs → Vue d’ensemble / Suivi en direct', async ({ page }) => {
    clearFoodKingRateLimits();
    const rec = attachQuartet(page);
    const notes = {
      wave: 'A-admin-dashboard',
      base_url: BASE,
      states: [],
      sidebar_labels: [],
      quick_access: [],
      kpi_text: {},
      http_4xx_5xx_excluding_ws_debugbar: [],
    };

    try {
      await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#formEmail')).toBeVisible({ timeout: 25_000 });
      await page.waitForTimeout(600);
      await rec.snap('01-login');
      notes.states.push({
        name: 'login',
        url: page.url(),
        png: path.join(OUT_DIR, '01-login.png'),
        notes: 'Opened /login; form visible (#formEmail / #formPassword). No submit yet.',
      });

      await page.locator('#formEmail').click();
      await page.locator('#formEmail').fill(EMAIL);
      await page.locator('#formPassword').click();
      await page.locator('#formPassword').fill(PASSWORD);
      const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
      const loginResponse = page.waitForResponse(
        (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
        { timeout: 25_000 },
      );
      await submit.click();
      const loginRes = await loginResponse;
      if (loginRes.status() !== 201) {
        const body = await loginRes.text().catch(() => '');
        throw new Error(`Login API HTTP ${loginRes.status()} ${body.slice(0, 300)}`);
      }
      await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
      await page.waitForTimeout(1200);
      if (!/\/admin(\/|$|\?)/.test(page.url())) {
        await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
      }
      if (!/\/admin\/dashboard/.test(page.url())) {
        await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
      }

      await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 25_000 });
      await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});

      const ventes = page.getByText(/Ventes du jour/i).first();
      const commandes = page.getByText(/Commandes du jour|Commandes/i).first();
      await ventes.waitFor({ state: 'visible', timeout: 30_000 });
      await commandes.waitFor({ state: 'visible', timeout: 30_000 });
      await page.waitForFunction(() => {
        const body = document.body ? document.body.innerText : '';
        if (!/Ventes du jour/i.test(body)) return false;
        if (!/Commandes/i.test(body)) return false;
        return !/Ventes du jour[\s\S]{0,40}…/.test(body);
      }, { timeout: 25_000 }).catch(() => {});
      await page.waitForTimeout(800);

      await rec.snap('02-dashboard');
      notes.states.push({
        name: 'dashboard',
        url: page.url(),
        png: path.join(OUT_DIR, '02-dashboard.png'),
        notes: 'Post-login /admin/dashboard; waited for Ventes du jour / Commandes. Overflow expanded for full-page PNG.',
      });

      const overview = page.getByText(/Vue d[’']ensemble/i).first();
      const live = page.getByText(/Suivi en direct/i).first();
      if (await overview.count()) {
        await overview.scrollIntoViewIfNeeded();
      }
      if (await live.count()) {
        await live.scrollIntoViewIfNeeded();
      }
      await page.waitForTimeout(700);
      await rec.snap('03-overview-live');
      notes.states.push({
        name: 'overview-live',
        url: page.url(),
        png: path.join(OUT_DIR, '03-overview-live.png'),
        notes: 'scrollIntoViewIfNeeded on Vue d’ensemble + Suivi en direct. No POS/kiosk click.',
      });

      const sidebar = await visibleSidebarLabels(page);
      notes.sidebar_labels = sidebar.labels;
      notes.quick_access = await page.evaluate(() => {
        const nav = document.querySelector('nav[aria-label*="Accès" i], nav[aria-label*="quick" i]');
        if (!nav) return [];
        return Array.from(nav.querySelectorAll('a'))
          .map((a) => ({
            text: (a.innerText || '').replace(/\s+/g, ' ').trim(),
            href: a.getAttribute('href') || '',
          }))
          .filter((x) => x.text);
      });
      notes.kpi_text = await page.evaluate(() => {
        const body = document.body ? document.body.innerText : '';
        const grab = (label) => {
          const re = new RegExp(`${label}[\\s\\n]+([^\\n]+)`, 'i');
          const m = body.match(re);
          return m ? m[1].trim().slice(0, 80) : null;
        };
        return {
          ventes_du_jour: grab('Ventes du jour'),
          commandes_du_jour: grab('Commandes du jour'),
          total_articles_menu: grab('Total articles menu'),
          vue_ensemble_present: /Vue d[’']ensemble/i.test(body),
          suivi_en_direct_present: /Suivi en direct/i.test(body),
          ca_jour: grab("Chiffre d'Affaires du Jour") || grab('Chiffre d’Affaires du Jour'),
          commandes_jour_live: grab('Commandes du Jour'),
          ticket_moyen: grab('Ticket Moyen'),
        };
      });
      notes.http_4xx_5xx_excluding_ws_debugbar = rec.allNetwork.filter(
        (n) => !n.ws_or_debugbar && (n.status >= 400 || n.kind === 'failed'),
      );
      notes.final_url = page.url();
      fs.writeFileSync(path.join(OUT_DIR, 'wave-A-notes.json'), JSON.stringify(notes, null, 2));
    } finally {
      rec.dispose();
    }
  });
});
