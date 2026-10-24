/**
 * GOAL W2 Wave E — cashier observability deeplink (isolated Playwright CLI).
 * Login pos@lecayenne.fr. If /admin/pos: do NOT click payment. Goto
 * /admin/observability/system. Quartet 02-observability.* + PUT interrupteur probe.
 * No MCP, no project globalSetup, no orders, no kiosk, no POS payment/wizard.
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-5/wave-E');
const EMAIL = 'pos@lecayenne.fr';
const PASS = '123456';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function resolveChromiumExecutable() {
  if (process.env.PLAYWRIGHT_CHROMIUM) return process.env.PLAYWRIGHT_CHROMIUM;
  const home = process.env.HOME || '';
  const candidates = [
    path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
    path.join(home, 'Library/Caches/ms-playwright/chromium-1237/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
    path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1208/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
    path.join(home, 'Library/Caches/ms-playwright/chromium-1208/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  return candidates.find((c) => fs.existsSync(c)) || null;
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

function isInterrupteurUrl(url) {
  return /\/admin\/observability\/interrupteurs/i.test(String(url || ''));
}

function isObservabilityApiUrl(url) {
  return /\/admin\/observability\/(interrupteurs|system-health|outbox)/i.test(String(url || ''));
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

function snapshotWheel() {
  const r = spawnSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      `echo json_encode(['wheel' => app(\\App\\Services\\Pilotage\\InterrupteurService::class)->valeur('wheel')]);`,
    ],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
  );
  const text = String(r.stdout || '');
  const m = text.match(/\{[^{}]*"wheel"[^{}]*\}/);
  try {
    return m ? JSON.parse(m[0]) : { raw: text.slice(0, 400), status: r.status };
  } catch {
    return { raw: text.slice(0, 400), status: r.status };
  }
}

function restoreWheel(actif) {
  const val = actif ? 'true' : 'false';
  spawnSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      `app(\\App\\Services\\Pilotage\\InterrupteurService::class)->regler('wheel', ${val}); echo 'restored';`,
    ],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
  );
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
  const allRelevant = [];
  const putInterrupteur = [];

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
    const row = {
      kind: 'failed',
      url,
      method: req.method(),
      failure: req.failure()?.errorText || 'failed',
      ts: Date.now(),
    };
    networkBuf.push(row);
    if (isObservabilityApiUrl(url) || isInterrupteurUrl(url)) allRelevant.push(row);
  });
  page.on('response', async (res) => {
    const status = res.status();
    const url = res.url();
    const method = res.request().method();
    const interesting = isObservabilityApiUrl(url) || isInterrupteurUrl(url) || method === 'PUT';
    if (status < 400 && !interesting) return;
    if (isWsOrDebugbar(url) && !interesting) return;
    if (isEnglishStorageLeftover(url) && status >= 400 && !interesting) return;
    let bodySnippet = null;
    if (interesting) {
      try {
        bodySnippet = String(await res.text()).slice(0, 1500);
      } catch {
        bodySnippet = null;
      }
    }
    const row = {
      kind: 'http',
      url,
      method,
      status,
      resourceType: res.request().resourceType(),
      ts: Date.now(),
      bodySnippet,
    };
    if (status >= 400 && !isWsOrDebugbar(url) && !isEnglishStorageLeftover(url)) {
      networkBuf.push(row);
    }
    if (interesting) allRelevant.push(row);
    if (method === 'PUT' && isInterrupteurUrl(url)) {
      putInterrupteur.push(row);
    }
  });
  return { consoleBuf, networkBuf, allRelevant, putInterrupteur };
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
    interrupteursPresent: (await page.locator('[data-testid="system-interrupteurs"]').count()) > 0,
    h2EtatDuSysteme: (await page.locator('h2', { hasText: 'État du système' }).count()) > 0,
    redirectedToDashboard: /\/admin\/dashboard(\/|$|\?)/.test(page.url()),
    stillOnObservability: /\/admin\/observability\/system/.test(page.url()),
    stillOnPos: /\/admin\/pos(\/|$|\?)/.test(page.url()),
    systemHealthErreurText: await page.locator('[data-testid="system-health-erreur"]').innerText().catch(() => null),
    toastOrAlert: await page.locator('.Toastify, .toast, [role="alert"], .swal2-container').allInnerTexts().catch(() => []),
    bodyHasVentesDuJour: /Ventes du jour/i.test(bodyText),
    bodyHasEtatDuSysteme: /État du système|Etat du système/i.test(bodyText),
    bodyHasImpossibleLire: /Impossible de lire l['’]état du système/i.test(bodyText),
    bodySnippet: bodyText.slice(0, 4000),
  };
}

async function saveQuartet(page, consoleBuf, networkBuf, name, extra) {
  const png = path.join(OUT, `${name}.png`);
  const domPath = path.join(OUT, `${name}.dom.html`);
  const consolePath = path.join(OUT, `${name}.console.json`);
  const networkPath = path.join(OUT, `${name}.network.json`);
  const notesPath = path.join(OUT, `${name}.notes.json`);

  await expandInnerOverflow(page);
  await page.waitForTimeout(300);
  await page.screenshot({ path: png, fullPage: true });
  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');
  const signals = await pageSignals(page);
  const notes = { name, ...signals, ...extra };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  return { png, dom: domPath, console: consolePath, network: networkPath, notes, signals };
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  const email = page.locator('#formEmail, input[name="email"]').first();
  const password = page.locator('#formPassword, input[name="password"]').first();
  await email.waitFor({ state: 'visible', timeout: 20_000 });
  await email.fill(EMAIL);
  await password.fill(PASS);
  const submit = page.locator('button[type="submit"]').first();
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

async function probeInterrupteurPut(page) {
  return page.evaluate(async () => {
    let token = null;
    try {
      const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
      token = (vuex && vuex.auth && vuex.auth.authToken) || null;
    } catch {
      token = null;
    }
    const cfg = window.foodkingConfig || {};
    const apiKey = cfg.apiKey || '';
    const url = '/api/admin/observability/interrupteurs/wheel';
    const res = await fetch(url, {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: token ? `Bearer ${token}` : '',
        'x-api-key': apiKey,
        'X-Device-Id': 'e2e-hunter-w2',
        'X-Device-Label': 'e2e-hunter-w2',
      },
      body: JSON.stringify({ actif: true }),
      credentials: 'same-origin',
    });
    const text = await res.text();
    return {
      url,
      status: res.status,
      body: String(text).slice(0, 1500),
      hasToken: Boolean(token),
      forced: true,
    };
  });
}

(async () => {
  mkdirp(OUT);
  clearRateLimits();
  const wheelBefore = snapshotWheel();

  const executablePath = resolveChromiumExecutable();
  const launchOpts = {
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  };
  if (executablePath) launchOpts.executablePath = executablePath;

  const browser = await chromium.launch(launchOpts);
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
  const { consoleBuf, networkBuf, allRelevant, putInterrupteur } = attachCollectors(page);

  const blockers = [];
  let forcedPut = null;
  let wheelAfter = null;
  try {
    const loginInfo = await login(page);
    const landingUrl = page.url();
    const landedOnPos = /\/admin\/pos(\/|$|\?)/.test(landingUrl);

    if (landedOnPos) {
      await page.waitForTimeout(400);
    }

    const landing = await saveQuartet(
      page,
      consoleBuf.slice(),
      networkBuf.filter((r) => !isWsOrDebugbar(r.url) && !isEnglishStorageLeftover(r.url)),
      '01-landing',
      {
        loginHttp: loginInfo.status,
        landingUrl,
        landedOnPos,
        didClickPos: false,
        didClickPaymentOrWizard: false,
        note: 'Landing after cashier login. No payment/wizard clicks.',
      },
    );

    const consoleAtNav = consoleBuf.length;
    const networkAtNav = networkBuf.length;
    const relevantAtNav = allRelevant.length;

    await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForTimeout(1500);
    await page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => {});
    await page
      .locator('[data-testid="system-health"], [data-testid="system-health-erreur"], aside.db-sidebar, #app, body')
      .first()
      .waitFor({ timeout: 10_000 })
      .catch(() => {});
    if (/\/admin\/dashboard/.test(page.url())) {
      await page.getByText(/Ventes du jour|Tableau De Bord|Commandes/i).first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
    }
    await page.waitForTimeout(800);

    const png = path.join(OUT, '02-observability.png');
    const domPath = path.join(OUT, '02-observability.dom.html');
    await expandInnerOverflow(page);
    await page.waitForTimeout(300);
    await page.screenshot({ path: png, fullPage: true });
    fs.writeFileSync(domPath, await page.content(), 'utf8');
    const signalsBeforePut = await pageSignals(page);

    forcedPut = await probeInterrupteurPut(page);
    await page.waitForTimeout(400);
    if (forcedPut && Number(forcedPut.status) === 200) {
      blockers.push('FORCED_PUT_RETURNED_200_CASHIER_MUTATED_WHEEL');
      if (wheelBefore && typeof wheelBefore.wheel === 'boolean') {
        restoreWheel(wheelBefore.wheel);
      }
    }
    wheelAfter = snapshotWheel();

    const obsNetwork = [
      ...networkBuf.slice(networkAtNav).filter((r) => !isWsOrDebugbar(r.url) && !isEnglishStorageLeftover(r.url)),
      ...allRelevant.slice(relevantAtNav),
    ];
    const seen = new Set();
    const deduped = [];
    for (const row of obsNetwork) {
      const key = `${row.method}|${row.status}|${row.url}|${row.ts}`;
      if (seen.has(key)) continue;
      seen.add(key);
      deduped.push(row);
    }
    if (forcedPut) {
      deduped.push({
        kind: 'forced-put',
        url: `${BASE}${forcedPut.url}`,
        method: 'PUT',
        status: forcedPut.status,
        bodySnippet: forcedPut.body,
        forced: true,
        ts: Date.now(),
      });
    }

    const consolePath = path.join(OUT, '02-observability.console.json');
    const networkPath = path.join(OUT, '02-observability.network.json');
    const notesPath = path.join(OUT, '02-observability.notes.json');
    fs.writeFileSync(consolePath, JSON.stringify(consoleBuf.slice(consoleAtNav), null, 2), 'utf8');
    fs.writeFileSync(networkPath, JSON.stringify(deduped, null, 2), 'utf8');
    const notes = {
      name: '02-observability',
      ...signalsBeforePut,
      requestedUrl: `${BASE}/admin/observability/system`,
      landingUrl,
      landedOnPos,
      didClickPaymentOrWizard: false,
      didClickPosUi: false,
      forcedPut,
      naturalPutInterrupteur: putInterrupteur,
      observabilityApi: allRelevant.slice(relevantAtNav),
      wheelBefore,
      wheelAfter,
      chromium: executablePath,
    };
    fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');

    const summary = {
      wave: 'E-W2-observability',
      base_url: BASE,
      landingUrl,
      states: [
        {
          name: 'landing',
          url: landing.signals.url,
          png: landing.png,
          dom: landing.dom,
          console: landing.console,
          network: landing.network,
          notes: `Logged in as ${EMAIL}. Landing ${landingUrl}. No POS payment/wizard clicks.`,
        },
        {
          name: 'observability-deeplink',
          url: signalsBeforePut.url,
          png,
          dom: domPath,
          console: consolePath,
          network: networkPath,
          notes: `goto /admin/observability/system. finalUrl=${signalsBeforePut.url}. system-health exists=${signalsBeforePut.vueSystemHealthExists} visible=${signalsBeforePut.vueSystemHealthVisible}. redirectedToDashboard=${signalsBeforePut.redirectedToDashboard}. forcedPut.status=${forcedPut && forcedPut.status}. naturalPuts=${putInterrupteur.length}.`,
        },
      ],
      record: {
        final_url_after_deeplink: signalsBeforePut.url,
        expected_dashboard_not_system_health: true,
        redirected_to_dashboard: signalsBeforePut.redirectedToDashboard,
        still_on_observability: signalsBeforePut.stillOnObservability,
        system_health_exists: signalsBeforePut.vueSystemHealthExists,
        system_health_visible: signalsBeforePut.vueSystemHealthVisible,
        interrupteurs_present: signalsBeforePut.interrupteursPresent,
        sidebar_etat_du_systeme: signalsBeforePut.sidebar.hasEtatDuSysteme,
        forced_put: forcedPut,
        natural_put_interrupteur: putInterrupteur,
        wheel_before: wheelBefore,
        wheel_after: wheelAfter,
        mix_app_js_hash: signalsBeforePut.appJsHash,
        mix_admin_shell_hash: signalsBeforePut.adminShellHash,
      },
      blockers,
    };
    fs.writeFileSync(path.join(OUT, 'wave-E-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
    console.log(JSON.stringify(summary, null, 2));
  } catch (err) {
    blockers.push(String(err && err.stack ? err.stack : err));
    try {
      await saveQuartet(page, consoleBuf, networkBuf, '99-error', { error: String(err) });
    } catch {
      /* ignore */
    }
    fs.writeFileSync(path.join(OUT, 'wave-E-error.txt'), String(err && err.stack ? err.stack : err), 'utf8');
    console.error(err);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
