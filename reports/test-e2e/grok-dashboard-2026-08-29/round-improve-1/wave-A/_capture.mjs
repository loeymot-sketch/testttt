/**
 * Round-improve-1 Wave A — admin dashboard quartet (isolated Playwright CLI).
 * Login admin → HARD RELOAD /admin/dashboard. No MCP, no globalSetup, no POS, no orders.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);

const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');
const { clearFoodKingRateLimits } = require(path.join(REPO, 'tests/e2e/helpers/rate-limit.js'));

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-improve-1/wave-A');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

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

function attachCollectors(page) {
  const consoleBuf = [];
  const networkBuf = [];
  const allNetwork = [];
  const featuredApi = [];

  page.on('console', (msg) => {
    const type = msg.type();
    if (!['error', 'warning', 'assert'].includes(type)) return;
    const text = msg.text();
    if (/WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i.test(text)) return;
    if (/^Pusher\s*:/i.test(text)) return;
    consoleBuf.push({
      level: type,
      text: String(text).substring(0, 4000),
      location: msg.location(),
      ts: Date.now(),
    });
  });
  page.on('pageerror', (err) => {
    consoleBuf.push({
      level: 'pageerror',
      text: String(err && err.message ? err.message : err).substring(0, 4000),
      stack: String(err && err.stack ? err.stack : '').substring(0, 6000),
      ts: Date.now(),
    });
  });
  page.on('response', async (resp) => {
    const status = resp.status();
    const url = resp.url();
    const row = {
      kind: 'http',
      url: String(url).substring(0, 500),
      method: resp.request().method(),
      status,
      resourceType: resp.request().resourceType(),
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    if (status >= 400) networkBuf.push(row);
    if (/\/admin\/dashboard\/featured-items/i.test(url) && resp.request().method() === 'GET') {
      try {
        const json = await resp.json();
        const names = Array.isArray(json?.data)
          ? json.data.map((it) => it?.name).filter(Boolean)
          : [];
        featuredApi.push({ status, url: String(url).substring(0, 500), names, count: names.length });
      } catch (_) {
        featuredApi.push({ status, url: String(url).substring(0, 500), names: [], parse_error: true });
      }
    }
  });
  page.on('requestfailed', (req) => {
    const url = req.url();
    const failure = req.failure();
    const row = {
      kind: 'failed',
      url: String(url).substring(0, 500),
      method: req.method(),
      status: 0,
      resourceType: req.resourceType(),
      errorText: failure ? failure.errorText : 'requestfailed',
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    networkBuf.push(row);
  });

  return { consoleBuf, networkBuf, allNetwork, featuredApi };
}

async function disableCache(page) {
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  return client;
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

async function waitDashboardReady(page) {
  await page.getByText(/Ventes du jour/i).first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.getByText(/Commandes du jour|Commandes/i).first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForFunction(() => {
    const body = document.body ? document.body.innerText : '';
    if (!/Ventes du jour/i.test(body)) return false;
    if (!/Commandes/i.test(body)) return false;
    if (/Ventes du jour[\s\S]{0,40}…/.test(body)) return false;
    if (/Total articles menu[\s\S]{0,40}…/.test(body)) return false;
    return true;
  }, { timeout: 25_000 }).catch(() => {});
}

async function extractDashboard(page) {
  return page.evaluate(() => {
    const rgbToHex = (rgb) => {
      const m = String(rgb || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
      if (!m) return String(rgb || '');
      const hex = [m[1], m[2], m[3]]
        .map((n) => Number(n).toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase();
      return `#${hex}`;
    };
    const classify = (hex) => {
      const h = String(hex || '').toUpperCase();
      if (h === '#F4501E' || h === '#FF5722' || h === '#F4511E' || h === '#E64A19') return 'orange';
      if (h === '#1A1A1A' || h === '#000000' || h === '#111111' || h === '#212121') return 'black';
      if (h === '#FFB800' || h === '#FFC107' || h === '#FFCA28' || h === '#F9A825') return 'yellow';
      if (h === '#C81E63' || h === '#E91E63' || h === '#D81B60' || h === '#AD1457' || h === '#C2185B' || h === '#FF00FF') {
        return 'magenta';
      }
      return `other:${h}`;
    };

    const headings = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,.db-card-title'));
    const overviewH = headings.find((h) => /Vue d[’'′]ensemble/i.test(h.textContent || ''));
    const liveH = headings.find((h) => /Suivi en direct/i.test(h.textContent || ''));
    const featuredH = headings.find((h) => /Articles mis en avant|featured items/i.test(h.textContent || ''));

    const collectTiles = (heading) => {
      if (!heading) return [];
      const root = heading.parentElement || heading;
      const candidates = Array.from(root.querySelectorAll('div')).filter((el) => {
        const cls = el.className || '';
        return /rounded-(lg|2xl)/.test(cls) && /p-[46]/.test(cls);
      });
      const tiles = [];
      const seen = new Set();
      for (const el of candidates) {
        if (seen.has(el)) continue;
        if (candidates.some((other) => other !== el && other.contains(el))) continue;
        seen.add(el);
        const cs = window.getComputedStyle(el);
        const bg = rgbToHex(cs.backgroundColor);
        const label = ((el.querySelector('h3') && el.querySelector('h3').innerText) || '').replace(/\s+/g, ' ').trim();
        const valueEl = el.querySelector('h4') || el.querySelector('h2');
        const value = (valueEl ? valueEl.innerText : '').replace(/\s+/g, ' ').trim();
        if (!label && !value) continue;
        tiles.push({
          label,
          value,
          background: bg,
          background_class: classify(bg),
          className: String(el.className || '').slice(0, 240),
        });
      }
      return tiles;
    };

    const body = document.body ? document.body.innerText : '';
    const grab = (label) => {
      const re = new RegExp(`${label}[\\s\\n]+([^\\n]+)`, 'i');
      const m = body.match(re);
      return m ? m[1].trim().slice(0, 80) : null;
    };

    const featuredNames = [];
    if (featuredH) {
      const card = featuredH.closest('.db-card') || featuredH.parentElement?.parentElement || featuredH.parentElement;
      if (card) {
        card.querySelectorAll('h4').forEach((h4) => {
          const n = (h4.textContent || '').replace(/\s+/g, ' ').trim();
          if (n) featuredNames.push(n);
        });
      }
    }

    const sidebarEls = [];
    const aside = document.querySelector('aside.db-sidebar, aside, [class*="sidebar"]');
    const pushSidebar = (el) => {
      if (!(el instanceof HTMLElement)) return;
      const textContent = (el.textContent || '').replace(/\s+/g, ' ').trim();
      const innerText = (el.innerText || '').replace(/\s+/g, ' ').trim();
      if (!/tableau\s+de\s+bord/i.test(textContent) && !/tableau\s+de\s+bord/i.test(innerText)) return;
      const cs = window.getComputedStyle(el);
      sidebarEls.push({
        tag: el.tagName,
        className: String(el.className || '').slice(0, 200),
        textContent,
        innerText,
        textTransform: cs.textTransform,
      });
    };
    if (aside) {
      aside.querySelectorAll('a, button, span, .db-sidebar-nav-title, .db-sidebar-nav-menu').forEach(pushSidebar);
    }
    document.querySelectorAll('a, button, span').forEach((el) => {
      const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (/^tableau\s+de\s+bord$/i.test(t)) pushSidebar(el);
    });

    const dashboardExact = [];
    const seenExact = new Set();
    for (const row of sidebarEls) {
      const candidates = [row.innerText, row.textContent];
      for (const c of candidates) {
        const m = String(c || '').match(/tableau\s+de\s+bord/i);
        if (!m) continue;
        const exact = m[0];
        if (seenExact.has(exact)) continue;
        seenExact.add(exact);
        dashboardExact.push({
          exact,
          is_Tableau_de_Bord: exact === 'Tableau de Bord',
          is_Tableau_De_Bord: exact === 'Tableau De Bord',
          is_Tableau_de_bord: exact === 'Tableau de bord',
          textTransform: row.textTransform,
          source: row.tag + '.' + row.className.slice(0, 80),
        });
      }
    }

    const overviewTiles = collectTiles(overviewH);
    const liveTiles = collectTiles(liveH);
    const articlesTile = overviewTiles.find((t) => /articles? menu|menu items/i.test(t.label));
    const liveLabels = liveTiles.map((t) => t.label);

    return {
      url: location.href,
      title: document.title,
      overview_heading: overviewH ? overviewH.innerText.trim() : null,
      live_heading: liveH ? liveH.innerText.trim() : null,
      featured_heading: featuredH ? featuredH.innerText.trim() : null,
      overview_tiles: overviewTiles,
      live_tiles: liveTiles,
      live_labels: liveLabels,
      total_articles_menu: articlesTile ? articlesTile.value : grab('Total articles menu'),
      ventes_du_jour: grab('Ventes du jour'),
      commandes_du_jour: grab('Commandes du jour'),
      ticket_moyen: grab('Ticket Moyen'),
      featured_names_dom: featuredNames,
      featured_contains_e2e_playwright: featuredNames.some((n) => /E2E_PLAYWRIGHT/i.test(n)),
      sidebar_dashboard_labels: dashboardExact,
      sidebar_dashboard_raw: sidebarEls.slice(0, 8),
      i18n_leak: /Label\.|kiosk\.|0undefined/.test(body),
      body_has_ellipsis_kpi: /Ventes du jour[\s\S]{0,40}…/.test(body),
    };
  });
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASSWORD);
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
  const loginResponse = page.waitForResponse(
    (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
    { timeout: 25_000 },
  );
  await submit.click();
  const resp = await loginResponse;
  const status = resp.status();
  if (status !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Login API failed: HTTP ${status} ${body.slice(0, 400)}`);
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1200);
  return { status, url: page.url() };
}

async function main() {
  mkdirp(OUT);
  clearFoodKingRateLimits();

  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    baseURL: BASE,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  page.on('dialog', async (dialog) => { await dialog.dismiss(); });
  await disableCache(page);
  const { consoleBuf, networkBuf, allNetwork, featuredApi } = attachCollectors(page);

  try {
    const loginInfo = await login(page);
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    await waitDashboardReady(page);

    // HARD RELOAD (cache already disabled via CDP)
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);
    await page.getByText(/Articles mis en avant/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(800);

    const overview = page.getByText(/Vue d[’']ensemble/i).first();
    const live = page.getByText(/Suivi en direct/i).first();
    const featured = page.getByText(/Articles mis en avant/i).first();
    if (await overview.count()) await overview.scrollIntoViewIfNeeded();
    if (await live.count()) await live.scrollIntoViewIfNeeded();
    if (await featured.count()) await featured.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    await expandInnerOverflow(page);
    await page.waitForTimeout(250);

    const observed = await extractDashboard(page);
    const png = path.join(OUT, '01-dashboard.png');
    const dom = path.join(OUT, '01-dashboard.dom.html');
    const consolePath = path.join(OUT, '01-dashboard.console.json');
    const networkPath = path.join(OUT, '01-dashboard.network.json');
    const notesPath = path.join(OUT, 'wave-A-notes.json');

    await page.screenshot({ path: png, fullPage: true });
    fs.writeFileSync(dom, (await page.content()).substring(0, 2_500_000), 'utf8');
    fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
    fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');

    const lastFeaturedApi = featuredApi[featuredApi.length - 1] || null;
    const featuredNames = (observed.featured_names_dom && observed.featured_names_dom.length)
      ? observed.featured_names_dom
      : (lastFeaturedApi ? lastFeaturedApi.names : []);

    const notes = {
      wave: 'A-admin-dashboard-round-improve-1',
      base_url: BASE,
      login: loginInfo,
      states: [
        {
          name: 'idle',
          url: page.url(),
          png,
          dom,
          console: consolePath,
          network: networkPath,
          notes: 'Admin login then HARD RELOAD /admin/dashboard (CDP cache disabled). Overflow expanded. No POS/kiosk/orders.',
        },
      ],
      observed,
      featured_names: featuredNames,
      featured_api: featuredApi,
      featured_contains_e2e_playwright: featuredNames.some((n) => /E2E_PLAYWRIGHT/i.test(String(n))),
      total_articles_menu: observed.total_articles_menu,
      sidebar_dashboard_labels: observed.sidebar_dashboard_labels,
      http_4xx_5xx_excluding_ws_debugbar: allNetwork.filter(
        (n) => !n.ws_or_debugbar && (n.status >= 400 || n.kind === 'failed'),
      ),
      final_url: page.url(),
    };
    fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      png,
      url: page.url(),
      total_articles_menu: observed.total_articles_menu,
      featured_names: featuredNames,
      featured_contains_e2e_playwright: notes.featured_contains_e2e_playwright,
      sidebar_dashboard_labels: observed.sidebar_dashboard_labels,
      ventes_du_jour: observed.ventes_du_jour,
      commandes_du_jour: observed.commandes_du_jour,
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
