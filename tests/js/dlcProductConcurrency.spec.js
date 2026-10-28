/**
 * [VG-2026-10-21 M7-001] Deux tablettes, une fiche DLC. L'éditeur renvoie la fiche ENTIÈRE telle qu'il l'a ouverte : une copie
 * périmée effaçait un allergène ajouté ailleurs, sans un mot. Désormais :
 *  - l'éditeur renvoie la version lue (`expected_version`) ;
 *  - il ne se ferme qu'APRÈS la relecture de la liste (sinon on rouvre aussitôt une copie périmée) ;
 *  - un refus 409 est DIT (« modifiée sur un autre appareil — rechargée ») et la fiche à jour est rechargée dans l'éditeur.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: vi.fn(),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));

import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';
import DlcProductEditor from '../../resources/js/components/admin/labels/DlcProductEditor.vue';

const P = (over) => ({
    id: 4909, name: 'Sauce course', category: 'Sauces', shelf_life_value: 48, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 48, unit: 'hours' }], allergens: ['lait'], no_allergens: false, storage: '0 à +3 °C',
    thawed_storage: '', note: null, ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, uses_30d: 0,
    version: 'v-lue-par-A', ...over,
});

let serverList;
let listGate;

function setup() {
    serverList = [P()];
    listGate = null;
    h.axios.get.mockReset();
    h.axios.put.mockReset();
    h.axios.post.mockReset();
    h.alert.success.mockReset();
    h.alert.error.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') {
            if (listGate) await listGate.promise;
            return { data: { data: serverList, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        }
        return { data: { data: [] } };
    });
    h.axios.post.mockResolvedValue({ data: { status: 'ok' } });
    window.localStorage.clear();
}

function gate() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return { promise, resolve };
}

async function mountScreen() {
    const w = mount(LabelDlcComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': { template: '<a><slot /></a>' } } } });
    await flushPromises();
    return w;
}

beforeEach(setup);

describe('fiche DLC modifiée sur deux tablettes (VG-2026-10-21 M7-001)', () => {
    it('l’éditeur renvoie la version lue, et ne se ferme qu’après la relecture de la liste', async () => {
        const w = await mountScreen();
        w.vm.openEditor(w.vm.products[0]);
        await flushPromises();
        h.axios.put.mockResolvedValue({ data: { data: P({ note: 'Maison', version: 'v-apres' }) } });

        listGate = gate();
        w.findComponent(DlcProductEditor).vm.submit();
        await flushPromises();

        const [url, body] = h.axios.put.mock.calls[0];
        expect(url).toBe('admin/labels/products/4909');
        expect(body.expected_version).toBe('v-lue-par-A');
        // la liste n'est pas encore relue : l'éditeur reste ouvert (sinon on rouvrirait aussitôt la copie périmée)
        expect(w.findComponent(DlcProductEditor).exists()).toBe(true);

        serverList = [P({ note: 'Maison', version: 'v-apres' })];
        listGate.resolve();
        await flushPromises();
        expect(w.findComponent(DlcProductEditor).exists()).toBe(false);
        expect(w.vm.products[0].version).toBe('v-apres');
    });

    it('409 : le refus est DIT et la fiche à jour (Gluten, 24 h) est rechargée dans l’éditeur', async () => {
        const w = await mountScreen();
        w.vm.openEditor(w.vm.products[0]);
        await flushPromises();

        // pendant ce temps, la tablette B a ajouté Gluten et raccourci à 24 h
        const aJour = P({ allergens: ['lait', 'gluten'], actions: [{ key: 'prepared', value: 24, unit: 'hours' }], shelf_life_value: 24, version: 'v-de-B' });
        serverList = [aJour];
        const err = new Error('Request failed with status code 409');
        err.response = { status: 409, data: { message: 'Cette fiche a été modifiée sur un autre appareil — rechargée. Vérifiez-la puis enregistrez à nouveau.', code: 'stale_product', data: aJour } };
        h.axios.put.mockRejectedValue(err);

        w.findComponent(DlcProductEditor).vm.submit();
        await flushPromises();

        const ed = w.findComponent(DlcProductEditor);
        expect(ed.exists()).toBe(true);
        expect(ed.text()).toContain('modifiée sur un autre appareil');
        // l'éditeur montre la fiche du serveur, pas la copie périmée
        expect(ed.vm.form.allergens).toEqual(['lait', 'gluten']);
        expect(ed.vm.enabledActions[0]).toEqual({ key: 'prepared', value: 24, unit: 'hours' });
        expect(h.alert.success).not.toHaveBeenCalled();

        // le nouvel enregistrement porte la version À JOUR
        h.axios.put.mockReset();
        h.axios.put.mockResolvedValue({ data: { data: aJour } });
        ed.vm.submit();
        await flushPromises();
        expect(h.axios.put.mock.calls[0][1].expected_version).toBe('v-de-B');
    });

    it('masquer / réactiver porte aussi la version : une copie périmée est refusée et la liste relue', async () => {
        const w = await mountScreen();
        const err = new Error('409');
        err.response = { status: 409, data: { message: 'Cette fiche a été modifiée sur un autre appareil — rechargée.' } };
        h.axios.put.mockRejectedValue(err);
        const gets = h.axios.get.mock.calls.filter(([u]) => u === 'admin/labels/products').length;

        await w.vm.toggleActive(w.vm.products[0]);
        await flushPromises();

        expect(h.axios.put.mock.calls[0][1]).toMatchObject({ is_active: false, expected_version: 'v-lue-par-A' });
        expect(h.alert.error).toHaveBeenCalledWith(expect.stringContaining('modifiée sur un autre appareil'));
        expect(h.axios.get.mock.calls.filter(([u]) => u === 'admin/labels/products').length).toBe(gets + 1);
    });
});
