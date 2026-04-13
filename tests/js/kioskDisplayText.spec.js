import { describe, it, expect } from 'vitest';
import { sanitizeKioskCustomerFacingText } from '../../resources/js/helpers/kioskDisplayText';

describe('sanitizeKioskCustomerFacingText', () => {
  it('remplace les termes add-on / addon par du français client', () => {
    expect(sanitizeKioskCustomerFacingText('Sauce add-on')).toContain('option');
    expect(sanitizeKioskCustomerFacingText('addon menu')).toContain('option');
    expect(sanitizeKioskCustomerFacingText('Add-ons gratuits')).toContain('options');
  });

  it('retourne une chaîne vide pour null ou vide', () => {
    expect(sanitizeKioskCustomerFacingText(null)).toBe('');
    expect(sanitizeKioskCustomerFacingText('   ')).toBe('');
  });
});
