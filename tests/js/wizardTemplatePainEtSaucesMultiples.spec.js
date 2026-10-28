/**
 * wizardTemplatePainEtSaucesMultiples.spec.js
 * -----------------------------------------------------------------------------
 * [owner 2026-09-02] Deux défauts constatés EN SERVICE par le propriétaire, et
 * la garde qui les empêche de revenir. Ce banc monte la VRAIE `public/js/
 * pos-wizard.js` (harnais happy-dom) — il ne réimplémente pas la logique.
 *
 * DÉFAUT 1 — « pain ou galette » réclamé sur un Cheeseburger.
 *   La catégorie « Burgers » portait `item_categories.wizard_template =
 *   'sandwich'`. Or `getAllowedSteps('sandwich')` insère l'étape `pain`, et
 *   comme un burger n'a AUCUNE variation de pain, pos-wizard.js retombe sur son
 *   repli codé en dur [Pain, Galette] (~:906). À l'ajout l'étape s'auto-
 *   sélectionne en silence ; à la MODIFICATION depuis le panier la restauration
 *   n'a pas de valeur de pain à rendre → le caissier reste bloqué sur un choix
 *   qui n'existe pas au menu. Corrigé côté DONNÉES : Burgers → 'burger',
 *   Menu enfant → 'menu_enfant'.
 *
 *   ⚠️ La cause profonde est architecturale et RESTE ouverte : ItemResource::
 *   composerProfilePayload() ne cherche QUE un profil composeur `item_id`
 *   publié — il n'y a AUCUN repli sur le profil de CATÉGORIE. Le profil
 *   catégorie « Burgers » (id=37) avait pourtant son étape `pain` désactivée
 *   (is_active=0) : il n'a jamais été lu. Tant que ce repli n'existe pas, c'est
 *   `wizard_template` qui gouverne réellement ces produits.
 *
 * DÉFAUT 2 — impossible d'ajouter une 2e sauce sur un bol.
 *   Les profils composeur publiés des bols plafonnaient l'étape sauce à
 *   `max_select = 1`. La facturation « 1ère gratuite, chaque suivante +0,50 € »
 *   existait pourtant déjà et fonctionnait (pos-wizard.js:1510 + extra générique
 *   « Sauce supplémentaire » :4201) : seul le plafond bloquait. Décision
 *   propriétaire 2026-09-02 : sauces ILLIMITÉES sur les bols.
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem, tick } from './posWizardHarness.js';

/** Libellés des étapes réellement construites par le wizard. */
function stepLabels(wizard) {
    return Array.from(wizard.querySelectorAll('.wizard-step-title, .step-title, [data-step-label]'))
        .map((n) => (n.getAttribute('data-step-label') || n.textContent || '').trim().toLowerCase())
        .filter(Boolean);
}

/** Le wizard réclame-t-il un choix pain/galette ? (marqueurs DOM du step `pain`) */
function demandeUnPain(wizard) {
    if (wizard.querySelector('.pain-opt, [data-step="pain"], .wizard-pain')) return true;
    const html = (wizard.innerHTML || '').toLowerCase();
    // Le repli codé en dur n'expose que les deux libellés, sans classe dédiée.
    return html.includes('type de pain') || html.includes('pain traditionnel ou galette');
}

describe('caisse — le gabarit de catégorie décide de l’étape pain (défaut owner 2026-09-02)', () => {
    it('un SANDWICH garde bien son choix pain/galette (non-régression : ne pas sur-corriger)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem({
                name: 'Big Cayenne',
                category_name: 'Sandwichs',
                wizard_template: 'sandwich',
            }),
        });
        expect(wizard, 'wizard monté').toBeTruthy();
        expect(
            demandeUnPain(wizard),
            'un sandwich DOIT continuer à proposer pain/galette — c’est un vrai choix de la carte',
        ).toBe(true);
    });

    it('un BURGER ne réclame plus pain/galette (wizard_template=burger)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem({
                id: 38,
                name: 'Chicken Burger',
                category_name: 'Burgers',
                wizard_template: 'burger',
                // Un burger n'a aucune variation de pain : c'est précisément ce
                // vide qui déclenchait le repli codé en dur [Pain, Galette].
                itemAttributes: [
                    { id: 301, name: 'Viande 1', max_select: 1 },
                    { id: 311, name: 'Sauce (1ère Gratuite)' },
                ],
                variations: {
                    301: [{ id: 9001, name: 'Poulet crispy', thumb: '' }],
                    311: [
                        { id: 9101, name: 'Algérienne', thumb: null },
                        { id: 9102, name: 'Barbecue', thumb: null },
                    ],
                },
            }),
        });
        expect(wizard, 'wizard monté').toBeTruthy();
        expect(
            demandeUnPain(wizard),
            'RÉGRESSION : le burger redemande un pain/galette qui n’existe pas à la carte',
        ).toBe(false);
    });

    it('un MENU ENFANT ne réclame plus pain/galette (wizard_template=menu_enfant)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem({
                id: 40,
                name: 'Menu Enfant Nuggets',
                category_name: 'Menu enfant',
                wizard_template: 'menu_enfant',
                itemAttributes: [{ id: 311, name: 'Sauce (1ère Gratuite)' }],
                variations: {
                    311: [
                        { id: 9101, name: 'Ketchup', thumb: null },
                        { id: 9102, name: 'Mayonnaise', thumb: null },
                    ],
                },
            }),
        });
        expect(wizard, 'wizard monté').toBeTruthy();
        expect(demandeUnPain(wizard), 'un menu enfant n’a pas de choix de pain').toBe(false);
    });
});

describe('caisse — sauces multiples sur les bols, 1ère gratuite puis +0,50 € (défaut owner 2026-09-02)', () => {
    /**
     * Le bol passe par le gabarit `custom` → getAllowedSteps() retombe sur le
     * défaut ['sauce_garnitures', 'supplements_menu', 'recap'], dont l'étape
     * sauce est MULTI-sélection (selections.sauces = map + sauceOrder).
     */
    async function monterUnBol() {
        return mountPosWizard({
            itemData: cayenneLikeItem({
                id: 45,
                name: 'Bol Riz',
                category_name: 'Bols',
                wizard_template: 'custom',
                convert_price: 7.9,
                currency_price: '€7.90',
                itemAttributes: [{ id: 311, name: 'Sauce bol' }],
                variations: {
                    311: [
                        { id: 9101, name: 'Fromagère maison', thumb: null },
                        { id: 9102, name: 'Barbecue', thumb: null },
                        { id: 9103, name: 'Algérienne', thumb: null },
                    ],
                },
            }),
        });
    }

    it('plusieurs sauces sont sélectionnables simultanément (cas réel : fromagère + barbecue)', async () => {
        const { wizard } = await monterUnBol();
        expect(wizard, 'wizard monté').toBeTruthy();

        const sauces = Array.from(wizard.querySelectorAll('.sauce-chip, .sauce-opt'));
        expect(sauces.length, 'les sauces du bol sont proposées').toBeGreaterThanOrEqual(2);

        sauces[0].click();
        await tick();
        sauces[1].click();
        await tick();

        const selectionnees = Array.from(wizard.querySelectorAll('.sauce-chip, .sauce-opt')).filter((el) =>
            (el.className || '').includes('selected'),
        );
        expect(
            selectionnees.length,
            'RÉGRESSION : le bol ne retient qu’une seule sauce — le client ne peut plus demander « fromagère + barbecue »',
        ).toBeGreaterThanOrEqual(2);
    });

    it('la 1ère sauce reste gratuite, la 2e ajoute 0,50 € au total', async () => {
        const { wizard } = await monterUnBol();
        const sauces = Array.from(wizard.querySelectorAll('.sauce-chip, .sauce-opt'));
        expect(sauces.length).toBeGreaterThanOrEqual(2);

        // Sélecteurs RÉELS du wizard figé : `.total-value` (bandeau collant
        // :3493) et `.run-total-value` (total provisoire :1396).
        const lireTotal = () => {
            const el = wizard.querySelector('.sticky-total .total-value, .total-value, .run-total-value');
            if (!el) return null;
            const m = (el.textContent || '').replace(/\s/g, '').match(/(\d+[.,]\d{2})/);
            return m ? parseFloat(m[1].replace(',', '.')) : null;
        };

        sauces[0].click();
        await tick();
        const apresUneSauce = lireTotal();
        expect(apresUneSauce, 'un total est affiché après la 1ère sauce').not.toBeNull();

        sauces[1].click();
        await tick();
        const apresDeuxSauces = lireTotal();

        expect(
            Math.round((apresDeuxSauces - apresUneSauce) * 100) / 100,
            'la 2e sauce doit facturer exactement +0,50 € (la 1ère reste offerte)',
        ).toBeCloseTo(0.5, 2);
    });
});
