import { describe, it, expect, beforeEach } from 'vitest';
import {
    saveSnapshot,
    readSnapshot,
    clearSnapshot,
    buildSnapshotKey,
    VERSION,
    DEFAULT_TTL_MS,
} from '../../resources/js/helpers/kioskWizardResumeSnapshot';

describe('kioskWizardResumeSnapshot', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('builds a scoped key per item id and rejects invalid ids', () => {
        expect(buildSnapshotKey(42)).toBe('kiosk:wizard-snapshot:42');
        expect(buildSnapshotKey('7')).toBe('kiosk:wizard-snapshot:7');
        expect(buildSnapshotKey(0)).toBeNull();
        expect(buildSnapshotKey(null)).toBeNull();
        expect(buildSnapshotKey('abc')).toBeNull();
    });

    it('persists to localStorage under a versioned envelope', () => {
        const ok = saveSnapshot(42, { step_index: 2, sauceOrder: [1, 2] }, { now: () => 1000 });
        expect(ok).toBe(true);

        const raw = window.localStorage.getItem('kiosk:wizard-snapshot:42');
        const record = JSON.parse(raw);
        expect(record.version).toBe(VERSION);
        expect(record.item_id).toBe(42);
        expect(record.saved_at).toBe(1000);
        expect(record.ttl_ms).toBe(DEFAULT_TTL_MS);
        expect(record.payload).toEqual({ step_index: 2, sauceOrder: [1, 2] });
    });

    it('rehydrates the exact payload for the matching item id', () => {
        saveSnapshot(42, { step_index: 1, a: true }, { now: () => 1000 });
        const payload = readSnapshot(42, { now: () => 1500 });
        expect(payload).toEqual({ step_index: 1, a: true });
    });

    it('returns null when the snapshot is older than its TTL and auto-purges it', () => {
        saveSnapshot(42, { step_index: 3 }, { now: () => 1000, ttlMs: 500 });
        const payload = readSnapshot(42, { now: () => 2000 });
        expect(payload).toBeNull();
        expect(window.localStorage.getItem('kiosk:wizard-snapshot:42')).toBeNull();
    });

    it('does not cross item boundaries (snapshot of item 42 is not returned for item 43)', () => {
        saveSnapshot(42, { step_index: 1 }, { now: () => 1000 });
        expect(readSnapshot(43, { now: () => 1000 })).toBeNull();
    });

    it('discards envelopes with a mismatching version and auto-purges them', () => {
        window.localStorage.setItem(
            'kiosk:wizard-snapshot:42',
            JSON.stringify({ version: 99, item_id: 42, saved_at: 1, ttl_ms: 1, payload: {} })
        );
        expect(readSnapshot(42, { now: () => 1000 })).toBeNull();
        expect(window.localStorage.getItem('kiosk:wizard-snapshot:42')).toBeNull();
    });

    it('clearSnapshot removes only the targeted key', () => {
        saveSnapshot(42, { a: 1 });
        saveSnapshot(43, { b: 2 });
        clearSnapshot(42);
        expect(window.localStorage.getItem('kiosk:wizard-snapshot:42')).toBeNull();
        expect(window.localStorage.getItem('kiosk:wizard-snapshot:43')).not.toBeNull();
    });

    it('is resilient to JSON corruption and auto-purges broken entries', () => {
        window.localStorage.setItem('kiosk:wizard-snapshot:42', '{not json');
        expect(readSnapshot(42)).toBeNull();
        expect(window.localStorage.getItem('kiosk:wizard-snapshot:42')).toBeNull();
    });
});
