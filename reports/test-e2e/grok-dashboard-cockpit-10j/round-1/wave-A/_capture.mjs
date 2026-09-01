/**
 * Vague 1 cycle 1 Wave A — admin dashboard cockpit quartet.
 * Isolated Playwright CLI (no MCP, no project globalSetup).
 * No POS payment, no orders, no kiosk wizard.
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-1/wave-A');
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
      english_storage_leftover: isEnglishStorageLeftover(url),
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

  return { consoleBuf, networkBuf, allNetwork, snapshotBuffers, clearStateBuffers };
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
  await page.getByText(/Dernier rapport Z|Last Z/i).first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
  await page.getByText(/Alertes stock bas|stock_low/i).first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
  await page.waitForTimeout(1200);
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

    const quickNav = document.querySelector('nav[aria-label*="Accès" i], nav[aria-label*="quick" i]');
    const quickAccess = quickNav
      ? Array.from(quickNav.querySelectorAll('a')).map((a) => ({
        text: visibleText(a),
        href: a.getAttribute('href') || '',
      })).filter((x) => x.text)
      : [];

    const pdfBtn = Array.from(document.querySelectorAll('button')).find((b) => /PDF Clôture|EOD Closing PDF|clôture du jour/i.test(b.innerText || ''));

    const findCardByTitle = (rx) => {
      const h = Array.from(document.querySelectorAll('h3,h4,.db-card-title')).find((el) => rx.test(el.textContent || ''));
      if (!h) return null;
      return h.closest('.db-card, .bg-white, section, .col-12') || h.parentElement;
    };

    const auditCard = Array.from(document.querySelectorAll('h4')).find((h) => /Audit Trail NF525/i.test(h.textContent || ''));
    let auditRows = [];
    let connexionUtilisateurCount = 0;
    if (auditCard) {
      const root = auditCard.closest('.bg-white, .col-12') || auditCard.parentElement;
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
    const popularNames = popularCard
      ? Array.from(popularCard.querySelectorAll('h4')).map((h) => visibleText(h)).filter(Boolean)
      : [];

    const stockCard = findCardByTitle(/Alertes stock bas|stock_low/i);
    let stockBas = { present: !!stockCard, count_badge: null, empty: false, error: false, rows: [] };
    if (stockCard) {
      const badge = stockCard.querySelector('[data-testid="stock-low-alerts-count"]');
      stockBas.count_badge = badge ? visibleText(badge) : null;
      stockBas.empty = /Aucune alerte/i.test(stockCard.innerText || '');
      stockBas.error = !!stockCard.querySelector('[data-testid="stock-low-alerts-error"]');
      stockBas.rows = Array.from(stockCard.querySelectorAll('tbody tr')).map((tr) => visibleText(tr)).filter(Boolean);
    }

    const zCard = findCardByTitle(/Dernier rapport Z|Last Z/i);
    const lastZ = zCard ? visibleText(zCard).slice(0, 400) : null;

    const sla = document.querySelector('[data-testid="sla-cockpit"]');
    const slaText = sla ? visibleText(sla).slice(0, 500) : (body.match(/Supervision cuisine[\s\S]{0,240}/i) || [null])[0];

    const channelH = headings.find((h) => /Répartition par Canal/i.test(h.textContent || ''));
    const channelRoot = channelH ? (channelH.closest('.bg-white, .col-12') || channelH.parentElement) : null;
    const channels = channelRoot
      ? Array.from(channelRoot.querySelectorAll('.flex.justify-between')).map((row) => visibleText(row)).filter(Boolean)
      : [];

    const orderStatsH = headings.find((h) => /statistiques.*commandes|order statistics/i.test((h.textContent || '').toLowerCase()) || /Statistiques/i.test(h.textContent || ''));
    const orderStatsSnippet = grab('Total commandes') || grab('Total Orders');

    const kpi55 = String(articlesTile ? articlesTile.value : grab('Total articles menu') || '').replace(/\s/g, '');
    const loginShare = auditRows.length ? connexionUtilisateurCount / auditRows.length : 0;

    return {
      url: location.href,
      title: document.title,
      overview_heading: overviewH ? visibleText(overviewH) : null,
      live_heading: liveH ? visibleText(liveH) : null,
      overview_tiles: overviewTiles,
      live_tiles: liveTiles,
      live_labels: liveLabels,
      live_only_ticket_moyen: liveLabels.length === 1 && /ticket moyen/i.test(liveLabels[0] || ''),
      live_has_duplicate_ca: liveLabels.some((l) => /chiffre d[’']affaires/i.test(l)),
      live_has_duplicate_commandes: liveLabels.some((l) => /commandes du jour/i.test(l)),
      total_articles_menu: articlesTile ? articlesTile.value : grab('Total articles menu'),
      kpi_articles_is_55: kpi55 === '55',
      ventes_du_jour: grab('Ventes du jour'),
      commandes_du_jour: grab('Commandes du jour'),
      ticket_moyen: grab('Ticket Moyen'),
      quick_access: quickAccess,
      pdf_cloture_button: pdfBtn ? visibleText(pdfBtn) : null,
      pdf_cloture_present: !!pdfBtn,
      audit_trail_heading_present: !!auditCard,
      audit_trail_row_count: auditRows.length,
      audit_trail_sample: auditRows.slice(0, 8),
      connexion_utilisateur_count: connexionUtilisateurCount,
      wall_of_connexion_utilisateur: auditRows.length > 0 && loginShare >= 0.5,
      featured_names: featuredNames,
      featured_has_e2e_playwright: featuredNames.some((n) => /E2E_PLAYWRIGHT|E2E Cat|AUDIT-KIOSK/i.test(n)),
      popular_names: popularNames,
      stock_bas: stockBas,
      last_z: lastZ,
      sla_present: !!sla || /Supervision cuisine/i.test(body),
      sla_text: slaText,
      channels,
      order_stats_heading: orderStatsH ? visibleText(orderStatsH) : null,
      total_orders: orderStatsSnippet,
      sales_summary_present: /résumé des ventes|sales summary/i.test(body),
      order_summary_present: /résumé des commandes|order summary/i.test(body),
      magenta_in_overview: overviewTiles.some((t) => t.background_class === 'magenta'),
      cayenne_palette: overviewTiles.map((t) => t.background_class),
      i18n_leak: /Label\.|kiosk\.|0undefined/.test(body),
      body_has_ellipsis_kpi: /Ventes du jour[\s\S]{0,40}…/.test(body),
      greeting: (body.match(/Bon(jour|soir)\s*!\s*\n?([^\n]+)/i) || [null, null, null])[0],
    };
  });
}

function checklistA1A11(obs) {
  return {
    A1: {
      id: 'A1',
      name: 'KPI ventes/commandes/articles/ticket',
      ventes_du_jour: obs.ventes_du_jour,
      commandes_du_jour: obs.commandes_du_jour,
      total_articles_menu: obs.total_articles_menu,
      kpi_articles_is_55: obs.kpi_articles_is_55,
      ticket_moyen: obs.ticket_moyen,
      observed: !!(obs.ventes_du_jour && obs.commandes_du_jour && obs.total_articles_menu && obs.ticket_moyen),
    },
    A2: {
      id: 'A2',
      name: 'Accès rapides',
      labels: (obs.quick_access || []).map((x) => x.text),
      hrefs: (obs.quick_access || []).map((x) => x.href),
      catalogue_present: (obs.quick_access || []).some((x) => /catalogue/i.test(x.text)),
      observed: (obs.quick_access || []).length > 0,
    },
    A3: {
      id: 'A3',
      name: 'Suivi en direct',
      heading: obs.live_heading,
      tiles: obs.live_tiles,
      live_only_ticket_moyen: obs.live_only_ticket_moyen,
      observed: !!obs.live_heading,
    },
    A4: {
      id: 'A4',
      name: 'SLA cuisine',
      present: obs.sla_present,
      text: obs.sla_text,
      observed: !!obs.sla_present,
    },
    A5: {
      id: 'A5',
      name: 'Canaux (Web / Kiosk / POS)',
      channels: obs.channels,
      observed: (obs.channels || []).length > 0,
    },
    A6: {
      id: 'A6',
      name: 'Audit trail',
      heading_present: obs.audit_trail_heading_present,
      row_count: obs.audit_trail_row_count,
      connexion_utilisateur_count: obs.connexion_utilisateur_count,
      wall_of_connexion_utilisateur: obs.wall_of_connexion_utilisateur,
      sample: obs.audit_trail_sample,
      observed: !!obs.audit_trail_heading_present,
    },
    A7: {
      id: 'A7',
      name: 'Stats commandes / CA / résumé',
      order_stats_heading: obs.order_stats_heading,
      total_orders: obs.total_orders,
      sales_summary_present: obs.sales_summary_present,
      order_summary_present: obs.order_summary_present,
      observed: !!(obs.order_stats_heading || obs.sales_summary_present || obs.order_summary_present),
    },
    A8: {
      id: 'A8',
      name: 'Dernier Z',
      last_z: obs.last_z,
      observed: !!obs.last_z,
    },
    A9: {
      id: 'A9',
      name: 'Mis en avant / populaires',
      featured_names: obs.featured_names,
      popular_names: obs.popular_names,
      featured_has_e2e_playwright: obs.featured_has_e2e_playwright,
      observed: (obs.featured_names || []).length > 0 || (obs.popular_names || []).length > 0,
    },
    A10: {
      id: 'A10',
      name: 'Stock bas',
      stock_bas: obs.stock_bas,
      observed: !!(obs.stock_bas && obs.stock_bas.present),
    },
    A11: {
      id: 'A11',
      name: 'PDF clôture',
      button: obs.pdf_cloture_button,
      present: obs.pdf_cloture_present,
      observed: !!obs.pdf_cloture_present,
    },
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
  const rec = attachCollectors(page);
  const states = [];
  let blockers = [];

  try {
    const loginInfo = await login(page);
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }

    rec.clearStateBuffers();
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);

    const overview = page.getByText(/Vue d[’']ensemble/i).first();
    const live = page.getByText(/Suivi en direct/i).first();
    if (await overview.count()) await overview.scrollIntoViewIfNeeded();
    if (await live.count()) await live.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    const observed = await extractCockpit(page);
    const dashBuffers = rec.snapshotBuffers();
    const dashQuartet = await snapQuartet(page, '01-dashboard', dashBuffers);
    rec.clearStateBuffers();

    states.push({
      name: 'idle',
      url: dashQuartet.url,
      png: dashQuartet.png,
      dom: dashQuartet.dom,
      console: dashQuartet.console,
      network: dashQuartet.network,
      notes: 'Admin login then HARD RELOAD /admin/dashboard. Overflow expanded. No POS/kiosk/orders.',
    });

    const catalogueLink = page.locator('nav[aria-label*="Accès" i] a, nav[aria-label*="quick" i] a').filter({ hasText: /^Catalogue$/i }).first();
    const catalogueCount = await catalogueLink.count();
    let catalogueClicked = false;
    let catalogueUrl = null;
    if (catalogueCount) {
      await catalogueLink.scrollIntoViewIfNeeded();
      await catalogueLink.click();
      catalogueClicked = true;
      await page.waitForURL(/\/admin\/items\/studio/, { timeout: 25_000 }).catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
      await page.getByText(/Sandwichs|Toutes les cat[ée]gories|Catalogue/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
      await page.waitForTimeout(800);
      catalogueUrl = page.url();
      const catBuffers = rec.snapshotBuffers();
      const catQuartet = await snapQuartet(page, '02-from-dashboard-catalogue', catBuffers);
      states.push({
        name: 'from-dashboard-catalogue',
        url: catQuartet.url,
        png: catQuartet.png,
        dom: catQuartet.dom,
        console: catQuartet.console,
        network: catQuartet.network,
        notes: 'Clicked Accès rapides « Catalogue ». Did not click POS payment, no orders, no kiosk wizard.',
      });
    } else {
      blockers.push('Accès rapides « Catalogue » not present — skipped 02-from-dashboard-catalogue');
    }

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const ignored4xx = rec.allNetwork.filter((n) => isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const checklist = checklistA1A11(observed);

    const notes = {
      wave: 'A-dashboard-cockpit-vague-1-cycle-1',
      base_url: BASE,
      login: loginInfo,
      hard_reload: true,
      catalogue_clicked: catalogueClicked,
      catalogue_url: catalogueUrl,
      states,
      observed,
      checklist_A1_A11: checklist,
      http_4xx_5xx_excluding_ws_debugbar_english_storage: relevant4xx,
      http_4xx_ignored_debugbar_english_storage: ignored4xx.map((n) => ({ url: n.url, status: n.status })),
      final_url: page.url(),
      blockers,
    };
    fs.writeFileSync(path.join(OUT, 'wave-A-notes.json'), JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      png: dashQuartet.png,
      catalogue_png: states[1] ? states[1].png : null,
      url: dashQuartet.url,
      catalogue_url: catalogueUrl,
      checklist: Object.fromEntries(Object.entries(checklist).map(([k, v]) => [k, {
        name: v.name,
        observed: v.observed,
        extra: k === 'A1' ? { articles: v.total_articles_menu, ticket: v.ticket_moyen, kpi55: v.kpi_articles_is_55 }
          : k === 'A2' ? { labels: v.labels }
          : k === 'A6' ? { rows: v.row_count, wall: v.wall_of_connexion_utilisateur, logins: v.connexion_utilisateur_count }
          : k === 'A8' ? { last_z: v.last_z }
          : k === 'A9' ? { featured: v.featured_names, popular: v.popular_names }
          : k === 'A10' ? v.stock_bas
          : k === 'A11' ? { button: v.button }
          : undefined,
      }])),
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
