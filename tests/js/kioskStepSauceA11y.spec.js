import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [AUDIT 2026-04-17 C7] A11y regression guards for the sauce step.
 *
 * The sauce cards switched from unannounced <div @click> to ARIA checkboxes
 * with keyboard activation. This spec locks that contract so any future
 * refactor can’t silently regress the keyboard / screen-reader experience.
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const buildItem = () => ({
  id: 42,
  name: 'Tacos',
  itemAttributes: [{ id: 11, name: 'Sauce' }],
  variations: {
    11: [
      { id: 101, name: 'Algérienne', status: 5 },
      { id: 102, name: 'Blanche', status: 5 },
    ],
  },
  extras: [],
});

const mountStep = (selections = { sauces: {}, sauceOrder: [] }) =>
  mount(KioskStepSauceComponent, {
    global: { plugins: [i18n] },
    props: {
      step: { type: 'sauce' },
      item: buildItem(),
      selections,
    },
  });

describe('[C7] KioskStepSauce — accessibility contract', () => {
  it('exposes role=checkbox, tabindex=0 and aria-checked on every sauce card', () => {
    const wrapper = mountStep();
    const cards = wrapper.findAll('.kiosk-option-card');

    expect(cards.length).toBeGreaterThan(0);

    cards.forEach((card) => {
      expect(card.attributes('role')).toBe('checkbox');
      expect(card.attributes('tabindex')).toBe('0');
      expect(card.attributes('aria-checked')).toBeDefined();
      expect(card.attributes('aria-label')).toBeTruthy();
    });
  });

  it('toggles selection on Enter key (keyboard-only path)', async () => {
    const wrapper = mountStep();
    const firstCard = wrapper.find('.kiosk-option-card');

    await firstCard.trigger('keydown.enter');

    const emitted = wrapper.emitted('update');
    expect(emitted, 'update event must fire on Enter').toBeTruthy();
    const sauceOrderEvent = emitted.find((args) => args[0] === 'sauceOrder');
    expect(sauceOrderEvent, 'a sauceOrder update must be emitted').toBeTruthy();
    expect(sauceOrderEvent[1]).toHaveLength(1);
  });

  it('toggles selection on Space key (keyboard-only path)', async () => {
    const wrapper = mountStep();
    const firstCard = wrapper.find('.kiosk-option-card');

    await firstCard.trigger('keydown.space');

    const emitted = wrapper.emitted('update');
    expect(emitted).toBeTruthy();
    expect(emitted.some((args) => args[0] === 'sauceOrder')).toBe(true);
  });

  it('keeps aria-checked in sync with selected state', async () => {
    const firstSauceKey = '101'; // matches variation.id
    const wrapper = mountStep({
      sauces: { [firstSauceKey]: true },
      sauceOrder: [101],
    });
    const cards = wrapper.findAll('.kiosk-option-card');
    // At least one card should reflect the pre-selected state.
    const checked = cards.filter((c) => c.attributes('aria-checked') === 'true');
    expect(checked.length).toBe(1);
  });

  it('exposes a polite live region with the validation hint', () => {
    const wrapper = mountStep();
    const hint = wrapper.find('.kiosk-validation-hint');
    expect(hint.exists()).toBe(true);
    expect(hint.attributes('role')).toBe('status');
    expect(hint.attributes('aria-live')).toBe('polite');
  });
});
