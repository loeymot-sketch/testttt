import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-01 2026-08-28] Les quatre champs fiscaux de la filiale sont SAISISSABLES.
 *
 * `register_id` avait tout sauf le champ : sa règle
 * (`BranchRequest.php:68`), sa place dans `fillable`, son exposition par
 * `BranchResource`, son hydratation à l'édition (`BranchListComponent:208`), et son
 * impression sur le ticket par `ReceiptDataService` sous `pos_register_id`.
 *
 * Il manquait uniquement le `<input>`. Le commerçant ne pouvait donc ni voir ni
 * corriger l'identifiant de caisse qui figure sur ses tickets — sur un document
 * fiscal, ce n'est pas un détail cosmétique.
 *
 * C'est la variante la moins coûteuse d'un motif qui revient toute la nuit : la
 * chaîne est complète partout SAUF à l'endroit où un humain entre la vérité.
 * Allergènes, poste de cuisine, horaires d'ouverture, frais de livraison — même
 * forme, coûts différents.
 *
 * ⚠️ Un banc voisin, `BranchFiscalIdentityFormTest`, s'appelle « les trois champs
 * fiscaux » et boucle sur `['siret', 'vat_intra', 'legal_footer']` : il ne mesure
 * PAS `register_id`, alors que la règle existait. Le trou était donc laissé ouvert
 * par le banc censé le fermer. On mesure les QUATRE ici.
 */
describe("le formulaire de filiale porte l'identité fiscale complète", () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const FORMULAIRE = 'resources/js/components/admin/settings/Branch/BranchCreateComponent.vue';
    const LISTE = 'resources/js/components/admin/settings/Branch/BranchListComponent.vue';

    /** Les quatre champs que `BranchRequest` valide et que le ticket imprime. */
    const CHAMPS = ['siret', 'vat_intra', 'legal_footer', 'register_id'];

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const source = lire(FORMULAIRE);

        expect(source.length, 'Le formulaire est vide ou introuvable.').toBeGreaterThan(3000);
        // Témoin : un champ dont on sait qu'il est là depuis longtemps.
        expect(source).toContain('id="name"');
    });

    it('chacun des quatre champs a un contrôle de saisie', () => {
        const source = lire(FORMULAIRE);

        const absents = CHAMPS.filter((champ) => !source.includes(`id="${champ}"`));

        expect(
            absents,
            "Ces champs sont validés par le serveur, exposés par la ressource et\n"
            + "imprimés sur le ticket — mais le commerçant ne peut pas les saisir :\n"
            + absents.join(', '),
        ).toEqual([]);
    });

    it('chacun est lié au formulaire et affiche son erreur', () => {
        const source = lire(FORMULAIRE);

        for (const champ of CHAMPS) {
            expect(
                source,
                `\`${champ}\` a un champ mais n'est pas lié à \`props.form\` : la saisie ne partirait pas.`,
            ).toContain(`props.form.${champ}`);

            expect(
                source,
                `\`${champ}\` n'affiche pas son message d'erreur : un refus serveur serait muet.`,
            ).toContain(`errors.${champ}`);
        }
    });

    it('chacun a son défaut ET son hydratation', () => {
        const source = lire(LISTE);

        for (const champ of CHAMPS) {
            // Le défaut : sans lui, la CRÉATION part sans la clé.
            expect(
                source,
                `\`${champ}\` manque aux valeurs par défaut : absent du formulaire à la création.`,
            ).toMatch(new RegExp(`${champ}:\\s*""`));

            // L'hydratation : sans elle, rouvrir une filiale et enregistrer EFFACERAIT
            // la valeur — le défaut exact corrigé le 2026-08-28 sur ce même écran.
            expect(
                source,
                `\`${champ}\` n'est pas hydraté : rouvrir la fiche et enregistrer l'effacerait.`,
            ).toMatch(new RegExp(`${champ}:\\s*branch\\.${champ}`));
        }
    });

    it('les libellés et repères de saisie existent dans les deux langues', () => {
        const cles = ['siret', 'siret_placeholder', 'vat_intra', 'legal_footer',
            'register_id', 'register_id_placeholder'];

        for (const fichier of ['resources/js/languages/fr.json', 'resources/js/languages/en.json']) {
            const label = JSON.parse(lire(fichier)).label;

            const manquantes = cles.filter((c) => typeof label[c] !== 'string');

            expect(
                manquantes,
                `${fichier} : ces clés sont référencées par le gabarit mais absentes —\n`
                + `l'écran afficherait la clé brute : ${manquantes.join(', ')}`,
            ).toEqual([]);
        }
    });
});
