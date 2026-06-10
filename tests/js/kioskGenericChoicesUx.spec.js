import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import KioskStepGenericChoicesComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue';

// [GOAL_WIZARD_BEST_CAISSE_BORNE_2026-06-10 §1 B1-B9] Mount-level UX coverage
// for the borne generic-choices step: price join (display-only, NF525 SSOT
// stays backend), X/N counter, −/+ stepper without wipe, Épuisé badge,
// media grid, single-select checkmark, guidance line.

function makeStep(overrides = {}, choices = null) {
    return {
        type: 'generic_choices',
        label: 'Accompagnement',
        composer_step: {
            id: 77,
            step_key: 'accompagnement',
            label: 'Accompagnement',
            source_type: 'item_attribute',
            min_select: 1,
            max_select: 1,
            allow_repeat: false,
            choices: choices || [
                { id: 11, name: 'Riz', source_type: 'variation', item_attribute_id: 4, status: 5 },
                { id: 12, name: 'Frites', source_type: 'variation', item_attribute_id: 4, status: 5 },
            ],
            ...overrides,
        },
    };
}

// Catalog item shape mirrors the kiosk payload joined by the frozen wizard
// (variations keyed by attribute id, extras array, addons array).
const catalogItem = {
    id: 900,
    variations: {
        4: [
            { id: 11, name: 'Riz', item_attribute_id: 4, convert_price: 1, price: 1 },
            { id: 12, name: 'Frites', item_attribute_id: 4, convert_price: 0, price: 0 },
        ],
    },
    extras: [
        { id: 21, name: 'Cheddar', convert_price: 1.5, price: 1.5 },
    ],
    addons: [
        { id: 31, addon_item_id: 444, name: 'Coca', addon_item_currency_price: 2, price: 2 },
    ],
};

function mountStep(step, { selections = { composerChoices: {} }, item = catalogItem } = {}) {
    return mount(KioskStepGenericChoicesComponent, {
        props: { step, selections, item },
        global: { mocks: { $t: (key) => key } },
    });
}

async function applyEmittedSelections(wrapper, emitIndex) {
    const payload = wrapper.emitted('update')[emitIndex][1];
    await wrapper.setProps({ selections: { composerChoices: payload } });
}

describe('kiosk generic choices UX uplift (B1-B9)', () => {
    it('[B1] joins variation prices from the catalog item: +X for paid, Inclus for free', () => {
        const wrapper = mountStep(makeStep());

        const prices = wrapper.findAll('[data-testid="kiosk-generic-choice-price"]');
        expect(prices).toHaveLength(2);
        expect(prices[0].text()).toContain('+1,00');
        expect(prices[1].text()).toBe('Inclus');
        expect(prices[1].classes()).toContain('is-included');
    });

    it('[B1] joins extra and addon prices (addon via addon_item_currency_price)', () => {
        const extraStep = makeStep(
            { id: 88, source_type: 'extra_group', min_select: 0, max_select: 2 },
            [{ id: 21, name: 'Cheddar', source_type: 'extra', status: 5 }],
        );
        const extraWrapper = mountStep(extraStep);
        expect(extraWrapper.find('[data-testid="kiosk-generic-choice-price"]').text()).toContain('+1,50');

        const addonStep = makeStep(
            { id: 99, source_type: 'addon', min_select: 0, max_select: 1 },
            [{ id: 31, name: 'Coca', source_type: 'addon', addon_item_id: 444 }],
        );
        const addonWrapper = mountStep(addonStep);
        expect(addonWrapper.find('[data-testid="kiosk-generic-choice-price"]').text()).toContain('+2,00');
    });

    it('[B1] renders Inclus (never NaN/undefined) when no catalog item is bound', () => {
        const wrapper = mountStep(makeStep(), { item: null });
        const prices = wrapper.findAll('[data-testid="kiosk-generic-choice-price"]');
        expect(prices[0].text()).toBe('Inclus');
    });

    it('[B2] shows the selected/max counter on multi-select steps and updates it live', async () => {
        const step = makeStep({ min_select: 0, max_select: 3, allow_repeat: false });
        const wrapper = mountStep(step);

        const counter = wrapper.find('[data-testid="kiosk-generic-counter"]');
        expect(counter.exists()).toBe(true);
        expect(counter.text()).toBe('0 / 3');
        expect(wrapper.find('.kiosk-generic-progress').attributes('aria-live')).toBe('polite');
        expect(wrapper.find('.kiosk-generic-progress').attributes('role')).toBe('status');

        await wrapper.findAll('button.kiosk-generic-choice')[0].trigger('click');
        await applyEmittedSelections(wrapper, 0);
        expect(wrapper.find('[data-testid="kiosk-generic-counter"]').text()).toBe('1 / 3');
    });

    it('[B2] hides the counter on single-select steps', () => {
        const wrapper = mountStep(makeStep());
        expect(wrapper.find('[data-testid="kiosk-generic-counter"]').exists()).toBe(false);
    });

    it('[B5] the heading is min/max guidance, not a duplicate of the frozen banner label', () => {
        const multi = mountStep(makeStep({ min_select: 0, max_select: 3 }));
        expect(multi.find('[data-testid="kiosk-generic-guidance"]').text()).toBe('Choisissez jusqu\'à 3 options');

        const single = mountStep(makeStep());
        expect(single.find('[data-testid="kiosk-generic-guidance"]').text()).toBe('Choisissez 1 option');

        expect(multi.find('h3').text()).not.toBe('Accompagnement');
        // step label still exposed to AT on the group
        expect(multi.find('.kiosk-generic-grid').attributes('aria-label')).toBe('Accompagnement');
    });

    it('[B3] shows a −/+ stepper on repeatable choices; + increments, − decrements by 1 (no wipe)', async () => {
        const step = makeStep(
            { id: 88, source_type: 'extra_group', min_select: 0, max_select: 3, allow_repeat: true },
            [{ id: 21, name: 'Cheddar', source_type: 'extra', status: 5 }],
        );
        const wrapper = mountStep(step);

        // no stepper before first selection
        expect(wrapper.find('[data-testid="kiosk-generic-qty-plus"]').exists()).toBe(false);

        await wrapper.find('button.kiosk-generic-choice').trigger('click');
        await applyEmittedSelections(wrapper, 0);
        expect(wrapper.emitted('update')[0][1]['88'].choices['extra:21'].count).toBe(1);

        // + increments to 2
        await wrapper.find('[data-testid="kiosk-generic-qty-plus"]').trigger('click');
        await applyEmittedSelections(wrapper, 1);
        expect(wrapper.emitted('update')[1][1]['88'].choices['extra:21'].count).toBe(2);

        // + again reaches max 3
        await wrapper.find('[data-testid="kiosk-generic-qty-plus"]').trigger('click');
        await applyEmittedSelections(wrapper, 2);
        expect(wrapper.emitted('update')[2][1]['88'].choices['extra:21'].count).toBe(3);

        // at max the + button is disabled
        expect(wrapper.find('[data-testid="kiosk-generic-qty-plus"]').attributes('disabled')).toBeDefined();

        // − decrements by exactly 1 (previously a card tap at max wiped the entry)
        await wrapper.find('[data-testid="kiosk-generic-qty-minus"]').trigger('click');
        await applyEmittedSelections(wrapper, 3);
        expect(wrapper.emitted('update')[3][1]['88'].choices['extra:21'].count).toBe(2);

        // − down to zero removes the entry cleanly
        await wrapper.find('[data-testid="kiosk-generic-qty-minus"]').trigger('click');
        await applyEmittedSelections(wrapper, 4);
        await wrapper.find('[data-testid="kiosk-generic-qty-minus"]').trigger('click');
        await applyEmittedSelections(wrapper, 5);
        expect(wrapper.emitted('update')[5][1]['88'].choices['extra:21']).toBeUndefined();
        expect(wrapper.find('[data-testid="kiosk-generic-qty-minus"]').exists()).toBe(false);
    });

    it('[B3] stepper buttons are sibling controls, never nested inside the card button', async () => {
        const step = makeStep(
            { id: 88, source_type: 'extra_group', min_select: 0, max_select: 3, allow_repeat: true },
            [{ id: 21, name: 'Cheddar', source_type: 'extra', status: 5 }],
        );
        const wrapper = mountStep(step);
        await wrapper.find('button.kiosk-generic-choice').trigger('click');
        await applyEmittedSelections(wrapper, 0);

        // invalid-HTML guard: a <button> must not contain other buttons
        expect(wrapper.find('button.kiosk-generic-choice button').exists()).toBe(false);
        expect(wrapper.find('.kiosk-generic-cell > .kiosk-generic-qty').exists()).toBe(true);
    });

    it('[B4] renders a visible Épuisé badge on unavailable choices', () => {
        const step = makeStep({}, [
            { id: 11, name: 'Riz', source_type: 'variation', item_attribute_id: 4, status: 5, is_available: false },
            { id: 12, name: 'Frites', source_type: 'variation', item_attribute_id: 4, status: 5 },
        ]);
        const wrapper = mountStep(step);

        const badges = wrapper.findAll('[data-testid="kiosk-generic-oos-badge"]');
        expect(badges).toHaveLength(1);
        expect(badges[0].text()).toBe('Épuisé');
        expect(wrapper.findAll('button.kiosk-generic-choice')[0].attributes('disabled')).toBeDefined();
    });

    it('[B6] switches to the media grid only when every visible choice has an image', () => {
        const allImaged = mountStep(makeStep({}, [
            { id: 11, name: 'Riz', source_type: 'variation', status: 5, image: 'https://cdn.test/riz.png' },
            { id: 12, name: 'Frites', source_type: 'variation', status: 5, image: 'https://cdn.test/frites.png' },
        ]));
        expect(allImaged.find('.kiosk-generic-grid').classes()).toContain('kiosk-generic-grid--media');
        expect(allImaged.findAll('.kiosk-generic-choice--media')).toHaveLength(2);

        const mixed = mountStep(makeStep({}, [
            { id: 11, name: 'Riz', source_type: 'variation', status: 5, image: 'https://cdn.test/riz.png' },
            { id: 12, name: 'Frites', source_type: 'variation', status: 5 },
        ]));
        expect(mixed.find('.kiosk-generic-grid').classes()).not.toContain('kiosk-generic-grid--media');
    });

    it('[B8] single-select shows a ✓ checkmark instead of the count pill', async () => {
        const wrapper = mountStep(makeStep());
        await wrapper.findAll('button.kiosk-generic-choice')[0].trigger('click');
        await applyEmittedSelections(wrapper, 0);

        const check = wrapper.find('[data-testid="kiosk-generic-choice-check"]');
        expect(check.exists()).toBe(true);
        expect(check.text()).toBe('✓');
        expect(wrapper.find('.kiosk-generic-choice-count').exists()).toBe(false);
    });

    it('[B8] multi-select keeps the xN count pill', async () => {
        const step = makeStep({ min_select: 0, max_select: 3 });
        const wrapper = mountStep(step);
        await wrapper.findAll('button.kiosk-generic-choice')[0].trigger('click');
        await applyEmittedSelections(wrapper, 0);

        expect(wrapper.find('[data-testid="kiosk-generic-choice-check"]').exists()).toBe(false);
        expect(wrapper.find('.kiosk-generic-choice-count').text()).toBe('x1');
    });

    it('emit contract unchanged: update(composerChoices) keeps the frozen-wizard shape', async () => {
        const wrapper = mountStep(makeStep());
        await wrapper.findAll('button.kiosk-generic-choice')[0].trigger('click');

        const event = wrapper.emitted('update')?.[0];
        expect(event?.[0]).toBe('composerChoices');
        expect(event?.[1]['77']).toMatchObject({
            step_id: 77,
            step_key: 'accompagnement',
            label: 'Accompagnement',
            source_type: 'item_attribute',
        });
        expect(event?.[1]['77'].choices['variation:11']).toMatchObject({
            id: 11,
            name: 'Riz',
            source_type: 'variation',
            item_attribute_id: 4,
            count: 1,
        });
    });
});
