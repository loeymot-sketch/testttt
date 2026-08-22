// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * [GOAL CAISSE 2026-08-22] LES RACCOURCIS F1–F12 MARCHENT-ILS VRAIMENT, DANS UN NAVIGATEUR ?
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * `tests/js/posBarcode.spec.js:67-76` prouve que le HELPER associe F1 à l'index 1. Il ne prouve
 * pas qu'une touche pressée sur la caisse ouvre une catégorie : le helper est testé isolé, sur
 * un DOM synthétique. Entre les deux il y a le montage réel (`PosComponent.vue:3115-3119`), la
 * garde `shouldIntercept`, l'état du composant et le routeur. Rien ne couvrait ce tronçon.
 *
 * Ça compte davantage depuis qu'on a mesuré que la grille des catégories démarre 24 px sous le
 * bord bas d'un écran 1366×768 : les touches F sont, aujourd'hui, le seul moyen d'atteindre une
 * catégorie sans faire défiler 687 px. Si elles cassaient en silence, le comptoir perdrait sa
 * seule échappatoire — et personne ne le saurait avant le coup de feu.
 *
 * ⚠️ LE DÉCALAGE D'UN CRAN, VÉRIFIÉ AU NAVIGATEUR — NE PAS LE « CORRIGER » SANS RELIRE CECI
 * `onFKeyShortcut` (`PosComponent.vue:5066-5074`) indexe `categories`, la liste BRUTE du store,
 * tandis que la grille rend `categoryTiles` = `browseCategoryTiles()` qui **filtre `id > 0`**
 * (`resources/js/helpers/posBrowseView.js:58`). La liste brute porte en tête la sentinelle
 * « toutes les catégories » (id = 0). Donc :
 *      F1  → sentinelle → `allCategory()` → **on RESTE sur la grille**
 *      F2  → 1re tuile (Sandwichs)   F3 → 2e tuile   …   soit **tuile N ↔ F(N+1)**
 * Mesuré le 2026-08-22 : F1 laisse la grille en place, F2/F3/F4 la font disparaître. C'est
 * cohérent, mais contre-intuitif : étiqueter les tuiles « F1…F9 » afficherait NEUF faux
 * libellés. Ce cas fige la correspondance réelle pour que la question soit tranchée une fois.
 *
 * ⚠️ POURQUOI DES ÉVÉNEMENTS SYNTHÉTIQUES ET PAS `keyboard.press('F2')`
 * En navigateur sans interface, les touches F sont interceptées AVANT la page (F1 = aide, etc.).
 * `keyboard.press('F2')` ne déclenche rien et donnerait un FAUX NÉGATIF — je m'y suis laissé
 * prendre avant de vérifier. On dispatche donc un `KeyboardEvent` sur `document`, exactement là
 * où `createFKeyShortcuts` écoute (`resources/js/helpers/posBarcode.js:72`).
 *
 * LANCEMENT : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/goal-caisse-raccourcis-fkeys.spec.js
 */

const IDENTIFIANT = process.env.POS_EMAIL || 'pos@lecayenne.fr';
const MOT_DE_PASSE = process.env.POS_PASSWORD || '123456';

test.use({ viewport: { width: 1366, height: 768 } });

/**
 * Ramène la caisse sur son écran d'accueil, que la session soit déjà ouverte ou non.
 *
 * ⚠️ La garde `#formEmail` n'est pas de la coquetterie : plusieurs cas ici rechargent l'accueil
 * DEUX fois (une fois par touche testée). Au deuxième passage la session existe déjà, `/login`
 * redirige vers `/admin/pos`, et un `fill('#formEmail')` inconditionnel attend un champ qui
 * n'apparaîtra jamais — le cas expire au bout de 90 s sans que le produit ait le moindre tort.
 * C'est exactement ce qui est arrivé à la première version de ce fichier.
 */
async function ouvrirLaCaisse(page) {
    await page.goto('/login', { waitUntil: 'networkidle' });

    if (await page.locator('#formEmail').count()) {
        await page.fill('#formEmail', IDENTIFIANT);
        await page.fill('#formPassword, input[type="password"]', MOT_DE_PASSE);
        await page.click('button:has-text("Connexion")');
        await page.waitForTimeout(3500);
    }

    await page.goto('/admin/pos', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-testid="pos-category-grid"]', { timeout: 20000 });
    await page.waitForTimeout(2500);
}

/** Dispatche la touche là où l'écouteur l'attend, et rend l'état de l'écran après coup. */
async function presserToucheF(page, n) {
    return page.evaluate((num) => {
        const ev = new KeyboardEvent('keydown', {
            key: `F${num}`, code: `F${num}`, bubbles: true, cancelable: true,
        });
        document.dispatchEvent(ev);

        return new Promise((resolve) => setTimeout(() => resolve({
            grilleVisible: !!document.querySelector('[data-testid="pos-category-grid"]'),
            interceptee: ev.defaultPrevented,
        }), 1400));
    }, n);
}

test('les touches F sont bien interceptées par la caisse', async ({ page }) => {
    await ouvrirLaCaisse(page);
    const r = await presserToucheF(page, 2);

    expect(
        r.interceptee,
        'La caisse doit consommer la touche (preventDefault) — sinon le navigateur la garde '
            + 'pour lui et le raccourci est mort sans que personne le voie.'
    ).toBe(true);
});

test('F2 ouvre la PREMIÈRE tuile, pas F1 — le décalage d\'un cran est réel', async ({ page }) => {
    await ouvrirLaCaisse(page);

    // F1 vise la sentinelle « toutes les catégories » : on reste sur l'écran d'accueil.
    const f1 = await presserToucheF(page, 1);
    expect(
        f1.grilleVisible,
        'F1 = « toutes les catégories » : la grille doit RESTER. Si ce cas casse, quelqu\'un a '
            + 'aligné les touches sur les tuiles — relire l\'en-tête de ce fichier avant de le "réparer".'
    ).toBe(true);

    // F2 entre dans la première catégorie : la grille cède la place aux produits.
    await ouvrirLaCaisse(page);
    const f2 = await presserToucheF(page, 2);
    expect(f2.grilleVisible, 'F2 doit entrer dans la première catégorie.').toBe(false);
});

test('F3 et F4 entrent aussi dans une catégorie', async ({ page }) => {
    for (const n of [3, 4]) {
        await ouvrirLaCaisse(page);
        const r = await presserToucheF(page, n);
        expect(r.grilleVisible, `F${n} doit ouvrir la tuile n°${n - 1}.`).toBe(false);
    }
});

/**
 * Le garde-fou qui compte le plus : une touche F ne doit JAMAIS voler la frappe pendant qu'on
 * remplit un champ. `createFKeyShortcuts` l'écarte via la même garde que le lecteur de
 * code-barres (`posBarcode.js:26-34`). Sans ce cas, une régression là-dessus se manifesterait
 * en plein service, sur le champ nom du client.
 */
test('une touche F ne détourne rien pendant la saisie d\'un champ', async ({ page }) => {
    await ouvrirLaCaisse(page);

    const resultat = await page.evaluate(() => {
        const champ = document.querySelector('[data-testid="pos-customer-name"]');
        if (!champ) {
            return { champTrouve: false };
        }
        champ.focus();
        const ev = new KeyboardEvent('keydown', {
            key: 'F2', code: 'F2', bubbles: true, cancelable: true,
        });
        champ.dispatchEvent(ev);

        return new Promise((resolve) => setTimeout(() => resolve({
            champTrouve: true,
            interceptee: ev.defaultPrevented,
            grilleVisible: !!document.querySelector('[data-testid="pos-category-grid"]'),
        }), 1200));
    });

    expect(resultat.champTrouve, 'Le champ nom du client doit être atteignable à l\'écran.').toBe(true);
    expect(resultat.interceptee, 'Pendant une saisie, la touche doit être laissée au champ.').toBe(false);
    expect(resultat.grilleVisible, 'Aucune navigation ne doit avoir lieu pendant une saisie.').toBe(true);
});
