import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LA QUANTITÉ D'UN SUPPLÉMENT DOIT ARRIVER JUSQU'AU CUISINIER.
 *
 * Défaut mesuré par le superviseur adverse à la ronde 3 (2026-08-25), constat E-010, sur la
 * MÊME commande rendue par les deux écrans cuisine :
 *
 *   écran V2 (par défaut) : « ⭐ Cheddar ×2 »
 *   écran hérité (?v2=0)  : « Extras: Salade, Cheddar »   ← le ×2 a DISPARU
 *
 * Ce n'est pas un manque de données : `KDSOrderItemsResource::resolveExtrasForKds()` rend
 * l'instantané de composition tel quel, quantité comprise. C'est le gabarit qui ne la lit
 * jamais — alors que la ligne des ADDONS, HUIT LIGNES PLUS BAS dans le même fichier, porte
 * bien sa garde `v-if="Number(addon.quantity || 1) > 1"`.
 *
 * Ce que ça coûte : le cuisinier met UN cheddar là où le client en a payé DEUX, en silence.
 * Défaut symétrique de celui corrigé le 2026-08-24 (« Extras: , , , »), au même endroit.
 *
 * Le test ne compte pas les sites : il vérifie que CHAQUE site de rendu porte la garde. Un
 * sixième site ajouté demain sans quantité échouera tout seul.
 */

const KDS = path.resolve(
    __dirname,
    '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'
);

const source = () => fs.readFileSync(KDS, 'utf8');

/** Tous les sites de rendu d'un nom de supplément, avec leur numéro de ligne. */
function sitesDeRendu(src, appel) {
    const lignes = src.split('\n');
    const out = [];
    lignes.forEach((l, i) => {
        if (l.includes(appel)) out.push({ ligne: i + 1, texte: l });
    });
    return out;
}

describe('quantité des suppléments sur les écrans cuisine', () => {
    it('CHAQUE site de rendu d\'un supplément affiche sa quantité', () => {
        const sites = sitesDeRendu(source(), '{{ kdsExtraDisplayName(extra) }}');

        expect(
            sites.length,
            'aucun site de rendu de supplément trouvé — le gabarit a changé de forme, '
            + 'ce test ne garde plus rien. À réécrire avant de le croire.'
        ).toBeGreaterThan(0);

        const sansQuantite = sites.filter(
            (s) => !/Number\(extra\.quantity \|\| 1\) > 1/.test(s.texte)
        );

        expect(
            sansQuantite.map((s) => `ligne ${s.ligne}`).join(', '),
            'RÉGRESSION E-010 : un site affiche le NOM d\'un supplément sans sa QUANTITÉ. '
            + 'Le cuisinier lira « Cheddar » là où le client a payé « Cheddar ×2 », et rien '
            + 'à l\'écran ne dira qu\'il en manque un.'
        ).toBe('');
    });

    it('les suppléments sont traités comme les addons — même garde, même seuil', () => {
        const src = source();

        const addons = sitesDeRendu(src, '{{ kdsAddonDisplayName(addon) }}');
        expect(addons.length, 'aucun site de rendu d\'addon trouvé').toBeGreaterThan(0);

        // La garde des addons est la référence : elle existait déjà et elle est correcte.
        // On exige que celle des extras ait EXACTEMENT la même forme — même appel à Number,
        // même repli à 1, même seuil. Une garde « > 0 » afficherait « ×1 » partout ; une
        // garde sans repli casserait sur une quantité absente.
        const formeAddon = addons.every((s) => /Number\(addon\.quantity \|\| 1\) > 1/.test(s.texte));
        expect(formeAddon, 'la garde de référence des addons a changé de forme').toBe(true);

        const extras = sitesDeRendu(src, '{{ kdsExtraDisplayName(extra) }}');
        extras.forEach((s) => {
            expect(
                /<span v-if="Number\(extra\.quantity \|\| 1\) > 1"> ×\{\{ Number\(extra\.quantity \|\| 1\) \}\}<\/span>/
                    .test(s.texte),
                `ligne ${s.ligne} : la garde de quantité n'a pas la forme de référence. `
                + 'Un seuil « > 0 » afficherait « ×1 » sur tous les suppléments ; une absence '
                + 'de repli « || 1 » rendrait « ×NaN » sur une quantité manquante.'
            ).toBe(true);
        });
    });

    it('la quantité vient bien AVANT la virgule de séparation', () => {
        // « Cheddar ×2, Salade » et non « Cheddar, ×2 Salade » : l'ordre change le sens.
        sitesDeRendu(source(), '{{ kdsExtraDisplayName(extra) }}').forEach((s) => {
            const posQuantite = s.texte.indexOf('Number(extra.quantity');
            const posVirgule = s.texte.indexOf(',&nbsp;');
            if (posVirgule === -1) return; // site sans séparateur
            expect(
                posQuantite,
                `ligne ${s.ligne} : la quantité est écrite APRÈS le séparateur — elle se `
                + 'rattacherait visuellement au supplément SUIVANT.'
            ).toBeLessThan(posVirgule);
        });
    });
});
