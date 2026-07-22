/**
 * [KDS-V2-BLIND-BANNERS 2026-07-22] KdsStatusBanner tier priority.
 *
 * The V2 layout (prod default) was blind to the two signals that only lived
 * in the legacy v-else template: the persistent applicative error banner
 * (kds-error-banner) and the "synchro incertaine" data-freshness stamp.
 * KdsStatusBanner now exposes them as two new tiers:
 *   errorMessage (red, top priority) > offline > syncUncertain (orange)
 *   > cap/fallback/sync/bump transport tiers (unchanged).
 *
 * $t stub mirrors kdsRemediationComponents.spec.js: returns the key itself
 * (vue-i18n behavior on missing keys) — which also proves the tOr() fallback
 * renders real FR text instead of a raw key.
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsStatusBanner from '../../resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue';

const tStub = (k, p) => (p && p.m !== undefined ? `${p.m}m${p.s}s` : k);
const factory = (props = {}) => mount(KdsStatusBanner, {
    props,
    global: { mocks: { $t: tStub } },
});

describe('KdsStatusBanner — errorMessage tier (applicative error, red, top priority)', () => {
    it('renders the error tier with the given message when errorMessage is set', () => {
        const w = factory({ errorMessage: 'Impossible de rafraîchir les commandes' });
        const banner = w.find('.kds-banner');
        expect(banner.exists()).toBe(true);
        expect(banner.classes()).toContain('kds-banner--error');
        expect(w.text()).toContain('Impossible de rafraîchir les commandes');
        expect(w.text()).toContain('ERREUR');
    });

    it('outranks every other signal (syncUncertain + fallback + cap + offline all active)', () => {
        const offlineSince = 1_000_000;
        const w = factory({
            errorMessage: 'Conflit de statut détecté',
            syncUncertain: true,
            fallbackMode: true,
            listAtCap: true,
            nearCap: 250,
            offlineSince,
            now: offlineSince + 90_000,
        });
        expect(w.text()).toContain('Conflit de statut détecté');
        expect(w.text()).toContain('ERREUR');
        expect(w.text()).not.toContain('OFFLINE');
        // applicative error shows the static alert glyph, not the reconnect spinner
        expect(w.find('.kds-banner__spinner').exists()).toBe(false);
    });

    it('an empty errorMessage falls through to lower tiers', () => {
        const w = factory({ errorMessage: '', fallbackMode: true });
        expect(w.text()).toContain('label.kds_fallback_banner');
        expect(w.text()).not.toContain('ERREUR');
    });
});

describe('KdsStatusBanner — syncUncertain tier (orange, above transport tiers)', () => {
    it('renders the orange "synchro incertaine" tier when syncUncertain is the only signal', () => {
        const w = factory({ syncUncertain: true });
        const banner = w.find('.kds-banner');
        expect(banner.exists()).toBe(true);
        expect(banner.classes()).toContain('kds-banner--warning');
        // tOr() fallback: $t returns the raw key → FR literal must render instead
        expect(w.text()).toContain('Synchro incertaine — données peut-être datées');
        expect(w.text()).not.toContain('label.kds_sync_uncertain_banner');
        expect(w.text()).toContain('SYNC · ?');
    });

    it('outranks the transport tiers (fallback + cap warnings)', () => {
        const w = factory({ syncUncertain: true, fallbackMode: true, nearCap: 250 });
        expect(w.text()).toContain('Synchro incertaine — données peut-être datées');
        expect(w.text()).not.toContain('label.kds_fallback_banner');
        expect(w.text()).not.toContain('label.kds_order_cap_warning');
    });

    it('does NOT mask the existing red offline counter (offline > syncUncertain)', () => {
        const offlineSince = 1_000_000;
        const w = factory({ syncUncertain: true, offlineSince, now: offlineSince + 65_000 });
        expect(w.text()).toContain('1m5s'); // label.kds_connection_lost_long via stub
        expect(w.text()).toContain('OFFLINE');
        expect(w.text()).not.toContain('Synchro incertaine');
        // offline tier keeps its reconnect spinner (regression guard on the icon switch)
        expect(w.find('.kds-banner__spinner').exists()).toBe(true);
    });
});

describe('KdsStatusBanner — transport tiers unchanged without the new props', () => {
    it('fallbackMode alone still renders the polling-fallback warning tier', () => {
        const w = factory({ fallbackMode: true });
        const banner = w.find('.kds-banner');
        expect(banner.classes()).toContain('kds-banner--warning');
        expect(w.text()).toContain('label.kds_fallback_banner');
        expect(w.text()).toContain('SYNC · LOCAL');
    });

    it('offline >60s alone still renders the red elapsed counter with spinner', () => {
        const offlineSince = 1_000_000;
        const w = factory({ offlineSince, now: offlineSince + 125_000 });
        expect(w.find('.kds-banner').classes()).toContain('kds-banner--error');
        expect(w.text()).toContain('2m5s');
        expect(w.find('.kds-banner__spinner').exists()).toBe(true);
    });

    it('renders nothing at all when no signal is active', () => {
        const w = factory({});
        expect(w.find('.kds-banner').exists()).toBe(false);
    });
});
