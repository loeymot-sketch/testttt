/**
 * [HEAL dispute-r3 B-R1-07 2026-06-12] — cash-overview aveugle aux refunds.
 * -----------------------------------------------------------------------------
 * R1/R2 adversarial : les remboursements du jour (−13,40 €) n'apparaissaient
 * ni en ligne ni en mention « hors remboursements » sur la page, alors que le
 * grand livre transactions les montre en négatif — 2 réalités sur le même
 * périmètre, et des cartes BRUTES sans étiquette de périmètre.
 *
 * Invariants verrouillés :
 *  1. Le GRAND TOTAL porte une étiquette de périmètre explicite
 *     (« bruts — hors remboursements »).
 *  2. Quand le payload `refunds.count > 0`, une ligne disclosure rend le
 *     total (−X €) + le count + la non-déduction.
 *  3. count = 0 → pas de ligne refunds (pas de bruit), mais l'étiquette de
 *     périmètre reste.
 *  4. Clefs FR + parité EN.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import CashOverviewComponent from '../../resources/js/components/admin/cashOverview/CashOverviewComponent.vue';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

function mountPage() {
    return mount(CashOverviewComponent, {
        global: {
            mocks: {
                $t: (key, params) => key + (params ? ':' + JSON.stringify(params) : ''),
                $route: { query: {} },
                $router: { push: vi.fn(() => Promise.resolve()) },
            },
            stubs: {
                Datepicker: { template: '<input />' },
            },
        },
    });
}

describe('[B-R1-07] disclosure refunds sur cash-overview', () => {
    it('grand total étiqueté BRUT — hors remboursements', async () => {
        const wrapper = mountPage();
        await wrapper.setData({
            loading: false,
            summary: { total: 59.54, count: 12, by_source: {}, by_mode: {} },
            refunds: { count: 0, total: 0, total_currency_price: '0,00 €' },
        });
        const note = wrapper.find('[data-testid="cash-overview-gross-note"]');
        expect(note.exists()).toBe(true);
        expect(note.text()).toContain('label.cash_overview_gross_note');
    });

    it('refunds.count > 0 → ligne disclosure avec total négatif + count', async () => {
        const wrapper = mountPage();
        await wrapper.setData({
            loading: false,
            summary: { total: 59.54, count: 12, by_source: {}, by_mode: {} },
            refunds: { count: 3, total: 13.4, total_currency_price: '13,40 €' },
        });
        const block = wrapper.find('[data-testid="cash-overview-refunds"]');
        expect(block.exists()).toBe(true);
        expect(block.text()).toContain('label.cash_overview_refunds_title');
        const total = wrapper.find('[data-testid="cash-overview-refunds-total"]');
        expect(total.text()).toContain('13,40');
        expect(total.text()).toContain('−');
        const count = wrapper.find('[data-testid="cash-overview-refunds-count"]');
        expect(count.text()).toContain('3');
        expect(block.text()).toContain('label.cash_overview_refunds_note');
    });

    it('refunds.count = 0 → pas de ligne refunds (pas de bruit)', async () => {
        const wrapper = mountPage();
        await wrapper.setData({
            loading: false,
            summary: { total: 59.54, count: 12, by_source: {}, by_mode: {} },
            refunds: { count: 0, total: 0, total_currency_price: '0,00 €' },
        });
        expect(wrapper.find('[data-testid="cash-overview-refunds"]').exists()).toBe(false);
    });

    it('le fetch hydrate refunds depuis payload.refunds', () => {
        const src = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/cashOverview/CashOverviewComponent.vue'),
            'utf-8'
        );
        expect(src).toMatch(/this\.refunds\s*=\s*payload\.refunds/);
    });
});

describe('[B-R1-07] clefs i18n (FR + parité EN)', () => {
    const KEYS = ['cash_overview_gross_note', 'cash_overview_refunds_title', 'cash_overview_refunds_note'];

    it.each(KEYS)('fr.json label.%s existe et est FR', (key) => {
        const value = fr?.label?.[key];
        expect(typeof value).toBe('string');
        expect(value.length).toBeGreaterThan(3);
    });

    it.each(KEYS)('en.json label.%s existe (parité)', (key) => {
        const value = en?.label?.[key];
        expect(typeof value).toBe('string');
    });

    it('la copy FR dit explicitement « hors remboursements » et la non-déduction', () => {
        expect(fr.label.cash_overview_gross_note).toMatch(/hors remboursements/i);
        expect(fr.label.cash_overview_refunds_note).toMatch(/non déduits/i);
    });
});
