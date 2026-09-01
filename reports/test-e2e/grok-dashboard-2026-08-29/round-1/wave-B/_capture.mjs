/**
 * Wave B isolated Playwright capture — Cockpit État du système.
 * Does NOT use repo playwright.config.js / MCP. Does NOT toggle interrupteurs.
 */
import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT = __dirname;
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';

function isInterestingNet(entry) {
  if (entry.failed) return true;
  const s = entry.status;
  return typeof s === 'number' && (s >= 400 || s === 0);
}

async function main() {
  await fs.mkdir(OUT, { recursive: true });
  const consoleEvents = [];
  const networkEvents = [];
  const allNetwork = [];

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    baseURL: BASE,
  });
  const page = await context.newPage();

  page.on('dialog', async (dialog) => {
    await dialog.dismiss();
  });
  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error' || type === 'warning') {
      consoleEvents.push({
        type,
        text: msg.text(),
        location: msg.location(),
        ts: new Date().toISOString(),
      });
    }
  });
  page.on('pageerror', (err) => {
    consoleEvents.push({
      type: 'pageerror',
      text: String(err?.message || err),
      stack: err?.stack || null,
      ts: new Date().toISOString(),
    });
  });
  page.on('requestfailed', (req) => {
    const entry = {
      url: req.url(),
      method: req.method(),
      status: 0,
      failed: true,
      failure: req.failure()?.errorText || 'requestfailed',
      resourceType: req.resourceType(),
      ts: new Date().toISOString(),
    };
    allNetwork.push(entry);
    networkEvents.push(entry);
  });
  page.on('response', async (res) => {
    const req = res.request();
    const entry = {
      url: res.url(),
      method: req.method(),
      status: res.status(),
      failed: false,
      resourceType: req.resourceType(),
      ts: new Date().toISOString(),
    };
    allNetwork.push(entry);
    if (isInterestingNet(entry)) networkEvents.push(entry);
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

  const healthRespPromise = page.waitForResponse(
    (res) => /\/admin\/observability\/system-health/i.test(res.url()),
    { timeout: 25_000 },
  );
  await page.goto(`${BASE}/admin/observability/system`, {
    waitUntil: 'domcontentloaded',
    timeout: 30_000,
  });
  await page.locator('[data-testid="system-health"]').waitFor({ state: 'visible', timeout: 20_000 });
  let healthStatus = null;
  try {
    const healthResp = await healthRespPromise;
    healthStatus = healthResp.status();
  } catch (e) {
    healthStatus = `wait-failed: ${e.message}`;
  }

  await page.waitForFunction(
    () => {
      const root = document.querySelector('[data-testid="system-health"]');
      if (!root) return false;
      const busy = root.getAttribute('aria-busy');
      if (busy === 'true') return false;
      return !!(
        document.querySelector('[data-testid="system-health-verdict"]') ||
        document.querySelector('[data-testid="system-health-erreur"]')
      );
    },
    { timeout: 20_000 },
  ).catch(() => {});

  const refresh = page.locator('[data-testid="system-health-refresh"]');
  if (await refresh.isVisible().catch(() => false)) {
    const refreshHealth = page.waitForResponse(
      (res) => /\/admin\/observability\/system-health/i.test(res.url()),
      { timeout: 15_000 },
    );
    await refresh.click();
    await refreshHealth.catch(() => {});
    await page.waitForFunction(
      () => document.querySelector('[data-testid="system-health"]')?.getAttribute('aria-busy') !== 'true',
      { timeout: 15_000 },
    ).catch(() => {});
  }

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
    const impossible = [];
    const re = /Impossible de lire[^\n.]*(?:\.|$)/gi;
    let m;
    while ((m = re.exec(body))) impossible.push(m[0].trim());
    const queueEl = document.querySelector('[data-testid="system-health-controle-queue_pending"]');
    const queueText = queueEl ? (queueEl.innerText || '').trim() : null;
    let queuePending = null;
    if (queueText) {
      const num = queueText.match(/(\d+)\s+en attente/i);
      const unk = /mesure impossible|unknown/i.test(queueText);
      queuePending = unk ? 'mesure impossible' : (num ? Number(num[1]) : queueText);
    }
    const interrupteurs = Array.from(document.querySelectorAll('[data-testid^="interrupteur-bouton-"]')).map((btn) => ({
      testid: btn.getAttribute('data-testid'),
      pressed: btn.getAttribute('aria-pressed'),
      label: (btn.innerText || '').trim(),
    }));
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
      impossibleDeLire: impossible,
      interrupteurs,
      bodySnippet: body.slice(0, 4000),
    };
  });

  const pngPath = path.join(OUT, '01-cockpit.png');
  const domPath = path.join(OUT, '01-cockpit.dom.html');
  const consolePath = path.join(OUT, '01-cockpit.console.json');
  const networkPath = path.join(OUT, '01-cockpit.network.json');
  const extractPath = path.join(OUT, '01-cockpit.extract.json');

  await page.screenshot({ path: pngPath, fullPage: true });
  const html = await page.content();
  await fs.writeFile(domPath, html, 'utf8');
  await fs.writeFile(consolePath, JSON.stringify(consoleEvents, null, 2), 'utf8');
  await fs.writeFile(
    networkPath,
    JSON.stringify(
      {
        interesting: networkEvents,
        healthStatus,
        totals: {
          responses: allNetwork.length,
          interesting: networkEvents.length,
        },
      },
      null,
      2,
    ),
    'utf8',
  );
  await fs.writeFile(
    extractPath,
    JSON.stringify({ ...extract, healthStatus, finalUrl: page.url() }, null, 2),
    'utf8',
  );

  console.log(JSON.stringify({
    ok: true,
    png: pngPath,
    dom: domPath,
    console: consolePath,
    network: networkPath,
    extract: extractPath,
    url: page.url(),
    queuePending: extract.queuePending,
    backup: extract.backup,
    scheduler: extract.scheduler,
    impossibleDeLire: extract.impossibleDeLire,
    erreur: extract.erreur,
    verdict: extract.verdict,
  }, null, 2));

  await browser.close();
}

main().catch(async (err) => {
  console.error(err);
  process.exit(1);
});
