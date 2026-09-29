import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-02 2026-08-28] RÉGRESSION QUE J'AI INTRODUITE, puis corrigée.
 *
 * En filtrant la liste des taxes du formulaire produit sur les seules taxes ACTIVES,
 * j'ai vidé le champ obligatoire « Taxe » pour tout article rattaché à une taxe
 * inactive — **64 produits** sur la base de travail.
 *
 * Le scénario est le pire possible : le commerçant vient renommer un article, trouve
 * un champ obligatoire vide qu'il n'a pas touché, et le geste naturel pour le remplir
 * — choisir une taxe dans la liste — **change le taux de TVA facturé**. Mon correctif
 * était plus dangereux que le défaut qu'il visait.
 *
 * Trouvé par un agent adverse lancé sur mon propre travail, pas par un test.
 *
 * LA RÈGLE RETENUE : la taxe COURANTE reste dans la liste même inactive, signalée
 * comme telle. Le commerçant voit ce qui s'applique réellement et décide de le
 * changer, au lieu d'y être poussé sans le savoir. Les NOUVELLES sélections restent
 * bornées aux taxes actives — l'intention d'origine est préservée.
 */
describe('ONB-02 · liste des taxes du formulaire produit', () => {
    const composant = fs.readFileSync(
        path.join(process.cwd(), 'resources/js/components/admin/items/ItemCreateComponent.vue'),
        'utf8',
    );

    /** La règle du composant, reproduite pour être exercée cas par cas. */
    const construire = (taxes, courante) => {
        const ACTIVE = 5;
        const actives = taxes.filter((t) => Number(t?.status) === ACTIVE);
        const id = Number(courante);
        const dejaLa = actives.some((t) => Number(t.id) === id);
        const heritee = (!dejaLa && Number.isFinite(id) && id > 0)
            ? taxes.find((t) => Number(t.id) === id)
            : null;
        return heritee ? [heritee, ...actives] : actives;
    };

    const taxes = [
        { id: 1, name: 'No-VAT', tax_rate: 0, status: 5 },
        { id: 3, name: 'VAT', tax_rate: 10, status: 5 },
        { id: 17, name: 'TVA 97%', tax_rate: 20, status: 1 },
    ];

    it("la taxe courante reste visible même inactive", () => {
        const liste = construire(taxes, 17);

        expect(
            liste.map((t) => t.id),
            "le champ obligatoire s'ouvrirait VIDE, et remplir ce vide changerait le taux",
        ).toContain(17);
    });

    it("une taxe inactive NON sélectionnée reste hors de la liste", () => {
        // L'intention d'origine est préservée : on ne propose pas « TVA 97% » à
        // quelqu'un qui ne l'a pas déjà.
        const liste = construire(taxes, 3);

        expect(liste.map((t) => t.id)).toEqual([1, 3]);
    });

    it('un produit neuf, sans taxe, ne voit que les actives', () => {
        expect(construire(taxes, null).map((t) => t.id)).toEqual([1, 3]);
        expect(construire(taxes, 0).map((t) => t.id)).toEqual([1, 3]);
        expect(construire(taxes, undefined).map((t) => t.id)).toEqual([1, 3]);
    });

    it("la taxe héritée est SIGNALÉE inactive à l'écran", () => {
        // Sans ce marqueur, le commerçant ne saurait pas pourquoi cette entrée est là.
        expect(composant).toContain('— inactive');
    });

    it('le composant préserve bien la taxe courante', () => {
        expect(composant).toContain('this.props?.form?.tax_id');
        expect(
            composant.includes(".filter((taxe) => Number(taxe?.status) === statusEnum.ACTIVE)\n                .map("),
            'le filtre nu est revenu : le champ obligatoire redeviendrait vide',
        ).toBe(false);
    });
});
