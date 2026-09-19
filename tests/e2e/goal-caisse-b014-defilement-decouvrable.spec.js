// [AUDIT-SUPERVISEUR 2026-08-25 · B-014]
//
// LE DÉFAUT — le correctif du P0 précédent a supprimé le débordement peint (plus rien
// ne recouvre une commande cliquable), mais il a envoyé la facture ailleurs : sur un
// panier chargé, l'en-tête du panneau panier cache 243 de ses 450 px — 54 % — SANS
// AUCUN INDICE qu'on peut défiler. Disparaissent le sélecteur de type de commande, le
// champ « Nom du client », le téléphone, « Programmer », et « Annuler la dernière
// ligne » : un bouton qui n'existe dans la page QUE lorsque le panier a des lignes,
// donc caché exactement au moment où il sert.
//
// CE QUE CE SPEC EXIGE — non pas que tout tienne (impossible sur 600 px de haut), mais
// que le caissier SACHE qu'il y a autre chose. Deux signaux mesurables : une barre de
// défilement qui occupe de la place, et une ombre de bord.
//
// UNE LEÇON PAYÉE SUR CE FICHIER MÊME — sa première version visait un sélecteur de
// tuile INEXISTANT. Le panier restait vide, l'en-tête ne débordait pas, et le test
// sortait en vert en annonçant « rien de caché ». Il ne mesurait RIEN. D'où les deux
// gardes ci-dessous : on échoue si le panier n'a pas de ligne, et on échoue si
// l'en-tête ne déborde pas — car dans les deux cas c'est la mise en scène qui a
// échoué, pas le produit qui va bien.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SHOTS = path.join(__dirname, '__screenshots__', 'b014-defilement');
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

/** Refuse de mesurer une application cassée — un code 200 ne prouve pas qu'une page s'affiche. */
async function allerVerifie(page, url) {
    const reponse = await page.goto(url, { waitUntil: 'domcontentloaded' });
    const corps = await page.content();
    for (const poison of ['Warning: require', 'Fatal error', 'Failed to open stream']) {
        if (corps.includes(poison)) throw new Error(`Application cassée (${poison}) — mesure refusée.`);
    }
    expect(reponse.status()).toBeLessThan(400);
}

/**
 * Remplit le panier en passant par l'ASSISTANT — le vrai chemin du caissier.
 *
 * Sélecteurs repris de `audit-supervisor-waveB.spec.js`, qui les a éprouvés : une tuile
 * ouvre l'assistant (`public/js/pos-wizard.js`, zone GELÉE, lue jamais modifiée), et il
 * faut satisfaire les étapes requises (viande, sauce) avant que « Ajouter au panier »
 * accepte — `singlePageCanAddToCart()` refuse sinon, et c'est le BON comportement.
 *
 * Le diagnostic est RENVOYÉ, jamais avalé.
 */
async function chargerLePanier(page) {
    const diag = { poses: 0, refus: 0 };

    // La caisse est « catégorie d'abord » : aucune tuile produit n'existe tant qu'une
    // catégorie n'a pas été choisie. C'est ce qui a fait échouer ma première mise en
    // scène — je cherchais des tuiles sur un écran qui n'en affiche encore aucune.
    await page.locator('#pos-cart').waitFor({ state: 'visible', timeout: 45_000 }).catch(() => {});
    await page.locator('[data-testid="pos-category-grid"], [data-testid="pos-shortcuts"]')
        .first().waitFor({ state: 'visible', timeout: 45_000 }).catch(() => {});
    await page.waitForTimeout(2500);

    if ((await page.locator('.pos-v5-tile').count().catch(() => 0)) === 0) {
        const categories = page.locator('[data-testid="pos-category-grid"] button, .pos-v5-category-tile');
        const nbCat = await categories.count().catch(() => 0);
        for (let c = 0; c < Math.min(nbCat, 4); c += 1) {
            await categories.nth(c).click({ timeout: 4000 }).catch(() => {});
            await page.waitForTimeout(1200);
            if ((await page.locator('.pos-v5-tile').count().catch(() => 0)) > 0) break;
        }
        diag.categories_essayees = nbCat;
    }

    for (let produit = 0; produit < 4 && diag.poses < 2; produit += 1) {
        const tuiles = page.locator('.pos-v5-tile');
        if ((await tuiles.count().catch(() => 0)) === 0) break;

        await tuiles.nth(produit).click({ timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(600);

        const ajouter = page.locator('[data-action="add-to-cart"]').first();
        if (!(await ajouter.isVisible({ timeout: 3000 }).catch(() => false))) {
            // Pas d'assistant : le produit est entré directement au panier.
            diag.poses += 1;
            continue;
        }

        const plus = page.locator('.wizard-viande-tile button[data-action="plus"]');
        if (await plus.count().catch(() => 0)) {
            await plus.first().click({ timeout: 4000 }).catch(() => {});
            await page.waitForTimeout(400);
        }
        const sauce = page.locator('.sauce-chip').first();
        if (await sauce.count().catch(() => 0)) {
            await sauce.click({ timeout: 4000 }).catch(() => {});
            await page.waitForTimeout(400);
        }

        await ajouter.click({ timeout: 6000 }).catch(() => {});
        const referme = await ajouter.waitFor({ state: 'hidden', timeout: 9000 })
            .then(() => true).catch(() => false);

        if (referme) {
            diag.poses += 1;
            await page.waitForTimeout(900);
        } else {
            diag.refus += 1;
            await page.locator('[data-action="cancel-wizard"], .wizard-btn-cancel').first()
                .click({ timeout: 4000 }).catch(() => {});
            await page.keyboard.press('Escape').catch(() => {});
            await page.waitForTimeout(700);
        }
    }

    diag.lignes = await page.locator('.pos-v5-cart-item').count().catch(() => 0);
    return diag;
}

for (const { l, h } of [{ l: 1366, h: 768 }, { l: 1024, h: 600 }]) {
    test(`en-tête du panier : le défilement est DÉCOUVRABLE @ ${l}x${h}`, async ({ page }) => {
        test.setTimeout(240_000);
        await page.setViewportSize({ width: l, height: h });
        await loginAsAdmin(page);
        await allerVerifie(page, '/admin/pos');
        await page.waitForSelector('.pos-v5-cart__head', { timeout: 30_000 });

        const diag = await chargerLePanier(page);
        await page.waitForTimeout(800);

        // GARDE 1 — sans panier, il n'y a rien à mesurer.
        expect(
            diag.lignes,
            `panier vide (${JSON.stringify(diag)}) — la mise en scène a échoué, la mesure n'a pas eu lieu`
        ).toBeGreaterThan(0);

        const mesure = await page.evaluate(() => {
            const tete = document.querySelector('.pos-v5-cart__head');
            if (!tete) return null;
            const style = getComputedStyle(tete);
            return {
                cache: Math.max(0, tete.scrollHeight - tete.clientHeight),
                defilable: tete.scrollHeight > tete.clientHeight + 1,
                overflowY: style.overflowY,
                largeurBarre: tete.offsetWidth - tete.clientWidth,
                // L'affordance réelle : le dégradé épinglé au bas de la zone.
                voile: (() => {
                    const a = getComputedStyle(tete, '::after');
                    return { position: a.position, bottom: a.bottom, hauteur: a.height, fond: a.backgroundImage };
                })(),
            };
        });

        expect(mesure, 'en-tête du panier introuvable').not.toBeNull();

        // GARDE 2 — si rien ne déborde, ce n'est pas une bonne nouvelle : c'est que la
        // mise en scène n'a pas produit l'état qu'on prétend vérifier.
        expect(
            mesure.defilable,
            `l'en-tête ne déborde pas à ${l}×${h} (${JSON.stringify({ ...diag, ...mesure })})`
        ).toBe(true);

        // L'AFFORDANCE — un dégradé ÉPINGLÉ au bas de la zone qui défile.
        //
        // Pourquoi pas la barre de défilement : mesuré sur ce moteur, `largeurBarre`
        // vaut 0 alors que des centaines de pixels sont cachés — les barres sont
        // flottantes et n'apparaissent qu'APRÈS le geste. Elles ne peuvent donc pas
        // servir à faire DÉCOUVRIR le geste. C'est un constat, pas une préférence :
        // la première version de ce correctif s'appuyait dessus et ce test l'a réfutée.
        expect(mesure.voile.position, 'le voile de bas de zone n\'est pas épinglé').toBe('sticky');
        expect(mesure.voile.bottom, 'le voile n\'est pas ancré en bas').toBe('0px');
        expect(parseFloat(mesure.voile.hauteur), 'voile sans hauteur').toBeGreaterThan(0);
        expect(mesure.voile.fond, 'le voile ne peint aucun dégradé').toContain('gradient');

        // Et la barre reste mesurée, pour documenter le constat plutôt que l'oublier.
        test.info().annotations.push({
            type: 'mesure',
            description: `${mesure.cache} px cachés · largeur de barre ${mesure.largeurBarre} px (0 = barre flottante)`,
        });

        await page.screenshot({ path: path.join(SHOTS, `entete-defilable-${l}x${h}.png`) });
    });
}
