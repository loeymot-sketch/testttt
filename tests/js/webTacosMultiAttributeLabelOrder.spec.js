/**
 * [Root cause 2026-09-17, propriétaire : "problème de logique" sur un Tacos multi-viandes
 * commandé sur le SITE WEB (pas la borne, pas la caisse) — plusieurs viandes choisies sur un
 * Tacos XL (3 viandes incluses = attributs "Viande 1"/"Viande 2"/"Viande 3", ids 1/2/3 en
 * base) plus une Sauce (attribut id 5).]
 *
 * ItemComponent.vue::changeVariation() maintenait DEUX structures pour la même sélection :
 *   - `.variations`  indexé par ID d'attribut  (ex. {1: varId, 2: varId, 3: varId, 5: varId})
 *   - `.names`       indexé par NOM d'attribut (ex. {"Viande 1": "Tenders", "Sauce…": "Harissa"})
 *
 * JavaScript trie les clés d'objet qui RESSEMBLENT à des entiers par ORDRE NUMÉRIQUE CROISSANT,
 * quel que soit l'ordre d'insertion — mais garde l'ordre d'INSERTION pour les clés non-entières.
 * CheckoutComponent.vue::orderSubmit() zippait ces deux objets PAR POSITION (un compteur commun
 * `i`), ce qui n'est correct QUE si le client clique ses attributs dans l'ordre croissant de
 * leurs identifiants — sans rapport avec l'ordre réel de clic à l'écran. Sur un Tacos XL,
 * cliquer la Sauce avant la 3ᵉ viande décale déjà tout le zip.
 *
 * Le correctif ajoute `labelsByAttributeId`, indexé par le MÊME id que `.variations`, pour ne
 * plus dépendre d'aucun ordre d'itération.
 */
import { describe, it, expect } from 'vitest';

// Réplique fidèle de ItemComponent.vue::changeVariation() (partie item_variations) — le point
// exact du correctif — pour tester la logique sans monter le composant Vue complet.
function changeVariation(temp, itemAttributes, attributeId, variationId, variationName) {
    temp.item_variations.variations[attributeId] = variationId;
    itemAttributes.forEach((element) => {
        if (element.id === attributeId) {
            temp.item_variations.names[element.name] = variationName;
            temp.item_variations.labelsByAttributeId[attributeId] = {
                attribute_name: element.name,
                variation_name: variationName,
            };
        }
    });
}

// Réplique fidèle de la construction `item_variations` (1ère boucle) + du zip corrigé
// (2ème boucle) de CheckoutComponent.vue::orderSubmit().
function buildOrderItemVariations(itemVariations) {
    const item_variations = [];
    if (Object.keys(itemVariations.variations).length > 0) {
        Object.entries(itemVariations.variations).forEach(([attrId, varId]) => {
            item_variations.push({ id: varId, item_attribute_id: attrId });
        });
    }

    const labelsByAttributeId = itemVariations.labelsByAttributeId || {};
    if (Object.keys(labelsByAttributeId).length > 0) {
        item_variations.forEach((entry) => {
            const label = labelsByAttributeId[entry.item_attribute_id];
            if (label) {
                entry.variation_name = label.attribute_name;
                entry.name = label.variation_name;
            }
        });
    } else if (Object.keys(itemVariations.names).length > 0) {
        let i = 0;
        Object.entries(itemVariations.names).forEach(([attrName, varName]) => {
            if (item_variations[i]) {
                item_variations[i].variation_name = attrName;
                item_variations[i].name = varName;
            }
            i++;
        });
    }

    return item_variations;
}

const TACOS_XL_ATTRIBUTES = [
    { id: 1, name: 'Viande 1' },
    { id: 2, name: 'Viande 2' },
    { id: 3, name: 'Viande 3' },
    { id: 5, name: 'Sauce (1ère Gratuite)' },
];

function freshItemVariations() {
    return { variations: {}, names: {}, labelsByAttributeId: {} };
}

describe('Site web — Tacos XL (multi-attributs viande) : libellés indépendants de l\'ordre de clic', () => {
    it('confirme le piège JS : `.names` (clés non-entières) garde l\'ordre de clic, `.variations` (clés entières) est retrié numériquement', () => {
        const temp = { item_variations: freshItemVariations() };
        // Ordre de clic RÉALISTE et non-croissant : Sauce, puis Viande 3, puis Viande 1, puis Viande 2.
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 5, 9005, 'Harissa');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 3, 9003, 'Tenders');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 1, 9001, 'Poulet mariné');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 2, 9002, 'Mexicanos');

        expect(Object.keys(temp.item_variations.variations)).toEqual(['1', '2', '3', '5']);
        expect(Object.keys(temp.item_variations.names)).toEqual([
            'Sauce (1ère Gratuite)', 'Viande 3', 'Viande 1', 'Viande 2',
        ]);
    });

    it('AVANT correctif (repli positionnel) : les libellés seraient attribués aux MAUVAISES lignes', () => {
        const temp = { item_variations: { variations: {}, names: {} } }; // pas de labelsByAttributeId, simule l'ancien code
        const itemVariations = temp.item_variations;
        itemVariations.variations[5] = 9005;
        itemVariations.names['Sauce (1ère Gratuite)'] = 'Harissa';
        itemVariations.variations[3] = 9003;
        itemVariations.names['Viande 3'] = 'Tenders';
        itemVariations.variations[1] = 9001;
        itemVariations.names['Viande 1'] = 'Poulet mariné';
        itemVariations.variations[2] = 9002;
        itemVariations.names['Viande 2'] = 'Mexicanos';

        const result = buildOrderItemVariations(itemVariations);
        const viande1Line = result.find((r) => r.item_attribute_id === '1');
        // Le repli positionnel attribue à l'attribut 1 (Viande 1, réellement "Poulet mariné")
        // le libellé de la PREMIÈRE entrée insérée dans `.names`, c'est-à-dire la Sauce.
        expect(viande1Line.variation_name).toBe('Sauce (1ère Gratuite)');
        expect(viande1Line.name).toBe('Harissa');
        expect(viande1Line.name).not.toBe('Poulet mariné'); // le vrai choix, faussé
    });

    it('APRÈS correctif : chaque ligne porte le bon nom d\'attribut ET la bonne viande choisie, quel que soit l\'ordre de clic', () => {
        const temp = { item_variations: freshItemVariations() };
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 5, 9005, 'Harissa');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 3, 9003, 'Tenders');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 1, 9001, 'Poulet mariné');
        changeVariation(temp, TACOS_XL_ATTRIBUTES, 2, 9002, 'Mexicanos');

        const result = buildOrderItemVariations(temp.item_variations);
        expect(result).toHaveLength(4);

        const byAttr = Object.fromEntries(result.map((r) => [r.item_attribute_id, r]));
        expect(byAttr['1']).toMatchObject({ id: 9001, variation_name: 'Viande 1', name: 'Poulet mariné' });
        expect(byAttr['2']).toMatchObject({ id: 9002, variation_name: 'Viande 2', name: 'Mexicanos' });
        expect(byAttr['3']).toMatchObject({ id: 9003, variation_name: 'Viande 3', name: 'Tenders' });
        expect(byAttr['5']).toMatchObject({ id: 9005, variation_name: 'Sauce (1ère Gratuite)', name: 'Harissa' });

        // Chaque ligne reste 1 variation pour 1 attribut : rien ici ne peut faire croire au
        // serveur qu'un même attribut a reçu 2 sélections.
        result.forEach((line) => {
            const sameAttr = result.filter((r) => r.item_attribute_id === line.item_attribute_id);
            expect(sameAttr).toHaveLength(1);
        });
    });
});
