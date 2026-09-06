// [AUDIT-SUPERVISEUR 2026-08-25 · C-002 / C-018 / C-019]
//
// LE DÉFAUT — sur `/admin/historique`, la colonne ACTION est `position: sticky; right: 0`
// sur une table plus large que son conteneur. Au repos, la cellule épinglée se pose donc
// sur les colonnes de droite : le superviseur a mesuré **zéro pixel** rendu pour DATE et
// STATUT sur un état, et une date amputée en plein glyphe sur un autre (« 04:29, 25 » —
// 8 caractères sur 17 cachés, sans ellipse, sans infobulle).
//
// Le round précédent avait supprimé la BAVURE (fonds opaques) mais laissé le
// RECOUVREMENT, en le renvoyant à un arbitrage propriétaire. Deux colonnes entières
// invisibles ne sont pas un arbitrage : c'est un défaut.
//
// CE QUE CE SPEC MESURE — la seule chose qui compte : est-ce que la table TIENT dans son
// conteneur ? Si elle tient, rien n'est recouvert, et la question de l'épinglage devient
// sans objet. On mesure le débordement, pas l'intention.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SHOTS = path.join(__dirname, '__screenshots__', 'c002-colonne-date');
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

/** Un code 200 ne prouve pas qu'une page s'affiche — on lit le corps. */
async function allerVerifie(page, url) {
    const reponse = await page.goto(url, { waitUntil: 'domcontentloaded' });
    const corps = await page.content();
    for (const poison of ['Warning: require', 'Fatal error', 'Failed to open stream']) {
        if (corps.includes(poison)) throw new Error(`Application cassée (${poison}) — mesure refusée.`);
    }
    expect(reponse.status()).toBeLessThan(400);
}

for (const { l, h } of [{ l: 1280, h: 800 }, { l: 1366, h: 768 }]) {
    test(`historique : la colonne DATE est LISIBLE @ ${l}x${h}`, async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: l, height: h });
        await loginAsAdmin(page);
        await allerVerifie(page, '/admin/historique');
        await page.waitForSelector('table', { timeout: 45_000 });
        await page.waitForTimeout(2500);

        const mesure = await page.evaluate(() => {
            const table = document.querySelector('table');
            if (!table) return null;
            const conteneur = table.closest('.overflow-x-auto') || table.parentElement;

            // Repère la colonne DATE par son en-tête, puis la première cellule du corps.
            const entetes = Array.from(table.querySelectorAll('thead th'));
            const idxDate = entetes.findIndex((th) => /date/i.test(th.textContent || ''));
            const premiereLigne = table.querySelector('tbody tr');
            const cellule = (premiereLigne && idxDate >= 0)
                ? premiereLigne.querySelectorAll('td')[idxDate]
                : null;

            // La cellule épinglée (ACTION) — celle qui recouvre.
            const epinglee = premiereLigne
                ? Array.from(premiereLigne.querySelectorAll('td'))
                    .find((td) => getComputedStyle(td).position === 'sticky')
                : null;

            const boiteC = conteneur ? conteneur.getBoundingClientRect() : null;
            const boiteD = cellule ? cellule.getBoundingClientRect() : null;
            const boiteE = epinglee ? epinglee.getBoundingClientRect() : null;

            // Combien de la cellule DATE est masqué par l'épinglée ou hors conteneur.
            let masque = 0;
            if (boiteD && boiteC) {
                const droiteVisible = boiteE
                    ? Math.min(boiteC.right, boiteE.left)
                    : boiteC.right;
                masque = Math.max(0, boiteD.right - Math.max(boiteD.left, droiteVisible));
                masque = Math.min(masque, boiteD.width);
            }

            return {
                enteteDate: idxDate >= 0 ? (entetes[idxDate].textContent || '').trim() : null,
                debordement: conteneur ? Math.max(0, table.scrollWidth - conteneur.clientWidth) : null,
                largeurDate: boiteD ? Math.round(boiteD.width) : null,
                masqueDate: Math.round(masque),
                texteDate: cellule ? (cellule.textContent || '').trim() : null,
            };
        });

        expect(mesure, 'table introuvable').not.toBeNull();

        // GARDE ANTI-TEST-À-VIDE : sans ligne de données, il n'y a rien à masquer.
        expect(mesure.texteDate, `aucune ligne dans l'historique (${JSON.stringify(mesure)})`).toBeTruthy();

        await page.screenshot({ path: path.join(SHOTS, `historique-${l}x${h}.png`) });

        // LA MESURE QUI COMPTE — si la table tient, rien n'est recouvert.
        expect(
            mesure.debordement,
            `la table déborde de ${mesure.debordement} px : la colonne épinglée recouvre les colonnes de droite`
        ).toBe(0);

        // Et la conséquence, vérifiée directement plutôt que déduite.
        expect(
            mesure.masqueDate,
            `${mesure.masqueDate} px de la colonne DATE (${mesure.largeurDate} px) sont masqués — « ${mesure.texteDate} »`
        ).toBe(0);
    });
}
