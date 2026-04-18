import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepGarnituresComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

describe('KioskStepGarnituresComponent', () => {
  it('deselect_all_clears_selection', async () => {
    const wrapper = mount(KioskStepGarnituresComponent, {
      global: { plugins: [i18n] },
      props: {
        step: { type: 'garnitures' },
        item: {
          extras: [
            { id: 1, name: 'Salade', price: 0, convert_price: 0 },
            { id: 2, name: 'Tomate', price: 0, convert_price: 0 },
          ],
        },
        selections: {
          garnitures: { 1: true, 2: true },
        },
      },
    });

    await wrapper.get('[data-testid="kiosk-step-garnitures-clear-all"]').trigger('click');

    expect(wrapper.emitted('update')).toBeTruthy();
    expect(wrapper.emitted('update').at(-1)).toEqual([
      'garnitures',
      { 1: false, 2: false },
    ]);
  });
});
