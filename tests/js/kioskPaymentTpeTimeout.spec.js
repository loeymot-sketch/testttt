/**
 * Sentinel: TPE timeout uses SSOT constant from config (W7.B β).
 */
import fs from 'node:fs';
import { describe, it, expect } from 'vitest';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { KIOSK_HARDWARE } from '../../resources/js/config/kioskHardware';

const __dirname = dirname(fileURLToPath(import.meta.url));
const paymentPath = resolve(__dirname, '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

describe('KioskPaymentComponent TPE timeout wiring', () => {
  it('imports TPE_TIMEOUT_MS from config/kioskHardware (sentinel)', () => {
    const src = fs.readFileSync(paymentPath, 'utf8');
    expect(src).toMatch(/import\s*\{[^}]*\bKIOSK_HARDWARE\b[^}]*\}\s*from\s*['"]\.\.\/\.\.\/\.\.\/config\/kioskHardware['"]/);
    expect(src).toMatch(/\bTPE_TIMEOUT_MS\b/);
  });

  it('TPE_TIMEOUT_MS dans config a la valeur fiscalement validée 120000ms', () => {
    expect(KIOSK_HARDWARE.TPE_TIMEOUT_MS).toBe(120_000);
  });
});
