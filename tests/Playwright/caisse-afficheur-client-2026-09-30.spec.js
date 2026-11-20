// [AFFICHEUR-CLIENT 2026-09-30, owner : « l'écran face client affiche juste le prix ; à chaque
// produit ajouté il faut le total, que le client sache combien il va payer »]
//
// Constat en production (2026-09-30) : la caisse POSTait bien le total au serveur, mais le serveur
// est un VPS Linux → il n'atteint pas l'USB du PC caisse, et l'afficheur y est désactivé. Rien de
// notre système n'arrivait sur la SAGA. Désormais c'est le Chrome de la caisse qui écrit sur le port
// série (Web Serial).
//
// Instrument : un faux `navigator.serial` qui ENREGISTRE les octets destinés à la SAGA. On décode
// l'écran (2 × 20 colonnes après l'en-tête ESC @ · ESC t 19 · CLR) et on le compare au total
// affiché par la caisse elle-même, après chaque ajout.
const { test, expect } = require('@playwright/test');
const path = require('path');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SHOTS = path.join(__dirname, '..', 'captures', 'caisse-afficheur-client-2026-09-30');

function installFakeSerial(page, { alreadyGranted }) {
    return page.addInitScript(({ alreadyGranted }) => {
        window.__displayWrites = [];
        window.__requestPortCalls = 0;
        const port = {
            writable: null,
            getInfo: () => ({ usbVendorId: 0x067b, usbProductId: 0x2303 }),
            async open(opts) {
                window.__displayOpenOpts = opts;
                port.writable = {
                    getWriter: () => ({
                        write: async (chunk) => { window.__displayWrites.push(Array.from(chunk)); },
                        releaseLock() {},
                    }),
                };
            },
            async close() {},
        };
        const fake = {
            async getPorts() { return alreadyGranted ? [port] : []; },
            async requestPort() { window.__requestPortCalls++; return port; },
            addEventListener() {},
        };
        Object.defineProperty(Navigator.prototype, 'serial', { get: () => fake, configurable: true });
    }, { alreadyGranted });
}

/** Ce que la SAGA affiche après la dernière écriture : { haut, bas }. */
async function ecranSaga(page) {
    return page.evaluate(() => {
        const w = window.__displayWrites;
        if (!w.length) return null;
        const s = String.fromCharCode(...w[w.length - 1]);
        const corps = s.slice(6); // ESC @ · ESC t 19 · CLR
        return { entete: [...s.slice(0, 6)].map((c) => c.charCodeAt(0)), haut: corps.slice(0, 20), bas: corps.slice(20, 40), longueur: s.length };
    });
}

async function totalCaisse(page) {
    return page.evaluate(() => {
        const b = document.querySelector('[data-testid="pos-v5-pay"]');
        const m = (b?.textContent || '').replace(/\s/g, ' ').match(/(\d[\d  ]*,\d{2})/);
        return m ? m[1].replace(/[  ]/g, ' ') : null;
    });
}

function jsClick(page, sel) {
    return page.evaluate((s) => {
        const el = [...document.querySelectorAll(s)].find((e) => e.offsetParent !== null);
        if (!el) return false;
        el.scrollIntoView({ block: 'center' });
        el.click();
        return true;
    }, sel);
}

async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await page.fill('#formEmail', 'pos@lecayenne.fr');
    await page.fill('#formPassword', '123456');
    await page.evaluate(() => {
        const b = [...document.querySelectorAll('button[type=submit]')].find((x) => /connexion/i.test(x.innerText || ''));
        if (b) b.click();
    });
    await page.waitForTimeout(3000);
}

async function ajouterTacosM(page) {
    const search = page.locator('input[placeholder="Rechercher un article du menu"]');
    await search.fill('');
    await search.type('Tacos M', { delay: 40 });
    await page.waitForTimeout(1000);
    await page.evaluate(() => {
        const btn = [...document.querySelectorAll('[data-pos-item-id]')].find((b) => !b.disabled);
        if (btn) btn.click();
    });
    await page.waitForTimeout(1500);
    await jsClick(page, '[data-viande="v_43"]');
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        const el = [...document.querySelectorAll('button.sauce-chip')].find((e) => e.offsetParent !== null && /Barbecue/i.test(e.textContent || ''));
        if (el) el.click();
    });
    await page.waitForTimeout(300);
    expect(await jsClick(page, 'button.wizard-btn-cart'), 'bouton du wizard « Ajouter au panier »').toBe(true);
    await page.waitForTimeout(1200); // > anti-rebond 350 ms de l'afficheur
}

test('premier branchement : un clic sur « Afficheur », puis le TOTAL s’affiche à chaque ajout', async ({ page }) => {
    test.setTimeout(120000);
    const erreurs = [];
    page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
    await installFakeSerial(page, { alreadyGranted: false });
    await login(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);

    const bouton = page.locator('[data-testid="pos-customer-display-connect"]');
    await expect(bouton, 'le bouton « Afficheur » est présent dans la barre Caisse').toBeVisible();
    expect(await page.evaluate(() => window.__displayWrites.length), 'jamais appairé : rien n’est écrit sans geste').toBe(0);

    await bouton.click();
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => window.__requestPortCalls)).toBe(1);
    expect(await page.evaluate(() => window.__displayOpenOpts)).toMatchObject({ baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none' });
    const accueil = await ecranSaga(page);
    expect(accueil.entete).toEqual([0x1b, 0x40, 0x1b, 0x74, 0x13, 0x0c]);
    expect(accueil.longueur).toBe(46);
    expect(accueil.bas.trim()).toBe('Soyez le bienvenu !');
    await page.screenshot({ path: path.join(SHOTS, '01-afficheur-branche.png') });

    await ajouterTacosM(page);
    const t1 = await totalCaisse(page);
    const e1 = await ecranSaga(page);
    expect(e1.haut.trim()).toBe('TOTAL');
    expect(e1.bas, `SAGA après 1 article, caisse = ${t1}`).toBe(`${t1} EUR`.padStart(20));

    await ajouterTacosM(page);
    const t2 = await totalCaisse(page);
    const e2 = await ecranSaga(page);
    expect(t2).not.toBe(t1);
    expect(e2.haut.trim()).toBe('TOTAL');
    expect(e2.bas, `SAGA après 2 articles = TOTAL cumulé (${t2}), pas le prix du dernier article (${t1})`).toBe(`${t2} EUR`.padStart(20));
    await page.screenshot({ path: path.join(SHOTS, '02-deux-articles-total.png') });

    // Panier vidé → retour à l'accueil.
    await page.evaluate(() => { const v = [...document.querySelectorAll('*')].find((e) => e.__vue_app__)?.__vue_app__; v?.config.globalProperties.$store.dispatch('posCart/resetCart'); });
    await page.waitForTimeout(1200);
    const e3 = await ecranSaga(page);
    expect(e3.bas.trim()).toBe('Soyez le bienvenu !');

    console.log('ECRANS', JSON.stringify({ accueil, t1, e1, t2, e2, e3 }));
    expect(erreurs.filter((e) => /serial|afficheur|customerDisplay/i.test(e))).toEqual([]);
});

test('au rechargement de la caisse : reconnexion SANS clic et total réaffiché', async ({ page }) => {
    test.setTimeout(90000);
    await installFakeSerial(page, { alreadyGranted: true });
    await login(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    expect(await page.evaluate(() => window.__requestPortCalls), 'aucune fenêtre de choix de port').toBe(0);
    expect(await page.evaluate(() => window.__displayWrites.length)).toBeGreaterThan(0);
    await ajouterTacosM(page);
    const t = await totalCaisse(page);
    const e = await ecranSaga(page);
    expect(e.bas).toBe(`${t} EUR`.padStart(20));
    await page.screenshot({ path: path.join(SHOTS, '03-reconnexion-auto.png') });
});
