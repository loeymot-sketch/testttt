import { describe, expect, it } from 'vitest';

import {
    ORDER_STATUS,
    KDS_STATE,
    KDS_STATE_THEME,
    KDS_STATE_I18N_KEYS,
    kdsStateFromStatus,
    statusFromKdsState,
} from '../../resources/js/helpers/kdsState.js';

// [kds/sprint-2 F-1] Verify the numeric OrderStatus ↔ KdsState mapping table.
// Mirrors app/Enums/OrderStatus.php — if these constants change server-side,
// these tests catch the drift.
describe('kdsState — status ↔ kdsState mapping', () => {
    it('exposes the numeric OrderStatus enum mirroring server values', () => {
        expect(ORDER_STATUS.PENDING).toBe(1);
        expect(ORDER_STATUS.ACCEPT).toBe(4);
        expect(ORDER_STATUS.PREPARING).toBe(7);
        expect(ORDER_STATUS.PREPARED).toBe(8);
        expect(ORDER_STATUS.OUT_FOR_DELIVERY).toBe(10);
        expect(ORDER_STATUS.DELIVERED).toBe(13);
        expect(ORDER_STATUS.CANCELED).toBe(16);
        expect(ORDER_STATUS.REJECTED).toBe(19);
        expect(ORDER_STATUS.RETURNED).toBe(22);
    });

    it('maps PENDING and ACCEPT to NEW (queue entry)', () => {
        expect(kdsStateFromStatus(ORDER_STATUS.PENDING)).toBe('NEW');
        expect(kdsStateFromStatus(ORDER_STATUS.ACCEPT)).toBe('NEW');
    });

    it('maps PREPARING to PREPARING', () => {
        expect(kdsStateFromStatus(ORDER_STATUS.PREPARING)).toBe('PREPARING');
    });

    it('maps PREPARED to READY (UI label "Prête")', () => {
        expect(kdsStateFromStatus(ORDER_STATUS.PREPARED)).toBe('READY');
    });

    it('maps OUT_FOR_DELIVERY and DELIVERED to DONE', () => {
        expect(kdsStateFromStatus(ORDER_STATUS.OUT_FOR_DELIVERY)).toBe('DONE');
        expect(kdsStateFromStatus(ORDER_STATUS.DELIVERED)).toBe('DONE');
    });

    it('maps CANCELED / REJECTED / RETURNED to CANCELLED', () => {
        expect(kdsStateFromStatus(ORDER_STATUS.CANCELED)).toBe('CANCELLED');
        expect(kdsStateFromStatus(ORDER_STATUS.REJECTED)).toBe('CANCELLED');
        expect(kdsStateFromStatus(ORDER_STATUS.RETURNED)).toBe('CANCELLED');
    });

    it('returns null for unknown values (defensive)', () => {
        expect(kdsStateFromStatus(99)).toBeNull();
        expect(kdsStateFromStatus(null)).toBeNull();
        expect(kdsStateFromStatus(undefined)).toBeNull();
        expect(kdsStateFromStatus('not-a-number')).toBeNull();
    });

    it('accepts numeric strings (server may emit string statuses)', () => {
        expect(kdsStateFromStatus('4')).toBe('NEW');
        expect(kdsStateFromStatus('7')).toBe('PREPARING');
    });
});

describe('kdsState — reverse mapping for PATCH', () => {
    it('NEW resolves to ACCEPT (idle entry state)', () => {
        expect(statusFromKdsState(KDS_STATE.NEW)).toBe(ORDER_STATUS.ACCEPT);
    });
    it('PREPARING ↔ PREPARING (start)', () => {
        expect(statusFromKdsState(KDS_STATE.PREPARING)).toBe(ORDER_STATUS.PREPARING);
    });
    it('READY ↔ PREPARED (chef bump)', () => {
        expect(statusFromKdsState(KDS_STATE.READY)).toBe(ORDER_STATUS.PREPARED);
    });
    it('DONE ↔ DELIVERED (terminal happy path)', () => {
        expect(statusFromKdsState(KDS_STATE.DONE)).toBe(ORDER_STATUS.DELIVERED);
    });
    it('CANCELLED ↔ CANCELED', () => {
        expect(statusFromKdsState(KDS_STATE.CANCELLED)).toBe(ORDER_STATUS.CANCELED);
    });
    it('unknown KdsState returns null', () => {
        expect(statusFromKdsState('SOMETHING_ELSE')).toBeNull();
        expect(statusFromKdsState(null)).toBeNull();
    });
});

describe('kdsState — theme + i18n tables stay in sync', () => {
    it('every KdsState has a theme entry', () => {
        for (const state of Object.values(KDS_STATE)) {
            expect(KDS_STATE_THEME).toHaveProperty(state);
            const t = KDS_STATE_THEME[state];
            // [kds/sprint-2 V-1] Each theme must define the four token shapes
            // the card pill renders. Catches accidental partial themes.
            expect(t).toHaveProperty('bg');
            expect(t).toHaveProperty('dot');
            expect(t).toHaveProperty('text');
            expect(t).toHaveProperty('border');
        }
    });
    it('every KdsState has an i18n key', () => {
        for (const state of Object.values(KDS_STATE)) {
            expect(KDS_STATE_I18N_KEYS).toHaveProperty(state);
            expect(KDS_STATE_I18N_KEYS[state]).toMatch(/^label\.kds_state_/);
        }
    });
});
