/* F3 step 4 phase 2b: -1 (native HTML5 block, capture bubble) + empty (server 422 FR?) + restore 1 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const DIR = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const sink = { console: [], http: [] };
    page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
    page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
    page.on('response', r => { if (r.status() >= 400 && r.status() !== 422) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');

    await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
    await page.waitForSelector('#loyalty_points_per_euro', { timeout: 20000 });
    await page.waitForTimeout(800);
    console.log('UI value on fresh load (curl probes left 0) =', await page.inputValue('#loyalty_points_per_euro'));

    // a) -1 : native HTML5 min=0 blocks submit — no XHR fires
    await page.fill('#loyalty_points_per_euro', '-1');
    let fired = false;
    const sniffer = r => { if (r.url().includes('loyalty-setup') && r.request().method() !== 'GET') fired = true; };
    page.on('response', sniffer);
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).click();
    await page.waitForTimeout(2500);
    page.off('response', sniffer);
    const nat = await page.evaluate(() => {
        const i = document.querySelector('#loyalty_points_per_euro');
        return { validity: i.checkValidity(), message: i.validationMessage };
    });
    console.log('[-1] XHR fired =', fired, '| native validity =', nat.validity, '| native message =', JSON.stringify(nat.message));
    await page.screenshot({ path: DIR + '/F3-setup-validation-minus1.png', fullPage: true });

    // b) empty (≈ texte: input number rejette les lettres → champ vide) → server 422
    await page.fill('#loyalty_points_per_euro', '');
    const wait422 = page.waitForResponse(r => r.url().includes('loyalty-setup') && r.request().method() !== 'GET', { timeout: 15000 });
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).click();
    const r422 = await wait422;
    console.log('[empty] status =', r422.status(), '| body =', (await r422.text()).slice(0, 250));
    await page.waitForTimeout(900);
    const errEl = await page.$('small.db-field-alert');
    console.log('[empty] inline error =', errEl ? (await errEl.textContent()).trim() : '(none shown)');
    await page.screenshot({ path: DIR + '/F3-setup-validation-empty.png', fullPage: true });

    // c) restore to 1
    await page.fill('#loyalty_points_per_euro', '1');
    const waitOk = page.waitForResponse(r => r.url().includes('loyalty-setup') && r.request().method() !== 'GET', { timeout: 15000 });
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).click();
    const ok = await waitOk;
    console.log('[restore-1] status =', ok.status(), '| body =', (await ok.text()).slice(0, 200));
    await page.waitForTimeout(900);
    await page.screenshot({ path: DIR + '/F3-setup-restored-1.png', fullPage: true });

    // fresh navigation to confirm persisted value
    await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle' });
    await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
    await page.waitForSelector('#loyalty_points_per_euro', { timeout: 20000 });
    await page.waitForTimeout(800);
    console.log('UI value after restore + fresh nav =', await page.inputValue('#loyalty_points_per_euro'));

    console.log('console errors:', JSON.stringify(sink.console, null, 1));
    console.log('http>=400 (hors 422):', JSON.stringify(sink.http, null, 1));
    await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
