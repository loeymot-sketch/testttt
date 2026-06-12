/* Phase 3: remaining tabs + interactions, resilient to 401 token kills */
const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';
const log = (o) => { fs.appendFileSync(OUT + '/db4-phase3-progress.log', JSON.stringify(o) + '\n'); console.log(JSON.stringify(o).slice(0, 800)); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  page.on('requestfailed', r => { if (r.url().includes('/api/')) sink.http.push(`REQFAIL ${r.url().replace(BASE, '').slice(0, 80)} ${(r.failure() || {}).errorText}`); });
  const drain = () => { const c = [...sink.console], h = [...sink.http]; sink.console = []; sink.http = []; return { console: c, http: h }; };

  const login = async () => {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    if (!page.url().includes('/login')) return; // already logged
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
  };
  const ensure = async (path) => {
    for (let i = 0; i < 3; i++) {
      await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
      await page.waitForTimeout(2500);
      if (!page.url().includes('/login')) return true;
      log({ relogin: true, attempt: i, path });
      await login();
    }
    return !page.url().includes('/login');
  };
  const content = () => page.evaluate(() => Array.from(document.querySelectorAll('.db-card')).slice(1).map(c => c.innerText).join('\n---\n').slice(0, 3500));

  await login();
  drain();

  // A) remaining renders
  for (const [slug, path] of [
    ['license', '/admin/settings/license'],
    ['languages', '/admin/settings/languages/list'],
    ['cookies', '/admin/settings/cookies'],
    ['analytics', '/admin/settings/analytics/list'],
    ['payment-terminals', '/admin/settings/payment-terminals'],
  ]) {
    const t0 = Date.now();
    const ok = await ensure(path);
    const c = await content();
    await page.screenshot({ path: `${OUT}/D-B4-settings-${slug}.png`, fullPage: true });
    log({ step: 'render', slug, ok, loadMs: Date.now() - t0, finalUrl: page.url(), ...drain(), content: c.slice(0, 1800) });
  }

  // B) kiosk-machines STATUT DOM + Modifier modal
  if (await ensure('/admin/settings/kiosk-machines/list')) {
    const cells = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).slice(0, 2).map(r =>
      Array.from(r.querySelectorAll('td')).map(td => td.innerHTML.replace(/\s+/g, ' ').slice(0, 120))));
    log({ step: 'kiosk-status-dom', cells, ...drain() });
    const editBtn = page.locator('table tbody tr').first().locator('button, a').filter({ hasText: 'Modifier' }).first();
    if (await editBtn.count()) {
      await editBtn.click({ timeout: 8000 }).catch(e => log({ step: 'kiosk-edit-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${OUT}/D-B4-settings-kiosk-machines-edit-modal.png` });
      const mt = await page.evaluate(() => { const m = document.querySelector('[id*=modal], .modal'); return m && m.checkVisibility?.() !== false ? m.innerText.slice(0, 900) : 'NO_MODAL'; });
      log({ step: 'kiosk-edit-modal', modalText: mt, ...drain() });
    } else log({ step: 'kiosk-edit-modal', modalText: 'NO_EDIT_BTN' });
  }

  // C) branches Voir
  if (await ensure('/admin/settings/branches/list')) {
    const voir = page.locator('table tbody tr').first().locator('a, button').filter({ hasText: 'Voir' }).first();
    if (await voir.count()) {
      await voir.click({ timeout: 8000 }).catch(e => log({ step: 'branch-voir-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(2500);
      await page.screenshot({ path: `${OUT}/D-B4-settings-branch-show.png`, fullPage: true });
      log({ step: 'branch-show', finalUrl: page.url(), content: (await content()).slice(0, 1800), ...drain() });
    } else log({ step: 'branch-show', err: 'NO_VOIR' });
  }

  // D) currencies add modal
  if (await ensure('/admin/settings/currencies/list')) {
    const add = page.locator('button, a').filter({ hasText: 'Ajouter Une Devise' }).first();
    if (await add.count()) {
      await add.click({ timeout: 8000 }).catch(e => log({ step: 'currency-add-clickfail', e: String(e).slice(0, 120) }));
      await page.waitForTimeout(1200);
      await page.screenshot({ path: `${OUT}/D-B4-settings-currencies-add-modal.png` });
      const mt = await page.evaluate(() => { const m = document.querySelector('[id*=modal], .modal'); return m ? m.innerText.slice(0, 700) : 'NO_MODAL'; });
      log({ step: 'currency-add-modal', modalText: mt, ...drain() });
      await page.keyboard.press('Escape');
    }
  }

  // E) company idempotent save
  if (await ensure('/admin/settings/company')) {
    const vals = await page.evaluate(() => Array.from(document.querySelectorAll('.db-card input')).slice(0, 10).map(i => i.value));
    const save = page.locator('button').filter({ hasText: 'Enregistrer' }).first();
    await save.click({ timeout: 8000 }).catch(e => log({ step: 'company-save-clickfail', e: String(e).slice(0, 120) }));
    await page.waitForTimeout(2500);
    const toast = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('div, p, span')).filter(el => /succès|réussi|Success|mis à jour|enregistr/i.test(el.innerText || '') && el.innerText.length < 120);
      return els.length ? els[els.length - 1].innerText.trim().slice(0, 150) : 'NO_TOAST_TEXT';
    });
    await page.screenshot({ path: `${OUT}/D-B4-settings-company-after-save.png` });
    log({ step: 'company-save', vals, toast, ...drain() });
  }

  // F) order-setup: open a select + check options FR
  if (await ensure('/admin/settings/order-setup')) {
    const selects = await page.evaluate(() => Array.from(document.querySelectorAll('.db-card select')).map(s => ({ id: s.id || s.name, opts: Array.from(s.options).map(o => o.text).slice(0, 6) })));
    log({ step: 'order-setup-selects', selects, ...drain() });
  }

  await browser.close();
  console.log('PHASE3 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
