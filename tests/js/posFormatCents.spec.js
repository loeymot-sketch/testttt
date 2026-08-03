import { describe, it, expect } from 'vitest';
import { formatCents } from '../../resources/js/helpers/posFormatCents.js';

describe('formatCents', () => {
  const NBSP = '\u00a0';

  it('formats 0 cents in display variant by default', () => {
    expect(formatCents(0)).toBe('0,00 €');
  });

  it('formats simple cents amount', () => {
    expect(formatCents(199)).toBe('1,99 €');
  });

  it('formats grouped thousands with NBSP in display variant', () => {
    expect(formatCents(123456)).toBe(`1${NBSP}234,56 €`);
  });

  it('formats explicit EUR currency', () => {
    expect(formatCents(1, { currency: 'EUR' })).toBe('0,01 €');
  });

  it('omits symbol when currency is empty string', () => {
    expect(formatCents(100, { currency: '' })).toBe('1,00');
  });

  it('formats plain variant without currency symbol', () => {
    expect(formatCents(100, { variant: 'plain' })).toBe('1.00');
  });

  it('formats plain variant without thousands separator', () => {
    expect(formatCents(123456, { variant: 'plain' })).toBe('1234.56');
  });

  it('uses provided symbol override', () => {
    expect(formatCents(50, { currency: 'USD', symbol: '$' })).toBe('0,50 $');
  });

  it('throws RangeError for negative cents with exact message', () => {
    expect(() => formatCents(-1)).toThrow(RangeError);
    expect(() => formatCents(-1)).toThrow('cents cannot be negative');
  });

  it('throws RangeError for non-integer cents with exact message', () => {
    expect(() => formatCents(1.5)).toThrow(RangeError);
    expect(() => formatCents(1.5)).toThrow('cents must be an integer');
  });

  it('throws TypeError for string cents with exact message', () => {
    expect(() => formatCents('100')).toThrow(TypeError);
    expect(() => formatCents('100')).toThrow('cents must be a finite number');
  });

  it('throws TypeError for null cents', () => {
    expect(() => formatCents(null)).toThrow(TypeError);
  });
});
