import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { preRemplissageComptage } from '../../resources/js/services/preRemplissageComptage';

/**
 * [ONB-08 2026-08-28] Le formulaire d'ajustement pré-remplissait une valeur qu'il refuse.
 *
 * Le stock théorique d'une matière première est décrémenté à chaque vente SANS
 * plancher — choix assumé et documenté (`RawMaterialStockService::consume`). Personne
 * ne saisissant les réceptions, il dérive vers le négatif : la base de travail affiche
 * « Poulet −9600 g », « Oignon −1545 g ».
 *
 * Le panneau d'ajustement se pré-remplissait avec ce stock courant — donc −9600 — puis
 * refusait la saisie trois lignes plus bas, garde `target_on_hand < 0`. Le commerçant
 * ouvrait le panneau, cliquait Enregistrer, et s'entendait dire que sa PROPRE valeur
 * pré-remplie était invalide. Sans indication de ce qu'il fallait mettre à la place.
 *
 * Zéro est aussi le bon point de départ métier : ce champ demande ce qu'on a COMPTÉ
 * sur l'étagère, et un comptage physique n'est jamais négatif.
 *
 * Second défaut du même écran : l'unité n'apparaissait que dans l'en-tête de la carte,
 * au-dessus du formulaire. Un patron qui compte « 9 kg de poulet » tapait 9 et posait
 * 9 grammes — un facteur mille sur une valeur ABSOLUE, qui écrase le stock au lieu de
 * l'ajuster.
 */
describe('ONB-08 · ajustement de stock', () => {
    const source = fs.readFileSync(
        path.join(
            process.cwd(),
            'resources/js/components/admin/stock/RawMaterialAdjustComponent.vue',
        ),
        'utf8',
    );

    it('le pré-remplissage ne peut jamais être négatif', () => {
        expect(source).toContain('preRemplissageComptage(material.on_hand)');
        expect(
            source.includes('target_on_hand: this.roundQty(material.on_hand)'),
            'le pré-remplissage est repassé sur le stock courant, qui peut être négatif',
        ).toBe(false);
    });

    it("la garde qui refuse le négatif reste en place", () => {
        // Contrôle négatif : on a corrigé le PRÉ-REMPLISSAGE, pas la validation.
        // Supprimer la garde ferait accepter un comptage négatif, ce qui n'a aucun
        // sens physique et masquerait le vrai problème (les réceptions non saisies).
        expect(source).toContain('payload.target_on_hand < 0');
    });

    it("l'unité est affichée à côté du champ, pas seulement dans l'en-tête", () => {
        expect(source).toContain('material.unit }})');
    });

    /**
     * [ONB-08 2026-08-28 · TAUTOLOGIE CORRIGÉE] Ce cas RECOPIAIT la règle.
     *
     * Il définissait `const preRemplissage = (onHand) => Math.max(0, ...)` dans le
     * test lui-même, puis vérifiait que cette copie se comportait comme elle est
     * écrite. Une tautologie : il serait resté vert si le composant avait perdu la
     * règle entièrement. Je l'avais écrit en connaissance de cause — « reproduction
     * de la règle hors composant » — ce qui ne le rend pas moins creux.
     *
     * La règle vit maintenant dans `services/preRemplissageComptage.js` et le
     * composant l'appelle. Le banc importe la VRAIE fonction.
     * Trouvé par un agent adverse lancé sur mon propre travail.
     */
    it('la règle rend 0 pour un stock négatif et la valeur pour un stock positif', () => {
        expect(preRemplissageComptage(-9600)).toBe(0);
        expect(preRemplissageComptage(-0.001)).toBe(0);
        expect(preRemplissageComptage(0)).toBe(0);
        expect(preRemplissageComptage(12.5)).toBe(12.5);
        expect(preRemplissageComptage(1500)).toBe(1500);
    });

    it("arrondit au millième, l'unité de stock la plus fine du produit", () => {
        expect(preRemplissageComptage(12.3456)).toBe(12.346);
        expect(preRemplissageComptage('1500')).toBe(1500);
    });

    it('une valeur illisible donne 0, jamais NaN dans le champ', () => {
        // `NaN` dans un `v-model.number` laisse le champ vide et fait échouer la
        // garde avec un message qui ne dit rien au commerçant.
        expect(preRemplissageComptage(null)).toBe(0);
        expect(preRemplissageComptage(undefined)).toBe(0);
        expect(preRemplissageComptage('bonjour')).toBe(0);
    });

    it('le composant appelle la règle extraite, il ne la recopie pas', () => {
        expect(source).toContain('preRemplissageComptage(material.on_hand)');
        expect(
            source.includes('Math.max(0, this.roundQty(material.on_hand))'),
            'la règle est revenue en ligne dans le composant : le banc ci-dessus '
            + 'cesserait alors de porter sur ce que l\'écran fait vraiment',
        ).toBe(false);
    });
});
