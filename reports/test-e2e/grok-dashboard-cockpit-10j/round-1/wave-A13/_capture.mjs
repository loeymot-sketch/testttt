/**
 * Wave A13 isolated Playwright capture — cockpit A12/A13 (État du système).
 * Playwright CLI Chromium, not MCP. Does NOT toggle interrupteurs. No orders.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);

const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-1/wave-A13');
const PNG = path.join(OUT, '01-health.png');

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
  spawnSync(
    'php',
    ['artisan', 'tinker', '--execute', `
      $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
      $keys = [
        '127.0.0.1','::1','localhost',
        'admin@lecayenne.fr|127.0.0.1','admin@lecayenne.fr|::1',
      ];
      foreach (['api','login-lockout'] as $name) {
        foreach ($keys as $key) { $limiter->clear(md5($name.$key)); }
      }
      echo 'ok';
    `],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
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

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  clearRateLimits();

  const consoleEvents = [];
  const networkEvents = [];

  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();

  page.on('dialog', async (dialog) => {
    await dialog.dismiss();
  });
  page.on('console', (msg) => {
    const type = msg.type();
    if (!['error', 'warning', 'assert'].includes(type)) return;
    const text = msg.text();
    if (/WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i.test(text)) return;
    if (/^Pusher\s*:/i.test(text)) return;
    consoleEvents.push({
      level: type,
      text: text.substring(0, 4000),
      location: msg.location(),
      ts: new Date().toISOString(),
    });
  });
  page.on('pageerror', (err) => {
    consoleEvents.push({
      level: 'pageerror',
      text: String(err && err.message ? err.message : err).substring(0, 4000),
      stack: String(err && err.stack ? err.stack : '').substring(0, 6000),
      ts: new Date().toISOString(),
    });
  });
  page.on('response', (res) => {
    const status = res.status();
    if (status < 400) return;
    const url = res.url();
    networkEvents.push({
      kind: 'http',
      url: url.substring(0, 500),
      method: res.request().method(),
      status,
      resourceType: res.request().resourceType(),
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: new Date().toISOString(),
    });
  });
  page.on('requestfailed', (req) => {
    const url = req.url();
    const failure = req.failure();
    networkEvents.push({
      kind: 'failed',
      url: url.substring(0, 500),
      method: req.method(),
      status: 0,
      resourceType: req.resourceType(),
      errorText: failure ? failure.errorText : 'requestfailed',
      ws_or_debugbar: isWsOrDebugbar(url),
      ts: new Date().toISOString(),
    });
  });

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASSWORD);
  const loginRespPromise = page.waitForResponse(
    (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
    { timeout: 25_000 },
  );
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  const loginResp = await loginRespPromise;
  if (loginResp.status() !== 201) {
    throw new Error(`Login API HTTP ${loginResp.status()}`);
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1200);

  if (/\/admin\/pos(\/|$|\?)/.test(page.url()) || !/\/admin\/dashboard/.test(page.url())) {
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }

  await page.waitForURL(/\/admin\/dashboard/, { timeout: 25_000 });
  await page.getByText(/Ventes du jour/i).first().waitFor({ state: 'visible', timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(600);

  const navHow = { method: null, href: null };
  const sidebarLink = page.locator('aside a, aside button, aside .db-sidebar-nav-menu').filter({
    hasText: /[ÉE]tat du syst[eè]me/i,
  }).first();
  const sidebarVisible = await sidebarLink.isVisible().catch(() => false);
  if (sidebarVisible) {
    await sidebarLink.scrollIntoViewIfNeeded().catch(() => {});
    await sidebarLink.click();
    navHow.method = 'sidebar-click';
    navHow.href = await sidebarLink.getAttribute('href').catch(() => null);
  } else {
    await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    navHow.method = 'direct-goto-fallback';
  }

  await page.waitForURL(/\/admin\/observability\/system/, { timeout: 20_000 }).catch(() => {});
  await page.locator('[data-testid="system-health"]').waitFor({ state: 'visible', timeout: 25_000 });
  await page.waitForFunction(
    () => {
      const root = document.querySelector('[data-testid="system-health"]');
      if (!root) return false;
      if (root.getAttribute('aria-busy') === 'true') return false;
      return !!(
        document.querySelector('[data-testid="system-health-verdict"]')
        || document.querySelector('[data-testid="system-health-erreur"]')
      );
    },
    { timeout: 20_000 },
  ).catch(() => {});
  await page.locator('[data-testid="system-interrupteurs"]').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
  await page.waitForTimeout(500);

  await expandInnerOverflow(page);
  await page.waitForTimeout(250);

  const extract = await page.evaluate(() => {
    const t = (sel) => {
      const el = document.querySelector(sel);
      return el ? (el.innerText || el.textContent || '').trim() : null;
    };
    const root = document.querySelector('[data-testid="system-health"]');
    const healthText = root ? (root.innerText || '') : '';
    const body = (document.body && document.body.innerText) || '';

    const queueEl = document.querySelector('[data-testid="system-health-controle-queue_pending"]');
    const queueText = queueEl ? (queueEl.innerText || '').trim() : null;
    let queuePending = null;
    if (queueText) {
      const num = queueText.match(/(\d+)\s+en attente/i);
      const unk = /mesure impossible|unknown/i.test(queueText);
      queuePending = unk ? 'mesure impossible' : (num ? Number(num[1]) : queueText);
    }

    const interrupteurRows = Array.from(document.querySelectorAll('[data-testid^="interrupteur-"]'))
      .filter((el) => /^interrupteur-[a-z0-9_]+$/i.test(el.getAttribute('data-testid') || ''));
    const interrupteurs = interrupteurRows.map((row) => {
      const btn = row.querySelector('[data-testid^="interrupteur-bouton-"]');
      const nameEl = row.querySelector('p.font-medium, .font-medium');
      return {
        testid: row.getAttribute('data-testid'),
        nom: (btn && (btn.getAttribute('data-testid') || '').replace(/^interrupteur-bouton-/, '')) || null,
        libelle: nameEl ? (nameEl.innerText || '').trim() : null,
        pressed: btn ? btn.getAttribute('aria-pressed') : null,
        etat: btn ? (btn.innerText || '').trim() : null,
        description: (() => {
          const ps = Array.from(row.querySelectorAll('p'));
          return ps[1] ? (ps[1].innerText || '').trim() : null;
        })(),
      };
    });

    const interrupteursLead = t('[data-testid="system-interrupteurs"] p');
    const nf525Lead = /journal fiscal NF525/i.test(healthText) || /journal fiscal NF525/i.test(body);
    const nf525Chain = /Intégrité NF525/i.test(healthText);
    const consigneJournal = /Consigne dans le journal serveur/i.test(healthText);

    return {
      url: location.href,
      title: document.title,
      verdict: t('[data-testid="system-health-verdict"]'),
      mesure: t('[data-testid="system-health-mesure"]'),
      erreur: t('[data-testid="system-health-erreur"]'),
      queueBlock: queueText,
      queuePending,
      backup: t('[data-testid="system-health-sauvegarde"]'),
      scheduler: t('[data-testid="system-health-planificateur"]'),
      controles: ['db', 'redis', 'websocket', 'fiscal_chain', 'queue_pending'].map((cle) => ({
        cle,
        text: t(`[data-testid="system-health-controle-${cle}"]`),
      })),
      interrupteursLead,
      interrupteurs,
      nf525: {
        interrupteursLeadHasNf525: nf525Lead,
        fiscalChainCopy: nf525Chain,
        consigneJournalServeur: consigneJournal,
        leadText: interrupteursLead,
        fiscalChainText: t('[data-testid="system-health-controle-fiscal_chain"]'),
      },
      healthSnippet: healthText.slice(0, 6000),
    };
  });

  await page.screenshot({ path: PNG, fullPage: true });

  const health = page.locator('[data-testid="system-health"]');
  const panelPng = path.join(OUT, '01-health-panel.png');
  if (await health.count()) {
    await health.screenshot({ path: panelPng }).catch(() => {});
  }

  const domPath = path.join(OUT, '01-health.dom.html');
  const consolePath = path.join(OUT, '01-health.console.json');
  const networkPath = path.join(OUT, '01-health.network.json');
  const extractPath = path.join(OUT, '01-health.extract.json');

  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleEvents, null, 2), 'utf8');
  fs.writeFileSync(
    networkPath,
    JSON.stringify(
      {
        interesting: networkEvents.filter((e) => !e.ws_or_debugbar),
        all_including_noise: networkEvents,
      },
      null,
      2,
    ),
    'utf8',
  );
  fs.writeFileSync(
    extractPath,
    JSON.stringify({ ...extract, navHow, finalUrl: page.url(), png: PNG, panelPng }, null, 2),
    'utf8',
  );

  const summary = {
    wave: 'A13',
    base_url: BASE,
    values: {
      queuePending: extract.queuePending,
      backup: extract.backup,
      scheduler: extract.scheduler,
      interrupteurNames: extract.interrupteurs.map((i) => i.libelle || i.nom),
      interrupteurs: extract.interrupteurs,
      nf525: extract.nf525,
      verdict: extract.verdict,
    },
    states: [
      {
        name: 'health',
        url: page.url(),
        png: PNG,
        dom: domPath,
        console: consolePath,
        network: networkPath,
        notes: `nav=${navHow.method}. No interrupteur toggle. queue=${extract.queuePending}. backup=${(extract.backup || '').split('\n')[2] || extract.backup}. scheduler=${(extract.scheduler || '').split('\n')[2] || extract.scheduler}. NF525 lead=${extract.nf525 && extract.nf525.interrupteursLeadHasNf525}.`,
      },
    ],
    blockers: [],
  };
  fs.writeFileSync(path.join(OUT, 'wave-A13-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
  console.log(JSON.stringify(summary, null, 2));

  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
