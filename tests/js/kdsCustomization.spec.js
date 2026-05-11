import { describe, expect, it } from 'vitest';

import {
    categorize,
    renderItem,
    orderHasAnyAllergen,
} from '../../resources/js/helpers/kdsCustomization.js';

// [kds/sprint-2 F-3] Adaptive customization renderer. The Vue card template
// is generic; ALL per-category rendering rules live in this helper. Tests
// pin the contract so a category drift doesn't silently change cards.

describe('categorize — explicit kds_category overrides heuristic', () => {
    it('honors explicit kds_category when present', () => {
        expect(categorize({ kds_category: 'sandwich', item_name: 'Random' })).toBe('sandwich');
        expect(categorize({ kds_category: 'BURGER', item_name: 'foo' })).toBe('burger');
    });
    it('falls back to name heuristic for sandwiches / brick / kafteji', () => {
        expect(categorize({ item_name: 'Sandwich Kafteji' })).toBe('sandwich');
        expect(categorize({ item_name: 'Brick à l’œuf' })).toBe('sandwich');
    });
    it('classifies tacos as taco', () => {
        expect(categorize({ item_name: 'Tacos XXL Mixte' })).toBe('taco');
    });
    it('classifies burger as burger', () => {
        expect(categorize({ item_name: 'Burger Le Cayenne' })).toBe('burger');
    });
    it('classifies couscous/ojja/lablabi as assiette', () => {
        expect(categorize({ item_name: 'Couscous Royal' })).toBe('assiette');
        expect(categorize({ item_name: 'Ojja Merguez' })).toBe('assiette');
        expect(categorize({ item_name: 'Lablabi' })).toBe('assiette');
    });
    it('classifies frite/onion ring as side', () => {
        expect(categorize({ item_name: 'Frites moyennes' })).toBe('side');
    });
    it('classifies drinks (coca, thé, eau, café)', () => {
        expect(categorize({ item_name: 'Coca 33cl' })).toBe('drink');
        expect(categorize({ item_name: 'Thé à la menthe' })).toBe('drink');
    });
    it('classifies "Menu Burger" as menu_formule', () => {
        expect(categorize({ item_name: 'Menu Burger Le Cayenne' })).toBe('menu_formule');
        expect(categorize({ item_name: 'Formule du midi' })).toBe('menu_formule');
    });
    it('falls back to "other" when nothing matches', () => {
        expect(categorize({ item_name: 'Mystery dish' })).toBe('other');
    });
});

describe('renderItem — header always first, allergens last', () => {
    it('emits a header line with qty + name + hasAllergen=false (no codes)', () => {
        const out = renderItem({ item_name: 'Frites moyennes', quantity: 2 });
        expect(out.category).toBe('side');
        expect(out.lines[0]).toMatchObject({ type: 'header', qty: 2, label: 'Frites moyennes', hasAllergen: false });
    });
    it('flips hasAllergen=true and appends an allergen line when codes are present', () => {
        const out = renderItem({
            item_name: 'Lablabi',
            quantity: 1,
            allergens_snapshot: ['gluten', 'lait'],
        });
        expect(out.hasAllergen).toBe(true);
        const last = out.lines[out.lines.length - 1];
        expect(last.type).toBe('allergen');
        expect(last.codes).toEqual(['gluten', 'lait']);
    });
});

describe('renderItem — sandwich groups variations by Pain/Crudités/Sauce', () => {
    it('emits one variation line per group classified from attribute_name', () => {
        const out = renderItem({
            item_name: 'Sandwich Kafteji',
            quantity: 1,
            item_variations: [
                { name: 'Baguette traditionnelle', variation_name: 'Pain' },
                { name: 'Salade', variation_name: 'Crudités' },
                { name: 'Tomate', variation_name: 'Crudités' },
                { name: 'Harissa', variation_name: 'Sauce' },
            ],
        });
        const groups = out.lines.filter((l) => l.type === 'variation').map((l) => l.group);
        expect(groups).toContain('bread');
        expect(groups).toContain('crudites');
        expect(groups).toContain('sauce');
        const crudites = out.lines.find((l) => l.type === 'variation' && l.group === 'crudites');
        expect(crudites.label).toBe('Salade, Tomate');
    });
});

describe('renderItem — taco/burger skip the bread group', () => {
    it('drops Pain variations on taco', () => {
        const out = renderItem({
            item_name: 'Tacos XXL Mixte',
            quantity: 1,
            item_variations: [
                { name: 'Galette de blé', variation_name: 'Pain' },
                { name: 'Algérienne', variation_name: 'Sauce' },
            ],
        });
        const groups = out.lines.filter((l) => l.type === 'variation').map((l) => l.group);
        expect(groups).not.toContain('bread');
        expect(groups).toContain('sauce');
    });
});

describe('renderItem — assiette flattens variations onto one "Avec" line', () => {
    it('joins variation names with ", " on a single variation-flat row', () => {
        const out = renderItem({
            item_name: 'Couscous Royal',
            quantity: 1,
            item_variations: [
                { name: 'Merguez', quantity: 2 },
                { name: 'Brochette de bœuf', quantity: 1 },
                { name: 'Légumes vapeur' },
            ],
        });
        expect(out.category).toBe('assiette');
        const flat = out.lines.find((l) => l.type === 'variation-flat');
        expect(flat).toBeTruthy();
        expect(flat.group).toBe('avec');
        expect(flat.label).toContain('2 Merguez');
        expect(flat.label).toContain('Brochette de bœuf');
        expect(flat.label).toContain('Légumes vapeur');
    });
});

describe('renderItem — supplements render as yellow-italic "+" lines', () => {
    it('emits a supplement line per item_extras entry, qty suffix when > 1', () => {
        const out = renderItem({
            item_name: 'Burger Le Cayenne',
            quantity: 1,
            item_extras: [
                { name: 'Cheddar' },
                { name: 'Bacon', quantity: 2 },
            ],
        });
        const supps = out.lines.filter((l) => l.type === 'supplement').map((l) => l.label);
        expect(supps).toContain('+ Cheddar');
        expect(supps).toContain('+ Bacon ×2');
    });
});

describe('renderItem — menu formule emits menu_child lines from role-tagged addons', () => {
    it('classifies addons.role startsWith "menu_" as menu_child', () => {
        const out = renderItem({
            item_name: 'Menu Burger Le Cayenne',
            quantity: 1,
            item_addons: [
                { addon_name: 'Burger Le Cayenne', role: 'menu_full' },
                { addon_name: 'Frites moyennes', role: 'menu_frites' },
                { addon_name: 'Coca 33cl', role: 'menu_boisson' },
            ],
        });
        const children = out.lines.filter((l) => l.type === 'menu_child');
        expect(children).toHaveLength(3);
        expect(children.map((c) => c.label)).toEqual([
            'Burger Le Cayenne',
            'Frites moyennes',
            'Coca 33cl',
        ]);
    });
    it('keeps unrelated addons (role=null or non-menu_) as generic addon lines', () => {
        const out = renderItem({
            item_name: 'Sandwich Kafteji',
            quantity: 1,
            item_addons: [
                { addon_name: 'Sauce additionnelle', role: null },
            ],
        });
        const addons = out.lines.filter((l) => l.type === 'addon');
        expect(addons).toHaveLength(1);
        expect(addons[0].label).toBe('Sauce additionnelle');
    });
});

describe('renderItem — composition_snapshot wins over legacy item_* fields', () => {
    it('prefers composition_snapshot.lines when present', () => {
        const out = renderItem({
            item_name: 'Sandwich Kafteji',
            quantity: 1,
            composition_snapshot: {
                lines: [{ name: 'Pain de mie', variation_name: 'Pain' }],
                extras: [],
                addons: [],
            },
            item_variations: [{ name: 'Should-be-ignored', variation_name: 'Pain' }],
        });
        const bread = out.lines.find((l) => l.type === 'variation' && l.group === 'bread');
        expect(bread.label).toBe('Pain de mie');
    });
});

describe('renderItem — instruction line is keyword-classified', () => {
    it('flags allergen-keyword instructions with the visualClass', () => {
        const out = renderItem({
            item_name: 'Lablabi',
            quantity: 1,
            instruction: 'allergie gluten — pain sans gluten obligatoire',
        });
        const ins = out.lines.find((l) => l.type === 'instruction');
        expect(ins).toBeTruthy();
        expect(ins.visualClass).toBe('kds-instruction--allergen');
    });
});

describe('orderHasAnyAllergen — aggregates across all items', () => {
    it('returns true if any item has codes', () => {
        expect(orderHasAnyAllergen([
            { allergens_snapshot: [] },
            { allergens_snapshot: ['gluten'] },
        ])).toBe(true);
    });
    it('returns false when no item has codes', () => {
        expect(orderHasAnyAllergen([
            { allergens_snapshot: [] },
            { allergens_snapshot: null },
            {},
        ])).toBe(false);
    });
    it('returns false on malformed input (defensive)', () => {
        expect(orderHasAnyAllergen(null)).toBe(false);
        expect(orderHasAnyAllergen('not-an-array')).toBe(false);
    });
});
