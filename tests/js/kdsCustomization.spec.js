import { describe, expect, it } from 'vitest';

import {
    categorize,
    renderItem,
    orderHasAnyAllergen,
    kdsVariationGroupValue,
    kdsVariationLine,
    sanitizeKdsInstruction,
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

// [POS-OUTPUT-AUDIT 2026-06-24 P1-KDS] The frozen pos-wizard.js writes the FULL
// composition into orderItem.instruction (line0=PRODUCT NAME, line1=compo blob
// "Viandes : X Sauce : Y - crudités", then UNIQUE extras "+ Menu", "↳ Sauce
// frites: X", free notes). The KDS already renders the composition structurally
// from composition_snapshot (SSOT) — so rendering the raw instruction DOUBLES it
// (and on legacy bridge-divergent orders shows TWO contradictory sauces for one
// product → kitchen makes the wrong food). sanitizeKdsInstruction strips the
// compo-duplicate (product name + compo blob + bare crudités) and KEEPS the
// unique extras (↳ / + / [note] / free notes) that the snapshot does NOT carry.
describe('sanitizeKdsInstruction — strips compo duplicate, keeps unique extras', () => {
    it('strips the product-name line + compo blob entirely (pure duplicate → empty)', () => {
        expect(sanitizeKdsInstruction('TACOS M\nViandes : Mexicanos Sauce : Harissa', 'Tacos M')).toBe('');
    });

    it('[KITCHEN-MENU 2026-06-30] drops "↳ Sauce frites: X" (now shown as the "MENU : SYM" line)', () => {
        // Owner: la sauce frites du menu s'affiche en symbole sur la ligne MENU, pas en clair.
        expect(sanitizeKdsInstruction('TACOS M\n↳ Sauce frites: Curry', 'Tacos M')).toBe('');
    });

    it('[KITCHEN-MENU 2026-06-30] drops compo + name AND the "+ Menu"/"↳ Sauce frites" lines', () => {
        const raw = 'SANDWICH CAYENNE\nViandes : Poulet Sauce : Blanche - Salade, Tomate\n+ Menu (Frites, Coca)\n↳ Sauce frites: Harissa';
        // Tout part : compo (dup), menu et sauce frites (→ ligne MENU : SYM). Reste vide ici.
        expect(sanitizeKdsInstruction(raw, 'Sandwich Cayenne')).toBe('');
    });

    it('keeps a free client note that is not a compo line', () => {
        expect(sanitizeKdsInstruction('allergie gluten — pain sans gluten obligatoire', 'Lablabi'))
            .toBe('allergie gluten — pain sans gluten obligatoire');
    });

    it('drops a bare crudités-removal line "- Oignon" (already covered by structured render)', () => {
        expect(sanitizeKdsInstruction('TACOS M\n- Oignon', 'Tacos M')).toBe('');
    });

    it('[VIANDE-TICKET 2026-08-03] drops the standalone "Viandes en plus : X" line (folded into the named extra)', () => {
        // La caisse single-page émet désormais cette ligne dédiée pour que le ticket
        // cuisine NOMME la viande payée (« + Viande supplémentaire : Kefta »). Une fois
        // repliée dans la ligne extra, elle ne doit PAS rester en note (doublon).
        const raw = 'TACOS L\nViandes : Poulet mariné, +Kefta - Salade\nViandes en plus : Kefta';
        expect(sanitizeKdsInstruction(raw, 'Tacos L')).toBe('');
        // Miroir sauce (borne/web écrivent parfois la ligne seule).
        expect(sanitizeKdsInstruction('TACOS M\nSauces en plus : Andalouse', 'Tacos M')).toBe('');
    });

    it('[RED 2026-08-03 P1 FOOD-SAFETY] mono-ligne borne : la note client co-localisée (ALLERGIE) SURVIT au strip', () => {
        // La borne joint tout par '. ' sur UNE ligne : on strippe le SEGMENT
        // « …en plus : … », jamais la ligne — sinon l'allergie disparaît de la cuisine.
        const raw = 'ALLERGIE ARACHIDE — sans cacahuète. Viandes en plus : Tenders, Nuggets. Sauces en plus : Algérienne';
        const out = sanitizeKdsInstruction(raw, 'Tacos L');
        expect(out).toContain('ALLERGIE ARACHIDE');
        expect(out).not.toMatch(/en plus/i);
        // Séparateur legacy '|' : la note après le marqueur est conservée.
        const out2 = sanitizeKdsInstruction('Sauces en plus : Ketchup | ZZ-TEST bien cuit', 'Cayenne');
        expect(out2).toContain('bien cuit');
        expect(out2).not.toContain('Ketchup');
    });

    it('[FOOD-SAFETY] keeps continuation lines of a multi-line bracketed free note (allergens NOT stripped)', () => {
        // pos-wizard.js (frozen) wrappe la note libre caissier en [...] : une note
        // « Allergie:\n- gluten\n- arachide » devient « [Allergie:\n- gluten\n- arachide] ».
        // Les lignes « - X » de continuation NE doivent PAS être confondues avec un
        // retrait de compo et droppées → sinon les allergènes disparaissent du ticket cuisine.
        const out = sanitizeKdsInstruction('TACOS M\n[Allergie:\n- gluten\n- arachide]', 'Tacos M');
        expect(out).toContain('gluten');
        expect(out).toContain('arachide');
    });

    it('is safe on empty / null / non-string (never throws, returns empty)', () => {
        expect(sanitizeKdsInstruction('', 'X')).toBe('');
        expect(sanitizeKdsInstruction(null, 'X')).toBe('');
        expect(sanitizeKdsInstruction(undefined, undefined)).toBe('');
        expect(sanitizeKdsInstruction(42, 'X')).toBe('');
    });
});

describe('renderItem — instruction is sanitized (no compo doublage, extras preserved)', () => {
    it('does NOT emit an instruction line when the instruction is a pure compo duplicate', () => {
        const out = renderItem({
            item_name: 'Tacos M',
            quantity: 1,
            instruction: 'TACOS M\nViandes : Mexicanos Sauce : Harissa',
        });
        expect(out.lines.find((l) => l.type === 'instruction')).toBeFalsy();
    });

    it('[KITCHEN-MENU 2026-06-30] no instruction line when it was only menu/sauce-frites (now MENU : SYM)', () => {
        const out = renderItem({
            item_name: 'Tacos M',
            quantity: 1,
            instruction: 'TACOS M\n↳ Sauce frites: Curry',
        });
        // La sauce frites est désormais portée par la ligne « MENU : SYM », pas par une note.
        expect(out.lines.find((l) => l.type === 'instruction')).toBeFalsy();
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

// [POS-WIZARD-COMPO-AUDIT 2026-06-23 P2-B] Shape-agnostic "GROUP: VALUE" accessor
// for the KDS order-card / kitchen-ticket variation render. Fixes the field
// inversion where snapshot-shaped lines (attribute_name=GROUP, variation_name=
// VALUE, no `name`) were rendered with the legacy template `{{variation_name}}:
// {{name}}` → "Poulet mariné: " (group dropped, value mislabeled). Works for
// BOTH shapes so the items-board (legacy shape) stays correct too.
describe('kdsVariationGroupValue / kdsVariationLine — shape-agnostic GROUP:VALUE', () => {
    it('snapshot shape: attribute_name=GROUP, variation_name=VALUE (no name)', () => {
        const v = { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' };
        expect(kdsVariationGroupValue(v)).toEqual({ group: 'Viande 1', value: 'Poulet mariné' });
        expect(kdsVariationLine(v)).toBe('Viande 1: Poulet mariné');
    });

    it('legacy shape: variation_name=GROUP, name=VALUE', () => {
        const v = { variation_name: 'Pain', name: 'Galette' };
        expect(kdsVariationGroupValue(v)).toEqual({ group: 'Pain', value: 'Galette' });
        expect(kdsVariationLine(v)).toBe('Pain: Galette');
    });

    it('empty/missing attribute_name falls back to legacy interpretation', () => {
        const v = { attribute_name: '', variation_name: 'Sauce', name: 'Algérienne' };
        expect(kdsVariationLine(v)).toBe('Sauce: Algérienne');
    });

    it('value-only (no group) renders the value alone, no dangling colon', () => {
        expect(kdsVariationLine({ variation_name: 'Frites' })).toBe('Frites');
        expect(kdsVariationLine({ attribute_name: 'Taille', variation_name: '' })).toBe('Taille');
    });

    it('null / garbage is safe (empty string, never throws)', () => {
        expect(kdsVariationLine(null)).toBe('');
        expect(kdsVariationLine(undefined)).toBe('');
        expect(kdsVariationLine({})).toBe('');
        expect(kdsVariationGroupValue(null)).toEqual({ group: '', value: '' });
    });
});
