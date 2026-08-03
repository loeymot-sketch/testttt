import { describe, it, expect } from 'vitest';
import {
  detectTacosSize,
  viandeCountFromName,
  hasPresetSizeInName,
  tacosSizeLabel,
} from '../../resources/js/helpers/kioskTacosSize';

/**
 * [P-MEGA-01] Garde-fou contre le bug rapporté par l'utilisateur :
 *   « Tacos 2 / 3 / 4 viandes → on ne peut sélectionner qu'1 viande ».
 *
 * Cause racine : 3 fonctions du wizard (`detectViandeCount`,
 * `shouldAskTacosTaille`, `inferTacosPresetMeta`) implémentaient chacune
 * leur propre regex incompatible. Pour `Tacos M`, `Tacos Méga`,
 * `Tacos Famille`, l'une matche tandis qu'une autre tombe à 1.
 *
 * Ce test bloque toute régression sur le helper centralisé qui les
 * remplace.
 */

describe('kioskTacosSize — detectTacosSize', () => {
  const cases = [
    { name: 'Tacos M', expected: 'M' },
    { name: 'Tacos L', expected: 'L' },
    { name: 'Tacos XL', expected: 'XL' },
    { name: 'Tacos XXL', expected: 'XXL' },
    { name: 'Tacos Méga', expected: 'MEGA' },
    { name: 'Tacos Mega', expected: 'MEGA' },
    { name: 'Tacos Famille', expected: 'FAMILLE' },
    { name: 'TACOS XXL Spécial', expected: 'XXL' },
    { name: 'tacos l 2 viandes', expected: 'L' },
    { name: 'Sandwich Simple', expected: null },
    { name: 'Coca Zero', expected: null },
    { name: '', expected: null },
    { name: null, expected: null },
    { name: undefined, expected: null },
  ];

  cases.forEach(({ name, expected }) => {
    it(`"${String(name)}" → ${expected}`, () => {
      expect(detectTacosSize(name)).toBe(expected);
    });
  });

  it('XXL est matché avant XL (ordre des regex)', () => {
    expect(detectTacosSize('Tacos XXL')).toBe('XXL');
    expect(detectTacosSize('Tacos XXL spécial')).toBe('XXL');
    expect(detectTacosSize('Tacos XL')).toBe('XL');
  });
});

describe('kioskTacosSize — viandeCountFromName', () => {
  const cases = [
    // Bug original : ces noms retombaient à 1 (silencieux)
    { name: 'Tacos M', expected: 1 },
    { name: 'Tacos L', expected: 2 },
    { name: 'Tacos XL', expected: 3 },
    { name: 'Tacos XXL', expected: 4 },
    { name: 'Tacos Méga', expected: 4 },
    { name: 'Tacos Famille', expected: 4 },

    // Mention digit explicite gagne sur taille lettre
    { name: 'Tacos L 3 viandes', expected: 3 },
    { name: 'Tacos XXL 4 viandes', expected: 4 },
    { name: 'Tacos 2 viandes', expected: 2 },

    // Cas non-tacos / non-reconnus → null (PAS 1)
    { name: 'Coca Zero', expected: null },
    { name: 'Frites maison', expected: null },
    { name: '', expected: null },
    { name: null, expected: null },
  ];

  cases.forEach(({ name, expected }) => {
    it(`"${String(name)}" → ${expected}`, () => {
      expect(viandeCountFromName(name)).toBe(expected);
    });
  });

  it("retourne null (et non 1) sur un nom inconnu — l'appelant DOIT gérer le fallback", () => {
    expect(viandeCountFromName('Tacos Spécial Maison')).toBeNull();
    expect(viandeCountFromName('Burger Classic')).toBeNull();
  });
});

describe('kioskTacosSize — hasPresetSizeInName', () => {
  it.each([
    ['Tacos M', true],
    ['Tacos L', true],
    ['Tacos XL', true],
    ['Tacos XXL', true],
    ['Tacos Méga', true],
    ['Tacos 3 viandes', true],
    ['Sandwich classique', false],
    ['', false],
    [null, false],
  ])('"%s" → %s', (name, expected) => {
    expect(hasPresetSizeInName(name)).toBe(expected);
  });
});

describe('kioskTacosSize — tacosSizeLabel', () => {
  it.each([
    ['Tacos M', 'M'],
    ['Tacos L', 'L'],
    ['Tacos XL', 'XL'],
    ['Tacos XXL', 'XXL'],
    ['Tacos Méga', 'Méga'],
    ['Tacos Famille', 'Famille'],
    ['Tacos 3 viandes', '3 viandes'],
    ['Coca Zero', null],
  ])('"%s" → "%s"', (name, expected) => {
    expect(tacosSizeLabel(name)).toBe(expected);
  });
});
