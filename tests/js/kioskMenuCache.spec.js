import { describe, it, expect, beforeEach, vi } from 'vitest';

// Mock idb-keyval before importing the module under test
vi.mock('idb-keyval', () => {
    const store = {};
    return {
        get: vi.fn(async (key) => store[key]),
        set: vi.fn(async (key, val) => { store[key] = val; }),
        del: vi.fn(async (key) => { delete store[key]; }),
        _store: store,
    };
});

import { saveSnapshot, loadSnapshot, isSnapshotFresh, clearSnapshot } from '../../resources/js/helpers/kioskMenuCache';
import * as idb from 'idb-keyval';

const CATS  = [{ id: 1, name: 'Tacos' }];
const ITEMS = [{ id: 10, name: 'Tacos L', price: 8 }];

describe('kioskMenuCache — saveSnapshot / loadSnapshot', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // Reset mock store
        Object.keys(idb._store).forEach(k => delete idb._store[k]);
        localStorage.clear();
    });

    it('saveSnapshot stores categories and items with savedAt timestamp', async () => {
        const before = Date.now();
        await saveSnapshot(CATS, ITEMS);
        const stored = idb._store['kiosk_menu_snapshot_v1'];
        expect(stored).toBeDefined();
        expect(stored.categories).toEqual(CATS);
        expect(stored.items).toEqual(ITEMS);
        expect(stored.savedAt).toBeGreaterThanOrEqual(before);
    });

    it('loadSnapshot returns the saved snapshot', async () => {
        await saveSnapshot(CATS, ITEMS);
        const snap = await loadSnapshot();
        expect(snap).not.toBeNull();
        expect(snap.categories).toEqual(CATS);
        expect(snap.items).toEqual(ITEMS);
    });

    it('loadSnapshot returns null when nothing saved', async () => {
        const snap = await loadSnapshot();
        expect(snap).toBeNull();
    });

    it('loadSnapshot falls back to localStorage when idb throws', async () => {
        idb.get.mockRejectedValueOnce(new Error('idb unavailable'));
        const snapshot = { categories: CATS, items: ITEMS, savedAt: Date.now() };
        localStorage.setItem('kiosk_menu_snapshot_v1', JSON.stringify(snapshot));
        const snap = await loadSnapshot();
        expect(snap).not.toBeNull();
        expect(snap.categories).toEqual(CATS);
    });

    it('clearSnapshot removes the snapshot', async () => {
        await saveSnapshot(CATS, ITEMS);
        await clearSnapshot();
        const snap = await loadSnapshot();
        expect(snap).toBeNull();
    });
});

describe('kioskMenuCache — isSnapshotFresh', () => {
    it('returns true for a recent timestamp', () => {
        expect(isSnapshotFresh(Date.now() - 1000)).toBe(true);
    });

    it('returns false for a timestamp older than maxAgeMs', () => {
        const old = Date.now() - 25 * 60 * 60 * 1000; // 25 hours ago
        expect(isSnapshotFresh(old)).toBe(false);
    });

    it('returns false for null/undefined savedAt', () => {
        expect(isSnapshotFresh(null)).toBe(false);
        expect(isSnapshotFresh(undefined)).toBe(false);
    });

    it('respects custom maxAgeMs', () => {
        const fiveMinAgo = Date.now() - 5 * 60 * 1000;
        expect(isSnapshotFresh(fiveMinAgo, 10 * 60 * 1000)).toBe(true);  // 10min window
        expect(isSnapshotFresh(fiveMinAgo, 4 * 60 * 1000)).toBe(false);  // 4min window
    });
});
