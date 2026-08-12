/**
 * [GOAL 2026-08-12] Parcours RÉELS sur le web local + relevé des points faibles.
 *
 * On ne teste pas des codes HTTP : on joue ce qu'un humain fait, on capture, on lit, et on
 * enregistre TOUT ce que la page dit d'elle-même (erreurs JS, requêtes en échec, libellés bruts).
 * Un écran qui répond 200 en affichant « Label.foo » ou une grille vide est un écran cassé.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator, loginAsChefOperator, loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/goal-reel-2026-08-12');
fs.mkdirSync(OUT, { recursive: true });

const bilan = [];

/** Attache les mouchards : erreurs JS, console, requêtes ratées. */
function sondes(page, nom) {
    const r = { pageErrors: [], consoleErr: [], http: [], libellesBruts: [] };
    page.on('pageerror', (e) => r.pageErrors.push(String(e.message).slice(0, 300)));
    page.on('console', (m) => { if (m.type() === 'error') r.consoleErr.push(String(m.text()).slice(0, 300)); });
    page.on('response', (res) => {
        const s = res.status();
        if (s >= 400) r.http.push(`${s} ${res.request().method()} ${res.url().replace(/^https?:\/\/[^/]+/, '')}`);
    });
    return r;
}

/** Cherche les libellés non traduits / valeurs cassées visibles à l'écran. */
async function libellesCasses(page) {
    return page.evaluate(() => {
        const txt = document.body.innerText || '';
        const motifs = [
            /\bLabel\.[a-zA-Z_.]+/g, /\bbutton\.[a-zA-Z_.]+/g, /\bmessage\.[a-zA-Z_.]+/g,
            /\bundefined\b/g, /\bNaN\b/g, /\bnull\b/g, /\[object Object\]/g,
        ];
        const trouves = [];
        for (const m of motifs) { const t = txt.match(m); if (t) trouves.push(...t.slice(0, 5)); }
        return [...new Set(trouves)];
    });
}

async function capturer(page, nom, r) {
    await page.screenshot({ path: path.join(OUT, `${nom}.png`), fullPage: false });
    r.libellesBruts = await libellesCasses(page);
    const entree = {
        surface: nom,
        url: page.url(),
        erreursJS: r.pageErrors,
        consoleErreurs: r.consoleErr.slice(0, 8),
        httpEnEchec: [...new Set(r.http)].slice(0, 12),
        libellesBruts: r.libellesBruts,
    };
    bilan.push(entree);
    console.log(`[${nom}] ` + JSON.stringify(entree));
}

test.describe.configure({ mode: 'serial' });

test.afterAll(() => {
    fs.writeFileSync(path.join(OUT, 'bilan.json'), JSON.stringify(bilan, null, 2));
});

test('CAISSE — écran complet, panneaux, ajout produit réel', async ({ page }) => {
    const r = sondes(page, 'caisse');
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(6000); // laisser les panneaux se remplir (sondage 5 s)
    await capturer(page, '01-caisse-accueil', r);

    // Parcours réel : cliquer une vraie tuile produit.
    const tuiles = page.locator('[data-testid^="pos-item-"], .pos-item-card, .pos-v5-tile');
    const n = await tuiles.count();
    console.log(`[caisse] tuiles produit visibles = ${n}`);
    if (n > 0) {
        await tuiles.first().click({ timeout: 15_000 }).catch((e) => console.log('[caisse] clic tuile: ' + e.message));
        await page.waitForTimeout(2500);
        await capturer(page, '02-caisse-apres-clic-produit', r);
    }
});

test('KDS — écran cuisine réel', async ({ page }) => {
    const r = sondes(page, 'kds');
    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(5000);
    await capturer(page, '03-kds', r);
    const cartes = await page.locator('[data-testid^="kds-order-"], .kds-order-card').count();
    console.log(`[kds] cartes commande = ${cartes}`);
});

test('BORNE — parcours client jusqu\'au panier', async ({ page }) => {
    const r = sondes(page, 'borne');
    await page.goto('/kiosk/idle', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(3000);
    await capturer(page, '04-borne-accueil', r);

    // Toucher l'écran d'accueil pour entrer dans la carte.
    await page.locator('body').click({ position: { x: 640, y: 400 } }).catch(() => {});
    await page.waitForTimeout(4000);
    await capturer(page, '05-borne-apres-toucher', r);
});

test('MUR CLIENT (OSS)', async ({ page }) => {
    const r = sondes(page, 'oss');
    await loginAsPosOperator(page);
    await page.goto('/admin/order-status-screen', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(4000);
    await capturer(page, '06-mur-client', r);
});

test('ADMIN — catalogue et stock', async ({ page }) => {
    const r = sondes(page, 'admin');
    await loginAsAdmin(page);
    await page.goto('/admin/items', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(4000);
    await capturer(page, '07-admin-catalogue', r);

    await page.goto('/admin/stock/rupture', { waitUntil: 'networkidle', timeout: 60_000 });
    await page.waitForTimeout(4000);
    await capturer(page, '08-admin-stock', r);
});
