/**
 * Kiosk Design V1 — Phase V1x-4 : KsButton atomic component DS
 *
 * Vérifie :
 *  - Variants render correct class (`primary` / `secondary` / `ghost` / `danger` / `dark`)
 *  - Sizes render correct class (`lg` / `md` / `sm`)
 *  - Disabled prop sets aria-disabled and disables click event
 *  - Loading prop sets aria-busy + shows spinner + ne re-emet pas click
 *  - FullWidth prop adds `ks-btn--full` class
 *  - Validators reject invalid props (Vue prop validator warning)
 *  - Slot content rendered inside `.ks-btn__label`
 *  - Icon prop / icon slot rendered inside `.ks-btn__icon`
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KsButton from '../../resources/js/components/frontend/kiosk/ds/KsButton.vue';

function mountBtn(props = {}, slots = { default: 'Click me' }) {
    return mount(KsButton, { props, slots });
}

describe('KsButton — variants', () => {
    it.each([
        ['primary'],
        ['secondary'],
        ['ghost'],
        ['danger'],
        ['dark'],
    ])('rend la classe ks-btn--%s', (variant) => {
        const w = mountBtn({ variant });
        expect(w.classes()).toContain(`ks-btn--${variant}`);
        expect(w.classes()).toContain('ks-btn');
    });

    it('défaut variant=primary si non fourni', () => {
        const w = mountBtn();
        expect(w.classes()).toContain('ks-btn--primary');
    });
});

describe('KsButton — sizes', () => {
    it.each([
        ['lg'],
        ['md'],
        ['sm'],
    ])('rend la classe ks-btn--%s', (size) => {
        const w = mountBtn({ size });
        expect(w.classes()).toContain(`ks-btn--${size}`);
    });

    it('défaut size=lg si non fourni', () => {
        const w = mountBtn();
        expect(w.classes()).toContain('ks-btn--lg');
    });
});

describe('KsButton — disabled state', () => {
    it('disabled=true pose attribute disabled + aria-disabled', () => {
        const w = mountBtn({ disabled: true });
        expect(w.attributes('disabled')).toBeDefined();
        expect(w.attributes('aria-disabled')).toBe('true');
    });

    it('disabled bloque l\'émission de @click', async () => {
        const w = mountBtn({ disabled: true });
        await w.trigger('click');
        expect(w.emitted('click')).toBeFalsy();
    });

    it('non-disabled émet @click avec event natif', async () => {
        const w = mountBtn();
        await w.trigger('click');
        expect(w.emitted('click')).toBeTruthy();
        expect(w.emitted('click').length).toBe(1);
    });
});

describe('KsButton — loading state', () => {
    it('loading=true pose aria-busy + classe ks-btn--loading', () => {
        const w = mountBtn({ loading: true });
        expect(w.attributes('aria-busy')).toBe('true');
        expect(w.classes()).toContain('ks-btn--loading');
    });

    it('loading=true affiche le spinner aria-hidden', () => {
        const w = mountBtn({ loading: true });
        const spinner = w.find('.ks-btn__spinner');
        expect(spinner.exists()).toBe(true);
        expect(spinner.attributes('aria-hidden')).toBe('true');
    });

    it('loading=true bloque @click (button disabled)', async () => {
        const w = mountBtn({ loading: true });
        // Le DOM disabled empêche déjà le click — on vérifie en plus la guard JS
        expect(w.attributes('disabled')).toBeDefined();
        await w.trigger('click');
        expect(w.emitted('click')).toBeFalsy();
    });

    it('loading=false n\'affiche pas de spinner', () => {
        const w = mountBtn({ loading: false });
        expect(w.find('.ks-btn__spinner').exists()).toBe(false);
    });
});

describe('KsButton — fullWidth', () => {
    it('fullWidth=true ajoute la classe ks-btn--full', () => {
        const w = mountBtn({ fullWidth: true });
        expect(w.classes()).toContain('ks-btn--full');
    });

    it('fullWidth=false n\'ajoute pas la classe', () => {
        const w = mountBtn({ fullWidth: false });
        expect(w.classes()).not.toContain('ks-btn--full');
    });
});

describe('KsButton — slot content & icon', () => {
    it('rend le slot default dans .ks-btn__label', () => {
        const w = mountBtn({}, { default: 'Valider' });
        expect(w.find('.ks-btn__label').text()).toBe('Valider');
    });

    it('rend prop icon (string) dans .ks-btn__icon', () => {
        const w = mountBtn({ icon: '🛒' }, { default: 'Panier' });
        const icon = w.find('.ks-btn__icon');
        expect(icon.exists()).toBe(true);
        expect(icon.text()).toContain('🛒');
    });

    it('rend slot icon prioritaire sur prop icon', () => {
        const w = mount(KsButton, {
            props: { icon: 'fallback' },
            slots: { default: 'CTA', icon: '<span class="custom-icon">★</span>' },
        });
        expect(w.find('.custom-icon').exists()).toBe(true);
    });

    it('icon non rendu si loading=true (le spinner prend la place)', () => {
        const w = mountBtn({ icon: '🛒', loading: true }, { default: 'Loading' });
        // Le spinner est rendu, l'icône non (v-if/v-else-if)
        expect(w.find('.ks-btn__spinner').exists()).toBe(true);
        expect(w.find('.ks-btn__icon').exists()).toBe(false);
    });
});

describe('KsButton — type attribute', () => {
    it.each([
        ['button'],
        ['submit'],
        ['reset'],
    ])('accepte type=%s', (type) => {
        const w = mountBtn({ type });
        expect(w.attributes('type')).toBe(type);
    });

    it('défaut type=button (évite submit accidentel dans un <form>)', () => {
        const w = mountBtn();
        expect(w.attributes('type')).toBe('button');
    });
});

describe('KsButton — prop validators', () => {
    /**
     * Vue valide les props via les fonctions `validator`. Une valeur invalide
     * loggue un warning mais ne bloque pas le mount. On vérifie que les
     * fonctions validator rejettent bien les valeurs hors enum.
     */
    it('variant validator rejette une valeur inconnue', () => {
        const validator = KsButton.props.variant.validator;
        expect(validator('primary')).toBe(true);
        expect(validator('secondary')).toBe(true);
        expect(validator('ghost')).toBe(true);
        expect(validator('danger')).toBe(true);
        expect(validator('dark')).toBe(true);
        expect(validator('unknown')).toBe(false);
        expect(validator('')).toBe(false);
    });

    it('size validator rejette une valeur inconnue', () => {
        const validator = KsButton.props.size.validator;
        expect(validator('lg')).toBe(true);
        expect(validator('md')).toBe(true);
        expect(validator('sm')).toBe(true);
        expect(validator('xl')).toBe(false);
        expect(validator('xs')).toBe(false);
    });

    it('type validator rejette une valeur inconnue', () => {
        const validator = KsButton.props.type.validator;
        expect(validator('button')).toBe(true);
        expect(validator('submit')).toBe(true);
        expect(validator('reset')).toBe(true);
        expect(validator('image')).toBe(false);
    });
});
