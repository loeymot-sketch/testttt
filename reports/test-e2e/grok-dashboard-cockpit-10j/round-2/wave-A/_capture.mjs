/**
 * Vague 1 cycle 2 Wave A recapture — /admin/dashboard HARD RELOAD fullPage.
 * Isolated Playwright CLI. No POS payment, no orders, no kiosk wizard.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);

const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = (process.env.FOODKING_E2E_BASE_URL || process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-2/wave-A');
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

function isEnglishStorageLeftover(url) {
  return /\/storage\/1\/english\.png/i.test(String(url || ''));
}

function isIgnored4xx(row) {
  return isWsOrDebugbar(row.url) || isEnglishStorageLeftover(row.url);
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
  const apiBodies = {
    total_menu_items: null,
    popular_items: null,
    featured_items: null,
    audit_trail: null,
  };

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
      english_storage_leftover: isEnglishStorageLeftover(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    if (status >= 400) networkBuf.push(row);
    if (status < 200 || status >= 400) return;
    if (!/\/admin\/dashboard\//.test(url)) return;
    try {
      const json = await resp.json();
      if (/\/total-menu-items(?:\?|$)/.test(url)) apiBodies.total_menu_items = json;
      if (/\/popular-items(?:\?|$)/.test(url)) apiBodies.popular_items = json;
      if (/\/featured-items(?:\?|$)/.test(url)) apiBodies.featured_items = json;
      if (/\/audit-trail(?:\?|$)/.test(url)) apiBodies.audit_trail = json;
    } catch {
      /* non-JSON */
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
      english_storage_leftover: isEnglishStorageLeftover(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    networkBuf.push(row);
  });

  function snapshotBuffers() {
    return {
      console: consoleBuf.slice(),
      network: networkBuf.slice(),
    };
  }

  function clearStateBuffers() {
    consoleBuf.length = 0;
    networkBuf.length = 0;
  }

  return { consoleBuf, networkBuf, allNetwork, apiBodies, snapshotBuffers, clearStateBuffers };
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

async function snapQuartet(page, name, buffers) {
  await expandInnerOverflow(page);
  await page.waitForTimeout(250);
  const png = path.join(OUT, `${name}.png`);
  const dom = path.join(OUT, `${name}.dom.html`);
  const consolePath = path.join(OUT, `${name}.console.json`);
  const networkPath = path.join(OUT, `${name}.network.json`);
  await page.screenshot({ path: png, fullPage: true });
  fs.writeFileSync(dom, (await page.content()).substring(0, 2_500_000), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(buffers.console, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(buffers.network, null, 2), 'utf8');
  return { png, dom, console: consolePath, network: networkPath, url: page.url() };
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
  await page.getByText(/Audit Trail NF525/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  await page.getByText(/Articles les plus populaires|most popular/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  await page.getByText(/Articles mis en avant|featured/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

async function extractCockpit(page) {
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
    const visibleText = (el) => (el && el.innerText ? el.innerText.replace(/\s+/g, ' ').trim() : '');
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
      return m ? m[1].trim().slice(0, 120) : null;
    };

    const overviewTiles = collectTiles(overviewH);
    const liveTiles = collectTiles(liveH);
    const articlesTile = overviewTiles.find((t) => /articles? menu|menu items/i.test(t.label));
    const liveLabels = liveTiles.map((t) => t.label);

    const findCardByTitle = (rx) => {
      const h = Array.from(document.querySelectorAll('h3,h4,.db-card-title')).find((el) => rx.test(el.textContent || ''));
      if (!h) return null;
      return h.closest('.db-card, .bg-white, section, .col-12') || h.parentElement;
    };

    const auditCard = Array.from(document.querySelectorAll('h4, .db-card-title')).find((h) => /Audit Trail NF525/i.test(h.textContent || ''));
    let auditRows = [];
    let connexionUtilisateurCount = 0;
    if (auditCard) {
      const root = auditCard.closest('.bg-white, .col-12, .db-card') || auditCard.parentElement;
      const table = root ? root.querySelector('table') : null;
      if (table) {
        auditRows = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
          const cells = Array.from(tr.querySelectorAll('td')).map((td) => visibleText(td));
          return {
            user: cells[0] || '',
            action: cells[1] || '',
            resource: cells[2] || '',
            hash: cells[3] || '',
            time: cells[4] || '',
          };
        }).filter((r) => r.user || r.action);
        connexionUtilisateurCount = auditRows.filter((r) => /connexion utilisateur/i.test(r.action)).length;
      }
    }

    const featuredCard = findCardByTitle(/Articles mis en avant|featured/i);
    const featuredNames = featuredCard
      ? Array.from(featuredCard.querySelectorAll('h4')).map((h) => visibleText(h)).filter(Boolean)
      : [];

    const popularCard = findCardByTitle(/Articles les plus populaires|most popular/i);
    const popularItems = popularCard
      ? Array.from(popularCard.querySelectorAll('li')).map((li) => ({
        name: visibleText(li.querySelector('h4')),
        category: visibleText(li.querySelector('h5')),
        price: visibleText(li.querySelector('h6')),
      })).filter((x) => x.name)
      : [];
    const popularNames = popularItems.map((x) => x.name);
    const popularCategories = popularItems.map((x) => x.category).filter(Boolean);

    const kpi55 = String(articlesTile ? articlesTile.value : grab('Total articles menu') || '').replace(/\s/g, '');
    const loginShare = auditRows.length ? connexionUtilisateurCount / auditRows.length : 0;
    const e2eRx = /E2E_PLAYWRIGHT|E2E Cat|AUDIT-KIOSK|E2E Playwright/i;
    const techniqueRx = /technique interne/i;

    return {
      url: location.href,
      title: document.title,
      overview_heading: overviewH ? visibleText(overviewH) : null,
      live_heading: liveH ? visibleText(liveH) : null,
      overview_tiles: overviewTiles,
      live_tiles: liveTiles,
      live_labels: liveLabels,
      total_articles_menu: articlesTile ? articlesTile.value : grab('Total articles menu'),
      kpi_articles_is_55: kpi55 === '55',
      ventes_du_jour: grab('Ventes du jour'),
      commandes_du_jour: grab('Commandes du jour'),
      ticket_moyen: grab('Ticket Moyen'),
      audit_trail_heading_present: !!auditCard,
      audit_trail_row_count: auditRows.length,
      audit_trail_sample: auditRows.slice(0, 12),
      audit_trail_actions: auditRows.map((r) => r.action),
      connexion_utilisateur_count: connexionUtilisateurCount,
      wall_of_connexion_utilisateur: auditRows.length > 0 && loginShare >= 0.5,
      featured_names: featuredNames,
      featured_has_e2e_playwright: featuredNames.some((n) => e2eRx.test(n)),
      popular_items: popularItems,
      popular_names: popularNames,
      popular_categories: popularCategories,
      popular_has_technique_interne: popularItems.some((x) => techniqueRx.test(x.name) || techniqueRx.test(x.category)),
      body_has_technique_interne: techniqueRx.test(body),
      body_technique_interne_count: (body.match(/technique interne/gi) || []).length,
      i18n_leak: /Label\.|kiosk\.|0undefined/.test(body),
      body_has_ellipsis_kpi: /Ventes du jour[\s\S]{0,40}…/.test(body),
    };
  });
}

function summarizeApi(apiBodies) {
  const popular = Array.isArray(apiBodies.popular_items?.data) ? apiBodies.popular_items.data : [];
  const featured = Array.isArray(apiBodies.featured_items?.data) ? apiBodies.featured_items.data : [];
  const audit = Array.isArray(apiBodies.audit_trail?.data) ? apiBodies.audit_trail.data : [];
  const menu = apiBodies.total_menu_items?.data?.total_menu_items ?? apiBodies.total_menu_items?.data ?? null;
  const techniqueRx = /technique interne/i;
  const e2eRx = /E2E_PLAYWRIGHT|E2E Cat|AUDIT-KIOSK|E2E Playwright/i;
  const connexionRx = /connexion utilisateur|user\.login|login/i;
  return {
    total_menu_items: menu,
    popular_count: popular.length,
    popular_items: popular.map((it) => ({
      name: it.name || it.item_name || null,
      category: it.category_name || it.category?.name || null,
    })),
    popular_has_technique_interne: popular.some((it) => techniqueRx.test(it.name || '') || techniqueRx.test(it.category_name || it.category?.name || '')),
    featured_count: featured.length,
    featured_names: featured.map((it) => it.name || null).filter(Boolean),
    featured_has_e2e_playwright: featured.some((it) => e2eRx.test(it.name || '')),
    audit_trail_row_count: audit.length,
    connexion_utilisateur_count: audit.filter((row) => {
      const action = String(row.action || row.event || row.description || '');
      return /connexion utilisateur/i.test(action);
    }).length,
    audit_actions_sample: audit.slice(0, 12).map((row) => row.action || row.event || null),
    connexion_like_action_count: audit.filter((row) => connexionRx.test(String(row.action || row.event || ''))).length,
  };
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
  await page.waitForTimeout(800);
  return { status, url: page.url() };
}

async function main() {
  mkdirp(OUT);
  clearRateLimits();

  const exeCandidates = [
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
    '/Users/1millnonstop/Library/Caches/ms-playwright/chromium-1237/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
    chromium.executablePath(),
  ].filter(Boolean);
  const executablePath = exeCandidates.find((exe) => fs.existsSync(exe));
  if (!executablePath) {
    throw new Error('No Chromium executable found (expected ms-playwright chromium-1237)');
  }
  const browser = await chromium.launch({
    headless: true,
    executablePath,
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
  const rec = attachCollectors(page);
  const states = [];
  const blockers = [];

  try {
    const loginInfo = await login(page);
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }

    rec.clearStateBuffers();
    rec.apiBodies.total_menu_items = null;
    rec.apiBodies.popular_items = null;
    rec.apiBodies.featured_items = null;
    rec.apiBodies.audit_trail = null;

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);

    const overview = page.getByText(/Vue d[’']ensemble/i).first();
    if (await overview.count()) await overview.scrollIntoViewIfNeeded();
    const popularHeading = page.getByText(/Articles les plus populaires/i).first();
    if (await popularHeading.count()) await popularHeading.scrollIntoViewIfNeeded();
    const featuredHeading = page.getByText(/Articles mis en avant/i).first();
    if (await featuredHeading.count()) await featuredHeading.scrollIntoViewIfNeeded();
    const auditHeading = page.getByText(/Audit Trail NF525/i).first();
    if (await auditHeading.count()) await auditHeading.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    const observed = await extractCockpit(page);
    const apiSummary = summarizeApi(rec.apiBodies);
    const dashBuffers = rec.snapshotBuffers();
    const dashQuartet = await snapQuartet(page, '01-dashboard', dashBuffers);

    states.push({
      name: 'idle',
      url: dashQuartet.url,
      png: dashQuartet.png,
      dom: dashQuartet.dom,
      console: dashQuartet.console,
      network: dashQuartet.network,
      notes: 'Admin login then HARD RELOAD /admin/dashboard (cache disabled). Overflow expanded. No POS/kiosk/orders. Did not click Accès rapides.',
    });

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const ignored4xx = rec.allNetwork.filter((n) => isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));

    const counts = {
      audit_trail_row_count_dom: observed.audit_trail_row_count,
      audit_trail_row_count_api: apiSummary.audit_trail_row_count,
      connexion_utilisateur_count_dom: observed.connexion_utilisateur_count,
      connexion_utilisateur_count_api: apiSummary.connexion_utilisateur_count,
      kpi_total_articles_menu_dom: observed.total_articles_menu,
      kpi_articles_is_55: observed.kpi_articles_is_55,
      kpi_total_menu_items_api: apiSummary.total_menu_items,
      popular_has_technique_interne_dom: observed.popular_has_technique_interne,
      popular_has_technique_interne_api: apiSummary.popular_has_technique_interne,
      body_has_technique_interne: observed.body_has_technique_interne,
      body_technique_interne_count: observed.body_technique_interne_count,
      popular_items: observed.popular_items,
      featured_names: observed.featured_names,
      featured_has_e2e_playwright_dom: observed.featured_has_e2e_playwright,
      featured_has_e2e_playwright_api: apiSummary.featured_has_e2e_playwright,
    };

    const notes = {
      wave: 'A-dashboard-cockpit-vague-1-cycle-2',
      base_url: BASE,
      login: loginInfo,
      hard_reload: true,
      states,
      observed,
      api_summary: apiSummary,
      counts,
      http_4xx_5xx_excluding_ws_debugbar_english_storage: relevant4xx,
      http_4xx_ignored_debugbar_english_storage: ignored4xx.map((n) => ({ url: n.url, status: n.status })),
      final_url: page.url(),
      blockers,
    };
    fs.writeFileSync(path.join(OUT, 'wave-A-notes.json'), JSON.stringify(notes, null, 2), 'utf8');
    fs.writeFileSync(path.join(OUT, '01-dashboard.extract.json'), JSON.stringify({ observed, api_summary: apiSummary, counts }, null, 2), 'utf8');
    console.log(JSON.stringify({
      png: dashQuartet.png,
      url: dashQuartet.url,
      counts,
      relevant4xx_count: relevant4xx.length,
      blockers,
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
