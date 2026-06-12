/* Phase 1 sweep: every settings tab — load timing, console, http>=400, text dump, a11y, screenshot */
const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';

const TABS = [
  ['company', '/admin/settings/company', 'visible'],
  ['site', '/admin/settings/site', 'visible'],
  ['branches', '/admin/settings/branches/list', 'visible'],
  ['kiosk-machines', '/admin/settings/kiosk-machines/list', 'visible'],
  ['order-setup', '/admin/settings/order-setup', 'visible'],
  ['kiosk-setup', '/admin/settings/kiosk-setup', 'visible'],
  ['currencies', '/admin/settings/currencies/list', 'visible'],
  ['loyalty-setup', '/admin/settings/loyalty-setup', 'hiddenV1'],
  ['mail', '/admin/settings/mail', 'hiddenV1'],
  ['notification', '/admin/settings/notification', 'hiddenV1'],
  ['notification-alert', '/admin/settings/notification-alert', 'hiddenV1'],
  ['license', '/admin/settings/license', 'hiddenV1'],
  ['languages', '/admin/settings/languages/list', 'hiddenV1'],
  ['cookies', '/admin/settings/cookies', 'hiddenV1'],
  ['analytics', '/admin/settings/analytics/list', 'hiddenV1'],
  ['payment-terminals', '/admin/settings/payment-terminals', 'hiddenV1'],
];

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  let sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  // login
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  sink = { console: [], http: [] };
  page.removeAllListeners('console'); page.removeAllListeners('pageerror'); page.removeAllListeners('response');
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  const results = [];
  let first = true;
  for (const [slug, path, vis] of TABS) {
    sink.console = []; sink.http = [];
    const t0 = Date.now();
    let navMode;
    // SPA in-app nav via menu link when visible & not first; else direct goto
    const link = page.locator(`nav a[href="${path.replace('/list','')}"], nav a[href="${path}"]`).first();
    try {
      if (!first && vis === 'visible' && await link.count() > 0 && await link.isVisible()) {
        navMode = 'spa-click';
        await Promise.all([
          page.waitForLoadState('networkidle'),
          link.click(),
        ]);
      } else {
        navMode = 'goto';
        await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
      }
    } catch (e) {
      results.push({ slug, path, error: 'NAV_FAIL ' + String(e).slice(0, 200), console: sink.console, http: sink.http });
      continue;
    }
    first = false;
    // wait for loading overlay to clear (best effort)
    await page.waitForTimeout(1500);
    const loadMs = Date.now() - t0;
    const info = await page.evaluate(() => {
      const content = document.querySelector('main') || document.body;
      const inputs = Array.from(document.querySelectorAll('input:not([type=hidden]), select, textarea'));
      const unlabeled = inputs.filter(el => {
        if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('placeholder')) return false;
        if (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`)) return false;
        if (el.closest('label')) return false;
        return true;
      }).length;
      const navLinks = Array.from(document.querySelectorAll('a')).filter(a => (a.getAttribute('href')||'').startsWith('/admin/settings')).map(a => a.innerText.trim()).filter(Boolean);
      return {
        url: location.href,
        title: (document.querySelector('h1,h2,h3')||{}).innerText || '',
        text: content.innerText.slice(0, 6000),
        inputCount: inputs.length, unlabeled,
        settingsNav: [...new Set(navLinks)],
        buttons: Array.from(document.querySelectorAll('main button, .db-card button')).map(b => b.innerText.trim()).filter(Boolean).slice(0, 40),
      };
    });
    await page.screenshot({ path: `${OUT}/D-B4-settings-${slug}.png`, fullPage: true });
    results.push({ slug, path, vis, navMode, loadMs, finalUrl: info.url, title: info.title, inputCount: info.inputCount, unlabeled: info.unlabeled, buttons: info.buttons, settingsNav: info.settingsNav, console: [...sink.console], http: [...sink.http], text: info.text });
    fs.appendFileSync(OUT + '/db4-sweep-progress.log', JSON.stringify({ slug, loadMs, console: sink.console, http: sink.http }) + '\n');
  }
  fs.writeFileSync(OUT + '/db4-sweep-results.json', JSON.stringify(results, null, 1));
  console.log('DONE', results.length, 'tabs');
  for (const r of results) console.log(r.slug, '| nav', r.navMode, '|', r.loadMs + 'ms', '| console', (r.console||[]).length, '| http', (r.http||[]).length, '| unlabeled', r.unlabeled, '|', (r.title||'').slice(0,40), r.error||'');
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
