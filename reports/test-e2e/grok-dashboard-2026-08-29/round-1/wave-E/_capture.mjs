/**
 * Wave E — isolated Playwright CLI capture (cashier POS Operator).
 * No MCP, no project globalSetup, no orders, no kiosk, no POS clicks.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
const require = createRequire('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/grok-dashboard-2026-08-29/round-1/wave-E';
const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
const EMAIL = 'pos@lecayenne.fr';
const PASS = '123456';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function clearRateLimits() {
  const r = spawnSync('php', ['artisan', 'tinker', '--execute', `
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
  `], { cwd: REPO, encoding: 'utf8', timeout: 20_000 });
  if (r.status !== 0) {
    console.warn('rate-limit clear failed', r.stderr || r.stdout);
  }
}

async function saveQuartet(page, consoleBuf, networkBuf, name, extra) {
  const dir = path.join(OUT, name);
  mkdirp(dir);
  const png = path.join(dir, `${name}.png`);
  const domPath = path.join(dir, `${name}.dom.html`);
  const consolePath = path.join(dir, `${name}.console.json`);
  const networkPath = path.join(dir, `${name}.network.json`);
  const notesPath = path.join(dir, `${name}.notes.json`);

  await page.waitForTimeout(400);
  await page.screenshot({ path: png, fullPage: true });
  const html = await page.content();
  fs.writeFileSync(domPath, html, 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');

  const sidebar = await extractSidebar(page);
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const notes = {
    name,
    url: page.url(),
    title: await page.title().catch(() => ''),
    viewport: page.viewportSize(),
    sidebar,
    vueSystemHealthMounted: (await page.locator('[data-testid="system-health"]').count()) > 0,
    h2EtatDuSysteme: (await page.locator('h2', { hasText: 'État du système' }).count()) > 0,
    impossibleDeLire: /Impossible de lire/i.test(bodyText),
    impossibleEtat: /Impossible de lire l'état du système/i.test(bodyText),
    impossibleInterrupteurs: /Impossible de lire les interrupteurs/i.test(bodyText),
    systemHealthErreurText: await page.locator('[data-testid="system-health-erreur"]').innerText().catch(() => null),
    toastOrAlert: await page.locator('.Toastify, .toast, [role="alert"], .swal2-container').allInnerTexts().catch(() => []),
    ...extra,
  };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  return { png, dom: domPath, console: consolePath, network: networkPath, notes };
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
  const hasEtat = unique.some((t) => /État du système|Etat du système|system health/i.test(t));
  const hrefs = present
    ? await aside.locator('a, [href]').evaluateAll((els) =>
        els.map((el) => ({
          tag: el.tagName,
          href: el.getAttribute('href'),
          text: (el.textContent || '').replace(/\s+/g, ' ').trim(),
        })),
      ).catch(() => [])
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
  // LoginComponent recursiveRouter setTimeout(1000) — wait so SPA hydrates, then leave POS immediately.
  await page.waitForTimeout(1200);
  return { status, url: page.url() };
}

(async () => {
  mkdirp(OUT);
  clearRateLimits();

  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    baseURL: BASE,
  });
  const page = await context.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });

  const consoleBuf = [];
  const networkBuf = [];
  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error' || type === 'warning') {
      consoleBuf.push({ type, text: msg.text(), loc: msg.location(), ts: Date.now() });
    }
  });
  page.on('pageerror', (err) => {
    consoleBuf.push({ type: 'pageerror', text: String(err), ts: Date.now() });
  });
  page.on('requestfailed', (req) => {
    networkBuf.push({
      kind: 'failed',
      url: req.url(),
      method: req.method(),
      failure: req.failure()?.errorText || 'failed',
      ts: Date.now(),
    });
  });
  page.on('response', (res) => {
    const status = res.status();
    if (status >= 400) {
      networkBuf.push({
        kind: 'http',
        url: res.url(),
        method: res.request().method(),
        status,
        ts: Date.now(),
      });
    }
  });

  const states = [];
  let blockers = [];

  try {
    const loginInfo = await login(page);
    const landingUrl = page.url();

    // If POS: do not click payment/wizard/frozen POS UI — leave immediately.
    if (/\/admin\/pos(\/|$|\?)/.test(landingUrl)) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    } else if (!/\/admin\/dashboard/.test(landingUrl)) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }

    await page.waitForTimeout(1500);
    await page.locator('aside.db-sidebar, #app, body').first().waitFor({ timeout: 15_000 }).catch(() => {});

    const dashNotes = await saveQuartet(page, consoleBuf, networkBuf, '01-dashboard-sidebar', {
      loginHttp: loginInfo.status,
      landingUrl,
      forcedDashboard: true,
      didClickPos: false,
    });
    states.push({
      name: 'cashier-dashboard-sidebar',
      url: page.url(),
      png: dashNotes.png,
      dom: dashNotes.dom,
      console: dashNotes.console,
      network: dashNotes.network,
      notes: `Logged in as ${EMAIL}. Landing was ${landingUrl}. Immediately navigated to /admin/dashboard without POS clicks. Sidebar labels: ${JSON.stringify(dashNotes.notes.sidebar.exactLabels)}. État du système present: ${dashNotes.notes.sidebar.hasEtatDuSysteme}`,
    });

    // Reset per-state buffers after snapshot so second quartet is scoped? Keep cumulative
    // from session start so 4xx during login are visible too. Parent asked per-state
    // quartet; keep full session plus a per-state slice by copying current length.
    const consoleAtDash = consoleBuf.length;
    const networkAtDash = networkBuf.length;

    await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForTimeout(2500);
    await page.locator('[data-testid="system-health"], [data-testid="system-health-erreur"], #app, body').first().waitFor({ timeout: 10_000 }).catch(() => {});

    const cockpitNotes = await saveQuartet(page, consoleBuf.slice(consoleAtDash), networkBuf.slice(networkAtDash), '02-observability-system', {
      vueMountSelector: '[data-testid="system-health"]',
    });
    states.push({
      name: 'observability-system-deeplink',
      url: page.url(),
      png: cockpitNotes.png,
      dom: cockpitNotes.dom,
      console: cockpitNotes.console,
      network: cockpitNotes.network,
      notes: `Deep-link /admin/observability/system. Vue [data-testid=system-health] mounted=${cockpitNotes.notes.vueSystemHealthMounted}. h2 État du système=${cockpitNotes.notes.h2EtatDuSysteme}. Impossible de lire=${cockpitNotes.notes.impossibleDeLire}. erreur=${cockpitNotes.notes.systemHealthErreurText}`,
    });

    const summary = {
      wave: 'E-cashier',
      base_url: BASE,
      states,
      sidebar_exact_labels: dashNotes.notes.sidebar.exactLabels,
      etat_du_systeme_in_sidebar: dashNotes.notes.sidebar.hasEtatDuSysteme,
      cockpit: {
        final_url: page.url(),
        vue_mounted: cockpitNotes.notes.vueSystemHealthMounted,
        h2_etat_du_systeme: cockpitNotes.notes.h2EtatDuSysteme,
        impossible_de_lire: cockpitNotes.notes.impossibleDeLire,
        impossible_etat: cockpitNotes.notes.impossibleEtat,
        impossible_interrupteurs: cockpitNotes.notes.impossibleInterrupteurs,
        erreur_text: cockpitNotes.notes.systemHealthErreurText,
      },
      blockers,
    };
    fs.writeFileSync(path.join(OUT, 'wave-E-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
    console.log(JSON.stringify(summary, null, 2));
  } catch (err) {
    blockers.push(String(err && err.stack ? err.stack : err));
    try {
      await saveQuartet(page, consoleBuf, networkBuf, '99-error', { error: String(err) });
    } catch (_) { /* ignore */ }
    fs.writeFileSync(path.join(OUT, 'wave-E-error.txt'), String(err && err.stack ? err.stack : err), 'utf8');
    console.error(err);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
