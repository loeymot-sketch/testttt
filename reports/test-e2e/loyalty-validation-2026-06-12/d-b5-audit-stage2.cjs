/* D-B5-divers stage 2: per-page interactions, durable token forced on /api/. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
const TOKEN = process.argv[2];
if (!TOKEN) { console.error('token arg required'); process.exit(1); }

function log(s) { fs.appendFileSync(path.join(OUT, 'D-B5-divers.md'), s + '\n'); console.log(s); }
const shot = (page, name) => page.screenshot({ path: path.join(OUT, `D-B5-divers-${name}.png`), fullPage: false });

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/\/api\//, (route) => {
    const h = { ...route.request().headers() };
    if (!route.request().url().includes('/api/auth/login')) h.authorization = 'Bearer ' + TOKEN;
    route.continue({ headers: h });
  });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const flush = (label) => {
    log(`- [${label}] console: ${sink.console.length ? '\n  ' + [...new Set(sink.console)].join('\n  ') : 'clean'}`);
    log(`- [${label}] http>=400: ${sink.http.length ? '\n  ' + [...new Set(sink.http)].join('\n  ') : 'clean'}`);
    sink.console.length = 0; sink.http.length = 0;
  };

  // login (UI, to satisfy router guards/localStorage)
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  log(`\n## STAGE 2 — interactions ${new Date().toISOString()} (token durable)`);
  sink.console.length = 0; sink.http.length = 0;

  // ---------- A. observability redirect ----------
  try {
    await page.goto(BASE + '/admin/observability', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    log(`\n### A. /admin/observability redirect\n- URL after settle: ${page.url().replace(BASE, '')}`);
    flush('obs-redirect');
  } catch (e) { log('A ERROR ' + String(e).slice(0, 200)); }

  // ---------- B. outbox: stats veracity + Rafraîchir + Purge guard ----------
  try {
    await page.goto(BASE + '/admin/observability/outbox', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const statsText = await page.evaluate(() => document.body.innerText.replace(/\n+/g, ' | ').slice(0, 2500));
    log(`\n### B. /admin/observability/outbox\n- page text: ${statsText.slice(0, 1800)}`);
    flush('outbox-load');
    const t0 = Date.now();
    await page.getByRole('button', { name: /Rafraîchir/i }).first().click();
    await page.waitForLoadState('networkidle');
    log(`- Rafraîchir clicked, settle ${Date.now() - t0}ms`);
    flush('outbox-refresh');
    // Purge guard: click and look for confirm dialog; CANCEL.
    page.once('dialog', async d => { log(`- Purge native dialog: "${d.message().slice(0, 200)}"`); await d.dismiss(); });
    await page.getByRole('button', { name: /^Purge/i }).first().click();
    await page.waitForTimeout(1500);
    const afterPurge = await page.evaluate(() => {
      const m = document.querySelector('.swal2-popup, .modal.show, [role=dialog]');
      return m ? m.innerText.slice(0, 300) : null;
    });
    log(`- Purge modal/in-page guard: ${afterPurge ? JSON.stringify(afterPurge) : 'none detected'}`);
    // cancel whatever opened
    const cancelBtn = page.locator('.swal2-cancel, [role=dialog] button:has-text("Annuler"), .modal.show button:has-text("Annuler"), [role=dialog] button:has-text("Non")');
    if (await cancelBtn.count()) { await cancelBtn.first().click(); await page.waitForTimeout(500); log('- Purge cancelled via UI'); }
    flush('outbox-purge');
    await shot(page, 'outbox-after-interact');
  } catch (e) { log('B ERROR ' + String(e).slice(0, 250)); }

  // ---------- C. profile edit: a11y labels + save no-op ----------
  try {
    await page.goto(BASE + '/admin/profile/edit-profile', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const a11y = await page.evaluate(() => {
      const inputs = [...document.querySelectorAll('main input, form input, .db-card input')];
      return inputs.map(i => ({
        id: i.id || null, type: i.type,
        hasLabel: !!(i.id && document.querySelector(`label[for="${i.id}"]`)) || !!i.closest('label') || !!i.getAttribute('aria-label'),
      }));
    });
    log(`\n### C. /admin/profile/edit-profile\n- inputs/labels: ${JSON.stringify(a11y)}`);
    await page.getByRole('button', { name: /Enregistrer/i }).first().click();
    await page.waitForTimeout(2000);
    const toast = await page.evaluate(() => { const t = document.querySelector('.Vue-Toastification__toast, .toast, .alert'); return t ? t.innerText.slice(0, 200) : null; });
    log(`- save unchanged -> toast: ${JSON.stringify(toast)}`);
    flush('profile-edit');
    await shot(page, 'profile-edit-after-save');
  } catch (e) { log('C ERROR ' + String(e).slice(0, 250)); }

  // ---------- D. change password: validation path (NEVER succeed) ----------
  try {
    await page.goto(BASE + '/admin/profile/change-password', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const pw = page.locator('input[type=password]');
    const n = await pw.count();
    log(`\n### D. /admin/profile/change-password\n- password inputs: ${n}`);
    await pw.nth(0).fill('wrong-old-pass');
    await pw.nth(1).fill('Zz12345678!');
    await pw.nth(2).fill('Zz99999999!'); // mismatch on purpose
    const saveBtn = page.getByRole('button', { name: /Enregistrer|Changer|Sauvegarder|Mettre/i }).first();
    await saveBtn.click();
    await page.waitForTimeout(2500);
    const errs = await page.evaluate(() => [...document.querySelectorAll('.db-field-alert, .invalid-feedback, .text-danger, small.db-error, .validation-error')].map(e => e.innerText.trim()).filter(Boolean).slice(0, 8));
    const toast2 = await page.evaluate(() => { const t = document.querySelector('.Vue-Toastification__toast, .toast, .alert'); return t ? t.innerText.slice(0, 200) : null; });
    log(`- mismatch+wrong-old submit -> field errors: ${JSON.stringify(errs)} toast: ${JSON.stringify(toast2)}`);
    flush('change-password');
    await shot(page, 'profile-password-errors');
  } catch (e) { log('D ERROR ' + String(e).slice(0, 250)); }

  // ---------- E. coupons: rows, search, pagination, filter datepicker AM/PM ----------
  try {
    await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const rows1 = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.innerText.split('\t')[0].slice(0, 30)));
    const pagText = await page.evaluate(() => { const p = document.querySelector('.pagination, nav[aria-label], .vt-pagination, ul.pagination'); return p ? p.innerText.replace(/\n/g, ' ') : null; });
    const totalText = await page.evaluate(() => { const m = document.body.innerText.match(/Affichage.{0,60}|Showing.{0,60}/); return m ? m[0] : null; });
    log(`\n### E. /admin/coupons (seed: 12 E2E-D5)\n- page1 rows (${rows1.length}): ${JSON.stringify(rows1.slice(0, 12))}\n- pagination: ${JSON.stringify(pagText)}\n- total label: ${JSON.stringify(totalText)}`);
    flush('coupons-load');
    await shot(page, 'coupons-page1');
    // pagination -> page 2
    const p2 = page.locator('.pagination a, .pagination button, ul.pagination li, nav a, nav button').filter({ hasText: /^2$/ });
    if (await p2.count()) {
      await p2.first().click();
      await page.waitForTimeout(1500);
      const rows2 = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.innerText.split('\t')[0].slice(0, 30)));
      const changed = JSON.stringify(rows1) !== JSON.stringify(rows2);
      log(`- page2 rows (${rows2.length}): ${JSON.stringify(rows2.slice(0, 12))} | DATA CHANGED: ${changed}`);
      flush('coupons-page2');
      await shot(page, 'coupons-page2');
    } else { log('- pagination control with "2" NOT found'); }
    // search via filter
    await page.getByRole('button', { name: /Filtrer/i }).first().click();
    await page.waitForTimeout(800);
    const nameInput = page.locator('#name, input[id*=name]').first();
    if (await nameInput.count()) {
      await nameInput.fill('E2E-D5 Coupon 03');
      const searchBtn = page.getByRole('button', { name: /Recherche|Rechercher|Search/i }).first();
      if (await searchBtn.count()) { await searchBtn.click(); } else { await nameInput.press('Enter'); }
      await page.waitForTimeout(1500);
      const rowsS = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.innerText.split('\t')[0].slice(0, 40)));
      log(`- search "E2E-D5 Coupon 03" -> rows (${rowsS.length}): ${JSON.stringify(rowsS)}`);
      flush('coupons-search');
      await shot(page, 'coupons-search');
    } else { log('- filter name input NOT found'); }
    // datepicker AM/PM evidence (filter start date)
    const dp = page.locator('.dp__input').first();
    if (await dp.count()) {
      await dp.click();
      await page.waitForTimeout(800);
      // open time picker if clock button exists
      const clock = page.locator('[data-test="open-time-picker-btn"], .dp__button');
      if (await clock.count()) { await clock.first().click().catch(() => {}); await page.waitForTimeout(600); }
      const ampm = await page.evaluate(() => { const m = document.body.innerText.match(/\b(AM|PM)\b/); return m ? m[0] : null; });
      log(`- filter datepicker open -> AM/PM visible: ${JSON.stringify(ampm)}`);
      await shot(page, 'coupons-datepicker-ampm');
      await page.keyboard.press('Escape');
    } else { log('- datepicker input NOT found in filter'); }
    flush('coupons-datepicker');
  } catch (e) { log('E ERROR ' + String(e).slice(0, 250)); }

  // ---------- F. offers: empty state + filter datepicker ----------
  try {
    await page.goto(BASE + '/admin/offers', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const empty = await page.evaluate(() => {
      const tb = document.querySelector('tbody');
      return { rows: tb ? tb.querySelectorAll('tr').length : -1, text: tb ? tb.innerText.slice(0, 200) : null, bodyHasEmptyMsg: (document.body.innerText.match(/Aucun|aucune|vide|No data|not found|Désolé/i) || [null])[0] };
    });
    log(`\n### F. /admin/offers (DB: 0 offers)\n- tbody: ${JSON.stringify(empty)}`);
    flush('offers-load');
    await shot(page, 'offers-empty');
  } catch (e) { log('F ERROR ' + String(e).slice(0, 250)); }

  // ---------- G. messages: veracity (DB=2) + open thread ----------
  try {
    await page.goto(BASE + '/admin/messages', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const msgs = await page.evaluate(() => document.body.innerText.replace(/\n+/g, ' | ').slice(0, 1200));
    log(`\n### G. /admin/messages (DB: 2 rows in messages)\n- visible: ${msgs.slice(0, 900)}`);
    // try open first conversation
    const item = page.locator('.message-list li, [class*=message] li, .chat-list li, aside li').first();
    if (await item.count()) { await item.click().catch(() => {}); await page.waitForTimeout(1200); }
    const thread = await page.evaluate(() => document.body.innerText.match(/\d{1,2}:\d{2}\s?(AM|PM)?[, ]+\d{2}-\d{2}-\d{4}/g));
    log(`- timestamps seen: ${JSON.stringify(thread && thread.slice(0, 5))}`);
    flush('messages');
    await shot(page, 'messages-open');
  } catch (e) { log('G ERROR ' + String(e).slice(0, 250)); }

  // ---------- H. subscribers: clean re-audit (stage1 was token-killed) ----------
  try {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/subscribers', { waitUntil: 'networkidle' });
    const tl = Date.now() - t0;
    await page.waitForTimeout(1000);
    const sub = await page.evaluate(() => {
      const tb = document.querySelector('tbody');
      return { rows: tb ? tb.querySelectorAll('tr').length : -1, tbText: tb ? tb.innerText.slice(0, 200) : null, title: (document.body.innerText.match(/Abonnés|Subscribers/) || [null])[0] };
    });
    log(`\n### H. /admin/subscribers (DB: 0 subscribers) — load ${tl}ms\n- ${JSON.stringify(sub)}`);
    flush('subscribers');
    await shot(page, 'subscribers');
  } catch (e) { log('H ERROR ' + String(e).slice(0, 250)); }

  // ---------- I. push notifications: clean re-audit + form guard ----------
  try {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/push-notifications', { waitUntil: 'networkidle' });
    const tl = Date.now() - t0;
    await page.waitForTimeout(1000);
    const push = await page.evaluate(() => document.body.innerText.replace(/\n+/g, ' | ').slice(0, 1000));
    log(`\n### I. /admin/push-notifications (DB: 0) — load ${tl}ms\n- visible: ${push.slice(0, 800)}`);
    const buttons = await page.evaluate(() => [...document.querySelectorAll('main button, .db-card button, button')].map(b => b.innerText.trim()).filter(t => t && t.length < 40).slice(0, 15));
    log(`- buttons: ${JSON.stringify(buttons)}`);
    flush('push');
    await shot(page, 'push-notifications');
  } catch (e) { log('I ERROR ' + String(e).slice(0, 250)); }

  await browser.close();
  log('\nSTAGE2 DONE');
})().catch(e => { log('FATAL ' + String(e).slice(0, 400)); process.exit(1); });
