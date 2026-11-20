/**
 * posWizardDrinkFallback.spec.js
 * -----------------------------------------------------------------------------
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] W2 — la formule caisse
 * « Menu (Frites + Boisson) » / « Boisson Seule » n'offrait AUCUN choix de
 * boisson : les items V1 n'ont que 3 addons génériques, tous exclus par le
 * filtre boissonItems → step jamais construit.
 *
 * Contrat verrouillé ici (modèle borne, 0 € facturé) :
 *  1. filtre addons vide + data-pos-drinks-catalog non-vide → boissonItems
 *     construit depuis le CATALOGUE (noms réels, y compris hors regex :
 *     « Hawaï 33cl », « Capri-Sun ») avec price:0 / « Incluse »
 *  2. la section single-page .boisson-inline apparaît quand la formule
 *     sélectionnée inclut une boisson (Menu complet / Boisson Seule) et
 *     disparaît sinon (avec purge du choix — anti-fantôme)
 *  3. la sélection alimente l'aperçu ticket « BOISSON: <nom> » (canal
 *     instruction existant :2581-2588) — AUCUN impact prix (total inchangé)
 *  4. catalogue vide → comportement historique (aucune section boisson)
 *  5. boisson is_available:false (rupture) → exclue du repli
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem, drinksCatalogFixture, tick } from './posWizardHarness.js';

function boissonNames(wizard) {
    return Array.from(wizard.querySelectorAll('.boisson-opt .option-name')).map((n) => n.textContent.trim());
}

describe('pos-wizard — repli catalogue boissons (LOCK_POSWIZARD_KIOSKWIZARD_OWNER8)', () => {
    it('filtre vide + catalogue → section boisson rendue avec les noms réels (hors regex inclus)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        expect(wizard, 'wizard single-page monté').toBeTruthy();

        const inline = wizard.querySelector('.boisson-inline');
        expect(inline, 'section .boisson-inline présente').toBeTruthy();

        const names = boissonNames(wizard);
        // « Hawaï 33cl » et « Capri-Sun » ne matchent PAS DRINK_LIKE_REGEX → preuve catalogue
        expect(names).toContain('Hawaï 33cl');
        expect(names).toContain('Capri-Sun');
        expect(names).toContain('Coca-Cola 33cl');
        expect(names).toContain('Sans boisson');
    });

    it('les boissons du repli sont GRATUITES : libellé « Incluse », jamais un prix', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        const hawai = Array.from(wizard.querySelectorAll('.boisson-opt'))
            .find((o) => (o.textContent || '').includes('Hawaï 33cl'));
        expect(hawai).toBeTruthy();
        expect(hawai.querySelector('.option-price').textContent.trim()).toBe('Incluse');
        expect(hawai.textContent).not.toMatch(/€\s*\d/);
    });

    it('boisson en rupture (is_available:false) exclue du repli', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        expect(boissonNames(wizard)).not.toContain('Rupture 33cl');
    });

    it('masquée par défaut, visible après « Menu (Frites + Boisson) », re-masquée + purgée sur « Frites Seules »', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        const inline = wizard.querySelector('.boisson-inline');
        expect(inline.classList.contains('visible'), 'cachée sans formule').toBe(false);

        // Sélectionne la formule Menu (addon id=1)
        wizard.querySelector('.formule-card[data-value="addon_1"]').click();
        await tick(10);
        expect(inline.classList.contains('visible'), 'visible avec Menu').toBe(true);

        // Choisit « Hawaï 33cl » (id 124)
        wizard.querySelector('.boisson-opt[data-id="124"]').click();
        await tick(10);
        expect(wizard.querySelector('.boisson-opt[data-id="124"]').classList.contains('selected')).toBe(true);
        expect(wizard.querySelector('.ticket-content').textContent).toContain('BOISSON: Hawaï 33cl');

        // Bascule sur « Frites Seules » (addon id=2) → section cachée + choix purgé
        wizard.querySelector('.formule-card[data-value="addon_2"]').click();
        await tick(10);
        expect(inline.classList.contains('visible'), 're-cachée sans boisson').toBe(false);
        expect(wizard.querySelector('.ticket-content').textContent).not.toContain('BOISSON:');
    });

    it('« Boisson Seule » (addon id=3) affiche aussi la liste', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        wizard.querySelector('.formule-card[data-value="addon_3"]').click();
        await tick(10);
        expect(wizard.querySelector('.boisson-inline').classList.contains('visible')).toBe(true);
    });

    it('sélection boisson → total sticky INCHANGÉ (9,90 € avec Menu, modèle borne 0 €)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: drinksCatalogFixture(),
        });
        wizard.querySelector('.formule-card[data-value="addon_1"]').click();
        await tick(10);
        const before = wizard.querySelector('.sticky-total .total-value').textContent.trim();
        // [AB-003 2026-08-26] L'assertion épinglait « €9.90 » — le format ANGLAIS — alors que
        // le titre de ce test et le commentaire de cette ligne disent tous deux « 9,90 € ».
        // Elle ne détectait donc pas le défaut : elle le VERROUILLAIT, dans un fichier gelé.
        // Le montant est inchangé (7,40 + 2,50) ; seul son rendu devient français, aligné sur
        // `AppLibrary::currencyAmountFormat` (backend, depuis 2026-05-23) et sur le reste de
        // l'interface. Espace insécable avant le symbole.
        expect(before).toBe('9,90\u00A0€'); // 7,40 + 2,50 (Menu)

        wizard.querySelector('.boisson-opt[data-id="124"]').click();
        await tick(10);
        expect(wizard.querySelector('.sticky-total .total-value').textContent.trim()).toBe(before);
    });

    it('catalogue vide + addons génériques → AUCUNE section boisson (comportement historique)', async () => {
        const { wizard } = await mountPosWizard({
            itemData: cayenneLikeItem(),
            drinksCatalog: [],
        });
        expect(wizard, 'wizard monté').toBeTruthy();
        expect(wizard.querySelector('.boisson-inline')).toBeNull();
        expect(wizard.querySelectorAll('.boisson-opt').length).toBe(0);
    });

    it('chemin COMPOSER-AWARE (prod Cayenne) : la section boisson existe aussi (ensureBoissonChoiceStep)', async () => {
        // En prod, buildSteps early-return sur le profil composer → le bloc legacy
        // qui pousse boisson_choice ne tourne JAMAIS. ensureBoissonChoiceStep doit
        // créer le step depuis le catalogue au rendu single-page.
        window.foodkingConfig = { posWizardComposerAware: { enabled: true } };
        try {
            const { wizard } = await mountPosWizard({
                itemData: cayenneLikeItem({
                    composer_profile: {
                        steps: [
                            { step_key: 'viande', label: 'Viande', min_select: 1, max_select: 1, visible_on: [], choices: [{ id: 9001, label: 'Poulet mariné' }] },
                        ],
                    },
                }),
                drinksCatalog: drinksCatalogFixture(),
            });
            expect(wizard, 'wizard monté (composer)').toBeTruthy();
            const inline = wizard.querySelector('.boisson-inline');
            expect(inline, 'section boisson présente sur le chemin composer').toBeTruthy();
            expect(boissonNames(wizard)).toContain('Hawaï 33cl');

            // Menu → visible ; choix → aperçu ticket BOISSON:
            wizard.querySelector('.formule-card[data-value="addon_1"]').click();
            await tick(10);
            expect(inline.classList.contains('visible')).toBe(true);
            wizard.querySelector('.boisson-opt[data-id="124"]').click();
            await tick(10);
            expect(wizard.querySelector('.ticket-content').textContent).toContain('BOISSON: Hawaï 33cl');
        } finally {
            delete window.foodkingConfig;
        }
    });
});
