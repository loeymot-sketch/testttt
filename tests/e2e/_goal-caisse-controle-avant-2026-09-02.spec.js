// FoodKing E2E — GOAL CAISSE CONTRÔLE (2026-09-02) — capture de l'état AVANT correctif.
//
// Sème un service réaliste (helpers/seed-caisse-controle.js), puis photographie ce que le
// caissier voit RÉELLEMENT aujourd'hui sur la caisse et sur ses pages de redirection.
// Rien n'est affirmé ici : on mesure. Les captures sont lues et analysées ensuite.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('./helpers/login');
const { seedService, cleanup } = require('./helpers/seed-caisse-controle');

const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(REPO_ROOT, 'reports', 'goal-caisse-controle-2026-09-02', 'captures-avant');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

test.describe('AVANT — ce que le caissier voit aujourd’hui', () => {
    test.setTimeout(240_000);
    let ids;

    test.beforeAll(() => {
        ids = seedService();
        fs.writeFileSync(path.join(SHOTS_DIR, 'seed-ids.json'), JSON.stringify(ids, null, 2));
        for (const [k, v] of Object.entries(ids)) {
            if (!v) throw new Error(`Semis raté pour ${k}`);
        }
    });

    test.afterAll(() => {
        if (process.env.KEEP_SEED !== '1') cleanup();
    });

    test('caisse principale, panneau borne, page suivi', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
        const navs = [];
        page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs.push(f.url()); });

        await loginAsPosOperator(page);
        await page.waitForTimeout(4000);
        await page.screenshot({ path: path.join(SHOTS_DIR, '01-caisse-principale.png'), fullPage: false });

        // Panneau « À encaisser borne » (drawer existant)
        const open = page.getByTestId('kiosk-cash-open');
        if (await open.isVisible().catch(() => false)) {
            await open.click();
            await page.waitForTimeout(1200);
            await page.screenshot({ path: path.join(SHOTS_DIR, '02-panneau-encaisser-borne.png'), fullPage: false });
            const expand = page.getByTestId(`kiosk-cash-expand-${ids.K2.id}`);
            if (await expand.isVisible().catch(() => false)) {
                await expand.click();
                await page.waitForTimeout(500);
                await page.screenshot({ path: path.join(SHOTS_DIR, '03-panneau-encaisser-borne-detail.png'), fullPage: false });
            }
            await page.keyboard.press('Escape');
            await page.locator('.kiosk-cash-panel-close').click({ timeout: 3000 }).catch(() => {});
            await page.waitForTimeout(500);
        }

        // Bouton « Suivi » → mesure : navigation dure ou SPA ?
        const before = navs.length;
        const t0 = Date.now();
        await page.getByTestId('pos-tracker-open').click();
        await page.waitForURL(/pos-orders-tracker/, { timeout: 30_000 });
        await page.waitForTimeout(4000);
        const dt = Date.now() - t0;
        const hardNav = navs.length > before;
        fs.writeFileSync(path.join(SHOTS_DIR, 'nav-suivi.json'), JSON.stringify({ hardNav, ms: dt, navs }, null, 2));
        await page.screenshot({ path: path.join(SHOTS_DIR, '04-page-suivi.png'), fullPage: true });

        // Retour caisse depuis le suivi
        const back = page.getByTestId('csn-back-caisse').or(page.getByText('Retour caisse')).first();
        if (await back.isVisible().catch(() => false)) {
            const b2 = navs.length;
            await back.click();
            await page.waitForTimeout(4000);
            fs.appendFileSync(path.join(SHOTS_DIR, 'nav-suivi.json'), `\n// retour caisse : hardNav=${navs.length > b2}, url=${page.url()}`);
            await page.screenshot({ path: path.join(SHOTS_DIR, '05-retour-caisse.png'), fullPage: false });
        }

        fs.writeFileSync(path.join(SHOTS_DIR, 'console-errors.json'), JSON.stringify(consoleErrors, null, 2));
        expect(ids.K1.id).toBeGreaterThan(0);
    });
});
