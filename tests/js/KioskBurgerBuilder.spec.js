/**
 * V2-2 Phase A — POC drag-and-drop burger builder.
 *
 * Behavioural contract verified:
 *   - Source ingredient list rendered + a11y-labelled (li[tabindex])
 *   - Empty state visible when no ingredient dropped
 *   - Empty state hidden + total surfaced after a layer is added
 *   - Keyboard add-path: Enter & Space append a clone to the burger
 *     and emit `update:extras`
 *   - Layer remove() emits `update:extras` with the rest of the stack
 *   - Fallback button emits `switch-to-classic`
 *   - cloneIngredient() produces a unique instanceId for repeated adds
 *   - totalPrice computed sums layers (handles missing/non-numeric price)
 *
 * Notes
 *   - Drag-and-drop itself is delegated to Sortable.js inside
 *     vue-draggable-next; jsdom/happy-dom cannot exercise the native
 *     drag events. We therefore drive state via the public API
 *     (keyboard add + remove) which is the *same* mutation path the
 *     drag handlers ultimately call. This is the standard approach
 *     used elsewhere in the FoodKing kiosk test suite.
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import KioskBurgerBuilder from '../../resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

const SAMPLE_INGREDIENTS = [
    { id: 1, name: 'Steak haché', emoji: '🥩', price: 3.50 },
    { id: 2, name: 'Cheddar', emoji: '🧀', price: 1.00 },
    { id: 3, name: 'Bacon', emoji: '🥓', price: 1.50 },
];

function mountBuilder(props = {}) {
    return mount(KioskBurgerBuilder, {
        props: {
            availableIngredients: SAMPLE_INGREDIENTS,
            ...props,
        },
        global: {
            plugins: [i18n],
        },
    });
}

describe('KioskBurgerBuilder — initial render', () => {
    it('renders the source list with one entry per ingredient', () => {
        const w = mountBuilder();
        const items = w.findAll('[data-testid="ks-burger-ingredient"]');
        expect(items).toHaveLength(SAMPLE_INGREDIENTS.length);
    });

    it('shows the empty-state placeholder before any drop', () => {
        const w = mountBuilder();
        expect(w.find('[data-testid="ks-burger-empty"]').exists()).toBe(true);
        // Total panel only appears once at least one layer is present.
        expect(w.find('[data-testid="ks-burger-total"]').exists()).toBe(false);
    });

    it('source ingredients are focusable for keyboard a11y', () => {
        const w = mountBuilder();
        const first = w.find('[data-testid="ks-burger-ingredient"]');
        expect(first.attributes('tabindex')).toBe('0');
        // aria-label must include both name and price (i18n contract).
        expect(first.attributes('aria-label')).toContain('Steak haché');
        expect(first.attributes('aria-label')).toContain('3.50');
    });
});

describe('KioskBurgerBuilder — keyboard add path', () => {
    it('Enter on a focused ingredient appends a layer & emits update:extras', async () => {
        const w = mountBuilder();
        const first = w.find('[data-testid="ks-burger-ingredient"]');
        await first.trigger('keydown', { key: 'Enter' });

        const events = w.emitted('update:extras');
        expect(events).toBeTruthy();
        expect(events).toHaveLength(1);
        const payload = events[0][0];
        expect(payload).toHaveLength(1);
        expect(payload[0].name).toBe('Steak haché');
        expect(payload[0].instanceId).toBeTruthy();
    });

    it('Space on a focused ingredient also appends a layer', async () => {
        const w = mountBuilder();
        const second = w.findAll('[data-testid="ks-burger-ingredient"]')[1];
        await second.trigger('keydown', { key: ' ' });

        const events = w.emitted('update:extras');
        expect(events).toBeTruthy();
        const payload = events[0][0];
        expect(payload[0].name).toBe('Cheddar');
    });

    it('hides the empty-state and shows the total once at least one layer is dropped', async () => {
        const w = mountBuilder();
        const first = w.find('[data-testid="ks-burger-ingredient"]');
        await first.trigger('keydown', { key: 'Enter' });

        expect(w.find('[data-testid="ks-burger-empty"]').exists()).toBe(false);
        expect(w.find('[data-testid="ks-burger-total"]').exists()).toBe(true);
        // formatPrice => "3.50 €"
        expect(w.find('[data-testid="ks-burger-total"]').text()).toContain('3.50');
    });

    it('repeated adds of the same ingredient produce distinct instanceIds', async () => {
        const w = mountBuilder();
        const first = w.find('[data-testid="ks-burger-ingredient"]');
        await first.trigger('keydown', { key: 'Enter' });
        await first.trigger('keydown', { key: 'Enter' });

        const last = w.emitted('update:extras').at(-1)[0];
        expect(last).toHaveLength(2);
        expect(last[0].instanceId).not.toBe(last[1].instanceId);
    });
});

describe('KioskBurgerBuilder — remove layer', () => {
    it('clicking remove drops the matching layer and re-emits update:extras', async () => {
        const w = mountBuilder();
        const first = w.find('[data-testid="ks-burger-ingredient"]');
        await first.trigger('keydown', { key: 'Enter' });
        await first.trigger('keydown', { key: 'Enter' });

        const removeButtons = w.findAll('[data-testid="ks-burger-layer-remove"]');
        expect(removeButtons).toHaveLength(2);
        await removeButtons[0].trigger('click');

        const events = w.emitted('update:extras');
        const last = events.at(-1)[0];
        expect(last).toHaveLength(1);
        // After removing the first layer, the surviving stack still has
        // the empty-state hidden (1 layer remains) and the total visible.
        expect(w.find('[data-testid="ks-burger-empty"]').exists()).toBe(false);
    });
});

describe('KioskBurgerBuilder — fallback / switch-to-classic', () => {
    it('clicking the fallback button emits switch-to-classic', async () => {
        const w = mountBuilder();
        await w.find('[data-testid="ks-burger-fallback"]').trigger('click');
        expect(w.emitted('switch-to-classic')).toBeTruthy();
        expect(w.emitted('switch-to-classic')).toHaveLength(1);
    });
});

describe('KioskBurgerBuilder — totalPrice computed', () => {
    it('handles missing or non-numeric price entries gracefully', async () => {
        const w = mountBuilder({
            availableIngredients: [
                { id: 10, name: 'Free', emoji: '🥒', price: undefined },
                { id: 11, name: 'Bad', emoji: '🍒', price: 'oops' },
                { id: 12, name: 'Good', emoji: '🍅', price: 2 },
            ],
        });
        // Add all three via keyboard
        const items = w.findAll('[data-testid="ks-burger-ingredient"]');
        await items[0].trigger('keydown', { key: 'Enter' });
        await items[1].trigger('keydown', { key: 'Enter' });
        await items[2].trigger('keydown', { key: 'Enter' });

        const totalNode = w.find('[data-testid="ks-burger-total"]');
        // 0 + 0 + 2 = 2.00 €
        expect(totalNode.text()).toContain('2.00');
    });
});
