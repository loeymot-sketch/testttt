/* Phase 2: proper render of goto-tabs + interactions */
const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';
const log = (o) => { fs.appendFileSync(OUT + '/db4-phase2-progress.log', JSON.stringify(o) + '\n'); console.log(JSON.stringify(o)); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const drain = () => { const c = [...sink.console], h = [...sink.http]; sink.console = []; sink.http = []; return { console: c, http: h }; };

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
  drain();

  const renderWait = async () => {
    try { await page.waitForFunction(() => document.body.innerText.length > 700, { timeout: 15000 }); } catch (e) {}
    await page.waitForTimeout(800);
  };
  const dump = async (slug) => {
    const info = await page.evaluate(() => {
      const card = Array.from(document.querySelectorAll('.db-card')).slice(1); // skip menu card
      return {
        url: location.href,
        text: document.body.innerText.length,
        content: (card.map(c => c.innerText).join('\n---\n')).slice(0, 5000),
      };
    });
    await page.screenshot({ path: `${OUT}/D-B4-settings-${slug}.png`, fullPage: true });
    return info;
  };

  // ---- A) re-render goto tabs
  for (const [slug, path] of [
    ['company', '/admin/settings/company'],
    ['loyalty-setup', '/admin/settings/loyalty-setup'],
    ['mail', '/admin/settings/mail'],
    ['notification', '/admin/settings/notification'],
    ['notification-alert', '/admin/settings/notification-alert'],
    ['license', '/admin/settings/license'],
    ['languages', '/admin/settings/languages/list'],
    ['cookies', '/admin/settings/cookies'],
    ['analytics', '/admin/settings/analytics/list'],
    ['payment-terminals', '/admin/settings/payment-terminals'],
  ]) {
    const t0 = Date.now();
    await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
    await renderWait();
    const info = await dump(slug);
    log({ step: 'render', slug, loadMs: Date.now() - t0, finalUrl: info.url, ...drain(), content: info.content.slice(0, 2200) });
  }

  // ---- B) kiosk-machines: STATUT cell DOM + Modifier modal
  await page.goto(BASE + '/admin/settings/kiosk-machines/list', { waitUntil: 'networkidle' });
  await renderWait();
  const statusCells = await page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('table tbody tr')).slice(0, 3);
    return rows.map(r => {
      const tds = Array.from(r.querySelectorAll('td'));
      return tds.map(td => ({ text: td.innerText.trim().slice(0, 40), html: td.innerHTML.replace(/\s+/g, ' ').slice(0, 160) }));
    });
  });
  log({ step: 'kiosk-status-cells', statusCells, ...drain() });
  // open Modifier modal on first row
  await page.locator('table tbody tr').first().getByText('Modifier').click().catch(e => log({ step: 'kiosk-modifier-clickfail', e: String(e).slice(0, 150) }));
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/D-B4-settings-kiosk-machines-edit-modal.png` });
  const modalText = await page.evaluate(() => { const m = document.querySelector('.modal, [class*=modal]'); return m ? m.innerText.slice(0, 1500) : 'NO_MODAL'; });
  log({ step: 'kiosk-modifier-modal', modalText, ...drain() });
  await page.keyboard.press('Escape'); await page.waitForTimeout(500);
  const closeBtn = page.locator('.modal button.modal-close, .modal [class*=close]').first();
  if (await closeBtn.count()) await closeBtn.click().catch(() => {});
  await page.waitForTimeout(500);

  // ---- C) branches: Voir
  await page.goto(BASE + '/admin/settings/branches/list', { waitUntil: 'networkidle' });
  await renderWait();
  await page.getByText('Voir', { exact: false }).first().click().catch(e => log({ step: 'branch-voir-clickfail', e: String(e).slice(0, 150) }));
  await page.waitForTimeout(2000);
  const bInfo = await dump('branch-show');
  log({ step: 'branch-show', finalUrl: bInfo.url, content: bInfo.content.slice(0, 2200), ...drain() });

  // ---- D) currencies: Modifier modal + Ajouter modal
  await page.goto(BASE + '/admin/settings/currencies/list', { waitUntil: 'networkidle' });
  await renderWait();
  await page.getByText('Ajouter Une Devise').first().click().catch(e => log({ step: 'currency-add-clickfail', e: String(e).slice(0, 150) }));
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/D-B4-settings-currencies-add-modal.png` });
  const curModal = await page.evaluate(() => { const m = document.querySelector('.modal, [class*=modal]'); return m ? m.innerText.slice(0, 1200) : 'NO_MODAL'; });
  log({ step: 'currency-add-modal', curModal, ...drain() });
  await page.keyboard.press('Escape'); await page.waitForTimeout(400);

  // ---- E) company idempotent save
  await page.goto(BASE + '/admin/settings/company', { waitUntil: 'networkidle' });
  await renderWait();
  const before = await page.evaluate(() => Array.from(document.querySelectorAll('.db-card input')).map(i => i.value));
  await page.getByRole('button', { name: /Enregistrer/i }).click().catch(e => log({ step: 'company-save-clickfail', e: String(e).slice(0, 150) }));
  await page.waitForTimeout(2500);
  const toast = await page.evaluate(() => {
    const t = document.querySelector('[class*=toast], [class*=Toast], [class*=alert], [class*=notif]');
    return t ? t.innerText.trim().slice(0, 200) : 'NO_TOAST';
  });
  await page.screenshot({ path: `${OUT}/D-B4-settings-company-after-save.png` });
  log({ step: 'company-save', toast, before: before.slice(0, 8), ...drain() });

  await browser.close();
  console.log('PHASE2 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
