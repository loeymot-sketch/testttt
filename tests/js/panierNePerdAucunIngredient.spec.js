import { describe, it, expect } from 'vitest';
import { compactCompositionSegments } from '../../resources/js/helpers/posCartCompactDisplay';

/**
 * LE PANIER NE DOIT PERDRE AUCUN INGRÉDIENT.
 *
 * AB-001, mesuré par le superviseur adverse à la ronde 3 (2026-08-25). Deux lignes du
 * panier affichaient la MÊME composition rendue — « Poulet mariné · STO · … » — alors que
 * leur contenu différait : la seconde portait « Salade, Tomate, Oignon, CORNICHON ».
 *
 * Le cornichon était absent du libellé visible. Quatre crudités compressées en un sigle de
 * trois lettres, sans que rien n'indique qu'il en manquait une.
 *
 * Cause : `cruditeSymbol()` ne connaît que salade, tomate et oignon — table volontairement
 * fermée, jumeau STRICT du formateur de ticket cuisine. Tout le reste rendait une chaîne
 * vide, jetée ensuite par un `.filter(Boolean)`. Le commentaire promettait « conservé en
 * fin, jamais perdu » : vrai des symboles PRODUITS, faux des ingrédients SANS symbole.
 *
 * C'est l'écran sur lequel le caissier confirme avant que la commande parte en cuisine.
 */

const seg = (texte) => compactCompositionSegments(texte);

describe('panier compact — aucun ingrédient ne disparaît', () => {
    it('LE CAS MESURÉ : le cornichon ne s\'évapore plus', () => {
        const rendu = seg('Viandes: Poulet mariné\nCrudités: Salade, Tomate, Oignon, Cornichon\nSauce: Mayonnaise').join(' · ');

        expect(
            /cornichon/i.test(rendu),
            `RÉGRESSION AB-001 : le cornichon a disparu du panier. Rendu : « ${rendu} ». `
            + 'Le caissier confirme la commande sur cet écran ; un ingrédient qui s\'évapore '
            + 'ici se paie en salle.'
        ).toBe(true);

        // Et les trois connues restent repliées : on ne jette pas la compaction pour autant.
        expect(rendu).toContain('STO');
    });

    it('DEUX COMPOSITIONS DIFFÉRENTES ne peuvent pas rendre le MÊME texte', () => {
        // C'est la forme la plus dure du constat : le défaut n'était pas « il manque un
        // mot », c'était « deux lignes distinctes s'affichent à l'identique ».
        const a = seg('Viandes: Poulet mariné\nCrudités: Salade, Tomate, Oignon\nSauce: Algérienne').join(' · ');
        const b = seg('Viandes: Poulet mariné\nCrudités: Salade, Tomate, Oignon, Cornichon\nSauce: Algérienne').join(' · ');

        expect(
            a === b,
            `Deux compositions DIFFÉRENTES rendent le même texte « ${a} ». Le caissier ne peut `
            + 'pas distinguer les deux lignes de son panier.'
        ).toBe(false);
    });

    it('plusieurs crudités inconnues sont TOUTES conservées', () => {
        const rendu = seg('Crudités: Salade, Cornichon, Betterave, Maïs').join(' · ');

        ['Cornichon', 'Betterave', 'Maïs'].forEach((mot) => {
            expect(
                rendu.includes(mot),
                `« ${mot} » manque dans « ${rendu} » : la conservation ne doit pas s'arrêter au premier.`
            ).toBe(true);
        });
        expect(rendu).toContain('S');
    });

    it('UN REFUS reste un refus — il ne doit pas se lire comme un ajout', () => {
        // Piège du correctif : en repêchant « tout ce qui n'a pas de symbole », on
        // repêcherait aussi « Sans oignon » et le panier annoncerait un oignon EN PLUS.
        const rendu = seg('Crudités: Salade, Sans oignon').join(' · ');

        expect(
            /^S$|^S\s*$/.test(rendu.trim()) || !/oignon/i.test(rendu),
            `un refus s'est glissé dans la liste des ajouts : « ${rendu} »`
        ).toBe(true);
    });

    it('les retraits explicites restent mis en évidence, en capitales', () => {
        const segments = seg('Crudités: Salade\nSans: Oignon');
        expect(segments.some((s) => s === 'SANS OIGNON')).toBe(true);
    });

    it('une liste entièrement connue reste compacte — la compaction n\'est pas abandonnée', () => {
        expect(seg('Crudités: Salade, Tomate, Oignon')).toEqual(['STO']);
    });

    it('aucune crudité du tout : aucun segment fabriqué', () => {
        expect(seg('Crudités: ')).toEqual([]);
        expect(seg('')).toEqual([]);
    });
});
