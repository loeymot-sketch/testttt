/**
 * [ÉTIQUETTES DLC · audit E2E 2026-11-18 P2-i] Sélection multiple interrompue par une panne d'imprimante : le récapitulatif ne dit
 * plus « ⚠ 0 étiquette imprimée — » en ne nommant que le premier produit ; il nomme TOUS les produits non imprimés.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, printBlocks: vi.fn(), printer: null }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: (...a) => h.printBlocks(...a),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));
vi.mock('../../resources/js/helpers/labelPrinter.js', async (orig) => ({ ...(await orig()), labelPrinter: () => h.printer }));

import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';

const printer = { isSupported: () => true, getState: () => ({ status: 'connected', printer: null }), onChange: () => () => {}, startKeepAlive() {}, stopKeepAlive() {}, isConnected: () => true };
const P = (over) => ({
    id: 1, name: 'Oignons émincés', category: 'Légumes & crudités', shelf_life_value: 24, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 24, unit: 'hours' }], allergens: [], storage: '0 à +4 °C', note: null,
    ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, uses_30d: 0, ...over,
});

function setup(products, printResults) {
    h.printer = printer;
    h.printBlocks.mockReset();
    printResults.forEach((r) => h.printBlocks.mockResolvedValueOnce(r));
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') return { data: { data: products, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        if (url === 'admin/labels/watch') return { data: { data: [], meta: { server_now: new Date().toISOString(), expired_hidden: 0 } } };
        return { data: { data: [] } };
    });
    let n = 0;
    h.axios.post.mockImplementation(async (url, body) => {
        if (url === 'admin/labels/dlc/print') {
            n += 1;
            const p = products.find((x) => x.id === body.product_id);
            return { data: {
                log_id: 100 + n, attempt: 1, name: p.name, category: p.category, action: body.action || p.actions[0].key,
                opened_at: '2026-10-16T08:00:00Z', expires_at: '2026-10-17T08:00:00Z', copies: body.copies, lot: `L261016-000${n}`,
                allergens: p.allergens, storage: p.storage, by: 'Karim', capped: false,
            } };
        }
        return { data: { status: 'ok' } };
    });
    window.localStorage.clear();
}

async function printAll(products, printResults) {
    setup(products, printResults);
    const w = mount(LabelDlcComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': { template: '<a><slot /></a>' } } } });
    await flushPromises();
    await w.get('[data-testid="dlc-mode-list"]').trigger('click');
    await w.get('[data-testid="dlc-select-all"]').trigger('click');
    await w.get('[data-testid="dlc-basket-print"]').trigger('click');
    await flushPromises();
    return w;
}

describe('écran DLC — sélection interrompue (audit 2026-11-18)', () => {
    it('panne dès le premier produit : « Aucune étiquette imprimée » et TOUS les produits sont nommés', async () => {
        const list = [P({ id: 1, name: 'Tomates' }), P({ id: 2, name: 'Oignons émincés' }), P({ id: 3, name: 'Olives' })];
        const w = await printAll(list, [{ ok: false, message: 'Capot ouvert' }]);
        const text = w.get('[data-testid="dlc-last-batch"]').text();
        expect(text).not.toMatch(/0 étiquette imprimée/);
        expect(text).toContain('Aucune étiquette imprimée');
        const failed = w.get('[data-testid="dlc-last-failed"]').text();
        const notTried = w.get('[data-testid="dlc-last-not-tried"]').text();
        const named = ['Tomates', 'Oignons émincés', 'Olives'].filter((n) => failed.includes(n) || notTried.includes(n));
        expect(named).toHaveLength(3);
        expect(failed).toContain('Capot ouvert');
        expect(notTried).toContain('impression interrompue');
    });

    it('panne au deuxième : le premier est compté imprimé, les deux autres sont nommés', async () => {
        const list = [P({ id: 1, name: 'Tomates' }), P({ id: 2, name: 'Oignons émincés' }), P({ id: 3, name: 'Olives' })];
        const w = await printAll(list, [{ ok: true, pages: 1 }, { ok: false, message: 'Plus de papier' }]);
        const text = w.get('[data-testid="dlc-last-batch"]').text();
        expect(text).toMatch(/1 étiquette imprimée — \S/);
        const rest = `${w.get('[data-testid="dlc-last-failed"]').text()} ${w.get('[data-testid="dlc-last-not-tried"]').text()}`;
        const printedName = ['Tomates', 'Oignons émincés', 'Olives'].find((n) => text.split('Non imprimé')[0].includes(n));
        expect(printedName).toBeTruthy();
        ['Tomates', 'Oignons émincés', 'Olives'].filter((n) => n !== printedName).forEach((n) => expect(rest).toContain(n));
    });

    it('sans panne : aucune mention d’interruption', async () => {
        const list = [P({ id: 1, name: 'Tomates' }), P({ id: 2, name: 'Olives' })];
        const w = await printAll(list, [{ ok: true, pages: 1 }, { ok: true, pages: 1 }]);
        expect(w.get('[data-testid="dlc-last-batch"]').text()).toMatch(/2 étiquettes imprimées/);
        expect(w.find('[data-testid="dlc-last-not-tried"]').exists()).toBe(false);
    });
});
