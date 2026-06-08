/**
 * [KDS-UIUX-2026-06-08 P2] Active waiting-timer rollup regression.
 *
 * elapsedFormatted previously returned raw `mm:ss` with no cap, so an order that had
 * waited > 1 h rendered an unreadable, column-clipping value like "15592:35". It must now
 * roll up: < 60 min → MM:SS, < 24 h → HhMM (French), >= 24 h → Dj — and ALWAYS fit the
 * 5-char age column.
 */
import { describe, it, expect } from 'vitest';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';

const fmt = (elapsedSeconds) => KdsOrderCard.computed.elapsedFormatted.call({ elapsedSeconds });

describe('KdsOrderCard.elapsedFormatted — rollup past 59:59', () => {
    it('keeps MM:SS (with seconds) under 60 minutes', () => {
        expect(fmt(35)).toBe('00:35');
        expect(fmt(320)).toBe('05:20');
        expect(fmt(59 * 60 + 59)).toBe('59:59');
    });

    it('rolls to HhMM (French hour notation) between 1 h and 24 h', () => {
        expect(fmt(3600)).toBe('1h00');
        expect(fmt(3600 + 5 * 60 + 25)).toBe('1h05');
        expect(fmt(23 * 3600 + 59 * 60 + 30)).toBe('23h59');
    });

    it('rolls to Dj at/over 24 h (pathological/stale orders)', () => {
        expect(fmt(24 * 3600)).toBe('1j');
        // the real "15592 min" pathology the audit measured (~10.8 days)
        expect(fmt(15592 * 60)).toBe('10j');
    });

    it('NEVER exceeds the 5-char age column at any age', () => {
        for (const s of [0, 35, 3599, 3600, 86399, 86400, 15592 * 60, 999 * 86400]) {
            expect(fmt(s).length, `"${fmt(s)}" (${s}s) must be <= 5 chars`).toBeLessThanOrEqual(5);
        }
    });

    it('clamps negatives to 00:00', () => {
        expect(fmt(-10)).toBe('00:00');
    });
});
