/* Phase 4: hidden tabs with overlay-wait + remaining interactions */
const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';
const log = (o) => { fs.appendFileSync(OUT + '/db4-phase4-progress.log', JSON.stringify(o) + '\n'); console.log(JSON.stringify(o).slice(0, 1000)); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const drain = () => { const c = [...sink.console], h = [...sink.http]; sink.console = []; sink.http = []; return { console: c, http: h }; };

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
  const overlayGone = async (ms) => {
    try {
      await page.waitForFunction(() => {
        const ov = document.querySelector('.velmld-overlay, .velmld-full-screen');
        return !ov || getComputedStyle(ov).display === 'none' || !ov.checkVisibility();
      }, { timeout: ms });
      return true;
    } catch { return false; }
  };
  const ensure = async (path) => {
    for (let i = 0; i < 4; i++) {
      await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
      await page.waitForTimeout(800);
      if (!page.url().includes('/login')) return true;
      await login();
    }
    return !page.url().includes('/login');
  };
  const content = () => page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.db-card'));
    return cards.slice(1).map(c => c.innerText).join('\n---\n').slice(0, 2500);
  });

  await login(); drain();

  for (const [slug, path] of [
    ['license', '/admin/settings/license'],
    ['languages', '/admin/settings/languages/list'],
    ['cookies', '/admin/settings/cookies'],
    ['analytics', '/admin/settings/analytics/list'],
    ['payment-terminals', '/admin/settings/payment-terminals'],
  ]) {
    const ok = await ensure(path);
    const og = await overlayGone(12000);
    await page.waitForTimeout(500);
    const c = await content();
    await page.screenshot({ path: `${OUT}/D-B4-settings-${slug}.png`, fullPage: true });
    log({ step: 'render4', slug, ok, overlayGone: og, finalUrl: page.url(), ...drain(), content: c.slice(0, 1500) });
  }

  // kiosk machines: status DOM + edit modal
  if (await ensure('/admin/settings/kiosk-machines/list')) {
    await overlayGone(12000);
    const cells = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).slice(0, 2).map(r =>
      Array.from(r.querySelectorAll('td')).map(td => td.innerHTML.replace(/\s+/g, ' ').slice(0, 130))));
    log({ step: 'kiosk-status-dom', nrows: cells.length, cells: cells[0], ...drain() });
    const editBtn = page.locator('table tbody tr').first().locator('button, a').filter({ hasText: 'Modifier' }).first();
    if (await editBtn.count()) {
      await editBtn.click({ timeout: 8000 }).catch(e => log({ step: 'kiosk-edit-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${OUT}/D-B4-settings-kiosk-machines-edit-modal.png` });
      const mt = await page.evaluate(() => { const m = Array.from(document.querySelectorAll('[id*=modal], .modal')).find(x => x.innerText.trim()); return m ? m.innerText.slice(0, 800) : 'NO_MODAL'; });
      log({ step: 'kiosk-edit-modal', modalText: mt, ...drain() });
    } else log({ step: 'kiosk-edit-modal', modalText: 'NO_EDIT_BTN', tableHtml: await page.evaluate(() => (document.querySelector('table tbody tr td:last-child') || {}).innerHTML || 'NO_TABLE') });
  }

  // branches voir
  if (await ensure('/admin/settings/branches/list')) {
    await overlayGone(12000);
    const voir = page.locator('table tbody').locator('a, button').filter({ hasText: 'Voir' }).first();
    if (await voir.count()) {
      await voir.click({ timeout: 8000 }).catch(e => log({ step: 'branch-voir-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(1000); await overlayGone(10000); await page.waitForTimeout(500);
      await page.screenshot({ path: `${OUT}/D-B4-settings-branch-show.png`, fullPage: true });
      log({ step: 'branch-show', finalUrl: page.url(), content: (await content()).slice(0, 1500), ...drain() });
    } else log({ step: 'branch-show', err: 'NO_VOIR', table: await page.evaluate(() => (document.querySelector('table') || {}).innerText || 'NO_TABLE') });
  }

  // currencies add modal
  if (await ensure('/admin/settings/currencies/list')) {
    await overlayGone(12000);
    const add = page.locator('button, a').filter({ hasText: 'Ajouter Une Devise' }).first();
    if (await add.count()) {
      await add.click({ timeout: 8000 }).catch(e => log({ step: 'currency-add-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(1200);
      await page.screenshot({ path: `${OUT}/D-B4-settings-currencies-add-modal.png` });
      const mt = await page.evaluate(() => { const m = Array.from(document.querySelectorAll('[id*=modal], .modal')).find(x => x.innerText.trim()); return m ? m.innerText.slice(0, 700) : 'NO_MODAL'; });
      log({ step: 'currency-add-modal', modalText: mt, ...drain() });
    }
  }

  // order-setup selects/toggles
  if (await ensure('/admin/settings/order-setup')) {
    await overlayGone(12000);
    const inputs = await page.evaluate(() => Array.from(document.querySelectorAll('.db-card input, .db-card select')).map(i => ({ id: i.id || i.name, type: i.type || i.tagName, value: String(i.value).slice(0, 30) })).slice(0, 15));
    log({ step: 'order-setup-inputs', inputs, ...drain() });
  }

  await browser.close();
  console.log('PHASE4 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
