/**
 * Wave B recapture: element screenshot of [data-testid=system-health]
 * so header + interrupteurs copy are both in 01-cockpit.png.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const require = createRequire('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-2/wave-B');

function clearRateLimits() {
  spawnSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      `
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    foreach (['api','login-lockout'] as $name) {
      foreach (['admin@lecayenne.fr|127.0.0.1','127.0.0.1','::1'] as $key) {
        $limiter->clear(md5($name.$key));
      }
    }
    echo 'ok';
  `,
    ],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
  );
}

(async () => {
  clearRateLimits();
  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 2200 },
    locale: 'fr-FR',
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  page.on('dialog', (d) => d.dismiss());

  const consoleBuf = [];
  const networkBuf = [];
  const loadedJs = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      consoleBuf.push({ type: msg.type(), text: msg.text(), loc: msg.location(), ts: Date.now() });
    }
  });
  page.on('pageerror', (err) => consoleBuf.push({ type: 'pageerror', text: String(err), ts: Date.now() }));
  page.on('requestfailed', (req) => {
    networkBuf.push({ kind: 'failed', url: req.url(), method: req.method(), failure: req.failure()?.errorText, ts: Date.now() });
  });
  page.on('response', (res) => {
    const url = res.url();
    if (/admin-shell|app\.js/.test(url)) loadedJs.push({ url, status: res.status() });
    if (res.status() >= 400) {
      networkBuf.push({ kind: 'http', url, method: res.request().method(), status: res.status(), ts: Date.now() });
    }
  });

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  const loginResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()), { timeout: 25_000 });
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  const lr = await loginResp;
  if (lr.status() !== 201) throw new Error(`login ${lr.status()}`);
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(800);
  if (/\/admin\/pos(\/|$|\?)/.test(page.url())) {
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }

  await page.goto(`${BASE}/admin/observability/system`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('[data-testid="system-health"]').waitFor({ state: 'visible', timeout: 20_000 });
  await page.waitForFunction(
    () => document.querySelector('[data-testid="system-health"]')?.getAttribute('aria-busy') !== 'true',
    { timeout: 20_000 },
  ).catch(() => {});
  await page.waitForTimeout(800);

  const png = path.join(OUT, '01-cockpit.png');
  const health = page.locator('[data-testid="system-health"]');
  await health.screenshot({ path: png });

  const extract = await page.evaluate(() => {
    const root = document.querySelector('[data-testid="system-health"]');
    const text = (root && (root.innerText || '')) || '';
    const body = document.body.innerText || '';
    return {
      url: location.href,
      consigneJournal: /Consigne dans le journal serveur/i.test(text),
      chaqueBascule: /Chaque bascule est tracée/i.test(text) || /Chaque bascule est tracée/i.test(body),
      mesureIndisponible: /mesure indisponible/i.test(text),
      aucuneInHealth: /\baucune\b/i.test(text),
      h2: (document.querySelector('[data-testid="system-health"] h2') || {}).innerText || null,
      interrupteursLead: (document.querySelector('[data-testid="system-interrupteurs"] p') || {}).innerText || null,
      scripts: Array.from(document.querySelectorAll('script[src]')).map((s) => s.src).filter((s) => /admin-shell|app\.js/.test(s)),
    };
  });

  fs.writeFileSync(path.join(OUT, '01-cockpit.dom.html'), await page.content(), 'utf8');
  fs.writeFileSync(path.join(OUT, '01-cockpit.console.json'), JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(
    path.join(OUT, '01-cockpit.network.json'),
    JSON.stringify({ interesting: networkBuf, loadedJs }, null, 2),
    'utf8',
  );
  fs.writeFileSync(
    path.join(OUT, '01-cockpit.extract.json'),
    JSON.stringify({ ...extract, loadedJs, pngSizeHint: 'element screenshot system-health' }, null, 2),
    'utf8',
  );

  const summary = {
    wave: 'B-admin-cockpit',
    base_url: BASE,
    mix_js_network: loadedJs,
    consigne_journal_serveur: extract.consigneJournal,
    chaque_bascule_est_tracee: extract.chaqueBascule,
    states: [
      {
        name: 'admin-cockpit',
        url: page.url(),
        png,
        dom: path.join(OUT, '01-cockpit.dom.html'),
        console: path.join(OUT, '01-cockpit.console.json'),
        network: path.join(OUT, '01-cockpit.network.json'),
        notes: `element screenshot of [data-testid=system-health]. Consigne=${extract.consigneJournal} ChaqueBascule=${extract.chaqueBascule} lead=${extract.interrupteursLead}`,
      },
    ],
    blockers: [],
  };
  fs.writeFileSync(path.join(OUT, 'wave-B-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
  console.log(JSON.stringify(summary, null, 2));
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
