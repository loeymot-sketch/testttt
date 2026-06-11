import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

/**
 * [UIUX-W2 F2 2026-06-11] Dates FR sur les filtres caisse.
 *
 * Les pages « Sessions de caisse » et « Vue d'ensemble caisse » utilisaient
 * des <input type="date"> natifs → rendu mm/dd/yyyy (locale navigateur,
 * Chromium headless = en-US) incohérent avec le reste de l'admin FR.
 *
 * Fix : remplacement par @vuepic/vue-datepicker configuré FR
 * (locale="fr", format dd/MM/yyyy) avec model-type="yyyy-MM-dd" pour que
 * `filters.from/to` restent des strings ISO — le contrat API
 * (`params.from/to`) et la sérialisation route-query sont inchangés.
 */

vi.mock('axios', () => ({
    default: { get: vi.fn(() => new Promise(() => {})) },
}));

import CashSessionReportListComponent from '../../resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue';
import CashOverviewComponent from '../../resources/js/components/admin/cashOverview/CashOverviewComponent.vue';

const globalCfg = {
    mocks: {
        $t: (k) => k,
        $i18n: { locale: 'fr-FR' },
        $route: { query: {} },
        $router: { push: vi.fn(), replace: vi.fn() },
    },
};

const datepickerProps = (wrapper) => wrapper
    .findAllComponents({ name: 'Datepicker' })
    .map((c) => c.props());

describe('Cash filters FR datepicker (F2 UIUX-W2)', () => {
    it('CashSessionReportListComponent: no native type=date input, 2 FR datepickers, ISO model', () => {
        const wrapper = shallowMount(CashSessionReportListComponent, { global: globalCfg });
        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        const dps = datepickerProps(wrapper);
        expect(dps.length).toBe(2);
        for (const p of dps) {
            expect(p.locale).toBe('fr');
            expect(p.format).toBe('dd/MM/yyyy');
            expect(p.modelType).toBe('yyyy-MM-dd');
            expect(p.enableTimePicker).toBe(false);
        }
    });

    it('CashOverviewComponent: no native type=date input, 2 FR datepickers, ISO model', () => {
        const wrapper = shallowMount(CashOverviewComponent, { global: globalCfg });
        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        const dps = datepickerProps(wrapper);
        expect(dps.length).toBe(2);
        for (const p of dps) {
            expect(p.locale).toBe('fr');
            expect(p.format).toBe('dd/MM/yyyy');
            expect(p.modelType).toBe('yyyy-MM-dd');
        }
        // Le modèle reste une string ISO (contrat API/route inchangé).
        expect(wrapper.vm.filters.from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
});
