/**
 * Round-opt-2 Wave A — admin dashboard quartet (isolated Playwright CLI).
 * No MCP, no project globalSetup, no orders, no POS clicks.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);

const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-opt-2/wave-A');
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

function clearRateLimits() {
  const r = spawnSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      `
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    $ids = \\App\\Models\\User::whereIn('email', ['admin@lecayenne.fr'])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, [
      '127.0.0.1','::1','localhost',
      'admin@lecayenne.fr|127.0.0.1','admin@lecayenne.fr|::1',
    ]));
    foreach (['api','login-lockout'] as $name) {
      foreach ($keys as $key) { $limiter->clear(md5($name.$key)); }
    }
    echo 'ok';
  `,
    ],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
  );
  if (r.status !== 0) {
    console.warn('rate-limit clear failed', r.stderr || r.stdout);
  }
}

function attachCollectors(page) {
  const consoleBuf = [];
  const networkBuf = [];
  const allNetwork = [];

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
  page.on('response', (resp) => {
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

  return { consoleBuf, networkBuf, allNetwork };
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

    const headings = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5'));
    const overviewH = headings.find((h) => /Vue d[’'′]ensemble/i.test(h.textContent || ''));
    const liveH = headings.find((h) => /Suivi en direct/i.test(h.textContent || ''));

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

    const overviewTiles = collectTiles(overviewH);
    const liveTiles = collectTiles(liveH);
    const articlesTile = overviewTiles.find((t) => /articles? menu|menu items/i.test(t.label));
    const liveLabels = liveTiles.map((t) => t.label);
    const hasDuplicateCa = liveLabels.some((l) => /chiffre d[’']affaires/i.test(l));
    const hasDuplicateCommandes = liveLabels.some((l) => /commandes du jour/i.test(l));

    return {
      url: location.href,
      title: document.title,
      overview_heading: overviewH ? overviewH.innerText.trim() : null,
      live_heading: liveH ? liveH.innerText.trim() : null,
      overview_tiles: overviewTiles,
      live_tiles: liveTiles,
      live_labels: liveLabels,
      live_only_ticket_moyen: liveLabels.length === 1 && /ticket moyen/i.test(liveLabels[0] || ''),
      live_has_duplicate_ca: hasDuplicateCa,
      live_has_duplicate_commandes: hasDuplicateCommandes,
      total_articles_menu: articlesTile ? articlesTile.value : grab('Total articles menu'),
      ventes_du_jour: grab('Ventes du jour'),
      commandes_du_jour: grab('Commandes du jour'),
      ticket_moyen: grab('Ticket Moyen'),
      magenta_in_overview: overviewTiles.some((t) => t.background_class === 'magenta'),
      cayenne_palette: overviewTiles.map((t) => t.background_class),
      i18n_leak: /Label\.|kiosk\.|0undefined/.test(body),
      body_has_ellipsis_kpi: /Ventes du jour[\s\S]{0,40}…/.test(body),
    };
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
  clearRateLimits();

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
  const { consoleBuf, networkBuf, allNetwork } = attachCollectors(page);

  try {
    const loginInfo = await login(page);
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);
    await page.waitForTimeout(800);

    const overview = page.getByText(/Vue d[’']ensemble/i).first();
    const live = page.getByText(/Suivi en direct/i).first();
    if (await overview.count()) await overview.scrollIntoViewIfNeeded();
    if (await live.count()) await live.scrollIntoViewIfNeeded();
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

    const notes = {
      wave: 'A-admin-dashboard-round-opt-2',
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
          notes: 'Admin login then hard reload /admin/dashboard. Overflow expanded. No POS/kiosk/orders.',
        },
      ],
      observed,
      http_4xx_5xx_excluding_ws_debugbar: allNetwork.filter(
        (n) => !n.ws_or_debugbar && (n.status >= 400 || n.kind === 'failed'),
      ),
      final_url: page.url(),
    };
    fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      png,
      url: page.url(),
      overview_tiles: observed.overview_tiles,
      live_tiles: observed.live_tiles,
      total_articles_menu: observed.total_articles_menu,
      live_only_ticket_moyen: observed.live_only_ticket_moyen,
      magenta_in_overview: observed.magenta_in_overview,
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
