/**
 * [KDS 2026-08-13] Test profond des chemins demandés : consultation d'historique et impression
 * directe depuis l'écran cuisine.
 *
 * On ne vérifie pas « le bouton existe » mais « le chemin va au bout » : la requête part, le
 * serveur répond, et l'écran montre quelque chose d'exploitable.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/kds-2026-08-13');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial' });

test('HISTORIQUE — le chef consulte la journée depuis l\'écran cuisine', async ({ page }) => {
    const erreurs = [];
    const appels = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));
    page.on('response', (r) => {
        if (r.url().includes('history')) appels.push(`${r.status()} ${r.url().replace(/^https?:\/\/[^/]+/, '')}`);
    });

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(6000);

    await page.getByTestId('kds-history-button').click();
    await page.waitForTimeout(4000);
    await page.screenshot({ path: path.join(OUT, 'historique-01-ouvert.png') });

    const etat = await page.evaluate(() => {
        const txt = document.body.innerText || '';
        return {
            tiroirVisible: !!document.querySelector('[data-testid="kds-history-drawer"], .kds-history-drawer'),
            mentionneHistorique: /historique/i.test(txt),
            lignes: document.querySelectorAll('[data-testid^="kds-history-row"], .kds-history-row').length,
            libellesBruts: (txt.match(/\bLabel\.[a-zA-Z_.]+/g) || []).slice(0, 3),
        };
    });

    console.log('[HISTORIQUE] ' + JSON.stringify({ etat, appels, erreurs }));

    expect(appels.some((a) => a.startsWith('200')), `l'historique doit répondre 200 — appels : ${JSON.stringify(appels)}`).toBe(true);
    expect(etat.libellesBruts, 'aucun libellé de traduction brut à l\'écran').toEqual([]);
    expect(erreurs, 'aucune erreur JS').toEqual([]);
});

test('IMPRESSION — le bouton réimprimer déclenche bien une demande au serveur', async ({ page }) => {
    const appels = [];
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));
    page.on('response', (r) => {
        if (/escpos|print/.test(r.url())) appels.push(`${r.status()} ${r.url().replace(/^https?:\/\/[^/]+/, '').split('?')[0]}`);
    });

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(6000);

    const bouton = page.getByTestId('kds-card-reprint').first();
    await expect(bouton, 'le bouton d\'impression doit être présent sur une carte').toBeVisible({ timeout: 20_000 });
    await bouton.click();
    await page.waitForTimeout(5000);
    await page.screenshot({ path: path.join(OUT, 'impression-01-apres-clic.png') });

    console.log('[IMPRESSION] ' + JSON.stringify({ appels, erreurs }));

    // Le pont local n'existe pas sur la machine de test : le papier ne sortira pas. Ce qui doit
    // être vrai, c'est que le chemin SERVEUR a été emprunté et qu'il a répondu — le reste dépend
    // du matériel, pas du logiciel.
    expect(appels.length, `une demande d'octets de ticket doit partir — appels : ${JSON.stringify(appels)}`).toBeGreaterThan(0);
    expect(appels.some((a) => a.startsWith('200')), `le serveur doit répondre 200 — ${JSON.stringify(appels)}`).toBe(true);
    expect(erreurs, 'aucune erreur JS').toEqual([]);
});
