/**
 * [P3 breadth-audit heal 2026-06-07] Cross-locale parity sentinel for the NEW
 * i18n keys introduced by the P3 cosmetic heals (KDS-UI-02 / KDS-UI-04 /
 * KDS-UI-05 / KIOSK-OFFLINE-PLANB-01).
 *
 * WHY THIS SPEC EXISTS — the three named "parity" sentinels do NOT actually
 * enforce parity for these keys:
 *   - studioFrontendI18nParity only diffs the `studio.*` keyset.
 *   - labelKeyParityFrontend only scans admin/items|ingredients|demo|
 *     layouts/backend|settings and only checks *fr* existence.
 *   - kdsAriaI18n imports only fr+en and only two fixed keys.
 * So a key shipped in fr-only would pass all three and still be a parity hole
 * in de/bn/ar. This sentinel closes that hole for the P3 keys by importing all
 * five locales and asserting each new key exists at the identical nested path,
 * non-empty, with its interpolation placeholders preserved per language.
 */
import { describe, it, expect } from 'vitest';
import fr from '../../../resources/js/languages/fr.json';
import en from '../../../resources/js/languages/en.json';
import de from '../../../resources/js/languages/de.json';
import bn from '../../../resources/js/languages/bn.json';
import ar from '../../../resources/js/languages/ar.json';

const LOCALES = { fr, en, de, bn, ar };

const get = (obj, dotted) =>
    dotted.split('.').reduce((acc, k) => (acc && typeof acc === 'object' ? acc[k] : undefined), obj);

// Keys added by the P3 heals → required placeholder tokens (if any).
const NEW_KEYS = {
    // KDS-UI-02 — served-ago rollup
    'label.kds_served_ago_hours': ['{hours}'],
    'label.kds_served_ago_days': ['{days}'],
    // KDS-UI-04 — state-aware CTA
    'label.kds_card_cta_start': [],
    // KDS-UI-05 — legacy lane empty-states + print button
    'label.kds_lane_empty_dinein': [],
    'label.kds_lane_empty_online': [],
    'label.kds_lane_empty_takeaway': [],
    'label.kds_lane_empty_borne': [],
    'button.kds_print_ticket': [],
    // KIOSK-OFFLINE-PLANB-01 — dedicated offline-queued state
    'kiosk.pay_screen.offline_queued_title': [],
    'kiosk.pay_screen.offline_queued_sub': [],
};

describe('P3 breadth heal — new i18n keys parity across all 5 locales', () => {
    for (const [key, placeholders] of Object.entries(NEW_KEYS)) {
        describe(key, () => {
            for (const [code, lang] of Object.entries(LOCALES)) {
                it(`exists, is a non-empty string in ${code}`, () => {
                    const v = get(lang, key);
                    expect(typeof v, `${code}:${key} should be a string`).toBe('string');
                    expect(v.trim().length, `${code}:${key} should be non-empty`).toBeGreaterThan(0);
                });
            }

            if (placeholders.length) {
                for (const [code, lang] of Object.entries(LOCALES)) {
                    it(`preserves placeholders ${placeholders.join(',')} in ${code}`, () => {
                        const v = get(lang, key);
                        for (const ph of placeholders) {
                            const re = new RegExp(ph.replace('{', '\\{').replace('}', '\\}'));
                            expect(v, `${code}:${key} missing ${ph}`).toMatch(re);
                        }
                    });
                }
            }
        });
    }

    it('FR canonical values match the audit-mandated phrasing', () => {
        expect(fr.label.kds_served_ago_hours).toBe('il y a {hours} h');
        expect(fr.label.kds_served_ago_days).toBe('il y a {days} j');
        expect(fr.label.kds_card_cta_start).toBe('Démarrer');
        expect(fr.button.kds_print_ticket).toBe('Imprimer ticket');
    });

    it('translations are not stale-copied from FR (de/en differ where script differs)', () => {
        // Latin-script de/en should differ from fr on the CTA + print button.
        expect(en.label.kds_card_cta_start).not.toBe(fr.label.kds_card_cta_start);
        expect(de.button.kds_print_ticket).not.toBe(fr.button.kds_print_ticket);
    });
});
