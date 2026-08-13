/**
 * [KDS 2026-08-13] Sonde : QUI occupe l'espace entre le haut de l'écran et la première commande ?
 * On ne devine pas une marge, on la lit.
 */
const { test } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');

test('KDS — qui mange les pixels du haut', async ({ page }) => {
    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);

    const rapport = await page.evaluate(() => {
        const carte = document.querySelector('.kds-card');
        const limite = carte ? carte.getBoundingClientRect().top : 200;

        const interessants = [];
        document.querySelectorAll('body *').forEach((el) => {
            const r = el.getBoundingClientRect();
            if (r.height <= 0 || r.width <= 0) return;
            if (r.top >= limite - 2) return;      // sous la première carte : hors sujet
            if (r.height > limite) return;         // conteneurs englobants : hors sujet
            if (r.width < 200) return;             // petits éléments : on veut les BANDES
            const cs = getComputedStyle(el);
            interessants.push({
                balise: el.tagName.toLowerCase(),
                classe: (el.className || '').toString().slice(0, 60),
                haut: Math.round(r.top),
                bas: Math.round(r.bottom),
                hauteur: Math.round(r.height),
                margeHaut: cs.marginTop,
                margeBas: cs.marginBottom,
                padHaut: cs.paddingTop,
                padBas: cs.paddingBottom,
            });
        });

        interessants.sort((a, b) => a.haut - b.haut || b.hauteur - a.hauteur);
        return { hautPremiereCarte: Math.round(limite), bandes: interessants.slice(0, 22) };
    });

    console.log('[SONDE] ' + JSON.stringify(rapport, null, 1));
});
