/**
 * [FINITION 2026-11-04] Étape générique de la borne : une étape FACULTATIVE (min_select = 0, ex. « Viande 2 »)
 * le dit au client ; une étape obligatoire ne le dit pas. Rien d'autre ne change (choix, émission).
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import Step from '../../resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue';
import fr from '../../resources/js/languages/fr.json';

const i18n = () => createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr } });
const monter = (min) => mount(Step, {
  props: {
    step: { id: 9, step_key: 'viande_2', label: 'Viande 2', min_select: min, max_select: 1, choices: [{ id: 1, name: 'Poulet mariné', status: 5 }] },
    selections: { composerChoices: {} },
  },
  global: { plugins: [i18n()] },
});

describe('borne — étape générique facultative', () => {
  it('min_select = 0 : pastille « Facultatif » et consigne', () => {
    const w = monter(0);
    const p = w.find('[data-testid="kiosk-generic-optional"]');
    expect(p.exists()).toBe(true);
    expect(p.text()).toContain('Facultatif');
    expect(p.text()).toContain('Suivant');
  });
  it('min_select = 1 : aucune mention facultative', () => {
    expect(monter(1).find('[data-testid="kiosk-generic-optional"]').exists()).toBe(false);
  });
});
