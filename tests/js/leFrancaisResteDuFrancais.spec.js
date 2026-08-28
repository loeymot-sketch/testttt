import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB 2026-08-28] Une valeur française ne doit pas contenir d'anglais.
 *
 * Six messages du dépôt étaient restés dans un état intermédiaire de traduction
 * automatique — une machine avait remplacé les mots anglais À L'INTÉRIEUR des
 * mots, puis laissé le reste :
 *
 *     "Photo Mettre à jourd Successfully."      ← « Updated » → « Mettre à jour » + d
 *     "Livreur Ajoutered Successfully!"         ← « Added »   → « Ajouter » + ed
 *     "Zone Mettre à jour Successfully."
 *
 * `message.photo_update` est atteint par NEUF écrans. Un commerçant français qui
 * change la photo d'un produit lisait donc « Photo Mettre à jourd Successfully. »
 * — au moment précis où on lui confirme que son geste a marché.
 *
 * Le dépôt est en locale FR figée (ADR-007) : le français n'est pas une
 * traduction parmi d'autres, c'est LA langue du produit.
 *
 * CE BANC MORD : remettre l'une des six valeurs le fait rougir en la citant.
 */
describe('le français reste du français', () => {
    const racine = process.cwd();

    /**
     * Vocabulaire anglais de confirmation et d'erreur. On ne vise QUE ce champ
     * lexical : le dépôt contient légitimement des mots anglais (noms de marques,
     * « Uber Eats », « SumUp », « KDS »), et un filtre plus large produirait du
     * bruit — c'est-à-dire un banc qu'on finit par ignorer.
     */
    const ANGLAIS = new RegExp(
        [
            'Successfully', 'Success\\b', 'Updated', 'Created', 'Deleted',
            'Please\\b', 'Failed\\b', 'Invalid\\b', 'Required\\b',
            'Something went wrong', 'Not found', 'Unauthorized',
        ].join('|'),
    );

    /**
     * Les mutilations produites par la substitution mot-à-mot.
     *
     * ⚠️ VERSION 2. La première liste ne contenait que les résidus de conjugaison
     * (`Ajoutered`, `Mettre à jourd`). Un audit adverse a trouvé trois valeurs qu'elle
     * laissait passer :
     *
     *     label.your_address        « Your Adresse »
     *     label.table_name          « Table Nom »
     *     message.no_schedule_found « N° schedule found. »
     *
     * La dernière est révélatrice : « **No** schedule found » est devenu « **N°**
     * schedule found » — la machine a pris la négation anglaise pour l'abréviation
     * française de « numéro ».
     *
     * Un cliquet au vocabulaire trop étroit attrape la première vague et laisse
     * passer la suivante, en donnant l'impression que le problème est traité.
     *
     * On vise donc DEUX formes précises plutôt qu'une liste large — le dépôt contient
     * légitimement des mots anglais (« Uber Eats », « SumUp », « KDS », « Wizard »),
     * et un filtre trop gourmand produit du bruit, c'est-à-dire un banc qu'on ignore.
     */
    const MUTILATIONS = new RegExp(
        [
            // Résidus de conjugaison substituée.
            'Ajoutered', 'Mettre à jourd', 'Supprimerd', 'Créered',
            // Un mot anglais très courant collé à un mot français dans la même valeur.
            '\\bYour\\b', '\\bschedule\\b', '\\bfound\\b',
            // « No » traduit comme le « N° » de numéro.
            'N° (?:schedule|data|result|order|item)\\b',
        ].join('|'),
    );

    const feuilles = (noeud, chemin = '') => {
        if (noeud && typeof noeud === 'object') {
            return Object.entries(noeud).flatMap(([k, v]) =>
                feuilles(v, chemin ? `${chemin}.${k}` : k),
            );
        }

        return typeof noeud === 'string' ? [[chemin, noeud]] : [];
    };

    const valeursFrancaises = () =>
        feuilles(
            JSON.parse(fs.readFileSync(path.join(racine, 'resources/js/languages/fr.json'), 'utf8')),
        );

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const toutes = valeursFrancaises();

        expect(toutes.length, 'fr.json est vide ou illisible.').toBeGreaterThan(1000);

        // Témoin : une clé qu'on vient de corriger doit être là, et en français.
        const photo = toutes.find(([c]) => c === 'message.photo_update');
        expect(photo, 'message.photo_update a disparu du fichier.').toBeDefined();
        expect(photo[1]).toBe('Photo mise à jour.');

        // Second témoin, sur la vague trouvée par l'audit : sans lui, l'élargissement
        // du vocabulaire ci-dessous pourrait être annulé sans que rien ne le dise.
        const adresse = toutes.find(([c]) => c === 'label.your_address');
        expect(adresse, 'label.your_address a disparu du fichier.').toBeDefined();
        expect(adresse[1]).toBe('Votre adresse');
    });

    it("aucune valeur française ne contient de vocabulaire anglais de confirmation", () => {
        const fautives = valeursFrancaises()
            .filter(([, v]) => ANGLAIS.test(v))
            .map(([c, v]) => `${c} = ${JSON.stringify(v)}`);

        expect(
            fautives,
            "Ces valeurs sont montrées à un commerçant français, dans un produit dont\n"
            + "la locale FR est figée (ADR-007). Traduisez-les :\n"
            + fautives.join('\n'),
        ).toEqual([]);
    });

    it("aucune valeur ne porte la trace d'une substitution mot-à-mot", () => {
        const fautives = valeursFrancaises()
            .filter(([, v]) => MUTILATIONS.test(v))
            .map(([c, v]) => `${c} = ${JSON.stringify(v)}`);

        expect(
            fautives,
            "Un mot anglais a été remplacé À L'INTÉRIEUR d'un mot français, laissant\n"
            + 'un résidu (« Ajoutered », « Mettre à jourd »). Réécrivez la phrase entière :\n'
            + fautives.join('\n'),
        ).toEqual([]);
    });
});
