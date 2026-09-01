/**
 * Round-opt-3 Wave E — cashier cockpit leak confirmation (isolated Playwright CLI).
 * No MCP, no project globalSetup, no orders, no kiosk, no POS payment/wizard clicks.
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-opt-3/wave-E');
const EMAIL = 'pos@lecayenne.fr';
const PASS = '123456';

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
    $ids = \\App\\Models\\User::whereIn('email', ['pos@lecayenne.fr','admin@lecayenne.fr'])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, [
      '127.0.0.1','::1','localhost',
      'pos@lecayenne.fr|127.0.0.1','pos@lecayenne.fr|::1',
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

function flattenCopy(srcDir, destDir, name) {
  mkdirp(destDir);
  for (const ext of ['.png', '.dom.html', '.console.json', '.network.json', '.notes.json']) {
    const from = path.join(srcDir, `${name}${ext}`);
    const to = path.join(destDir, `${name}${ext}`);
    if (fs.existsSync(from)) fs.copyFileSync(from, to);
  }
}

function hashFromUrl(url) {
  if (!url) return null;
  const id = url.match(/[?&]id=([a-f0-9]+)/i);
  if (id) return id[1];
  const dotted = url.match(/\/(?:app|admin-shell|vendor|manifest|pos-app)[./]([a-f0-9]{8,})\.js/i);
  return dotted ? dotted[1] : null;
}

function attachCollectors(page) {
  const consoleBuf = [];
  const networkBuf = [];
  page.on('console', (msg) => {
    const type = msg.type();
    if (!['error', 'warning', 'assert'].includes(type)) return;
    const text = msg.text();
    if (/WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i.test(text)) return;
    if (/^Pusher\s*:/i.test(text)) return;
    consoleBuf.push({ type, text, loc: msg.location(), ts: Date.now() });
  });
  page.on('pageerror', (err) => {
    consoleBuf.push({ type: 'pageerror', text: String(err), stack: err?.stack || null, ts: Date.now() });
  });
  page.on('requestfailed', (req) => {
    const url = req.url();
    if (isWsOrDebugbar(url)) return;
    networkBuf.push({
      kind: 'failed',
      url,
      method: req.method(),
      failure: req.failure()?.errorText || 'failed',
      ts: Date.now(),
    });
  });
  page.on('response', (res) => {
    const status = res.status();
    if (status < 400) return;
    const url = res.url();
    if (isWsOrDebugbar(url)) return;
    networkBuf.push({
      kind: 'http',
      url,
      method: res.request().method(),
      status,
      resourceType: res.request().resourceType(),
      ts: Date.now(),
    });
  });
  return { consoleBuf, networkBuf };
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

async function mixAssets(page) {
  return page.evaluate(() => {
    const scripts = Array.from(document.querySelectorAll('script[src]')).map((s) => s.src);
    const mixScripts = scripts.filter((s) =>
      /\/js\/(app|admin-shell|vendor|manifest|pos-app|pos-shell)(\.|\/|\?|$)/.test(s),
    );
    const perf = performance
      .getEntriesByType('resource')
      .filter((e) => /\/js\/(app|admin-shell|vendor|manifest|pos-app|pos-shell)/.test(e.name))
      .map((e) => ({
        name: e.name,
        transferSize: e.transferSize,
        encodedBodySize: e.encodedBodySize,
        initiatorType: e.initiatorType,
      }));
    const appFromScript = scripts.find((s) => /\/js\/app\.js/.test(s)) || null;
    const appFromPerf = (perf.find((e) => /\/js\/app\.js/.test(e.name)) || {}).name || null;
    const adminShellFromScript = scripts.find((s) => /admin-shell/.test(s)) || null;
    const adminShellFromPerf = (perf.find((e) => /admin-shell/.test(e.name)) || {}).name || null;
    return {
      mixScripts,
      allScriptCount: scripts.length,
      perf,
      appFromScript,
      appFromPerf,
      adminShellFromScript,
      adminShellFromPerf,
    };
  });
}

async function extractSidebar(page) {
  const aside = page.locator('aside.db-sidebar, aside.main-sidebar').first();
  const present = (await page.locator('aside.db-sidebar, aside.main-sidebar').count()) > 0;
  const visible = present ? await aside.isVisible().catch(() => false) : false;
  const labels = [];
  if (present) {
    const titles = await aside.locator('.db-sidebar-nav-title, .nav-header, .nav-item p, .nav-link p').allInnerTexts().catch(() => []);
    const menus = await aside.locator('.db-sidebar-nav-menu, .nav-sidebar .nav-link').allInnerTexts().catch(() => []);
    for (const t of titles) {
      const clean = t.replace(/\s+/g, ' ').trim();
      if (clean) labels.push({ kind: 'group', text: clean });
    }
    for (const t of menus) {
      const clean = t.replace(/\s+/g, ' ').trim();
      if (clean) labels.push({ kind: 'item', text: clean });
    }
  }
  const unique = [...new Set(labels.map((l) => l.text))];
  const hasEtat = unique.some((t) => /État du système|Etat du système|État Du Système|system health/i.test(t));
  const rawText = present ? (await aside.innerText().catch(() => '')).replace(/\s+/g, ' ').trim() : '';
  const hasEtatInRaw = /État du système|Etat du système|system health/i.test(rawText);
  const hrefs = present
    ? await aside
        .locator('a, [href]')
        .evaluateAll((els) =>
          els.map((el) => ({
            tag: el.tagName,
            href: el.getAttribute('href'),
            text: (el.textContent || '').replace(/\s+/g, ' ').trim(),
          })),
        )
        .catch(() => [])
    : [];
  return {
    present,
    visible,
    exactLabels: unique,
    labeled: labels,
    hasEtatDuSysteme: hasEtat || hasEtatInRaw,
    hasEtatInRaw,
    hrefs,
    rawText,
  };
}

async function pageSignals(page) {
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const mix = await mixAssets(page);
  const sidebar = await extractSidebar(page);
  const vueMounted = (await page.locator('[data-testid="system-health"]').count()) > 0;
  const vueVisible = vueMounted
    ? await page.locator('[data-testid="system-health"]').first().isVisible().catch(() => false)
    : false;
  const appJsUrl = mix.appFromScript || mix.appFromPerf || null;
  const adminShellUrl = mix.adminShellFromScript || mix.adminShellFromPerf || null;
  return {
    url: page.url(),
    title: await page.title().catch(() => ''),
    viewport: page.viewportSize(),
    sidebar,
    mix,
    appJsUrl,
    appJsHash: hashFromUrl(appJsUrl),
    adminShellUrl,
    adminShellHash: hashFromUrl(adminShellUrl),
    vueSystemHealthExists: vueMounted,
    vueSystemHealthVisible: vueVisible,
    healthCockpitPresent: (await page.locator('#health-cockpit').count()) > 0,
    h2EtatDuSysteme: (await page.locator('h2', { hasText: 'État du système' }).count()) > 0,
    redirectedToDashboard: /\/admin\/dashboard(\/|$|\?)/.test(page.url()),
    stillOnObservability: /\/admin\/observability\/system/.test(page.url()),
    stillOnPos: /\/admin\/pos(\/|$|\?)/.test(page.url()),
    systemHealthErreurText: await page.locator('[data-testid="system-health-erreur"]').innerText().catch(() => null),
    toastOrAlert: await page.locator('.Toastify, .toast, [role="alert"], .swal2-container').allInnerTexts().catch(() => []),
    bodyHasVentesDuJour: /Ventes du jour/i.test(bodyText),
    bodySnippet: bodyText.slice(0, 4000),
  };
}

async function saveQuartet(page, consoleBuf, networkBuf, name, extra) {
  const dir = path.join(OUT, name);
  mkdirp(dir);
  const png = path.join(dir, `${name}.png`);
  const domPath = path.join(dir, `${name}.dom.html`);
  const consolePath = path.join(dir, `${name}.console.json`);
  const networkPath = path.join(dir, `${name}.network.json`);
  const notesPath = path.join(dir, `${name}.notes.json`);

  await expandInnerOverflow(page);
  await page.waitForTimeout(300);
  await page.screenshot({ path: png, fullPage: true });
  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');
  const signals = await pageSignals(page);
  const notes = { name, ...signals, ...extra };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  flattenCopy(dir, OUT, name);
  return { png, dom: domPath, console: consolePath, network: networkPath, notes, signals };
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASS);
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

(async () => {
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
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
    ignoreHTTPSErrors: true,
  });
  const page = await context.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  page.on('dialog', async (dialog) => {
    await dialog.dismiss();
  });
  await disableCache(page);
  const { consoleBuf, networkBuf } = attachCollectors(page);

  const blockers = [];
  try {
    const loginInfo = await login(page);
    const landingUrl = page.url();

    if (/\/admin\/pos(\/|$|\?)/.test(landingUrl) || !/\/admin\/dashboard/.test(landingUrl)) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    await page.locator('aside.db-sidebar, aside.main-sidebar, #app, body').first().waitFor({ timeout: 15_000 }).catch(() => {});
    await page.getByText(/Ventes du jour|Tableau De Bord|Commandes/i).first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(800);

    const dash = await saveQuartet(page, consoleBuf.slice(), networkBuf.slice(), '01-dashboard-sidebar', {
      loginHttp: loginInfo.status,
      landingUrl,
      forcedDashboard: true,
      didClickPos: false,
      didClickPaymentOrWizard: false,
    });

    const consoleAtDash = consoleBuf.length;
    const networkAtDash = networkBuf.length;

    await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForTimeout(1500);
    await page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => {});
    await page
      .locator('[data-testid="system-health"], [data-testid="system-health-erreur"], aside.db-sidebar, #app, body')
      .first()
      .waitFor({ timeout: 10_000 })
      .catch(() => {});
    await page.waitForTimeout(800);

    const deep = await saveQuartet(
      page,
      consoleBuf.slice(consoleAtDash),
      networkBuf.slice(networkAtDash),
      '02-observability-system',
      {
        vueMountSelector: '[data-testid="system-health"]',
        requestedUrl: `${BASE}/admin/observability/system`,
      },
    );

    const summary = {
      wave: 'E',
      base_url: BASE,
      landingUrl,
      states: [
        {
          name: 'idle',
          url: dash.signals.url,
          png: path.join(OUT, '01-dashboard-sidebar.png'),
          png_nested: dash.png,
          dom: path.join(OUT, '01-dashboard-sidebar.dom.html'),
          console: path.join(OUT, '01-dashboard-sidebar.console.json'),
          network: path.join(OUT, '01-dashboard-sidebar.network.json'),
          notes: `Logged in as ${EMAIL}. Landing ${landingUrl}. Immediate /admin/dashboard, no POS payment/wizard clicks. Mix app.js hash=${dash.signals.appJsHash}. Sidebar État du système=${dash.signals.sidebar.hasEtatDuSysteme}. [data-testid=system-health] exists=${dash.signals.vueSystemHealthExists}.`,
        },
        {
          name: 'observability-deeplink',
          url: deep.signals.url,
          png: path.join(OUT, '02-observability-system.png'),
          png_nested: deep.png,
          dom: path.join(OUT, '02-observability-system.dom.html'),
          console: path.join(OUT, '02-observability-system.console.json'),
          network: path.join(OUT, '02-observability-system.network.json'),
          notes: `goto /admin/observability/system. finalUrl=${deep.signals.url}. system-health exists=${deep.signals.vueSystemHealthExists} visible=${deep.signals.vueSystemHealthVisible}. sidebar État du système=${deep.signals.sidebar.hasEtatDuSysteme}. healthCockpit=#${deep.signals.healthCockpitPresent}. app.js hash=${deep.signals.appJsHash}.`,
        },
      ],
      record: {
        final_url_after_deeplink: deep.signals.url,
        system_health_exists: deep.signals.vueSystemHealthExists,
        system_health_visible: deep.signals.vueSystemHealthVisible,
        health_cockpit_present: deep.signals.healthCockpitPresent,
        sidebar_etat_du_systeme: dash.signals.sidebar.hasEtatDuSysteme,
        sidebar_etat_du_systeme_after_deeplink: deep.signals.sidebar.hasEtatDuSysteme,
        sidebar_labels_dashboard: dash.signals.sidebar.exactLabels,
        sidebar_labels_deeplink: deep.signals.sidebar.exactLabels,
        mix_app_js_hash_dashboard: dash.signals.appJsHash,
        mix_app_js_url_dashboard: dash.signals.appJsUrl,
        mix_app_js_hash_deeplink: deep.signals.appJsHash,
        mix_app_js_url_deeplink: deep.signals.appJsUrl,
        redirected_to_dashboard: deep.signals.redirectedToDashboard,
        still_on_observability: deep.signals.stillOnObservability,
      },
      blockers,
    };
    fs.writeFileSync(path.join(OUT, 'wave-E-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
    console.log(JSON.stringify(summary, null, 2));
  } catch (err) {
    blockers.push(String(err && err.stack ? err.stack : err));
    try {
      await saveQuartet(page, consoleBuf, networkBuf, '99-error', { error: String(err) });
    } catch (_) {
      /* ignore */
    }
    fs.writeFileSync(path.join(OUT, 'wave-E-error.txt'), String(err && err.stack ? err.stack : err), 'utf8');
    console.error(err);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
