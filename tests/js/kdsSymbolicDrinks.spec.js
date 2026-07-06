import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';
import { categorize, isDrinkName, sanitizeKdsInstruction } from '../../resources/js/helpers/kdsCustomization.js';

/**
 * [W3-FIX-C 2026-07-06] Boissons VISIBLES en cuisine (écran KDS V2) :
 *  1. item boisson standalone (#5456 « Coca-Cola 33cl ») → nom COMPLET, plus « COC » cryptique
 *  2. addon role=drink (#5171 « Boisson Seule » sur Bol Riz) → ligne menu_child « 1× … »
 *  3. addon role=menu_boisson (formule borne) → ligne menu_child sous le badge MENU
 * Détection PHP↔JS strictement identique (KitchenTicketSymbolicFormatter::isDrinkItem
 * = jumeau de categorize()==='drink', garde dessert-avant-drink comprise).
 */

describe('renderItemSymbolic — boissons visibles cuisine', () => {
  it('item boisson standalone (#5456) → label = nom complet, pas le code 3 lettres', () => {
    const res = renderItemSymbolic({
      item_name: 'Coca-Cola 33cl',
      quantity: 1,
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(res.category).toBe('drink');
    expect(res.lines[0].type).toBe('symbolic-main');
    expect(res.lines[0].label).toBe('Coca-Cola 33cl');
    expect(res.lines[0].label).not.toBe('COC');
  });

  it('addon role=drink (#5171 shape réel) → menu_child « 1× Boisson Seule », main line intacte', () => {
    const res = renderItemSymbolic({
      item_name: 'Bol Riz',
      quantity: 1,
      composition_snapshot: {
        lines: [],
        extras: [],
        addons: [{ role: 'drink', addon_id: 100, quantity: 1, addon_name: 'Boisson Seule', line_total: 2, unit_price: 2 }],
      },
    });
    const drinks = res.lines.filter((l) => l.type === 'menu_child');
    expect(drinks).toHaveLength(1);
    expect(drinks[0].label).toBe('1× Boisson Seule');
    // le produit principal garde sa ligne symbolique
    expect(res.lines[0].type).toBe('symbolic-main');
    expect(res.lines[0].label).toContain('BOL');
  });

  it('addon role=menu_boisson → badge MENU + menu_child « 1× Coca-Cola 33cl »', () => {
    const res = renderItemSymbolic({
      item_name: 'Tacos M',
      quantity: 1,
      composition_snapshot: {
        lines: [],
        extras: [],
        addons: [
          { role: 'menu_frites', quantity: 1, addon_name: 'Frites' },
          { role: 'menu_boisson', quantity: 1, addon_name: 'Coca-Cola 33cl' },
        ],
      },
    });
    expect(res.lines.some((l) => l.type === 'symbolic-menu' && l.label.startsWith('MENU'))).toBe(true);
    const drinks = res.lines.filter((l) => l.type === 'menu_child');
    expect(drinks).toHaveLength(1);
    expect(drinks[0].label).toBe('1× Coca-Cola 33cl');
  });

  it('branche isMenuItem : addon boisson rendu aussi sous la ligne MENU', () => {
    const res = renderItemSymbolic({
      item_name: 'Menu (Frites + Boisson)',
      quantity: 1,
      composition_snapshot: {
        lines: [],
        extras: [],
        addons: [{ role: 'menu_boisson', quantity: 1, addon_name: 'Fanta 33cl' }],
      },
    });
    expect(res.lines[0].label).toBe('MENU');
    const drinks = res.lines.filter((l) => l.type === 'menu_child');
    expect(drinks).toHaveLength(1);
    expect(drinks[0].label).toBe('1× Fanta 33cl');
  });

  it('garde dessert-avant-drink : « Gâteau » (contient eau) n’est PAS une boisson', () => {
    const res = renderItemSymbolic({
      item_name: 'Gâteau',
      quantity: 1,
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(res.category).toBe('dessert');
    expect(res.lines[0].label).toBe('GAT');
  });

  it('addon non-boisson (frites, role vide) → PAS de ligne menu_child boisson', () => {
    const res = renderItemSymbolic({
      item_name: 'Tacos M',
      quantity: 1,
      composition_snapshot: {
        lines: [],
        extras: [],
        addons: [{ role: '', quantity: 1, addon_name: 'Frites' }],
      },
    });
    expect(res.lines.filter((l) => l.type === 'menu_child')).toHaveLength(0);
  });

  it('quantité > 1 → « 2× Boisson Seule »', () => {
    const res = renderItemSymbolic({
      item_name: 'Bol Riz',
      composition_snapshot: {
        lines: [],
        extras: [],
        addons: [{ role: 'drink', quantity: 2, addon_name: 'Boisson Seule' }],
      },
    });
    expect(res.lines.filter((l) => l.type === 'menu_child')[0].label).toBe('2× Boisson Seule');
  });
});

/**
 * [W6-ADV B-1 2026-07-06] Data-driven sur LA LISTE RÉELLE DB (fixture jumelle
 * tests/fixtures/drinks_active_db.json, verrouillée set-equality côté PHP contre les
 * seeders canoniques) : l'ancien filet regex ratait 8/15 boissons actives (Hawaï —
 * régression du renommage Fanta Hawai —, Oasis, Orangina, Capri-Sun, Tropico,
 * Ice Tea, Fuze Tea, Perrier → « 1× HAW » cryptique à l'écran cuisine).
 */
describe('détection boisson — 15/15 boissons actives DB (fixture jumelle PHP)', () => {
  const fx = JSON.parse(
    fs.readFileSync(path.join(process.cwd(), 'tests/fixtures/drinks_active_db.json'), 'utf8')
  );

  it('la fixture porte les 15 boissons réelles', () => {
    expect(fx.drinks.length).toBeGreaterThanOrEqual(15);
  });

  it.each(fx.drinks)('« %s » → drink + tête NOM COMPLET à l’écran', (name) => {
    expect(isDrinkName(name)).toBe(true);
    expect(categorize({ item_name: name })).toBe('drink');
    const res = renderItemSymbolic({
      item_name: name,
      quantity: 1,
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(res.lines[0].type).toBe('symbolic-main');
    expect(res.lines[0].label).toBe(name);
  });

  it.each(fx.desserts)('faux positif dessert réel : « %s » n’est PAS une boisson', (name) => {
    expect(isDrinkName(name)).toBe(false);
    expect(categorize({ item_name: name })).not.toBe('drink');
  });

  it('« Glace » ≠ « glaçons », « Tacos L » ≠ volume, libellé formule rejeté', () => {
    expect(isDrinkName('Glace 2 boules')).toBe(false);
    expect(isDrinkName('Tacos L')).toBe(false);
    expect(isDrinkName('frites + boisson')).toBe(false);
    expect(isDrinkName('Menu (Frites + Boisson)')).toBe(false);
    expect(isDrinkName('Limonade artisanale 1L')).toBe(true); // token volumétrique future-proof
  });
});

/**
 * [W6-ADV C-P1-1 2026-07-06] Boisson de formule BORNE : la borne n'écrit PAS d'addon
 * boisson (menu_full seul) — la boisson voyage en texte dans la ligne « Formule : … »
 * que le sanitizer droppe entière. Elle doit être EXTRAITE en « BOISSON: X » (canal
 * caisse, déjà rendu écran + ticket). Shape réel #5533 (A0012).
 */
describe('sanitizeKdsInstruction — extraction boisson de formule borne (#5533)', () => {
  const RAW_5533 = 'Pain : Pain. Formule : Menu complet (frites + boisson) (Hawaï 33cl). Sauce frites : Algérienne';

  it('instruction borne réelle → « BOISSON: Hawaï 33cl » (la ligne verbeuse reste droppée)', () => {
    expect(sanitizeKdsInstruction(RAW_5533, 'Cayenne')).toBe('BOISSON: Hawaï 33cl');
  });

  it('renderItemSymbolic (#5533 complet) → ligne instruction visible à l’écran KDS', () => {
    const res = renderItemSymbolic({
      item_name: 'Cayenne',
      quantity: 1,
      instruction: RAW_5533,
      composition_snapshot: {
        lines: [
          { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
          { attribute_name: 'Type de Pain', variation_name: 'Pain' },
        ],
        extras: [
          { extra_name: 'Salade', quantity: 1, unit_price: 0, line_total: 0 },
          { extra_name: 'Tomate', quantity: 1, unit_price: 0, line_total: 0 },
          { extra_name: 'Oignons cuits', quantity: 1, unit_price: 0, line_total: 0 },
        ],
        addons: [
          { role: 'menu_full', addon_id: 25, quantity: 1, addon_name: 'Menu (Frites + Boisson)', line_total: 2.5, unit_price: 2.5 },
        ],
      },
    });
    const notes = res.lines.filter((l) => l.type === 'instruction');
    expect(notes).toHaveLength(1);
    expect(notes[0].label).toBe('BOISSON: Hawaï 33cl');
    // sauce frites toujours convertie en symbole sur le badge MENU (fritesSauceSymbol intact)
    expect(res.lines.some((l) => l.type === 'symbolic-menu' && l.label === 'MENU : ALG')).toBe(true);
  });

  it('formule SANS boisson → rien d’inventé', () => {
    expect(sanitizeKdsInstruction('Pain : Pain. Formule : Menu complet (frites + boisson). Sauce frites : Andalouse', 'Cayenne')).toBe('');
    expect(sanitizeKdsInstruction('Formule : Frites seules. Sauce frites : Andalouse', 'Cayenne')).toBe('');
  });

  it('dédupe : la caisse a déjà écrit sa ligne « BOISSON: X » → pas de doublon', () => {
    const out = sanitizeKdsInstruction(
      'Formule : Menu (Hawaï 33cl). Sauce frites : Algérienne\nBOISSON: Hawaï 33cl',
      'Cayenne'
    );
    expect(out).toBe('BOISSON: Hawaï 33cl');
  });
});
