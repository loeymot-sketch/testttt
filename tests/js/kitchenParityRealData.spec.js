import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { symbolicMainLine, buildSymbolic, renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';
import { sanitizeKdsInstruction } from '../../resources/js/helpers/kdsCustomization.js';

// [PARITY-REAL 2026-06-30] Cross-engine parity on REAL composition_snapshots.
// The PHP renderer (printed ticket) and the JS engine (board/KDS) MUST produce the
// IDENTICAL symbolic Line-1. This proves the owner's "absolutely the same as the
// board" requirement on production data, not just hand-written fixtures.
// [W6-ADV B-2 2026-07-06] Corpus régénéré depuis la DB réelle (220 rows, commandes
// 5171 + 5506 + 5528-5533 forcées) et ENRICHI : instr (instruction brute), note
// (cleanInstruction PHP), drink (isDrinkItem PHP), drinks (drinkLines PHP) → parité
// stricte aussi sur les nouveaux shapes du range : O̲ (oignons cuits), notes client,
// tête boisson NOM COMPLET, addons boisson, extraction boisson de formule borne.
// Régénérer : php artisan tinker --execute="require 'tools/audit/gen-parity-fixture.php';"
const FIXTURE = path.join(process.cwd(), 'tests/fixtures/parity_php.json');

describe('kitchen symbolic parity — PHP (print) vs JS (board) on real data', () => {
  const rows = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));

  it('has a non-trivial corpus', () => {
    expect(rows.length).toBeGreaterThan(100);
  });

  it('[B-2] corpus couvre les nouveaux shapes : O̲, note, tête boisson, addon boisson', () => {
    expect(rows.some((r) => (r.php || '').includes('̲'))).toBe(true);
    expect(rows.some((r) => (r.note || '').length > 0)).toBe(true);
    expect(rows.some((r) => r.drink === true)).toBe(true);
    expect(rows.some((r) => Array.isArray(r.drinks) && r.drinks.length > 0)).toBe(true);
    // extraction boisson de formule borne (#5533) présente dans le corpus
    expect(rows.some((r) => /formule\s*:/i.test(r.instr || '') && (r.note || '').includes('BOISSON:'))).toBe(true);
  });

  it('every real (name, snapshot) yields identical Line-1 in both engines', () => {
    const mismatches = [];
    for (const r of rows) {
      const js = symbolicMainLine({ item_name: r.name, composition_snapshot: r.snap });
      if (js !== r.php) {
        mismatches.push({ name: r.name, php: r.php, js });
      }
    }
    if (mismatches.length) {
      // surface up to 15 for diagnosis
      console.error('MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });

  it('every real snapshot yields identical L2 supplements + MENU in both engines', () => {
    const mismatches = [];
    for (const r of rows) {
      const s = buildSymbolic({ item_name: r.name, composition_snapshot: r.snap });
      const jsSupps = s.supplements;
      const jsMenu = s.menu;
      if (JSON.stringify(jsSupps) !== JSON.stringify(r.supps ?? [])) {
        mismatches.push({ name: r.name, kind: 'supps', php: r.supps, js: jsSupps });
      }
      if (jsMenu !== (r.menu ?? '')) {
        mismatches.push({ name: r.name, kind: 'menu', php: r.menu, js: jsMenu });
      }
    }
    if (mismatches.length) {
      console.error('L2 MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });

  it('[B-2] note : cleanInstruction (PHP) === sanitizeKdsInstruction (JS) sur données réelles', () => {
    const mismatches = [];
    for (const r of rows) {
      const js = sanitizeKdsInstruction(r.instr ?? '', r.name);
      if (js !== (r.note ?? '')) {
        mismatches.push({ name: r.name, instr: r.instr, php: r.note, js });
      }
    }
    if (mismatches.length) {
      console.error('NOTE MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });

  it('[B-2] détection boisson + tête NOM COMPLET identiques PHP↔JS', () => {
    const mismatches = [];
    for (const r of rows) {
      const item = { item_name: r.name, composition_snapshot: r.snap, instruction: r.instr ?? '' };
      const res = renderItemSymbolic(item);
      const jsDrink = res.category === 'drink';
      if (jsDrink !== !!r.drink) {
        mismatches.push({ name: r.name, kind: 'is_drink', php: !!r.drink, js: jsDrink });
        continue;
      }
      if (jsDrink && res.lines[0].label !== String(r.name).trim()) {
        mismatches.push({ name: r.name, kind: 'drink_head', js: res.lines[0].label });
      }
    }
    if (mismatches.length) {
      console.error('DRINK MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });

  it('[B-2] lignes addon boisson identiques (écran « 1× X » ↔ ticket « 1 X »)', () => {
    const mismatches = [];
    for (const r of rows) {
      const res = renderItemSymbolic({ item_name: r.name, composition_snapshot: r.snap, instruction: r.instr ?? '' });
      // menu_child lines de renderItemSymbolic = uniquement les addons boisson
      const jsDrinks = res.lines
        .filter((l) => l.type === 'menu_child')
        .map((l) => l.label.replace(/^(\d+)×\s/, '$1 '));
      const phpDrinks = r.drinks ?? [];
      if (JSON.stringify(jsDrinks) !== JSON.stringify(phpDrinks)) {
        mismatches.push({ name: r.name, php: phpDrinks, js: jsDrinks });
      }
    }
    if (mismatches.length) {
      console.error('DRINK-LINES MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });
});
