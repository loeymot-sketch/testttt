/* F3 step 4 phase 1: login -> /admin/settings/loyalty-setup -> capture before -> set per-euro=2 -> save -> capture after */
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

    // settings > loyalty
    await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
    await page.waitForSelector('#loyalty_points_per_euro', { timeout: 20000 });
    await page.waitForTimeout(800);
    const before = await page.inputValue('#loyalty_points_per_euro');
    console.log('UI value BEFORE =', before);
    await page.screenshot({ path: DIR + '/F3-setup-before.png', fullPage: true });

    // set to 2 and save
    await page.fill('#loyalty_points_per_euro', '2');
    const saveResp = page.waitForResponse(r => r.url().includes('loyalty-setup') && r.request().method() !== 'GET', { timeout: 15000 });
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).click();
    const resp = await saveResp;
    console.log('SAVE response:', resp.status(), resp.request().method(), resp.url().replace(BASE, ''));
    console.log('SAVE body:', (await resp.text()).slice(0, 400));
    await page.waitForTimeout(1200);
    await page.screenshot({ path: DIR + '/F3-setup-after-2.png', fullPage: true });

    // reload to confirm round-trip from server
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForSelector('#loyalty_points_per_euro', { timeout: 20000 });
    await page.waitForTimeout(800);
    console.log('UI value AFTER RELOAD =', await page.inputValue('#loyalty_points_per_euro'));

    console.log('console errors:', JSON.stringify(sink.console, null, 1));
    console.log('http>=400:', JSON.stringify(sink.http, null, 1));
    await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
