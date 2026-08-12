/**
 * [GOAL 2026-08-12] Suite du relevé visuel — surfaces manquantes.
 *
 * Correction de harnais : `networkidle` ne se produit JAMAIS sur ces écrans, qui sondent le
 * serveur toutes les 5 s. Attendre le silence réseau bloque indéfiniment. On attend donc le DOM,
 * puis on laisse le temps aux panneaux de se remplir.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator, loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/goal-reel-2026-08-12');
fs.mkdirSync(OUT, { recursive: true });
const bilan = [];

function sondes(page) {
    const r = { pageErrors: [], consoleErr: [], http: [] };
    page.on('pageerror', (e) => r.pageErrors.push(String(e.message).slice(0, 250)));
    page.on('console', (m) => { if (m.type() === 'error') r.consoleErr.push(String(m.text()).slice(0, 250)); });
    page.on('response', (res) => {
        if (res.status() >= 400) r.http.push(`${res.status()} ${res.request().method()} ${res.url().replace(/^https?:\/\/[^/]+/, '')}`);
    });
    return r;
}

async function releve(page, nom, r) {
    await page.screenshot({ path: path.join(OUT, `${nom}.png`), fullPage: false });
    const bruts = await page.evaluate(() => {
        const t = document.body.innerText || '';
        const out = [];
        for (const m of [/\bLabel\.[a-zA-Z_.]+/g, /\bbutton\.[a-zA-Z_.]+/g, /\bundefined\b/g, /\bNaN\b/g, /\[object Object\]/g]) {
            const f = t.match(m); if (f) out.push(...f.slice(0, 4));
        }
        return [...new Set(out)];
    });
    const e = { surface: nom, url: page.url(), erreursJS: r.pageErrors, consoleErreurs: [...new Set(r.consoleErr)].slice(0, 6), httpEnEchec: [...new Set(r.http)].slice(0, 10), libellesBruts: bruts };
    bilan.push(e);
    console.log(`[${nom}] ` + JSON.stringify(e));
}

test.describe.configure({ mode: 'serial' });
test.afterAll(() => fs.writeFileSync(path.join(OUT, 'bilan-suite.json'), JSON.stringify(bilan, null, 2)));

test('MUR CLIENT (OSS)', async ({ page }) => {
    const r = sondes(page);
    await loginAsPosOperator(page);
    await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);
    await releve(page, '06-mur-client', r);
});

test('ADMIN — catalogue puis stock', async ({ page }) => {
    const r = sondes(page);
    await loginAsAdmin(page);
    await page.goto('/admin/items', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);
    await releve(page, '07-admin-catalogue', r);

    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);
    await releve(page, '08-admin-stock', r);
});

test('CAISSE — la grille produits est-elle atteignable ?', async ({ page }) => {
    const r = sondes(page);
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(8000);

    // Le premier écran est occupé par les panneaux ; la grille vit plus bas.
    const grille = page.locator('.pos-v4-items, .pos-items-grid, [data-testid="pos-items-grid"], .pos-v5-grid');
    const existe = await grille.count();
    console.log(`[caisse] conteneurs de grille trouvés = ${existe}`);
    if (existe > 0) {
        await grille.first().scrollIntoViewIfNeeded().catch(() => {});
        await page.waitForTimeout(1500);
    } else {
        await page.mouse.wheel(0, 900);
        await page.waitForTimeout(1500);
    }
    await releve(page, '09-caisse-grille-produits', r);
});
