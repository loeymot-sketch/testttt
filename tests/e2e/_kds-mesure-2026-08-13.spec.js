/**
 * [KDS 2026-08-13] Mesure de l'écran cuisine AVANT refonte.
 *
 * L'exploitant dit que « presque 30 % de l'espace » est mangé par les boutons du haut. On ne
 * refond pas sur une impression : on mesure la hauteur réellement occupée, on inventorie les
 * boutons, et on saura ensuite si la refonte a rendu l'espace promis.
 */
const { test } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/kds-2026-08-13');
fs.mkdirSync(OUT, { recursive: true });

test('KDS — mesure de l\'espace et inventaire des boutons', async ({ page }) => {
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);

    await page.screenshot({ path: path.join(OUT, 'avant-01-plein-ecran.png'), fullPage: false });

    const mesure = await page.evaluate(() => {
        const vh = window.innerHeight;
        const corps = document.body;

        // Tout ce qui est visuellement AU-DESSUS de la première carte de commande.
        const carte = document.querySelector(
            '[data-testid^="kds-order-"], .kds-order-card, .kds-v2-card, [class*="order-card"]'
        );
        const hautCarte = carte ? carte.getBoundingClientRect().top : null;

        // Inventaire des boutons visibles et de leur position verticale.
        const boutons = [...document.querySelectorAll('button, a[role="button"], .btn')]
            .filter((b) => {
                const r = b.getBoundingClientRect();
                return r.width > 0 && r.height > 0 && r.top < vh;
            })
            .map((b) => {
                const r = b.getBoundingClientRect();
                return {
                    texte: (b.innerText || b.getAttribute('aria-label') || '').trim().slice(0, 32),
                    testid: b.getAttribute('data-testid') || null,
                    haut: Math.round(r.top),
                    hauteur: Math.round(r.height),
                };
            });

        // Combien de « rangées » distinctes de boutons au-dessus des commandes ?
        const rangees = [...new Set(
            boutons.filter((b) => hautCarte === null || b.haut < hautCarte).map((b) => Math.round(b.haut / 12) * 12)
        )].sort((a, b) => a - b);

        return {
            hauteurFenetre: vh,
            hautDeLaPremiereCarte: hautCarte === null ? null : Math.round(hautCarte),
            pourcentagePerduEnHaut: hautCarte === null ? null : Math.round((hautCarte / vh) * 100),
            nombreDeBoutons: boutons.length,
            rangeesDeBoutonsAuDessus: rangees,
            boutons: boutons.slice(0, 40),
            hauteurDocument: corps.scrollHeight,
        };
    });

    console.log('[MESURE] ' + JSON.stringify(mesure, null, 2));
    console.log('[ERREURS JS] ' + JSON.stringify(erreurs));
    fs.writeFileSync(path.join(OUT, 'mesure-avant.json'), JSON.stringify({ mesure, erreurs }, null, 2));
});
