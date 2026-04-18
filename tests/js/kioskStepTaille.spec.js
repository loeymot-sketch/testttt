import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepTailleComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

describe('KioskStepTailleComponent', () => {
  it('no_fallback_when_db_empty_skips_step', () => {
    const wrapper = mount(KioskStepTailleComponent, {
      global: { plugins: [i18n] },
      props: {
        step: { type: 'taille' },
        item: {
          itemAttributes: [{ id: 5, name: 'Taille' }],
          variations: { 5: [{ id: 10, name: 'M', status: 5 }] },
        },
        selections: { taille: null },
      },
    });

    expect(wrapper.vm.tailleOptions).toEqual([]);
    expect(wrapper.findAll('.kiosk-taille-card')).toHaveLength(0);
    expect(wrapper.text()).toContain(frMessages.kiosk.wizard.step.taille.empty_hint);
  });
});
