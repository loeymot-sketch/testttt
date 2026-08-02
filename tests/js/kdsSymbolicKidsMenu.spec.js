import { describe, it, expect } from 'vitest';
import { buildSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [F-01 AUDIT CUISINIER 2026-08-01 · P0] « Chicken Burger » et « Menu Enfant Chicken Burger »
 * rendaient une ligne STRICTEMENT IDENTIQUE sur l'écran KDS et sur le ticket imprimé : le mot
 * « enfant » était strippé comme mot générique (à raison, pour distinguer BUR de NUG), mais rien
 * ne le remplaçait. Prouvé live sur la commande A0035 contenant les deux : 2 lignes byte-identiques
 * → le cuisinier prépare deux fois le même plat, la portion enfant (plus petite, frites + boisson
 * incluses) disparaît.
 *
 * Invariant scellé : un menu enfant est TOUJOURS distinguable de son produit adulte homonyme,
 * sans perdre le code produit distinctif. Jumeau PHP :
 * KitchenTicketSymbolicFormatter::produitCode() (même marqueur « ENF », parité ticket == écran).
 */
describe('KDS symbolique — menu enfant distinguable (F-01)', () => {
    const produit = (item_name) => buildSymbolic({ item_name, quantity: 1 }).produit;

    it('ne rend PAS le même code pour un produit et sa version menu enfant', () => {
        expect(produit('Menu Enfant Chicken Burger')).not.toBe(produit('Chicken Burger'));
    });

    it('marque explicitement le menu enfant', () => {
        expect(produit('Menu Enfant Chicken Burger')).toContain('ENF');
    });

    it('conserve le code produit distinctif entre deux menus enfants (CHI ≠ NUG)', () => {
        expect(produit('Menu Enfant Chicken Burger')).toContain('CHI');
        expect(produit('Menu Enfant Nuggets')).toContain('NUG');
        expect(produit('Menu Enfant Chicken Burger')).not.toBe(produit('Menu Enfant Nuggets'));
    });

    it('ne marque PAS les produits adultes et préserve les codes existants', () => {
        expect(produit('Chicken Burger')).toBe('CHI');
        expect(produit('Cayenne')).toBe('CAY');
        expect(produit('Bol Frites')).toBe('BOL FRI');
    });
});
