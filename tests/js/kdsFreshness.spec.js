import { describe, expect, it } from 'vitest';

import {
    SYNC_STALE_MS,
    SYNC_UNCERTAIN_MS,
    toEpochMs,
    freshnessBaseMs,
    freshnessAgeMs,
    isSyncStale,
    isSyncUncertain,
    humanizeSyncAgo,
} from '../../resources/js/helpers/kdsFreshness.js';

// [CLUSTER-4 P2 2026-07-11] The KDS "live" claim must reflect the age of the
// last SUCCESSFUL data refresh (Echo broadcast / poll / sync 200), NOT the
// soketi socket state — a dead queue worker keeps the socket "connected" while
// the board silently goes stale. These tests pin the pure freshness logic.

const NOW = 1_000_000_000_000; // fixed epoch-ms

describe('kdsFreshness — toEpochMs', () => {
    it('passes finite numbers through', () => {
        expect(toEpochMs(NOW)).toBe(NOW);
    });
    it('parses ISO strings', () => {
        const iso = new Date(NOW).toISOString();
        expect(toEpochMs(iso)).toBe(NOW);
    });
    it('collapses null / undefined / empty / invalid to 0', () => {
        expect(toEpochMs(null)).toBe(0);
        expect(toEpochMs(undefined)).toBe(0);
        expect(toEpochMs('')).toBe(0);
        expect(toEpochMs('not-a-date')).toBe(0);
        expect(toEpochMs(NaN)).toBe(0);
    });
});

describe('kdsFreshness — freshnessBaseMs picks the freshest clock', () => {
    it('returns 0 when neither path ever succeeded', () => {
        expect(freshnessBaseMs({ lastDataFreshAt: null, lastSyncAt: null })).toBe(0);
        expect(freshnessBaseMs({})).toBe(0);
        expect(freshnessBaseMs()).toBe(0);
    });
    it('uses the board-hydrate clock when it is newer than the sync poll', () => {
        expect(freshnessBaseMs({
            lastDataFreshAt: NOW,
            lastSyncAt: new Date(NOW - 40000).toISOString(),
        })).toBe(NOW);
    });
    it('uses the sync-poll clock when it is newer than the board hydrate', () => {
        const sync = new Date(NOW).toISOString();
        expect(freshnessBaseMs({
            lastDataFreshAt: NOW - 40000,
            lastSyncAt: sync,
        })).toBe(NOW);
    });
    it('survives when only one clock is present', () => {
        expect(freshnessBaseMs({ lastDataFreshAt: NOW })).toBe(NOW);
        expect(freshnessBaseMs({ lastSyncAt: new Date(NOW).toISOString() })).toBe(NOW);
    });
});

describe('kdsFreshness — age + humanize', () => {
    it('returns null age / label when never refreshed', () => {
        expect(freshnessAgeMs(0, NOW)).toBeNull();
        expect(humanizeSyncAgo(0, NOW)).toBeNull();
    });
    it('clamps negative drift to 0 (clock skew safety)', () => {
        expect(freshnessAgeMs(NOW + 5000, NOW)).toBe(0);
        expect(humanizeSyncAgo(NOW + 5000, NOW)).toBe('0s');
    });
    it('formats sub-minute ages in seconds', () => {
        expect(humanizeSyncAgo(NOW - 12000, NOW)).toBe('12s');
        expect(humanizeSyncAgo(NOW - 59000, NOW)).toBe('59s');
    });
    it('formats minute+ ages in minutes', () => {
        expect(humanizeSyncAgo(NOW - 60000, NOW)).toBe('1m');
        expect(humanizeSyncAgo(NOW - 130000, NOW)).toBe('2m');
    });
});

describe('kdsFreshness — stale tier (soft, badge turns orange)', () => {
    it('is stale when never refreshed', () => {
        expect(isSyncStale(0, NOW)).toBe(true);
    });
    it('is fresh just under the threshold', () => {
        expect(isSyncStale(NOW - (SYNC_STALE_MS - 1), NOW)).toBe(false);
    });
    it('is stale just over the threshold', () => {
        expect(isSyncStale(NOW - (SYNC_STALE_MS + 1), NOW)).toBe(true);
    });
});

describe('kdsFreshness — uncertain tier (hard stall, explicit warning)', () => {
    it('is NOT uncertain when never refreshed (shows "not yet synced" instead)', () => {
        expect(isSyncUncertain(0, NOW)).toBe(false);
    });
    it('is certain just under the threshold', () => {
        expect(isSyncUncertain(NOW - (SYNC_UNCERTAIN_MS - 1), NOW)).toBe(false);
    });
    it('is uncertain just over the threshold', () => {
        expect(isSyncUncertain(NOW - (SYNC_UNCERTAIN_MS + 1), NOW)).toBe(true);
    });
    it('uncertain implies stale (uncertain threshold > stale threshold)', () => {
        expect(SYNC_UNCERTAIN_MS).toBeGreaterThan(SYNC_STALE_MS);
        const base = NOW - (SYNC_UNCERTAIN_MS + 1000);
        expect(isSyncUncertain(base, NOW)).toBe(true);
        expect(isSyncStale(base, NOW)).toBe(true);
    });
    it('does not fire during a healthy WS-connected 60s poll cadence', () => {
        // Worst-case healthy gap between confirmed refreshes is the 60s drift
        // poll; uncertain must stay false there to avoid crying wolf.
        expect(isSyncUncertain(NOW - 60000, NOW)).toBe(false);
    });
});

describe('kdsFreshness — worker-death scenario', () => {
    it('flags uncertain once BOTH the broadcast and the poll clocks stall', () => {
        // Queue worker dead + all polls failing: last confirmed refresh 90s ago.
        const base = freshnessBaseMs({
            lastDataFreshAt: NOW - 90000,
            lastSyncAt: new Date(NOW - 92000).toISOString(),
        });
        expect(isSyncUncertain(base, NOW)).toBe(true);
        expect(humanizeSyncAgo(base, NOW)).toBe('1m');
    });
    it('stays live while the direct DB sync poll keeps refreshing', () => {
        // Worker dead but sync-service poll (direct DB read) landed 5s ago →
        // board is genuinely fresh, badge must NOT warn.
        const base = freshnessBaseMs({
            lastDataFreshAt: NOW - 90000, // Echo stalled
            lastSyncAt: new Date(NOW - 5000).toISOString(), // poll alive
        });
        expect(isSyncUncertain(base, NOW)).toBe(false);
        expect(isSyncStale(base, NOW)).toBe(false);
    });
});
