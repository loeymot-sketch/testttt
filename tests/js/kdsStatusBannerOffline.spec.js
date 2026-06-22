// [KDS-01] The V2 grid's escalated red "connexion perdue >60s" (OFFLINE) banner is gated on
// KdsStatusBanner's `offline-since` prop, which the orchestrator (KitchenDisplaySystemComponent)
// stamps in _onWsDisconnected and clears in _onWsConnected. Before KDS-01, v2OfflineSince was
// declared+bound but NEVER assigned, so this error branch was structurally dead (no unit coverage
// either). This spec proves the affordance now works: offline >60s → red OFFLINE banner; null → none.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsStatusBanner from '../../resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue';

const NOW = 1_700_000_000_000;
const mountBanner = (props) => mount(KdsStatusBanner, {
    props,
    global: { mocks: { $t: (k, params) => (params ? `${k}:${JSON.stringify(params)}` : k) } },
});

describe('KdsStatusBanner — OFFLINE error branch (KDS-01 affordance)', () => {
    it('renders the red error/OFFLINE banner when offlineSince is >60s ago', () => {
        const w = mountBanner({ offlineSince: NOW - 61_000, now: NOW });
        expect(w.find('.kds-banner--error').exists()).toBe(true);
        expect(w.text()).toContain('OFFLINE');
    });

    it('does NOT show the error banner when offlineSince is null (WS up — happy path preserved)', () => {
        const w = mountBanner({ offlineSince: null, now: NOW });
        expect(w.find('.kds-banner--error').exists()).toBe(false);
    });

    it('does NOT escalate before the 60s grace window', () => {
        const w = mountBanner({ offlineSince: NOW - 30_000, now: NOW });
        expect(w.find('.kds-banner--error').exists()).toBe(false);
    });
});
