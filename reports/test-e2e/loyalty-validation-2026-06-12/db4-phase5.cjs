/* Phase 5: languages trace + missed interactions (kiosk edit, branch voir, currency modal, order-setup, analytics) */
const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';
const log = (o) => { fs.appendFileSync(OUT + '/db4-phase5-progress.log', JSON.stringify(o) + '\n'); console.log(JSON.stringify(o).slice(0, 1000)); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const api = [];
  const sink = { console: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', async r => { if (r.url().includes('/api/')) api.push({ u: r.url().replace(BASE + '/api', '').slice(0, 80), s: r.status() }); });
  page.on('requestfailed', r => { if (r.url().includes('/api/')) api.push({ u: r.url().replace(BASE + '/api', '').slice(0, 80), s: 'FAIL ' + (r.failure() || {}).errorText }); });
  const drain = () => { const a = [...api], c = [...sink.console]; api.length = 0; sink.console.length = 0; return { api: a, console: c }; };

  const login = async () => {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(1000);
    if (!page.url().includes('/login')) return;
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');
  };
  const overlayGone = (ms) => page.waitForFunction(() => {
    const ov = document.querySelector('.velmld-overlay, .velmld-full-screen');
    return !ov || getComputedStyle(ov).display === 'none' || !ov.checkVisibility();
  }, { timeout: ms }).then(() => true).catch(() => false);
  const ensure = async (path) => {
    for (let i = 0; i < 4; i++) {
      await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
      await page.waitForTimeout(600);
      if (!page.url().includes('/login')) { await overlayGone(12000); await page.waitForTimeout(600); return true; }
      await login();
    }
    return false;
  };

  await login(); drain();

  // 1) languages full trace
  if (await ensure('/admin/settings/languages/list')) {
    await page.waitForTimeout(2000);
    const t = await page.evaluate(() => ({
      table: (document.querySelector('table') || {}).innerText || 'NO_TABLE',
      pagText: (Array.from(document.querySelectorAll('p, div')).find(e => /Affichage/.test(e.innerText || '') && e.innerText.length < 80) || {}).innerText || 'NONE',
    }));
    await page.screenshot({ path: OUT + '/D-B4-settings-languages.png', fullPage: true });
    log({ step: 'languages-trace', finalUrl: page.url(), ...t, ...drain() });
  }

  // 2) analytics render
  if (await ensure('/admin/settings/analytics/list')) {
    const t = await page.evaluate(() => (document.querySelector('table') || {}).innerText || document.body.innerText.split('Devises').pop().slice(0, 800));
    await page.screenshot({ path: OUT + '/D-B4-settings-analytics.png', fullPage: true });
    log({ step: 'analytics-trace', finalUrl: page.url(), content: String(t).slice(0, 600), ...drain() });
  }

  // 3) kiosk machines edit modal + status DOM
  if (await ensure('/admin/settings/kiosk-machines/list')) {
    await page.waitForFunction(() => document.querySelectorAll('table tbody tr').length > 0, { timeout: 15000 }).catch(() => {});
    const cells = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).slice(0, 1).map(r =>
      Array.from(r.querySelectorAll('td')).map(td => td.innerHTML.replace(/\s+/g, ' ').slice(0, 140))));
    log({ step: 'kiosk-status-dom', cells, ...drain() });
    const editBtn = page.locator('table tbody tr').first().locator('button, a').filter({ hasText: 'Modifier' }).first();
    if (await editBtn.count()) {
      await editBtn.click({ timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(1500);
      await page.screenshot({ path: OUT + '/D-B4-settings-kiosk-machines-edit-modal.png' });
      const mt = await page.evaluate(() => { const m = Array.from(document.querySelectorAll('[id*=modal], .modal')).find(x => x.innerText.trim() && x.checkVisibility()); return m ? m.innerText.slice(0, 800) : 'NO_MODAL'; });
      log({ step: 'kiosk-edit-modal', modalText: mt, ...drain() });
    }
  }

  // 4) branch voir
  if (await ensure('/admin/settings/branches/list')) {
    await page.waitForFunction(() => document.querySelectorAll('table tbody tr').length > 0, { timeout: 15000 }).catch(() => {});
    const voir = page.locator('table tbody').locator('a, button').filter({ hasText: 'Voir' }).first();
    if (await voir.count()) {
      await voir.click({ timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(1200); await overlayGone(10000); await page.waitForTimeout(800);
      await page.screenshot({ path: OUT + '/D-B4-settings-branch-show.png', fullPage: true });
      const c = await page.evaluate(() => document.body.innerText.split('Devises').pop().slice(0, 1500));
      log({ step: 'branch-show', finalUrl: page.url(), content: c, ...drain() });
    } else log({ step: 'branch-show', err: 'NO_VOIR_AGAIN' });
  }

  // 5) currency add modal
  if (await ensure('/admin/settings/currencies/list')) {
    const add = page.locator('button, a').filter({ hasText: 'Ajouter Une Devise' }).first();
    if (await add.count()) {
      await add.click({ timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(1200);
      await page.screenshot({ path: OUT + '/D-B4-settings-currencies-add-modal.png' });
      const mt = await page.evaluate(() => { const m = Array.from(document.querySelectorAll('[id*=modal], .modal')).find(x => x.innerText.trim() && x.checkVisibility()); return m ? m.innerText.slice(0, 700) : 'NO_MODAL'; });
      log({ step: 'currency-add-modal', modalText: mt, ...drain() });
    }
  }

  // 6) order-setup inputs
  if (await ensure('/admin/settings/order-setup')) {
    const inputs = await page.evaluate(() => Array.from(document.querySelectorAll('input:not([type=hidden]), select')).filter(i => !i.closest('nav') && !i.closest('header')).map(i => ({ id: (i.id || i.name || '').slice(0, 40), type: i.type || i.tagName, value: String(i.value).slice(0, 20) })).slice(0, 15));
    log({ step: 'order-setup-inputs', inputs, ...drain() });
  }

  await browser.close();
  console.log('PHASE5 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
