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

    it('préserve les codes compacts des produits qui ne prêtent pas à confusion', () => {
        expect(produit('Cayenne')).toBe('CAY');
        expect(produit('Bol Frites')).toBe('BOL FRI');
        expect(produit('Tacos M')).toBe('TAC');
    });

    /**
     * [OWNER 2026-08-10 · « la cuisine se trompe entre CHEESE et CHICKEN, écris-les en entier »]
     * Jumeau du test PHP KitchenTicketBolBaseTest : l'écran et le ticket doivent nommer le plat
     * de la même façon, sinon le cuisinier lit deux vérités.
     */
    it('écrit en toutes lettres les familles que la cuisine confondait', () => {
        expect(produit('Cheese Burger')).toBe('CHEESE BURGER');
        expect(produit('Chicken Burger')).toBe('CHICKEN BURGER');
        expect(produit('Double Cheese')).toBe('DOUBLE CHEESE');
        expect(produit('Menu Enfant Chicken Burger')).toBe('MENU ENFANT CHICKEN BURGER');
        expect(produit('Menu Enfant Nuggets')).toBe('MENU ENFANT NUGGETS');

        const rendus = ['Cheese Burger', 'Chicken Burger', 'Double Cheese', 'Cheddar',
            'Menu Enfant Chicken Burger', 'Menu Enfant Nuggets'].map(produit);
        expect(new Set(rendus).size, 'deux produits distincts rendent la même ligne').toBe(rendus.length);
    });

    it('distingue les galettes comme les bols', () => {
        expect(produit('Galette Cayenne')).toBe('GAL CAY');
        expect(produit('Galette Normale')).toBe('GAL NOR');
        expect(produit('Galette pommes de terre')).toBe('GAL POM');
    });
});
