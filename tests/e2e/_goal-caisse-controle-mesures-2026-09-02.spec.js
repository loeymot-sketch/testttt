// FoodKing E2E — GOAL CAISSE CONTRÔLE (2026-09-02) — mesures AVANT, instruments PROUVÉS.
//
// ⚠️ PIÈGE D'INSTRUMENT ÉVITÉ ICI (CLAUDE.md §3ter) : `page.on('framenavigated')` se déclenche
// AUSSI sur une navigation SPA (history.pushState). Il ne prouve donc RIEN sur « nouvelle page ou
// pas ». L'instrument valide est un marqueur posé sur `window` : il survit à une navigation SPA et
// disparaît à un rechargement de document. C'est celui-ci qu'on utilise.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('./helpers/login');

const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(REPO_ROOT, 'reports', 'goal-caisse-controle-2026-09-02', 'captures-avant');

async function allerAuSuivi(page, label) {
    await page.evaluate(() => { window.__gcc = 'vivant'; });
    const t0 = Date.now();
    await page.getByTestId('pos-tracker-open').first().click({ timeout: 15_000 });
    await page.waitForURL(/pos-orders-tracker/, { timeout: 30_000 });
    await page.waitForTimeout(2500);
    return {
        label,
        ms: Date.now() - t0,
        spa: (await page.evaluate(() => window.__gcc || null)) === 'vivant',
        url: page.url(),
    };
}

test.describe('MESURES AVANT — nouvelle page ou pas ?', () => {
    test.setTimeout(180_000);

    test('depuis /admin/pos (bundle app.js)', async ({ page }) => {
        const res = { source: '/admin/pos' };
        await loginAsPosOperator(page);
        await page.waitForTimeout(3000);
        res.url = page.url();
        res.scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.src).filter((s) => /js\/(pos-app|app)\.js/.test(s)));
        res.aller = await allerAuSuivi(page, 'app.js → suivi');
        await page.screenshot({ path: path.join(SHOTS_DIR, '06-suivi-depuis-admin-pos.png') });
        // Retour caisse
        await page.evaluate(() => { window.__gcc2 = 'vivant'; });
        const t0 = Date.now();
        await page.getByTestId('csn-back-caisse').first().click({ timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(3000);
        res.retour = {
            ms: Date.now() - t0,
            spa: (await page.evaluate(() => window.__gcc2 || null)) === 'vivant',
            url: page.url(),
        };
        fs.writeFileSync(path.join(SHOTS_DIR, 'mesures-app-js.json'), JSON.stringify(res, null, 2));
        expect(res.aller.url).toMatch(/pos-orders-tracker/);
    });

    test('depuis /admin/pos-v4 (bundle pos-app.js)', async ({ page }) => {
        const res = { source: '/admin/pos-v4' };
        await loginAsPosOperator(page);
        await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(4500);
        res.url = page.url();
        res.scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.src).filter((s) => /js\/(pos-app|app)\.js/.test(s)));
        await page.screenshot({ path: path.join(SHOTS_DIR, '07-caisse-pos-v4.png') });
        res.aller = await allerAuSuivi(page, 'pos-app.js → suivi');
        fs.writeFileSync(path.join(SHOTS_DIR, 'mesures-pos-app-js.json'), JSON.stringify(res, null, 2));
        expect(res.aller.url).toMatch(/pos-orders-tracker/);
    });

    test('caisse en 1920×1080', async ({ browser }) => {
        const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
        const page = await ctx.newPage();
        await loginAsPosOperator(page);
        await page.waitForTimeout(4000);
        await page.screenshot({ path: path.join(SHOTS_DIR, '08-caisse-1920.png') });
        await ctx.close();
        expect(true).toBe(true);
    });
});
