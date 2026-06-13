import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * CENTRAL-C1-P2-01 — Variante "Prix additionnel" affichait `0.00` (flat_price)
 * au lieu de `0,00 €` (currency_price) dans l'onglet Variante de la fiche item.
 *
 * Le backend (app/Http/Resources/ItemVariationResource.php:25-27) expose déjà
 *   - flat_price     => AppLibrary::flatAmountFormat($price)      // "0.00"   (en-US, sans devise)
 *   - currency_price => AppLibrary::currencyAmountFormat($price)  // "0,00 €" (FR + devise)
 *
 * La colonne "Prix additionnel" doit rendre la valeur FR avec devise
 * (currency_price), pas la valeur plate en-US (flat_price), pour rester
 * cohérent avec ItemListComponent / ItemShowComponent / CatalogStudio qui
 * affichent tous `currency_price`.
 *
 * Approche : contrat source statique (même précédent que itemListWizardButton.spec.js).
 * Monter ItemVariationListComponent tirerait Vuex itemVariation + ItemVariationCreate
 * + SmIcon wrappers + ENV ; le mission-brief tolère explicitement une assertion
 * allégée quand le full-mount est trop lourd.
 */

const componentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/items/variation/ItemVariationListComponent.vue',
);

const readSource = (path) => readFileSync(path, 'utf8');

describe('ItemVariationListComponent — additional price FR currency (CENTRAL-C1-P2-01)', () => {
    it('renders the additional-price cell with child.currency_price (0,00 €), not child.flat_price (0.00)', () => {
        const source = readSource(componentPath);

        // Le prix additionnel doit s'afficher en devise FR.
        expect(source).toContain('{{ child.currency_price }}');

        // Le raw flat_price (en-US "0.00") ne doit plus apparaître dans une cellule
        // de tableau affichée à l'utilisateur (il reste utilisé en édition, ligne
        // edit() : price: itemVariation.flat_price — ça c'est l'input numérique,
        // pas une cellule d'affichage). On garde donc la garde ciblée sur la cellule.
        const cellMatch = source.match(/<td[^>]*>\s*\{\{\s*child\.flat_price\s*\}\}\s*<\/td>/);
        expect(cellMatch).toBeNull();
    });
});
