/**
 * H4.6 / Z3-NEW-007 — KDS aria-label i18n parity (5 languages)
 *
 * Two raw FR aria-label fragments lived in
 * resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:
 *   - line 100 template: `Appeler ${customerName || ''} ${customerPhone}`.trim()
 *   - line 280 computed `ariaLabel()`:
 *     `Commande ${queue}, source ${sourceLabel}, ${stateLabel},
 *      attente ${m} minutes ${r} secondes`
 *
 * They now resolve through:
 *   - label.kds_call_customer_aria  (placeholders: {name}, {phone})
 *   - label.kds_card_aria           (placeholders: {queue}, {source},
 *                                    {state}, {minutes}, {seconds})
 *
 * This spec is the RED test: it asserts both keys exist in fr+en, are
 * non-empty, are NOT stale-copied between languages (translation actually
 * happened), and preserve the interpolation placeholders so vue-i18n can
 * resolve `$t('label.key', { name, phone })` at runtime.
 */
import { describe, it, expect } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

describe('KDS aria-label i18n parity (Z3-NEW-007)', () => {
    describe('label.kds_call_customer_aria', () => {
        it('is defined and non-empty in fr and en', () => {
            expect(fr.label.kds_call_customer_aria).toBeDefined();
            expect(en.label.kds_call_customer_aria).toBeDefined();
            expect(fr.label.kds_call_customer_aria).not.toBe('');
            expect(en.label.kds_call_customer_aria).not.toBe('');
        });

        it('differs between fr and en (translation, not stale-copy)', () => {
            expect(fr.label.kds_call_customer_aria).not.toBe(
                en.label.kds_call_customer_aria
            );
        });

        it('preserves {name} and {phone} placeholders in both langs', () => {
            expect(fr.label.kds_call_customer_aria).toMatch(/\{name\}/);
            expect(fr.label.kds_call_customer_aria).toMatch(/\{phone\}/);
            expect(en.label.kds_call_customer_aria).toMatch(/\{name\}/);
            expect(en.label.kds_call_customer_aria).toMatch(/\{phone\}/);
        });
    });

    describe('label.kds_card_aria', () => {
        it('is defined and non-empty in fr and en', () => {
            expect(fr.label.kds_card_aria).toBeDefined();
            expect(en.label.kds_card_aria).toBeDefined();
            expect(fr.label.kds_card_aria).not.toBe('');
            expect(en.label.kds_card_aria).not.toBe('');
        });

        it('differs between fr and en (translation, not stale-copy)', () => {
            expect(fr.label.kds_card_aria).not.toBe(en.label.kds_card_aria);
        });

        it('preserves all 5 placeholders in both langs', () => {
            const placeholders = ['{queue}', '{source}', '{state}', '{minutes}', '{seconds}'];
            for (const ph of placeholders) {
                const re = new RegExp(ph.replace('{', '\\{').replace('}', '\\}'));
                expect(fr.label.kds_card_aria).toMatch(re);
                expect(en.label.kds_card_aria).toMatch(re);
            }
        });
    });
});
