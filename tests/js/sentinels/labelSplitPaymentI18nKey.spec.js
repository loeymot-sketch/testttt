import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-BLUE-2026-05-08-B1-B5-P1 | @source HEAL-B mission B1 + B5 P1
 * @reason
 *   PaymentComponent.vue:76 utilise `$t('label.split_payment') || 'Multi-paiement'`.
 *   Comme vue-i18n retourne la KEY (truthy) quand la clé est absente, le fallback
 *   `|| 'Multi-paiement'` ne s'active jamais → le bouton affiche littéralement
 *   "label.split_payment". On garantit ici que la clé est présente dans les 3
 *   langues supportées (fr, en, ar).
 *
 * Sentinel ferme :
 *   - fr.json contient label.split_payment → "Multi-paiement"
 *   - en.json contient label.split_payment → "Multi-payment"
 *   - ar.json contient label.split_payment → "دفع متعدد"
 */
describe('i18n — label.split_payment présent dans fr/en/ar (B1 + B5 P1)', () => {
    const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'));
    const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8'));
    const ar = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8'));

    it('fr.json defines label.split_payment with a non-empty FR translation', () => {
        expect(fr.label).toBeDefined();
        expect(fr.label.split_payment).toBeDefined();
        expect(typeof fr.label.split_payment).toBe('string');
        expect(fr.label.split_payment.trim().length).toBeGreaterThan(0);
        // Sanity : ne doit PAS être la KEY elle-même (ce serait le bug original).
        expect(fr.label.split_payment).not.toBe('label.split_payment');
        expect(fr.label.split_payment).toBe('Multi-paiement');
    });

    it('en.json defines label.split_payment with a non-empty EN translation', () => {
        expect(en.label).toBeDefined();
        expect(en.label.split_payment).toBeDefined();
        expect(typeof en.label.split_payment).toBe('string');
        expect(en.label.split_payment.trim().length).toBeGreaterThan(0);
        expect(en.label.split_payment).not.toBe('label.split_payment');
        expect(en.label.split_payment).toBe('Multi-payment');
    });

    it('ar.json defines label.split_payment with a non-empty AR translation', () => {
        expect(ar.label).toBeDefined();
        expect(ar.label.split_payment).toBeDefined();
        expect(typeof ar.label.split_payment).toBe('string');
        expect(ar.label.split_payment.trim().length).toBeGreaterThan(0);
        expect(ar.label.split_payment).not.toBe('label.split_payment');
        expect(ar.label.split_payment).toBe('دفع متعدد');
    });
});
