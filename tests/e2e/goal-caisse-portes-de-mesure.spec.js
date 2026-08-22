// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * [GOAL CAISSE PARFAITE 2026-08-22] LES PORTES DE MESURE C1 / C2 / C3.
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * Le mandat visuel du projet (CLAUDE.md §6) dit qu'un test technique vert ne prouve pas qu'un
 * écran est correct. L'inverse est vrai aussi : « l'écran a l'air mieux » ne prouve rien. Ce
 * fichier transforme les trois critères du GOAL en chiffres qu'une machine peut refuser.
 *
 * ÉTAT À LA CRÉATION (2026-08-22, AVANT tout correctif) :
 *   · C1 ÉCHOUE  — la grille des catégories commence à y=792 sur un écran de 768.
 *   · C2 ÉCHOUE  — 214 px de contrôles de saisie sont derrière un défilement interne.
 *   · C3 PASSE   — et doit CONTINUER de passer : c'est le gain du 2026-08-19.
 * C'est voulu : rouge d'abord. Un test écrit après le correctif ne prouve que sa propre
 * complaisance.
 *
 * C3 EST UNE SENTINELLE, PAS UN OBJECTIF
 * Avant le 2026-08-19, l'en-tête et le pied du panier étaient tous deux incompressibles
 * (798 px fixes) et le corps — la commande elle-même — n'avait plus que 40 px : le caissier
 * lisait sa vente par un hublot. Le correctif a rendu l'en-tête compressible. Ce GOAL déplace
 * ce qui souffre de ce compromis ; il ne réclame pas les pixels rendus. C3 échoue si on essaie.
 *
 * LANCEMENT : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/goal-caisse-portes-de-mesure.spec.js
 */

const IDENTIFIANT = process.env.POS_EMAIL || 'pos@lecayenne.fr';
const MOT_DE_PASSE = process.env.POS_PASSWORD || '123456';

/** Postes de comptoir réalistes. 1024×768 est le pire cas encore en service. */
const GABARITS = [
    { nom: 'portable-comptoir', width: 1366, height: 768 },
    { nom: 'tablette-paysage', width: 1280, height: 800 },
    { nom: 'vieux-poste', width: 1024, height: 768 },
    { nom: 'grand-ecran', width: 1920, height: 1080 },
];

async function ouvrirLaCaisse(page) {
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.fill('#formEmail', IDENTIFIANT);
    await page.fill('#formPassword, input[type="password"]', MOT_DE_PASSE);
    await page.click('button:has-text("Connexion")');
    await page.waitForTimeout(3500);
    await page.goto('/admin/pos', { waitUntil: 'networkidle' });
    // La caisse charge son catalogue par plusieurs appels ; on laisse la grille se peindre.
    await page.waitForSelector('[data-testid="pos-category-grid"]', { timeout: 20000 });
    await page.waitForTimeout(2500);
    // La debugbar est un artefact de développement, pas un élément de la caisse.
    await page.addStyleTag({ content: '.phpdebugbar{display:none !important}' });
}

/** Toutes les mesures en un seul aller-retour : moins de flottement entre deux lectures. */
async function mesurer(page) {
    return page.evaluate(() => {
        const main = document.querySelector('main.db-main');
        const head = document.querySelector('.pos-v5-cart__head');
        const body = document.querySelector('.pos-v5-cart__body');
        const foot = document.querySelector('.pos-v5-cart__foot');
        const grille = document.querySelector('[data-testid="pos-category-grid"]');

        // Un contrôle est « coupé » si son bas dépasse la zone visible de son conteneur
        // défilant. On ne compte QUE les contrôles de saisie : un titre tronqué se relit,
        // un champ tronqué ne se remplit pas.
        const coupes = head
            ? [...head.querySelectorAll('input, select, textarea, button')].filter((el) => {
                  const zone = head.getBoundingClientRect();
                  const r = el.getBoundingClientRect();
                  const visible = r.width > 0 && r.height > 0;

                  return visible && (r.bottom > zone.bottom + 1 || r.top < zone.top - 1);
              }).map((el) => (el.innerText || el.placeholder || el.getAttribute('aria-label') || el.type || '?')
                  .trim().replace(/\s+/g, ' ').slice(0, 40))
            : [];

        const rect = (el) => (el ? el.getBoundingClientRect() : null);
        const rGrille = rect(grille);
        // Hauteur d'une tuile : la grille se réagence selon la largeur, on mesure le vrai enfant.
        const tuile = grille ? grille.querySelector('[role="listitem"], li, button, a, div') : null;
        const hTuile = tuile ? Math.round(tuile.getBoundingClientRect().height) : null;

        return {
            fenetre: window.innerHeight,
            grilleY: rGrille ? Math.round(rGrille.y) : null,
            hauteurTuile: hTuile,
            corpsPanier: body ? Math.round(body.getBoundingClientRect().height) : null,
            piedBas: foot ? Math.round(foot.getBoundingClientRect().bottom) : null,
            controlesCoupes: coupes,
            mainScroll: main ? main.scrollHeight : null,
            mainVisible: main ? main.clientHeight : null,
        };
    });
}

for (const gabarit of GABARITS) {
    test.describe(`caisse @ ${gabarit.nom} ${gabarit.width}x${gabarit.height}`, () => {
        test.use({ viewport: { width: gabarit.width, height: gabarit.height } });

        test('C1 — la grille des catégories est atteignable sans défiler', async ({ page }) => {
            await ouvrirLaCaisse(page);
            const m = await mesurer(page);

            expect(m.grilleY, 'la grille doit exister').not.toBeNull();
            // Critère : au moins une rangée entière de tuiles est dans l'écran au repos.
            const basPremiereRangee = m.grilleY + (m.hauteurTuile || 0);
            expect(
                basPremiereRangee,
                `grille à y=${m.grilleY}, tuile ${m.hauteurTuile}px, fenêtre ${m.fenetre}px — `
                    + `le caissier doit défiler ${Math.max(0, basPremiereRangee - m.fenetre)}px `
                    + 'pour voir une catégorie entière'
            ).toBeLessThanOrEqual(m.fenetre);
        });

        test('C2 — aucun contrôle de saisie coupé dans l\'en-tête du panier', async ({ page }) => {
            await ouvrirLaCaisse(page);
            const m = await mesurer(page);

            expect(
                m.controlesCoupes,
                `contrôles hors de la zone visible de l'en-tête : ${m.controlesCoupes.join(' · ')}`
            ).toEqual([]);
        });

        test('C3 — le gain du 19/08 n\'est pas repris (corps ≥ 20vh, pied entier)', async ({ page }) => {
            await ouvrirLaCaisse(page);
            const m = await mesurer(page);

            const plancher = Math.round(m.fenetre * 0.20);
            expect(
                m.corpsPanier,
                `le corps du panier fait ${m.corpsPanier}px pour un plancher de ${plancher}px `
                    + '(avant le 2026-08-19 il n\'en faisait que 40)'
            ).toBeGreaterThanOrEqual(plancher);

            // Le bouton d'encaissement ne doit jamais pouvoir sortir de l'écran.
            expect(
                m.piedBas,
                `le bas du pied du panier est à ${m.piedBas}px pour une fenêtre de ${m.fenetre}px`
            ).toBeLessThanOrEqual(m.fenetre);
        });
    });
}
