/**
 * [P3 breadth-audit heal 2026-06-07]
 *
 * KDS-UI-02 — KdsV2Grid.servedAgoLabel must roll the relative "served ago"
 *   label up past minutes so a multi-day-old served order no longer renders
 *   "il y a 5907 min". <60min → minutes, <24h → hours, ≥24h → days.
 *
 * KDS-UI-04 — KdsOrderCard CTA label must be state-aware: a NOUVEAU (NEW) card's
 *   first tap only advances to EN PRÉPARATION, so the label/aria must read
 *   "Démarrer" (kds_card_cta_start), not "Prêt" (kds_card_cta_ready). Once the
 *   order is EN PRÉPARATION the tap bumps to PRÊT → "Prêt".
 *
 * These are pure logic units: we invoke the component-option functions with a
 * controlled `this` context (and a `$t` that echoes the key + params) so we
 * assert WHICH i18n key is selected, without a full Vue mount. This is
 * intentionally decoupled from rendering — the key-selection is the contract.
 */
import { describe, it, expect } from 'vitest';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';
import KdsV2Grid from '../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';

// $t stub that echoes { key, params } so a test can assert both the chosen key
// and the interpolation payload, matching how vue-i18n would resolve it.
const echoT = (key, params) => ({ key, params });

describe('KDS-UI-04 — KdsOrderCard ctaLabelKey is state-aware', () => {
    const ctaLabelKey = KdsOrderCard.computed.ctaLabelKey;

    it('NEW (CONFIRMÉE/PENDING) → "Démarrer" key (first tap promotes to EN PRÉPARATION)', () => {
        expect(ctaLabelKey.call({ kdsState: 'NEW' })).toBe('label.kds_card_cta_start');
    });

    it('PREPARING → "Prêt" key (tap bumps to PRÊT)', () => {
        expect(ctaLabelKey.call({ kdsState: 'PREPARING' })).toBe('label.kds_card_cta_ready');
    });

    it('any non-NEW live state falls back to the "Prêt" key', () => {
        for (const state of ['PREPARING', 'READY', 'DONE', 'CANCELLED', undefined]) {
            expect(ctaLabelKey.call({ kdsState: state })).toBe('label.kds_card_cta_ready');
        }
    });

    it('label and action stay aligned: NEW maps to start, which onCtaTap promotes from ACCEPT', () => {
        // KdsV2Grid.onCtaTap: currentStatus===ACCEPT(4) ? PREPARING : PREPARED.
        // kdsStateFromStatus(4)==='NEW' (see kdsState.js), so a NEW card's CTA
        // ("Démarrer") performs the ACCEPT→PREPARING transition. No mismatch.
        expect(ctaLabelKey.call({ kdsState: 'NEW' })).toBe('label.kds_card_cta_start');
    });
});

describe('KDS-UI-02 — KdsV2Grid.servedAgoLabel rolls minutes up to hours/days', () => {
    const servedAgoLabel = KdsV2Grid.methods.servedAgoLabel;

    // Helper: build a ctx whose `now` is `agoMs` after the order's updated_at.
    const labelFor = (agoMs) => {
        const updated_at = '2026-06-01T12:00:00.000Z';
        const now = Date.parse(updated_at) + agoMs;
        return servedAgoLabel.call({ now, $t: echoT }, { updated_at });
    };

    const MIN = 60_000;
    const HOUR = 60 * MIN;
    const DAY = 24 * HOUR;

    it('returns "" when there is no parseable timestamp', () => {
        expect(servedAgoLabel.call({ now: Date.now(), $t: echoT }, {})).toBe('');
        expect(servedAgoLabel.call({ now: Date.now(), $t: echoT }, { updated_at: 'not-a-date' })).toBe('');
    });

    it('< 60s → just-now key (existing path preserved)', () => {
        expect(labelFor(0).key).toBe('label.kds_served_just_now');
        expect(labelFor(59_000).key).toBe('label.kds_served_just_now');
    });

    it('1..59 min → minutes key with {mins} (existing path preserved)', () => {
        const at1 = labelFor(1 * MIN);
        expect(at1.key).toBe('label.kds_served_ago');
        expect(at1.params).toEqual({ mins: 1 });

        const at59 = labelFor(59 * MIN);
        expect(at59.key).toBe('label.kds_served_ago');
        expect(at59.params).toEqual({ mins: 59 });
    });

    it('boundary 60 min → hours key with {hours} (rolls up, no more "il y a 60 min")', () => {
        const at60 = labelFor(60 * MIN);
        expect(at60.key).toBe('label.kds_served_ago_hours');
        expect(at60.params).toEqual({ hours: 1 });
    });

    it('1..23 h → hours key (floored)', () => {
        const at90 = labelFor(90 * MIN); // 1h30 → floor 1
        expect(at90.key).toBe('label.kds_served_ago_hours');
        expect(at90.params).toEqual({ hours: 1 });

        const at23h59 = labelFor(24 * HOUR - 1); // just under a day
        expect(at23h59.key).toBe('label.kds_served_ago_hours');
        expect(at23h59.params).toEqual({ hours: 23 });
    });

    it('boundary 24 h → days key with {days}', () => {
        const at24h = labelFor(24 * HOUR);
        expect(at24h.key).toBe('label.kds_served_ago_days');
        expect(at24h.params).toEqual({ days: 1 });
    });

    it('multi-day → days key (the reported "il y a 5907 min" regression)', () => {
        // 5907 min ≈ 4.1 days → must read "il y a 4 j", not "il y a 5907 min".
        const at5907min = labelFor(5907 * MIN);
        expect(at5907min.key).toBe('label.kds_served_ago_days');
        expect(at5907min.params).toEqual({ days: 4 });
    });
});
