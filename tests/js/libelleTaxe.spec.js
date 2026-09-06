import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { libelleTaxe } from '../../resources/js/services/libelleTaxe';

/**
 * [ONB-10 2026-08-27] Deux taxes s'affichaient « VAT », pour 5 % et 10 %.
 *
 * Sur le champ le plus lourd de conséquence du formulaire produit, le commerçant
 * jouait sa TVA à pile ou face. Le libellé est désormais dérivé de `tax_rate` — la
 * seule valeur que PricingService facture — et ne peut donc plus contredire ce qui
 * sera facturé.
 */
describe('ONB-10 · libellé de taxe', () => {
    it('distingue deux taxes de même nom par leur taux', () => {
        const cinq = libelleTaxe({ name: 'VAT', tax_rate: 5 });
        const dix = libelleTaxe({ name: 'VAT', tax_rate: 10 });

        expect(cinq).not.toBe(dix);
        expect(cinq).toBe('VAT — 5 %');
        expect(dix).toBe('VAT — 10 %');
    });

    it('rend les décimales à la française, sans zéros inutiles', () => {
        expect(libelleTaxe({ name: 'VAT 5.5', tax_rate: 5.5 })).toBe('VAT 5.5 — 5,5 %');
        expect(libelleTaxe({ name: 'VAT', tax_rate: '10.000000' })).toBe('VAT — 10 %');
    });

    it('affiche 0 % explicitement plutôt que de le taire', () => {
        // Une taxe à 0 % est un choix légitime (exonération) mais lourd de
        // conséquence : le commerçant doit le LIRE, pas le deviner.
        expect(libelleTaxe({ name: 'No-VAT', tax_rate: 0 })).toBe('No-VAT — 0 %');
    });

    it("n'invente pas un pourcentage quand le taux est illisible", () => {
        // Afficher « 0 % » sur un champ fiscal dont on ignore le taux serait pire
        // que de n'afficher que le nom.
        expect(libelleTaxe({ name: 'VAT', tax_rate: null })).toBe('VAT');
        expect(libelleTaxe({ name: 'VAT', tax_rate: 'abc' })).toBe('VAT');
        expect(libelleTaxe({})).toBe('—');
    });

    it('affiche le VRAI taux même quand le nom ment', () => {
        // La base contient « TVA 97% » au taux reel de 20 %, et « TVA 67% » a 0 %.
        // Elles sont inactives aujourd'hui ; si l'une etait reactivee, le commercant
        // doit voir ce qui sera FACTURE, pas ce que le nom raconte.
        expect(libelleTaxe({ name: 'TVA 97%', tax_rate: 20 })).toBe('TVA 97% — 20 %');
        expect(libelleTaxe({ name: 'TVA 67%', tax_rate: 0 })).toBe('TVA 67% — 0 %');
    });

    it('le formulaire produit refiltre les taxes inactives lui-même', () => {
        // Constate à l'ecran : `ItemListComponent` remplit le MEME emplacement du
        // magasin sans filtre de statut et ecrase celui du formulaire selon l'ordre
        // de chargement. Le formulaire proposait encore « TVA 67% » (taux reel 0 %).
        // Un filtre qui depend de l'ordre de chargement n'est pas un filtre.
        const source = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/components/admin/items/ItemCreateComponent.vue'),
            'utf8',
        );

        expect(
            source,
            'le formulaire doit refiltrer sur statusEnum.ACTIVE dans taxesLibellees',
        ).toMatch(/taxesLibellees[\s\S]{0,400}statusEnum\.ACTIVE/);
    });

    it('les deux sélecteurs de taxe utilisent la fonction', () => {
        const fichiers = [
            'resources/js/components/admin/items/ItemCreateComponent.vue',
            'resources/js/components/admin/items/ItemListComponent.vue',
        ];

        for (const fichier of fichiers) {
            const source = fs.readFileSync(path.join(process.cwd(), fichier), 'utf8');

            expect(source, `${fichier} n'importe pas libelleTaxe`).toContain('libelleTaxe');
            expect(
                source,
                `${fichier} : le sélecteur doit être alimenté par la liste libellée`,
            ).toContain(':options="taxesLibellees"');
            // La liste brute ne doit pas revenir : c'est elle qui rendait les deux
            // « VAT » indiscernables à l'écran.
            expect(
                source.includes(':options="taxes"'),
                `${fichier} : le sélecteur est repassé sur la liste brute`,
            ).toBe(false);
        }
    });
});
