/**
 * Round 2 isolated Playwright CLI (post Mix admin-shell.2649746a.js).
 * No MCP, no project globalSetup, no orders, no kiosk, no POS clicks.
 * Cache disabled (CDP) so stale app.js cannot serve.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const require = createRequire('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
const ROUND = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-2');
const OUT_E = path.join(ROUND, 'wave-E');
const OUT_B = path.join(ROUND, 'wave-B');
const EXPECTED_SHELL = 'admin-shell.2649746a.js';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
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

async function attachCollectors(page) {
  const consoleBuf = [];
  const networkBuf = [];
  const allNetwork = [];
  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error' || type === 'warning') {
      consoleBuf.push({ type, text: msg.text(), loc: msg.location(), ts: Date.now() });
    }
  });
  page.on('pageerror', (err) => {
    consoleBuf.push({ type: 'pageerror', text: String(err), stack: err?.stack || null, ts: Date.now() });
  });
  page.on('requestfailed', (req) => {
    const entry = {
      kind: 'failed',
      url: req.url(),
      method: req.method(),
      failure: req.failure()?.errorText || 'failed',
      ts: Date.now(),
    };
    networkBuf.push(entry);
    allNetwork.push({ ...entry, status: 0, failed: true });
  });
  page.on('response', (res) => {
    const status = res.status();
    const entry = {
      kind: 'http',
      url: res.url(),
      method: res.request().method(),
      status,
      resourceType: res.request().resourceType(),
      ts: Date.now(),
    };
    allNetwork.push(entry);
    if (status >= 400) networkBuf.push(entry);
  });
  return { consoleBuf, networkBuf, allNetwork };
}

async function disableCache(page) {
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  return client;
}

async function loadedAssets(page) {
  return page.evaluate(() => {
    const scripts = Array.from(document.querySelectorAll('script[src]')).map((s) => s.src);
    const shells = scripts.filter((s) => /admin-shell|app\.js(\?|$)|vendor\.js|pos-shell|manifest\.js/.test(s));
    return { scripts: shells, allScriptCount: scripts.length };
  });
}

async function extractSidebar(page) {
  const aside = page.locator('aside.db-sidebar');
  const present = (await aside.count()) > 0;
  const visible = present ? await aside.first().isVisible().catch(() => false) : false;
  const labels = [];
  if (present) {
    const titles = await aside.locator('.db-sidebar-nav-title').allInnerTexts().catch(() => []);
    const menus = await aside.locator('.db-sidebar-nav-menu').allInnerTexts().catch(() => []);
    for (const t of titles) {
      const clean = t.replace(/\s+/g, ' ').trim();
      if (clean) labels.push({ kind: 'group', text: clean });
    }
    for (const t of menus) {
      const clean = t.replace(/\s+/g, ' ').trim();
      if (clean) labels.push({ kind: 'item', text: clean });
    }
  }
  const exact = labels.map((l) => l.text);
  const unique = [...new Set(exact)];
  const hasEtat = unique.some((t) => /État du système|Etat du système|État Du Système|system health/i.test(t));
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
    hasEtatDuSysteme: hasEtat,
    hrefs,
    rawText: present ? (await aside.innerText().catch(() => '')).replace(/\s+/g, ' ').trim() : '',
  };
}

async function pageSignals(page) {
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const assets = await loadedAssets(page);
  const sidebar = await extractSidebar(page);
  const vueMounted = (await page.locator('[data-testid="system-health"]').count()) > 0;
  const healthCockpit = (await page.locator('#health-cockpit').count()) > 0;
  return {
    url: page.url(),
    title: await page.title().catch(() => ''),
    viewport: page.viewportSize(),
    sidebar,
    assets,
    mixHashLoaded: assets.scripts.some((s) => s.includes(EXPECTED_SHELL)),
    vueSystemHealthMounted: vueMounted,
    healthCockpitPresent: healthCockpit,
    h2EtatDuSysteme: (await page.locator('h2', { hasText: 'État du système' }).count()) > 0,
    redirectedToDashboard: /\/admin\/dashboard(\/|$|\?)/.test(page.url()),
    stillOnObservability: /\/admin\/observability\/system/.test(page.url()),
    stillOnPos: /\/admin\/pos(\/|$|\?)/.test(page.url()),
    impossibleDeLire: /Impossible de lire/i.test(bodyText),
    impossibleEtat: /Impossible de lire l'état du système/i.test(bodyText),
    impossibleInterrupteurs: /Impossible de lire les interrupteurs/i.test(bodyText),
    mesureIndisponible: /mesure indisponible/i.test(bodyText),
    aucune: /\baucune\b/i.test(bodyText),
    consigneJournal: /Consigne dans le journal serveur/i.test(bodyText),
    chaqueBascule: /Chaque bascule est tracée/i.test(bodyText),
    systemHealthErreurText: await page.locator('[data-testid="system-health-erreur"]').innerText().catch(() => null),
    sauvegardeText: await page.locator('[data-testid="system-health-sauvegarde"]').innerText().catch(() => null),
    planificateurText: await page.locator('[data-testid="system-health-planificateur"]').innerText().catch(() => null),
    interrupteursText: await page.locator('[data-testid="system-interrupteurs"]').innerText().catch(() => null),
    toastOrAlert: await page.locator('.Toastify, .toast, [role="alert"], .swal2-container').allInnerTexts().catch(() => []),
    bodySnippet: bodyText.slice(0, 5000),
  };
}

async function saveQuartet(page, consoleBuf, networkBuf, outRoot, name, extra) {
  const dir = path.join(outRoot, name);
  mkdirp(dir);
  const png = path.join(dir, `${name}.png`);
  const domPath = path.join(dir, `${name}.dom.html`);
  const consolePath = path.join(dir, `${name}.console.json`);
  const networkPath = path.join(dir, `${name}.network.json`);
  const notesPath = path.join(dir, `${name}.notes.json`);

  await page.waitForTimeout(400);
  await page.screenshot({ path: png, fullPage: true });
  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');
  const signals = await pageSignals(page);
  const notes = { name, ...signals, ...extra };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  flattenCopy(dir, outRoot, name);
  return { png, dom: domPath, console: consolePath, network: networkPath, notes, signals };
}

async function login(page, email, pass) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(email);
  await page.locator('#formPassword').fill(pass);
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
    throw new Error(`Login API failed for ${email}: HTTP ${status} ${body.slice(0, 400)}`);
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1200);
  return { status, url: page.url() };
}

async function hardReload(page) {
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.waitForTimeout(1500);
}

async function withBrowser(fn) {
  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    baseURL: BASE,
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  page.on('dialog', async (dialog) => {
    await dialog.dismiss();
  });
  await disableCache(page);
  const collectors = await attachCollectors(page);
  try {
    return await fn(page, collectors);
  } finally {
    await browser.close();
  }
}

async function captureWaveE() {
  mkdirp(OUT_E);
  return withBrowser(async (page, { consoleBuf, networkBuf }) => {
    const loginInfo = await login(page, 'pos@lecayenne.fr', '123456');
    const landingUrl = page.url();

    if (/\/admin\/pos(\/|$|\?)/.test(landingUrl) || !/\/admin\/dashboard/.test(landingUrl)) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    await hardReload(page);
    await page.locator('aside.db-sidebar, #app, body').first().waitFor({ timeout: 15_000 }).catch(() => {});

    const dash = await saveQuartet(page, consoleBuf, networkBuf, OUT_E, '01-dashboard-sidebar', {
      loginHttp: loginInfo.status,
      landingUrl,
      forcedDashboard: true,
      didClickPos: false,
      hardReload: true,
    });

    const consoleAtDash = consoleBuf.length;
    const networkAtDash = networkBuf.length;

    await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForTimeout(2800);
    await page
      .locator('[data-testid="system-health"], [data-testid="system-health-erreur"], aside.db-sidebar, #app, body')
      .first()
      .waitFor({ timeout: 10_000 })
      .catch(() => {});

    const deep = await saveQuartet(
      page,
      consoleBuf.slice(consoleAtDash),
      networkBuf.slice(networkAtDash),
      OUT_E,
      '02-observability-system',
      { vueMountSelector: '[data-testid="system-health"]' },
    );

    const mixClosedPosDeeplink =
      !deep.signals.vueSystemHealthMounted &&
      (deep.signals.redirectedToDashboard || !deep.signals.stillOnObservability);

    const summary = {
      wave: 'E-cashier',
      base_url: BASE,
      mix_expected: EXPECTED_SHELL,
      mix_hash_loaded_dashboard: dash.signals.mixHashLoaded,
      mix_hash_loaded_deeplink: deep.signals.mixHashLoaded,
      mix_closed_pos_deeplink: mixClosedPosDeeplink,
      etat_du_systeme_in_sidebar: dash.signals.sidebar.hasEtatDuSysteme,
      sidebar_exact_labels: dash.signals.sidebar.exactLabels,
      deeplink: {
        final_url: page.url(),
        vue_mounted: deep.signals.vueSystemHealthMounted,
        redirected_to_dashboard: deep.signals.redirectedToDashboard,
        still_on_observability: deep.signals.stillOnObservability,
        mesure_indisponible: deep.signals.mesureIndisponible,
        aucune: deep.signals.aucune,
        impossible_de_lire: deep.signals.impossibleDeLire,
        erreur_text: deep.signals.systemHealthErreurText,
        sauvegarde_text: deep.signals.sauvegardeText,
        planificateur_text: deep.signals.planificateurText,
      },
      states: [
        {
          name: 'cashier-dashboard-sidebar',
          url: dash.signals.url,
          png: dash.png,
          dom: dash.dom,
          console: dash.console,
          network: dash.network,
          notes: `Logged in as pos@lecayenne.fr. Landing was ${landingUrl}. Immediate goto /admin/dashboard, hard-reload, no POS clicks. Mix ${EXPECTED_SHELL} loaded=${dash.signals.mixHashLoaded}. Sidebar: ${JSON.stringify(dash.signals.sidebar.exactLabels)}. État du système present: ${dash.signals.sidebar.hasEtatDuSysteme}`,
        },
        {
          name: 'observability-system-deeplink',
          url: deep.signals.url,
          png: deep.png,
          dom: deep.dom,
          console: deep.console,
          network: deep.network,
          notes: `Deep-link. Vue mounted=${deep.signals.vueSystemHealthMounted}. redirectedToDashboard=${deep.signals.redirectedToDashboard}. stillOnObservability=${deep.signals.stillOnObservability}. mesure indisponible=${deep.signals.mesureIndisponible}. aucune=${deep.signals.aucune}. erreur=${deep.signals.systemHealthErreurText}. Mix closed POS deep-link=${mixClosedPosDeeplink}`,
        },
      ],
      blockers: [],
    };
    fs.writeFileSync(path.join(OUT_E, 'wave-E-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
    return summary;
  });
}

async function captureWaveB() {
  mkdirp(OUT_B);
  return withBrowser(async (page, { consoleBuf, networkBuf, allNetwork }) => {
    const loginInfo = await login(page, 'admin@lecayenne.fr', '123456');
    const landingUrl = page.url();
    if (/\/admin\/pos(\/|$|\?)/.test(landingUrl)) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }

    const healthRespPromise = page.waitForResponse(
      (res) => /\/admin\/observability\/system-health/i.test(res.url()),
      { timeout: 25_000 },
    );
    await page.goto(`${BASE}/admin/observability/system`, {
      waitUntil: 'domcontentloaded',
      timeout: 30_000,
    });
    await hardReload(page);
    await page.locator('[data-testid="system-health"]').waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
    let healthStatus = null;
    try {
      const healthResp = await healthRespPromise;
      healthStatus = healthResp.status();
    } catch (e) {
      healthStatus = `wait-failed: ${e.message}`;
    }

    await page
      .waitForFunction(
        () => {
          const root = document.querySelector('[data-testid="system-health"]');
          if (!root) return false;
          if (root.getAttribute('aria-busy') === 'true') return false;
          return !!(
            document.querySelector('[data-testid="system-health-verdict"]') ||
            document.querySelector('[data-testid="system-health-erreur"]')
          );
        },
        { timeout: 20_000 },
      )
      .catch(() => {});

    await page.locator('[data-testid="system-health-sauvegarde"]').scrollIntoViewIfNeeded().catch(() => {});
    await page.locator('[data-testid="system-health-planificateur"]').scrollIntoViewIfNeeded().catch(() => {});
    await page.locator('[data-testid="system-interrupteurs"]').scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(400);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);

    const extract = await page.evaluate(() => {
      const t = (sel) => {
        const el = document.querySelector(sel);
        return el ? (el.innerText || el.textContent || '').trim() : null;
      };
      const body = (document.body && document.body.innerText) || '';
      return {
        url: location.href,
        title: document.title,
        verdict: t('[data-testid="system-health-verdict"]'),
        mesure: t('[data-testid="system-health-mesure"]'),
        erreur: t('[data-testid="system-health-erreur"]'),
        backup: t('[data-testid="system-health-sauvegarde"]'),
        scheduler: t('[data-testid="system-health-planificateur"]'),
        interrupteurs: t('[data-testid="system-interrupteurs"]'),
        consigneJournal: /Consigne dans le journal serveur/i.test(body),
        chaqueBascule: /Chaque bascule est tracée/i.test(body),
        mesureIndisponible: /mesure indisponible/i.test(body),
        aucune: /\baucune\b/i.test(body),
        bodySnippet: body.slice(0, 5000),
        scripts: Array.from(document.querySelectorAll('script[src]'))
          .map((s) => s.src)
          .filter((s) => /admin-shell|app\.js/.test(s)),
      };
    });

    const png = path.join(OUT_B, '01-cockpit.png');
    const domPath = path.join(OUT_B, '01-cockpit.dom.html');
    const consolePath = path.join(OUT_B, '01-cockpit.console.json');
    const networkPath = path.join(OUT_B, '01-cockpit.network.json');
    const extractPath = path.join(OUT_B, '01-cockpit.extract.json');

    await page.screenshot({ path: png, fullPage: true });
    fs.writeFileSync(domPath, await page.content(), 'utf8');
    fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
    fs.writeFileSync(
      networkPath,
      JSON.stringify(
        {
          interesting: networkBuf,
          healthStatus,
          totals: { responses: allNetwork.length, interesting: networkBuf.length },
        },
        null,
        2,
      ),
      'utf8',
    );
    const signals = await pageSignals(page);
    fs.writeFileSync(
      extractPath,
      JSON.stringify({ ...extract, ...signals, healthStatus, loginHttp: loginInfo.status, landingUrl }, null, 2),
      'utf8',
    );

    const summary = {
      wave: 'B-admin-cockpit',
      base_url: BASE,
      mix_expected: EXPECTED_SHELL,
      mix_hash_loaded: signals.mixHashLoaded,
      consigne_journal_serveur: extract.consigneJournal,
      chaque_bascule_est_tracee: extract.chaqueBascule,
      states: [
        {
          name: 'admin-cockpit',
          url: page.url(),
          png,
          dom: domPath,
          console: consolePath,
          network: networkPath,
          notes: `admin@lecayenne.fr cockpit. Mix ${EXPECTED_SHELL} loaded=${signals.mixHashLoaded}. Consigne journal=${extract.consigneJournal}. Chaque bascule=${extract.chaqueBascule}. Vue mounted=${signals.vueSystemHealthMounted}`,
        },
      ],
      blockers: [],
    };
    fs.writeFileSync(path.join(OUT_B, 'wave-B-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
    return summary;
  });
}

(async () => {
  mkdirp(OUT_E);
  mkdirp(OUT_B);
  clearRateLimits();
  const waveE = await captureWaveE();
  clearRateLimits();
  const waveB = await captureWaveB();
  const combined = { waveE, waveB };
  fs.writeFileSync(path.join(ROUND, 'round-2-summary.json'), JSON.stringify(combined, null, 2), 'utf8');
  console.log(JSON.stringify(combined, null, 2));
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
