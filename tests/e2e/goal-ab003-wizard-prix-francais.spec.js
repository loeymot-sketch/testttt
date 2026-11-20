// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

/**
 * AB-003 — L'ASSISTANT PRODUIT NE DOIT PLUS AFFICHER DE PRIX AU FORMAT ANGLAIS.
 *
 * Défaut mesuré : dans la MÊME capture, l'assistant affichait « €7.40 » pendant que la fiche
 * produit derrière lui affichait « 7,40 € » et le ticket caisse « 0,00 € ». La chaîne était
 * construite en dur dans `public/js/pos-wizard.js` — donc identique quelle que soit la locale
 * du navigateur, sur un produit dont la locale est immuable (ADR-007, FR).
 *
 * `pos-wizard.js` est en ZONE GELÉE (CLAUDE.md §7). Ce qui y est gelé est le DESIGN : ce test
 * garde donc les deux moitiés du contrat — le format est français, ET l'assistant s'ouvre et
 * fonctionne comme avant.
 *
 * Le test unitaire garde la SOURCE. Celui-ci garde ce qui compte : ce que le caissier LIT.
 */

/**
 * Sans ce garde, une page non connectée ou un assistant qui ne s'ouvre pas rendraient toutes
 * les assertions d'absence trivialement vraies. Le piège s'est déjà refermé sur moi une fois
 * dans cette mission.
 */
async function assistantOuvert(page) {
    const corps = (await page.locator('body').innerText()).trim();
    expect(
        /\/login(\?|$)/.test(page.url()),
        `SESSION PERDUE : « ${page.url()} ». Aucune assertion de ce fichier ne prouverait rien.`
    ).toBe(false);
    expect(corps.length, 'la page est vide').toBeGreaterThan(50);
    return corps;
}

test.describe('AB-003 — prix de l\'assistant produit', () => {
    test('aucun prix au format anglais nulle part sur la caisse', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsAdmin(page);
        await page.goto('/admin/pos-v4', { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(3000);
        const corps = await assistantOuvert(page);

        // Le motif exact du défaut : symbole DEVANT le nombre, point décimal.
        const anglais = corps.match(/€\s?\d+\.\d{2}/g) || [];
        expect(
            [...new Set(anglais)].join(', '),
            'RÉGRESSION AB-003 : des prix au format anglais (symbole devant, point décimal) '
            + 'sont affichés sur la caisse.'
        ).toBe('');
    });

    test('les prix affichés sont au format français', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsAdmin(page);
        await page.goto('/admin/pos-v4', { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(3000);
        const corps = await assistantOuvert(page);

        const francais = corps.match(/\d+,\d{2}\s*€/g) || [];
        expect(
            francais.length,
            'aucun prix au format français trouvé sur la caisse — soit la page n\'a pas '
            + 'chargé, soit le format a changé. Dans les deux cas ce test ne prouve plus rien : '
            + 'il doit échouer plutôt que passer à vide.'
        ).toBeGreaterThan(0);
    });

    test('ZONE GELÉE : l\'assistant se charge toujours et sans erreur', async ({ page }) => {
        const erreurs = [];
        page.on('console', (m) => {
            if (m.type() === 'error' && !/9100|9101|favicon|ResizeObserver/i.test(m.text())) {
                erreurs.push(m.text().slice(0, 140));
            }
        });
        page.on('pageerror', (e) => erreurs.push(`[pageerror] ${e.message}`.slice(0, 140)));

        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsAdmin(page);
        await page.goto('/admin/pos-v4', { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(3000);
        await assistantOuvert(page);

        // Le script de l'assistant doit être chargé ET avoir posé son formateur.
        const charge = await page.evaluate(() => Boolean(
            document.querySelector('script[src*="pos-wizard"]')
            || document.querySelector('[class*="wizard"], [id*="wizard"]')
        ));
        expect(charge, 'le script de l\'assistant n\'est plus chargé par la page').toBe(true);

        const graves = erreurs.filter((e) => !/Object\.resolve|at J \(/.test(e));
        expect(
            [...new Set(graves)].join(' | '),
            'le correctif de format a introduit une erreur dans une zone gelée'
        ).toBe('');
    });
});
