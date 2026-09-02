// FoodKing E2E — GOAL CAISSE CONTRÔLE (2026-09-02)
//
// Demande propriétaire (verbatim) :
//   « Toute l'interface de gestion de la caisse […] contrôler toutes les commandes livrées,
//     voir ce qui est en cours, les commandes prêtes, celles pas encore encaissées, toutes celles
//     en cuisine — toujours voir ce qu'il y a dedans en mode technique avec le nom de produits
//     ainsi que l'heure de commande et son numéro, elle est numéro combien par rapport à la
//     cuisine, parce qu'il y a combien d'attente, directement depuis la caisse […] je me perds
//     toujours pour les commandes pas encaissées entre les clients qui viennent […] pour les
//     commandes en cours je veux PAS que ça ouvre une nouvelle page, vraiment directement en
//     petite barre à droite. »
//
// CE QUE CE BANC MESURE, ET COMMENT IL ÉVITE DE SE MENTIR (CLAUDE.md §3ter) :
//
//  · « pas une nouvelle page » n'est PAS mesuré par `page.on('framenavigated')` — cet événement
//    se déclenche aussi sur une navigation SPA, il ne prouve donc rien. L'instrument valide est
//    un MARQUEUR posé sur `window` : il survit à une navigation SPA et disparaît au rechargement
//    du document. C'est celui-là qui est utilisé, et l'URL est vérifiée inchangée en plus.
//  · les produits recherchés à l'écran sont ceux que le semeur a RÉELLEMENT écrits, résolus par
//    nom sur la carte réelle — chercher un produit inexistant ne prouverait rien.
//  · le rang cuisine attendu (1ᵉʳ…4ᵉ) découle de l'antidatage du semis, pas d'une lecture de
//    l'écran : si le composant se trompait d'ordre, l'assertion tomberait.
//
// Référence de l'état AVANT correctif : `reports/goal-caisse-controle-2026-09-02/captures-avant/`.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('./helpers/login');
const { seedService, cleanup } = require('./helpers/seed-caisse-controle');

const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = path.join(REPO_ROOT, 'reports', 'goal-caisse-controle-2026-09-02', 'captures-apres');
fs.mkdirSync(SHOTS, { recursive: true });

let ids = {};

test.beforeAll(() => { ids = seedService(); });
test.afterAll(() => { if (!process.env.KEEP_SEED) cleanup(); });

async function ouvrirCaisse(page) {
    await loginAsPosOperator(page);
    await page.waitForTimeout(4000);
    // Marqueur : il ne survit PAS à un rechargement de document.
    await page.evaluate(() => { window.__gcc = 'vivant'; });
}

const vivant = (page) => page.evaluate(() => window.__gcc === 'vivant');

test.describe('Le tiroir de contrôle des commandes', () => {
    test.setTimeout(180_000);

    test('s’ouvre SANS quitter la caisse, et montre les quatre files', async ({ page }) => {
        await ouvrirCaisse(page);
        const urlAvant = page.url();

        // UN SEUL bouton « commandes » dans la barre : « Suivi commandes », le libellé que le
        // propriétaire connaît, qui ouvre désormais le tiroir au clic simple.
        const bouton = page.getByTestId('pos-tracker-open');
        await expect(bouton).toBeVisible();
        await expect(page.getByTestId('pos-control-open')).toHaveCount(0);
        // Deux pastilles, jamais un total : « à encaisser » et « prêtes » ne sont pas de même nature.
        await expect(page.getByTestId('pos-control-badge-cash')).toContainText('3');
        await expect(page.getByTestId('pos-control-badge-ready')).toContainText('2');
        await page.screenshot({ path: path.join(SHOTS, '01-caisse-bouton-commandes.png'), fullPage: false });

        await bouton.click();
        await expect(page.getByTestId('pos-control-drawer')).toBeVisible();

        // LA mesure : le document n'a pas été remplacé, et l'URL n'a pas bougé.
        expect(await vivant(page), 'le document a été rechargé — c’est une nouvelle page').toBe(true);
        expect(page.url()).toBe(urlAvant);

        // Les quatre compteurs, dans l'ordre des onglets.
        await expect(page.getByTestId('pos-control-count-encaisser')).toHaveText('3');
        await expect(page.getByTestId('pos-control-count-cuisine')).toHaveText('4');
        await expect(page.getByTestId('pos-control-count-pretes')).toHaveText('2');
        await expect(page.getByTestId('pos-control-count-livrees')).toHaveText('2');
        await page.screenshot({ path: path.join(SHOTS, '02-tiroir-a-encaisser.png') });
    });

    test('la file « à encaisser » identifie le client : nom, produits, composition, heure, rang', async ({ page }) => {
        await ouvrirCaisse(page);
        await page.getByTestId('pos-tracker-open').click();
        const panneau = page.getByTestId('pos-control-panel-encaisser');
        await expect(panneau).toBeVisible();

        // La commande téléphone : le client n'est PAS là, son nom est la seule prise.
        const carteT1 = page.getByTestId(`pos-control-card-${ids.T1.id}`);
        await expect(carteT1).toContainText('Mme Diallo');
        await expect(carteT1).toContainText('06 12 34 56 78');
        // Le contenu, en clair — c'est ce que le propriétaire appelle « le mode technique ».
        await expect(carteT1).toContainText('Bol Frites');
        await expect(carteT1).toContainText('Cordon Bleu');
        await expect(carteT1).toContainText('Blanche');
        await expect(carteT1).toContainText(/commandée à \d{2}:\d{2}/);
        // Le rang dans la file cuisine : T1 est la dernière entrée des quatre.
        await expect(page.getByTestId(`pos-control-rank-${ids.T1.id}`)).toContainText('4ᵉ sur 4 en cuisine');

        // La borne la plus ancienne : composition compacte, âge en ambre au-delà de 10 min.
        const carteK1 = page.getByTestId(`pos-control-card-${ids.K1.id}`);
        await expect(carteK1).toContainText('Tacos M');
        await expect(carteK1).toContainText('Poulet mariné');
        await expect(carteK1).toContainText('+Cheddar');
        await expect(page.getByTestId(`pos-control-rank-${ids.K1.id}`)).toContainText('1ᵉʳ sur 4 en cuisine');

        // Les commandes d'avant aujourd'hui sont ANNONCÉES, pas mêlées : trois cartes, pas 587.
        await expect(panneau.locator('.pos-ctrl-carte')).toHaveCount(3);
        await page.screenshot({ path: path.join(SHOTS, '03-a-encaisser-detail.png') });
    });

    test('la file cuisine dit qui est devant qui — et compte QUATRE, pas une', async ({ page }) => {
        await ouvrirCaisse(page);
        await page.getByTestId('pos-tracker-open').click();
        await page.getByTestId('pos-control-tab-cuisine').click();

        const lignes = page.getByTestId('pos-control-panel-cuisine').locator('.pos-ctrl-ligne');
        await expect(lignes).toHaveCount(4);
        await expect(lignes.nth(0)).toContainText('1ᵉʳ');
        await expect(lignes.nth(3)).toContainText('4ᵉ');
        // Ordre d'entrée en cuisine, imposé par l'antidatage du semis (14, 9, 6, 3 min).
        await expect(lignes.nth(0)).toContainText(`N°${ids.K1.queue}`);
        await expect(lignes.nth(3)).toContainText(`N°${ids.T1.queue}`);
        // Celles qui sont AUSSI à encaisser portent la cloche ; la commande comptoir payée, non.
        await expect(page.getByTestId(`pos-control-bell-${ids.K1.id}`)).toBeVisible();
        await expect(page.getByTestId(`pos-control-bell-${ids.P1.id}`)).toHaveCount(0);
        await page.screenshot({ path: path.join(SHOTS, '04-file-cuisine.png') });
    });

    test('la commande COMPTOIR prête redevient visible depuis la caisse', async ({ page }) => {
        // Défaut mesuré avant correctif : le panneau « Prêt à livrer » et le badge de la caisse
        // étaient nourris par un flux filtré BORNE + À EMPORTER. La commande comptoir prête de
        // « Sofiane » n'apparaissait NULLE PART sur l'écran de la caisse.
        await ouvrirCaisse(page);

        // (a) dans le panneau raccourci, sans ouvrir quoi que ce soit
        await expect(page.getByTestId(`pos-shortcut-ready-${ids.R2.id}`)).toBeVisible();

        // (b) et dans la file « Prêtes » du tiroir, avec le nom du client
        await page.getByTestId('pos-tracker-open').click();
        await page.getByTestId('pos-control-tab-pretes').click();
        const carteR2 = page.getByTestId(`pos-control-card-${ids.R2.id}`);
        await expect(carteR2).toContainText('Sofiane');
        await expect(carteR2).toContainText('Big Burger');
        await page.screenshot({ path: path.join(SHOTS, '05-pretes-comptoir-visible.png') });
    });

    test('les livrées se relisent, la plus récente d’abord', async ({ page }) => {
        await ouvrirCaisse(page);
        await page.getByTestId('pos-tracker-open').click();
        await page.getByTestId('pos-control-tab-livrees').click();
        const lignes = page.getByTestId('pos-control-panel-livrees').locator('.pos-ctrl-ligne');
        await expect(lignes).toHaveCount(2);
        await expect(lignes.nth(0)).toContainText(`N°${ids.D1.queue}`); // 32 min — plus récente
        await expect(lignes.nth(1)).toContainText(`N°${ids.D2.queue}`); // 47 min
        await page.screenshot({ path: path.join(SHOTS, '06-livrees.png') });
    });

    test('« Voir tout » montre le contenu intégral, sans quitter la caisse', async ({ page }) => {
        await ouvrirCaisse(page);
        const urlAvant = page.url();
        await page.getByTestId('pos-tracker-open').click();
        await page.getByTestId(`pos-control-open-${ids.K2.id}`).click();

        const detail = page.getByTestId('pos-control-detail');
        await expect(detail).toBeVisible();
        // K2 porte trois lignes, une instruction, et deux quantités > 1.
        await expect(detail).toContainText('Cayenne');
        await expect(detail).toContainText('Grande Frites');
        await expect(detail).toContainText('Sprite 33cl');
        await expect(detail).toContainText('Sans oignons');
        expect(await vivant(page)).toBe(true);
        expect(page.url()).toBe(urlAvant);
        await page.screenshot({ path: path.join(SHOTS, '07-voir-tout.png') });
    });
});

test.describe('Le ticket en cours annonce l’attente, sans la prédire', () => {
    test.setTimeout(120_000);

    test('affiche trois faits mesurés et aucune estimation', async ({ page }) => {
        await ouvrirCaisse(page);
        const ligne = page.getByTestId('pos-cart-kitchen-depth');
        await expect(ligne).toBeVisible();
        await expect(ligne).toContainText('4 en cuisine');
        await expect(ligne).toContainText('la plus ancienne depuis');
        await expect(ligne).toContainText('vous serez le 5ᵉ');
        // Aucune promesse de durée : l'âge du plus ancien n'est pas l'attente du prochain.
        await expect(ligne).not.toContainText('≈');
        await page.screenshot({ path: path.join(SHOTS, '08-ticket-attente-cuisine.png') });
    });

    test('un clic dessus ouvre la file cuisine, toujours sans changer de page', async ({ page }) => {
        await ouvrirCaisse(page);
        const urlAvant = page.url();
        await page.getByTestId('pos-cart-kitchen-depth').click();
        await expect(page.getByTestId('pos-control-panel-cuisine')).toBeVisible();
        expect(await vivant(page)).toBe(true);
        expect(page.url()).toBe(urlAvant);
    });
});

test.describe('« Suivi commandes » cesse de recharger la caisse', () => {
    test.setTimeout(120_000);

    test('le clic simple ouvre le tiroir ; le lien reste un vrai lien', async ({ page }) => {
        await ouvrirCaisse(page);
        const urlAvant = page.url();

        // Le lien garde son `href` : clic-milieu et Ctrl-clic ouvrent toujours la page complète,
        // et les bancs qui vérifient sa présence restent verts.
        const lien = page.getByTestId('pos-tracker-open');
        await expect(lien).toHaveAttribute('href', /pos-orders-tracker/);

        await lien.click();
        await expect(page.getByTestId('pos-control-drawer')).toBeVisible();
        expect(await vivant(page), 'le clic simple a rechargé le document').toBe(true);
        expect(page.url()).toBe(urlAvant);
        await page.screenshot({ path: path.join(SHOTS, '09-suivi-ouvre-le-tiroir.png') });
    });

    test('le pied du tiroir mène quand même à la page complète', async ({ page }) => {
        await ouvrirCaisse(page);
        await page.getByTestId('pos-tracker-open').click();
        await page.getByTestId('pos-control-full-page').click();
        await page.waitForURL(/pos-orders-tracker/, { timeout: 30_000 });
        expect(page.url()).toMatch(/pos-orders-tracker/);
    });
});

test.describe('Depuis /admin/pos-v4 aussi — c’est là que la mesure AVANT était la pire', () => {
    test.setTimeout(120_000);

    test('15,6 s de rechargement deviennent une ouverture sur place', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(5000);
        await page.evaluate(() => { window.__gcc = 'vivant'; });
        const urlAvant = page.url();

        const t0 = Date.now();
        await page.getByTestId('pos-tracker-open').click();
        await expect(page.getByTestId('pos-control-drawer')).toBeVisible();
        const ms = Date.now() - t0;

        expect(await vivant(page)).toBe(true);
        expect(page.url()).toBe(urlAvant);
        expect(ms, `ouverture en ${ms} ms`).toBeLessThan(2000);
        fs.writeFileSync(
            path.join(SHOTS, 'mesure-pos-v4.json'),
            JSON.stringify({ ouvertureMs: ms, spa: true, url: page.url() }, null, 2),
        );
        await page.screenshot({ path: path.join(SHOTS, '10-pos-v4-tiroir.png') });
    });
});
