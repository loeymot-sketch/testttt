import { describe, it, expect } from 'vitest';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

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
