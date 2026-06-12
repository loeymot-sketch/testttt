/* F3 step 4 final: fresh UI session, confirm field shows 1, capture restored screenshot */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const DIR = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
    await page.waitForSelector('#loyalty_points_per_euro', { timeout: 20000 });
    await page.waitForTimeout(900);
    console.log('UI value (expect 1) =', await page.inputValue('#loyalty_points_per_euro'));
    await page.screenshot({ path: DIR + '/F3-setup-restored-1.png', fullPage: true });
    await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
