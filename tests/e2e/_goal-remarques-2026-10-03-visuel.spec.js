/**
 * [GOAL REMARQUES 2026-10-03] Contrôle VISUEL du lot « caisse / cuisine / encaissement ».
 *
 * Le navigateur était coupé dans la session qui a livré le lot : tout était prouvé au niveau du code
 * (6307 PHPUnit, 4834 Vitest). Ce spec comble le trou visuel exigé par CLAUDE.md §6, sur un banc local
 * (PLAYWRIGHT_BASE_URL) et UNIQUEMENT avec des commandes préfixées `E2E-VISU-REMARQUES`.
 *
 * Il ne se contente pas de capturer : il MESURE ce que le propriétaire a demandé, avec un TÉMOIN
 * NÉGATIF (un instrument qui ne sait pas dire « non » ne prouve rien — CLAUDE.md §3ter).
 *   R-071  supplément KDS au moins aussi grand que le produit, jamais coupé
 *   R-072  « # » devant le produit à supplément, et seulement lui
 *   R-059  page Encaissement : onglet « Ratées (24 h) » présent
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator, loginAsAdmin, cleanupOrphanTestOrders } = require('./helpers/login');
const { placeOrder } = require('./helpers/place-order');

const OUT = path.resolve(__dirname, '../captures/goal-remarques-2026-10-03');
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8790';
const PREFIX = 'E2E-VISU-REMARQUES';

// Article réel « Cayenne » (id 22) + son supplément « Œuf » (id 56), lus en base — rien d'inventé.
const ITEM_ID = 22;
const EXTRA_OEUF_ID = 56;

test.describe.configure({ mode: 'serial' });

test.afterAll(() => {
    cleanupOrphanTestOrders([PREFIX]);
});

test('KDS — supplément agrandi + dièse sur le produit (avec témoin négatif)', async ({ page }) => {
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));

    // Choix obligatoires lus en base : Pain (450), Poulet mariné (680), Ketchup (287).
    const base = { item_id: ITEM_ID, quantity: 1, item_variations: [{ id: 450 }, { id: 680 }, { id: 287 }] };
    // À emporter (10) : la V1 refuse « sur place » à la borne.
    const commun = { baseURL: BASE, paymentMethod: 4, orderType: 10, tokenPrefix: PREFIX, idempotencyPrefix: PREFIX };
    const avec = await placeOrder({ ...commun, items: [{ ...base, item_extras: [{ id: EXTRA_OEUF_ID, quantity: 1 }], instruction: 'E2E visuel' }] });
    // TÉMOIN NÉGATIF : même article, SANS supplément. S'il porte un « # », la mesure ne prouve rien.
    const sans = await placeOrder({ ...commun, items: [{ ...base, instruction: 'E2E visuel témoin' }] });
    expect(avec.orderId, 'la commande avec supplément doit exister').toBeGreaterThan(0);
    expect(sans.orderId, 'la commande témoin doit exister').toBeGreaterThan(0);

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForSelector('.kds-card', { timeout: 40_000 });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(OUT, '01-kds-plein-ecran.png') });

    // Le numéro affiché sur la carte (« A0032 ») n'est pas le numéro de série renvoyé par l'API :
    // on retrouve chaque carte par sa note, unique à ce spec.
    const carteAvec = page.locator('.kds-card', { hasText: 'E2E visuel', has: page.locator('.kds-line__supplement') }).first();
    const carteSans = page.locator('.kds-card', { hasText: 'E2E visuel témoin' }).first();
    await expect(carteAvec, 'la carte avec supplément doit s\'afficher').toBeVisible({ timeout: 20_000 });
    await expect(carteSans, 'la carte témoin doit s\'afficher').toBeVisible({ timeout: 20_000 });
    await carteAvec.screenshot({ path: path.join(OUT, '02-kds-carte-avec-supplement.png') });
    await carteSans.screenshot({ path: path.join(OUT, '03-kds-carte-temoin-sans-supplement.png') });

    const lire = (carte) => carte.evaluate((el) => {
        const px = (n) => parseFloat(getComputedStyle(n).fontSize);
        const supp = el.querySelector('.kds-line__supplement');
        const produit = el.querySelector('.kds-line__name, .kds-line__label, .kds-line__title');
        return {
            carteTexte: el.textContent.replace(/\s+/g, ' ').trim(),
            aUneLigneSupplement: !!supp,
            tailleSupplementPx: supp ? px(supp) : null,
            poidsSupplement: supp ? getComputedStyle(supp).fontWeight : null,
            fondSupplement: supp ? getComputedStyle(supp).backgroundColor : null,
            tailleProduitPx: produit ? px(produit) : null,
            debordeHorizontalement: supp ? supp.scrollWidth > supp.clientWidth + 1 : null,
        };
    });
    const mAvec = await lire(carteAvec);
    const mSans = await lire(carteSans);
    fs.writeFileSync(path.join(OUT, 'mesures-kds.json'), JSON.stringify({ avec: mAvec, sans: mSans }, null, 2));
    console.log('MESURES_KDS_AVEC', JSON.stringify(mAvec));
    console.log('MESURES_KDS_SANS', JSON.stringify(mSans));

    // R-071 — supplément en grand, jamais plus petit que le produit, jamais coupé.
    expect(mAvec.aUneLigneSupplement, 'la ligne supplément doit exister').toBe(true);
    expect(mAvec.tailleSupplementPx, 'R-071 : supplément en grand (>= 22 px)').toBeGreaterThanOrEqual(22);
    if (mAvec.tailleProduitPx) {
        expect(mAvec.tailleSupplementPx, 'R-071 : jamais plus petit que le produit').toBeGreaterThanOrEqual(mAvec.tailleProduitPx);
    }
    expect(mAvec.debordeHorizontalement, 'le supplément ne doit pas être coupé').toBe(false);
    // R-072 — « # » devant le produit à supplément, et SEULEMENT là.
    expect(mAvec.carteTexte, 'R-072 : « # » devant le produit à supplément').toMatch(/\d\s*×\s*#/);
    expect(mSans.aUneLigneSupplement, 'le témoin n\'a aucune ligne supplément').toBe(false);
    expect(mSans.carteTexte, 'témoin : PAS de « # » sans supplément').not.toMatch(/#/);
    expect(erreurs, 'aucune erreur JavaScript').toEqual([]);
});

test('Encaissement — onglet « Ratées » présent, pas de clé de traduction brute', async ({ page }) => {
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));

    await loginAsAdmin(page);
    await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(5000);
    await page.screenshot({ path: path.join(OUT, '04-encaissement.png') });

    const corps = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    console.log('ENCAISSEMENT_TEXTE', corps.slice(0, 400));
    expect(corps, 'R-059 : onglet « Ratées »').toMatch(/Ratées/i);
    expect(corps, 'pas de clé de traduction brute').not.toMatch(/\blabel\.enc_[a-z_]+/);
    expect(erreurs, 'aucune erreur JavaScript').toEqual([]);
});
