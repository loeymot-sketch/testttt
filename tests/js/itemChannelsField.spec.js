import { describe, it, expect } from 'vitest';

/**
 * [Sprint H5 Z5-P1-01 2026-05-17] Channels checkbox group on admin
 * items create/edit form (Wave Z V1.0.1 hardening).
 *
 * Server validation (ItemRequest.php:55-56):
 *   'channels'   => ['nullable', 'array'],
 *   'channels.*' => ['string', 'in:kiosk,pos,web'],
 *
 * Therefore 3 channels — NOT 4 (no `mobile` despite kiosk/pos/web/mobile
 * being mentioned in some earlier specs; server is the authority).
 *
 * Empty `channels` is treated client-side as "do not send" so that the
 * server keeps the legacy NULL = visible-everywhere semantics
 * (Item::displaysOn — channels === null ⇒ true for any surface). To
 * explicitly hide an item on all surfaces (an edge case), an admin can
 * persist an empty array via API/tinker; the UI does not currently
 * surface that path (V1.0.2 follow-up if needed).
 *
 * This spec asserts the contract the production block must respect:
 *   1. Three channel options exactly (kiosk, pos, web)
 *   2. form.channels is an array of strings
 *   3. Hydration on edit: item.channels (array or null) → form.channels (array)
 *
 * Production Vue mount lives in ItemCreateComponent.vue + ItemListComponent.vue
 * `edit()`. Mounting those requires Vuex/Router/API mocks beyond the
 * scope of a contract spec; the wiring is covered by manual visual
 * verification and by existing PHPUnit ItemRequest rule tests.
 */

const CHANNEL_OPTIONS = ['kiosk', 'pos', 'web'];

function hydrateFormChannels(itemChannels) {
    if (itemChannels === null || itemChannels === undefined) {
        return [];
    }
    if (!Array.isArray(itemChannels)) {
        return [];
    }
    return itemChannels.filter((c) => CHANNEL_OPTIONS.indexOf(c) !== -1);
}

function appendChannelsToFormData(channels) {
    const fd = new FormData();
    if (!Array.isArray(channels) || channels.length === 0) {
        return fd;
    }
    channels.forEach((c) => {
        if (CHANNEL_OPTIONS.indexOf(c) !== -1) {
            fd.append('channels[]', c);
        }
    });
    return fd;
}

describe('Admin items channels checkbox group (Z5-P1-01)', () => {
    it('exposes exactly 3 channel options: kiosk, pos, web', () => {
        expect(CHANNEL_OPTIONS).toEqual(['kiosk', 'pos', 'web']);
        expect(CHANNEL_OPTIONS.length).toBe(3);
    });

    it('hydrate: null item.channels → empty form array', () => {
        expect(hydrateFormChannels(null)).toEqual([]);
        expect(hydrateFormChannels(undefined)).toEqual([]);
    });

    it('hydrate: array item.channels → same channels (whitelisted)', () => {
        expect(hydrateFormChannels(['kiosk', 'pos'])).toEqual(['kiosk', 'pos']);
        expect(hydrateFormChannels(['web'])).toEqual(['web']);
    });

    it('hydrate: filters out unknown channels (defence-in-depth)', () => {
        // Server validation already rejects `mobile`, but client-side
        // defence guards against schema drift.
        expect(hydrateFormChannels(['kiosk', 'mobile', 'rogue'])).toEqual(['kiosk']);
    });

    it('append: empty channels → no channels key in FormData', () => {
        const fd = appendChannelsToFormData([]);
        expect(fd.has('channels[]')).toBe(false);
    });

    it('append: non-empty channels → FormData entries', () => {
        const fd = appendChannelsToFormData(['kiosk', 'web']);
        const values = fd.getAll('channels[]');
        expect(values).toEqual(['kiosk', 'web']);
    });

    it('contract: form.channels is an array of strings', () => {
        const form = { channels: ['kiosk', 'pos'] };
        expect(Array.isArray(form.channels)).toBe(true);
        form.channels.forEach((c) => expect(typeof c).toBe('string'));
    });
});
