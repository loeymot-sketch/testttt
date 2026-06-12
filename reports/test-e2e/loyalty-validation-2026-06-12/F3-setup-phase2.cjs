/* F3 step 4 phase 2: validation (-1, 0, empty/text) + restore per-euro=1 */
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
    console.log('UI value on fresh load (expect 2 from phase 1) =', await page.inputValue('#loyalty_points_per_euro'));

    const save = async (tag) => {
        const wait = page.waitForResponse(r => r.url().includes('loyalty-setup') && r.request().method() !== 'GET', { timeout: 15000 });
        await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).click();
        const resp = await wait;
        const body = await resp.text();
        console.log(`SAVE[${tag}]`, resp.status(), '→', body.slice(0, 300));
        await page.waitForTimeout(900);
        return resp.status();
    };
    const errText = async () => {
        const el = await page.$('#loyalty_points_per_euro ~ .db-field-alert, small.db-field-alert');
        return el ? (await el.textContent()).trim() : '(no inline error shown)';
    };

    // a) -1
    await page.fill('#loyalty_points_per_euro', '-1');
    await save('-1');
    console.log('inline error [-1]:', await errText());
    await page.screenshot({ path: DIR + '/F3-setup-validation-minus1.png', fullPage: true });

    // b) empty (text is not typable into <input type=number>; '' simulates text/garbage → field empty)
    await page.fill('#loyalty_points_per_euro', '');
    await page.evaluate(() => { const i = document.querySelector('#loyalty_points_per_euro'); i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); });
    await save('empty');
    console.log('inline error [empty]:', await errText());
    await page.screenshot({ path: DIR + '/F3-setup-validation-empty.png', fullPage: true });

    // c) 0 — accepted per rules (min:0)?
    await page.fill('#loyalty_points_per_euro', '0');
    await save('0');
    console.log('inline error [0]:', await errText());

    // d) restore to 1
    await page.fill('#loyalty_points_per_euro', '1');
    await save('restore-1');
    await page.waitForTimeout(600);
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
