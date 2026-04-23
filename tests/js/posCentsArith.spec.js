import { describe, it, expect } from 'vitest';
import posCentsArith, {
  addCents,
  subCents,
  sumCents,
  toCents,
} from '../../resources/js/helpers/posCentsArith.js';

describe('posCentsArith', () => {
  it('exports named helpers and default object helpers', () => {
    expect(posCentsArith.addCents).toBe(addCents);
    expect(posCentsArith.subCents).toBe(subCents);
    expect(posCentsArith.sumCents).toBe(sumCents);
    expect(posCentsArith.toCents).toBe(toCents);
  });

  it('adds cents with simple integer arithmetic', () => {
    expect(addCents(100, 25)).toBe(125);
  });

  it('subtracts cents without producing negative results', () => {
    expect(subCents(125, 25)).toBe(100);
  });

  it('throws when arithmetic inputs contain negative values', () => {
    expect(() => addCents(-1, 10)).toThrow(/cannot be negative/i);
    expect(() => subCents(10, -1)).toThrow(/cannot be negative/i);
    expect(() => sumCents(10, -1, 5)).toThrow(/cannot be negative/i);
  });

  it('returns 0 for an empty variadic sum', () => {
    expect(sumCents()).toBe(0);
  });

  it('sums multiple cent values variadically', () => {
    expect(sumCents(10, 20, 30, 40)).toBe(100);
  });

  it('converts 0.1 to 10 cents', () => {
    expect(toCents(0.1)).toBe(10);
  });

  it('converts floating-point imprecision like 0.30000004 to 30 cents', () => {
    expect(toCents(0.30000004)).toBe(30);
  });

  it('rejects non-finite number inputs', () => {
    expect(() => toCents(Infinity)).toThrow(/finite number/i);
    expect(() => toCents(NaN)).toThrow(/finite number/i);
  });

  it('rejects string inputs', () => {
    expect(() => toCents('12.34')).toThrow(/finite number/i);
    expect(() => addCents('10', 5)).toThrow(/finite number/i);
  });

  it('rejects values with more than two decimals beyond float tolerance', () => {
    expect(() => toCents(0.345)).toThrow(/at most 2 decimal places/i);
  });

  it('rejects subtraction results that would become negative', () => {
    expect(() => subCents(10, 20)).toThrow(/result cannot be negative/i);
  });
});
