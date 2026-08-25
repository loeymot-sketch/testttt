// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

/**
 * E-005 — LE MUR DE STATUT NE DOIT PORTER AUCUN HABILLAGE D'ADMINISTRATION.
 *
 * Ce que le superviseur adverse a mesuré sur la capture du mur, deux rondes de suite :
 *   « Déconnexion » ×1, « admin@lecayenne.fr » ×1, un bouton « Tableau De Bord », le
 *   sélecteur de langue, un avatar et « Bonjour / Admin Le Cayenne » avec son menu.
 *
 * `/admin/order-status-screen` est une TÉLÉ TOURNÉE VERS LA SALLE. Le client y lit son
 * numéro de commande — et lisait aussi, au-dessus, l'adresse du compte d'administration
 * et un bouton de déconnexion, à un clic du back-office.
 *
 * Les tests unitaires de `murClientSansChromeAdmin.spec.js` gardent les SOURCES. Celui-ci
 * garde ce qui compte vraiment : le DOM RÉELLEMENT SERVI, après build, dans un navigateur.
 */

const MUR = 'http://127.0.0.1:8000/admin/order-status-screen';

/**
 * Un garde anti-vide : sans lui, une page blanche (assets absents, erreur PHP rendue en
 * guise de page) passerait tous les tests d'absence ci-dessous en beauté. C'est exactement
 * le piège qui a déjà transformé un environnement cassé en « tout va bien » dans cette
 * mission — trois fois.
 */
async function murVraimentRendu(page) {
    const corps = (await page.locator('body').innerText()).trim();
    expect(
        corps.length,
        'la page du mur est VIDE — aucun test d\'absence ci-dessous ne prouverait quoi que ce '
        + 'soit sur une page blanche. Vérifier le serveur et le build avant de lire un vert ici.'
    ).toBeGreaterThan(20);
    return corps;
}

test.describe('E-005 — mur de statut client', () => {
    test('le DOM servi ne contient NI identité d\'admin NI sortie de session', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto(MUR, { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(1500);

        await murVraimentRendu(page);
        const dom = await page.content();

        const fuites = [
            ['admin@lecayenne.fr', 'l\'adresse du compte d\'administration'],
            ['Déconnexion', 'la sortie de session'],
            ['Changer Le Mot De Passe', 'le menu de mot de passe'],
            ['Appareils Connectés', 'la gestion des appareils'],
            ['Modifier Le Profil', 'le menu de profil'],
        ];

        const trouvees = fuites.filter(([m]) => dom.includes(m));

        expect(
            trouvees.map(([m, quoi]) => `« ${m} » (${quoi})`).join(' + '),
            'RÉGRESSION E-005 : de l\'habillage d\'administration est de retour dans le DOM d\'un '
            + 'écran tourné vers la salle.'
        ).toBe('');
    });

    test('aucune navbar ni menu d\'administration n\'est monté', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto(MUR, { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(1500);

        await murVraimentRendu(page);

        for (const sel of ['.db-navbar', '.db-sidebar', '.db-menu', 'nav.navbar']) {
            expect(
                await page.locator(sel).count(),
                `« ${sel} » est présent sur le mur : l'habillage d'admin est remonté.`
            ).toBe(0);
        }
    });

    test('le mur affiche bien SON contenu — le correctif ne l\'a pas vidé', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto(MUR, { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(1500);

        const corps = await murVraimentRendu(page);

        // Le mur montre deux colonnes de statut. On exige la preuve qu'il rend ENCORE
        // quelque chose de sien : retirer l'habillage ne doit pas avoir retiré la page.
        expect(
            /pr[ée]paration|pr[êe]t|commande/i.test(corps),
            `le mur ne montre plus son propre contenu. Corps lu : « ${corps.slice(0, 200)} »`
        ).toBe(true);
    });

    test('aucun libellé brut ni erreur console sur le mur', async ({ page }) => {
        const erreurs = [];
        page.on('console', (m) => {
            if (m.type() === 'error') erreurs.push(m.text());
        });

        await loginAsAdmin(page);
        await page.goto(MUR, { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(1500);

        const corps = await murVraimentRendu(page);

        const bruts = (corps.match(/\b[a-z]+\.[a-z_]+(\.[a-z_]+)*\b/g) || [])
            .filter((m) => !/\.(js|css|png|jpg|jpeg|webp|svg|fr|com|net|org)$/.test(m));
        expect(bruts.join(', '), 'libellés de traduction non résolus sur le mur').toBe('');

        const vraies = erreurs.filter((e) => !/favicon|ResizeObserver/i.test(e));
        expect(vraies.join(' | '), 'erreurs console sur le mur').toBe('');
    });
});
