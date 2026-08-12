import { describe, it, expect } from 'vitest';
import { buildSymbolic, renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [OWNER 2026-08-10 · « les sauces au bon endroit, frites ou sandwich »]
 *
 * Jumeau STRICT de tests/Feature/Hardware/KitchenTicketSaucesPlacementTest.php : mêmes formes
 * (relevées sur des commandes RÉELLES #5835 / #5810 / #5896), mêmes attentes. Si l'écran de
 * cuisine et le ticket imprimé divergent, l'un des deux fichiers rougit — le cuisinier ne doit
 * jamais lire deux vérités.
 */
const sauceExtra = (q = 1) => ({ extra_name: 'Sauce supplémentaire', quantity: q, unit_price: 0.5 });

const item = ({ name = 'Cayenne', lines = [], extras = [], addons = [], instruction = '' }) => ({
    item_name: name,
    quantity: 1,
    instruction,
    composition_snapshot: { lines, extras, addons },
});

describe('sauces — écran de cuisine', () => {
    it('la sauce payante des FRITES ne ressort pas en supplément du sandwich (#5835)', () => {
        const s = buildSymbolic(item({
            lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Fromagère maison' }],
            extras: [{ extra_name: 'Salade', quantity: 1, unit_price: 0 }, sauceExtra()],
            addons: [{ role: 'menu_full', addon_name: 'Menu (Frites + Boisson)', quantity: 1 }],
            instruction: 'Boisson menu: Coca-Cola 33cl · Sauce frites : Ketchup, Mayonnaise',
        }));

        expect(s.sauces).toContain('FRO');       // sauce du SANDWICH, ligne 1
        expect(s.supplements, 'sauce fantôme anonyme en supplément').toEqual([]);

        // …et les sauces des FRITES sont bien sur le badge.
        const { lines } = renderItemSymbolic(item({
            lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Fromagère maison' }],
            extras: [sauceExtra()],
            addons: [{ role: 'menu_full', addon_name: 'Menu (Frites + Boisson)', quantity: 1 }],
            instruction: 'Sauce frites : Ketchup, Mayonnaise',
        }));
        expect(lines.find((l) => l.type === 'symbolic-menu').label).toBe('MENU : KTP MAY');
    });

    it('trois sauces de frites tiennent dans le badge et nulle part ailleurs (#5810)', () => {
        const payload = item({
            name: 'Grande Frites',
            extras: [sauceExtra(2)],
            instruction: 'Sauce frites : Mayonnaise, Ketchup, Samouraï',
        });

        expect(buildSymbolic(payload).supplements).toEqual([]);
        expect(renderItemSymbolic(payload).lines.find((l) => l.type === 'symbolic-menu').label)
            .toBe('FRITES : MAY KTP SAM');
    });

    it('la sauce en plus du SANDWICH reste repliée dans la ligne 1', () => {
        const s = buildSymbolic(item({
            lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Mayonnaise' }],
            extras: [sauceExtra()],
            instruction: 'Sauces en plus : Andalouse',
        }));

        expect(s.sauces).toEqual(['MAY', 'AND']);
        expect(s.supplements).toEqual([]);
    });

    it('les deux canaux à la fois sont comptés ensemble', () => {
        const s = buildSymbolic(item({
            lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Mayonnaise' }],
            extras: [sauceExtra(2)],
            instruction: 'Sauces en plus : Andalouse\nSauce frites : Ketchup, Samouraï',
        }));

        expect(s.supplements).toEqual([]);
    });

    it('une sauce PAYÉE que rien n’explique reste visible', () => {
        const s = buildSymbolic(item({ extras: [sauceExtra()], instruction: 'Sauce frites : Ketchup' }));

        expect(s.supplements).toEqual(['+ Sauce supplémentaire']);
    });

    it('le surplus non expliqué est affiché avec son compte', () => {
        const s = buildSymbolic(item({
            extras: [sauceExtra(3)],
            instruction: 'Sauce frites : Ketchup, Mayonnaise',
        }));

        expect(s.supplements).toEqual(['+ Sauce supplémentaire ×2']);
    });
});

describe('sauce des frites — tous les chemins par lesquels des frites arrivent', () => {
    it('un MENU ENFANT montre la sauce de SES frites (elles viennent de la recette)', () => {
        const { lines } = renderItemSymbolic(item({
            name: 'Menu Enfant Chicken Burger',
            instruction: 'Sauce frites : Ketchup',
        }));

        expect(lines.find((l) => l.type === 'symbolic-menu')?.label).toBe('FRITES : KTP');
    });

    it("sans sauce frites choisie, aucun badge n'apparaît", () => {
        const { lines } = renderItemSymbolic(item({ name: 'Cayenne', instruction: 'note libre du client' }));

        expect(lines.find((l) => l.type === 'symbolic-menu')).toBeUndefined();
    });
});

describe('note client — aucun résidu de ponctuation (#5896)', () => {
    it('le retrait des segments de composition ne laisse rien à l’écran', () => {
        const { lines } = renderItemSymbolic(item({
            name: 'Galette Normale',
            instruction: 'Viandes en plus : Nuggets, Poulet mariné. · Sauces en plus : Algérienne.',
        }));

        expect(lines.find((l) => l.type === 'instruction')).toBeUndefined();
    });

    it('une vraie note client survit toujours', () => {
        const { lines } = renderItemSymbolic(item({
            name: 'Tacos L',
            instruction: 'Viandes en plus : Nuggets. · Sauces en plus : Algérienne.\n[ALLERGIE ARACHIDE — sans cacahuète]',
        }));

        expect(JSON.stringify(lines)).toContain('ALLERGIE ARACHIDE');
    });
});
