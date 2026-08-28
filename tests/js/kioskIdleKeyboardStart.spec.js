import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import KioskIdleScreenComponent from '../../resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

let wrapper;

function mountIdle({ dineIn = false } = {}) {
    const dispatch = vi.fn((action) => {
        if (action === 'frontendSetting/lists') {
            return Promise.resolve({ data: { data: { pos_dine_in_enabled: dineIn ? 1 : 0 } } });
        }
        return Promise.resolve();
    });
    wrapper = mount(KioskIdleScreenComponent, {
        global: {
            plugins: [createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } })],
            mocks: { $store: { dispatch } },
            stubs: { KsA11ySettings: true, transition: false },
        },
    });
    return wrapper;
}

async function expectSingleStart(action, expectedType) {
    const before = wrapper.emitted('start-order')?.length || 0;
    await action();
    const events = wrapper.emitted('start-order') || [];
    expect(events).toHaveLength(before + 1);
    expect(events.at(-1)).toEqual([expectedType]);
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('Départ borne — parité clavier sans double déclenchement', () => {
    it('émet exactement un départ À emporter au clic, à Entrée et à Espace', async () => {
        mountIdle();
        await flushPromises();
        const button = wrapper.get('[data-testid="kiosk-order-type-takeaway"]');

        await expectSingleStart(() => button.trigger('click'), 10);
        await expectSingleStart(() => button.trigger('keydown', { key: 'Enter' }), 10);
        await expectSingleStart(() => button.trigger('keydown', { key: ' ' }), 10);
    });

    it('une barre d’espace MAINTENUE ne lance pas une commande par répétition clavier', async () => {
        // [REPLAN_8 2026-08-24] `.prevent` sur `keydown.space` déplace l'activation du keyup natif
        // vers le keydown. Un `<button>` natif ne répète PAS sur Espace maintenu ; un handler
        // keydown, si. Sur une borne, un doigt posé sur la barre d'espace lançait donc autant de
        // commandes que le clavier émet de répétitions. Le navigateur signale ces frappes
        // automatiques par `KeyboardEvent.repeat = true` : seule la PREMIÈRE compte.
        mountIdle();
        await flushPromises();
        const button = wrapper.get('[data-testid="kiosk-order-type-takeaway"]');

        await expectSingleStart(() => button.trigger('keydown', { key: ' ' }), 10);

        const apresPremiere = wrapper.emitted('start-order').length;
        for (let i = 0; i < 12; i += 1) {
            await button.trigger('keydown', { key: ' ', repeat: true });
        }
        for (let i = 0; i < 12; i += 1) {
            await button.trigger('keydown', { key: 'Enter', repeat: true });
        }
        expect(
            wrapper.emitted('start-order').length,
            'les répétitions automatiques du clavier ont lancé des commandes supplémentaires',
        ).toBe(apresPremiere);
    });

    it('émet exactement un départ Sur place avec les mêmes trois activations', async () => {
        mountIdle({ dineIn: true });
        await flushPromises();
        const button = wrapper.get('[data-testid="kiosk-order-type-dine-in"]');

        await expectSingleStart(() => button.trigger('click'), 25);
        await expectSingleStart(() => button.trigger('keydown', { key: 'Enter' }), 25);
        await expectSingleStart(() => button.trigger('keydown', { key: ' ' }), 25);
    });
});
