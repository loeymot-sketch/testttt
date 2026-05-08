/**
 * Kiosk Design V1 — Wave Alpha (M-1) : KioskSkeletonLoader greenfield
 *
 * Vérifie :
 *  - Rendu par défaut (3 cartes)
 *  - count prop personnalisé
 *  - Bascule type='grid'/'list'/'inline'
 *  - aria-busy=true + role=status (a11y AT)
 *  - aria-label via i18n key (fallback FR si $t indisponible)
 *  - Validators de props (type, count)
 *  - Aucun couplage métier (pas de store, pas de router)
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import KioskSkeletonLoader from '../../resources/js/components/frontend/kiosk/KioskSkeletonLoader.vue';

const baseMountOpts = {
    global: { mocks: { $t: (k) => k } },
};

describe('KioskSkeletonLoader', () => {
    it('renders 3 cards by default', () => {
        const wrapper = mount(KioskSkeletonLoader, baseMountOpts);
        expect(wrapper.findAll('[data-testid="kiosk-skeleton-card"]')).toHaveLength(3);
    });

    it('renders custom count', () => {
        const wrapper = mount(KioskSkeletonLoader, {
            ...baseMountOpts,
            props: { count: 5 },
        });
        expect(wrapper.findAll('[data-testid="kiosk-skeleton-card"]')).toHaveLength(5);
    });

    it('switches to grid type', () => {
        const wrapper = mount(KioskSkeletonLoader, {
            ...baseMountOpts,
            props: { type: 'grid', count: 4 },
        });
        expect(wrapper.find('.kiosk-skeleton-grid').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="kiosk-skeleton-grid-item"]')).toHaveLength(4);
        // No card markup when type=grid
        expect(wrapper.find('[data-testid="kiosk-skeleton-card"]').exists()).toBe(false);
    });

    it('switches to list type', () => {
        const wrapper = mount(KioskSkeletonLoader, {
            ...baseMountOpts,
            props: { type: 'list', count: 2 },
        });
        expect(wrapper.findAll('[data-testid="kiosk-skeleton-row"]')).toHaveLength(2);
    });

    it('switches to inline type', () => {
        const wrapper = mount(KioskSkeletonLoader, {
            ...baseMountOpts,
            props: { type: 'inline' },
        });
        expect(wrapper.find('[data-testid="kiosk-skeleton-inline"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-skeleton-card"]').exists()).toBe(false);
    });

    it('exposes aria-busy=true and role=status for AT', () => {
        const wrapper = mount(KioskSkeletonLoader, baseMountOpts);
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.attributes('role')).toBe('status');
    });

    it('uses translated aria-label when $t resolves the key', () => {
        const wrapper = mount(KioskSkeletonLoader, {
            global: { mocks: { $t: (k) => (k === 'kiosk.skeleton.aria_label' ? 'Loading content…' : k) } },
        });
        expect(wrapper.attributes('aria-label')).toBe('Loading content…');
    });

    it('falls back to FR string when $t is missing or returns the key as-is', () => {
        // $t returning the key means i18n missing key — component falls back to FR.
        const wrapper = mount(KioskSkeletonLoader, baseMountOpts);
        expect(wrapper.attributes('aria-label')).toBe('Chargement du contenu en cours…');
    });

    it('rejects invalid type prop via validator', () => {
        const validator = KioskSkeletonLoader.props.type.validator;
        expect(validator('card')).toBe(true);
        expect(validator('list')).toBe(true);
        expect(validator('grid')).toBe(true);
        expect(validator('inline')).toBe(true);
        expect(validator('invalid')).toBe(false);
        expect(validator('')).toBe(false);
    });

    it('rejects invalid count prop via validator', () => {
        const validator = KioskSkeletonLoader.props.count.validator;
        expect(validator(1)).toBe(true);
        expect(validator(10)).toBe(true);
        expect(validator(0)).toBe(false);
        expect(validator(-1)).toBe(false);
        expect(validator(NaN)).toBe(false);
    });
});
