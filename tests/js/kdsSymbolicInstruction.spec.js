import { describe, it, expect } from 'vitest';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [W3-FIX-A 2026-07-06] La note client (instruction) DOIT apparaître sur l'écran
 * cuisine V2 (layout symbolique). Le ticket imprimé la gardait déjà (** note) mais
 * renderItemSymbolic() n'émettait JAMAIS de ligne type:'instruction' → le cuisinier
 * ne voyait pas « oignons cuits » sur le /kds (prouvé commande 5501 E2E-173832).
 */

const instructionLines = (item) =>
  renderItemSymbolic(item).lines.filter((l) => l.type === 'instruction');

describe('renderItemSymbolic — note client (instruction) sur écran cuisine', () => {
  it('émet la note libre du client (« oignons cuits »)', () => {
    const lines = instructionLines({
      item_name: 'Tacos M',
      quantity: 1,
      instruction: 'oignons cuits',
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(lines).toHaveLength(1);
    expect(lines[0].label).toBe('oignons cuits');
    expect(lines[0].visualClass).toBeTruthy();
  });

  it('shape réel 5501 : strip l’écho compo, garde la note', () => {
    // instruction EXACTE écrite par le wizard sur la commande 5501 (DB réelle)
    const lines = instructionLines({
      item_name: 'Tacos M',
      quantity: 1,
      instruction: 'Viandes : Poulet mariné ×1\noignons cuits',
      composition_snapshot: {
        lines: [
          { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
          { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
        ],
        extras: [],
        addons: [],
      },
    });
    expect(lines).toHaveLength(1);
    expect(lines[0].label).toBe('oignons cuits');
  });

  it('écho compo pur « Viandes : Poulet mariné ×1 » → AUCUNE ligne note', () => {
    const lines = instructionLines({
      item_name: 'Tacos M',
      instruction: 'Viandes : Poulet mariné ×1',
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(lines).toHaveLength(0);
  });

  it('canal boisson POS « BOISSON: Coca-Cola 33cl » → PRÉSENTE (jamais strippée)', () => {
    const lines = instructionLines({
      item_name: 'Tacos M',
      instruction: 'BOISSON: Coca-Cola 33cl',
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    expect(lines).toHaveLength(1);
    expect(lines[0].label).toBe('BOISSON: Coca-Cola 33cl');
  });

  it('branche isMenuItem (« Menu (Frites + Boisson) ») émet aussi la note', () => {
    const res = renderItemSymbolic({
      item_name: 'Menu (Frites + Boisson)',
      quantity: 1,
      instruction: 'sans glacons',
      composition_snapshot: { lines: [], extras: [], addons: [] },
    });
    const notes = res.lines.filter((l) => l.type === 'instruction');
    expect(notes).toHaveLength(1);
    expect(notes[0].label).toBe('sans glacons');
    // la ligne MENU reste la première ligne
    expect(res.lines[0].type).toBe('symbolic-main');
    expect(res.lines[0].label).toBe('MENU');
  });

  it('instruction vide / null → aucune ligne', () => {
    expect(instructionLines({ item_name: 'Tacos M', instruction: null })).toHaveLength(0);
    expect(instructionLines({ item_name: 'Tacos M', instruction: '' })).toHaveLength(0);
  });
});
