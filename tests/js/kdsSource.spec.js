import { describe, expect, it } from 'vitest';

import {
    KDS_SOURCE,
    KDS_SOURCE_THEME,
    KDS_SOURCE_I18N_KEYS,
    kdsSourceFromSurface,
} from '../../resources/js/helpers/kdsSource.js';

// [kds/sprint-2 F-2] Verify the source_surface (server) ↔ KdsSource (UI)
// mapping. Adding a new source = update map + theme + i18n in lockstep.
describe('kdsSource — surface → source mapping', () => {
    it('"pos" → POS', () => {
        expect(kdsSourceFromSurface('pos')).toBe('POS');
    });
    it('"admin" collapses to POS visually', () => {
        expect(kdsSourceFromSurface('admin')).toBe('POS');
    });
    it('"kiosk" → KIOSK', () => {
        expect(kdsSourceFromSurface('kiosk')).toBe('KIOSK');
    });
    it('"delivery" → DELIVERY (V1 chip ready)', () => {
        expect(kdsSourceFromSurface('delivery')).toBe('DELIVERY');
    });
    it('"web" and "online" both → ONLINE', () => {
        expect(kdsSourceFromSurface('web')).toBe('ONLINE');
        expect(kdsSourceFromSurface('online')).toBe('ONLINE');
    });
    it('"mobile" and "app" both → APP', () => {
        expect(kdsSourceFromSurface('mobile')).toBe('APP');
        expect(kdsSourceFromSurface('app')).toBe('APP');
    });
    it('"dinein" and "dine_in" both → DINE_IN', () => {
        expect(kdsSourceFromSurface('dinein')).toBe('DINE_IN');
        expect(kdsSourceFromSurface('dine_in')).toBe('DINE_IN');
    });
    it('is case-insensitive (servers may send mixed case)', () => {
        expect(kdsSourceFromSurface('KIOSK')).toBe('KIOSK');
        expect(kdsSourceFromSurface('Delivery')).toBe('DELIVERY');
    });
    it('falls back to POS for null/empty (defensive)', () => {
        expect(kdsSourceFromSurface(null)).toBe('POS');
        expect(kdsSourceFromSurface('')).toBe('POS');
        expect(kdsSourceFromSurface(undefined)).toBe('POS');
    });
    it('unknown surface falls back to POS (no crash on novel value)', () => {
        expect(kdsSourceFromSurface('quantum-tunneling')).toBe('POS');
    });
});

describe('kdsSource — theme + i18n in sync', () => {
    it('every KdsSource has a theme entry with bg/text/icon', () => {
        for (const src of Object.values(KDS_SOURCE)) {
            expect(KDS_SOURCE_THEME).toHaveProperty(src);
            const t = KDS_SOURCE_THEME[src];
            expect(t).toHaveProperty('bg');
            expect(t).toHaveProperty('text');
            expect(t).toHaveProperty('icon');
        }
    });
    it('every KdsSource has an i18n key', () => {
        for (const src of Object.values(KDS_SOURCE)) {
            expect(KDS_SOURCE_I18N_KEYS).toHaveProperty(src);
            expect(KDS_SOURCE_I18N_KEYS[src]).toMatch(/^label\.kds_(type|source)_/);
        }
    });
    it('CAISSE (POS) and BORNE (KIOSK) themes are visually distinguishable', () => {
        // [Audit cross-validation] earlier user feedback flagged that the
        // chips were too visually similar. Guard rail: different bg values.
        expect(KDS_SOURCE_THEME.POS.bg).not.toBe(KDS_SOURCE_THEME.KIOSK.bg);
        expect(KDS_SOURCE_THEME.POS.text).not.toBe(KDS_SOURCE_THEME.KIOSK.text);
    });
});

// [UBER-EATS 2026-06-28] Aggregator channel: an Uber order must show a distinct,
// prominent UBER chip on the KDS, distinct from every native channel.
describe('kdsSource — UBER EATS aggregator', () => {
    it('"uber" and "uber_eats" both → UBER', () => {
        expect(kdsSourceFromSurface('uber')).toBe('UBER');
        expect(kdsSourceFromSurface('uber_eats')).toBe('UBER');
        expect(kdsSourceFromSurface('UBER_EATS')).toBe('UBER'); // case-insensitive
    });
    it('UBER theme is the Uber green and prominent (distinct from all natives)', () => {
        expect(KDS_SOURCE.UBER).toBe('UBER');
        expect(KDS_SOURCE_THEME.UBER.bg).toBe('#06C167'); // Uber Eats green
        const others = ['POS', 'KIOSK', 'DELIVERY', 'ONLINE', 'APP', 'DINE_IN'];
        for (const o of others) {
            expect(KDS_SOURCE_THEME.UBER.bg).not.toBe(KDS_SOURCE_THEME[o].bg);
        }
    });
    it('UBER has an i18n key', () => {
        expect(KDS_SOURCE_I18N_KEYS.UBER).toBe('label.kds_source_uber');
    });
});
