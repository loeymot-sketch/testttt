/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P1.4] Import CSV : l'écran montre le résultat AVANT d'écrire, n'importe que ce qui est
 * à ajouter, dit pourquoi une ligne est refusée, et n'envoie « commit » qu'au dernier appui.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn() }, alert: { success: vi.fn(), error: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));

import DlcImportDialog from '../../resources/js/components/admin/labels/DlcImportDialog.vue';

const PREVIEW = {
    rows: [
        { line: 2, name: 'Oignons émincés', status: 'new', errors: [], summary: 'Préparation 24 h · 0 à +4 °C' },
        { line: 3, name: 'Olives', status: 'exists', errors: [], summary: null },
        { line: 4, name: 'Sans conservation', status: 'invalid', errors: ['Conservation obligatoire (elle est imprimée sur l’étiquette).'], summary: null },
    ],
    summary: { new: 1, exists: 1, invalid: 1, total: 3 }, created: 0, committed: false,
};

function pick(w, name = 'produits.csv') {
    const input = w.get('[data-testid="dlc-import-file"]');
    const file = new File(['Produit;Conservation\n'], name, { type: 'text/csv' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    return input.trigger('change');
}

beforeEach(() => { h.axios.get.mockReset(); h.axios.post.mockReset(); Object.values(h.alert).forEach((f) => f.mockReset()); });

describe('import CSV des produits', () => {
    it('le fichier est analysé tout de suite, sans rien écrire : le résultat dit à ajouter / déjà présent / à corriger', async () => {
        h.axios.post.mockResolvedValue({ data: { data: PREVIEW } });
        const w = mount(DlcImportDialog);
        await pick(w);
        await flushPromises();
        const [url, fd] = h.axios.post.mock.calls[0];
        expect(url).toBe('admin/labels/products/import');
        expect(fd.get('commit')).toBe('0');
        expect(fd.get('file').name).toBe('produits.csv');
        const summary = w.get('[data-testid="dlc-import-summary"]').text();
        expect(summary).toMatch(/1 à ajouter/);
        expect(summary).toMatch(/1 déjà présent \(non modifié\)/);
        expect(summary).toMatch(/1 à corriger/);
        expect(w.get('[data-testid="dlc-import-row-2"]').text()).toMatch(/Préparation 24 h/);
        expect(w.get('[data-testid="dlc-import-row-3"]').text()).toMatch(/laissé tel quel/);
        expect(w.get('[data-testid="dlc-import-row-4"]').text()).toMatch(/Conservation obligatoire/);
        expect(w.text()).toMatch(/ne sont\s+pas validées/);
        expect(w.emitted('imported')).toBeUndefined();
    });

    it('« Importer » n’est possible que s’il y a quelque chose à ajouter, et envoie alors commit=1', async () => {
        h.axios.post.mockResolvedValueOnce({ data: { data: PREVIEW } }).mockResolvedValueOnce({ data: { data: { ...PREVIEW, created: 1, committed: true } } });
        const w = mount(DlcImportDialog);
        expect(w.get('[data-testid="dlc-import-go"]').element.disabled).toBe(true);
        await pick(w);
        await flushPromises();
        const go = w.get('[data-testid="dlc-import-go"]');
        expect(go.element.disabled).toBe(false);
        expect(go.text()).toBe('Importer 1 produit');
        await go.trigger('click');
        await flushPromises();
        expect(h.axios.post.mock.calls[1][1].get('commit')).toBe('1');
        expect(h.alert.success).toHaveBeenCalledWith('1 produit importé.');
        expect(w.emitted('imported')[0]).toEqual([1]);
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('rien à ajouter (tout existe ou tout est à corriger) : le bouton reste inactif', async () => {
        h.axios.post.mockResolvedValue({ data: { data: { ...PREVIEW, summary: { new: 0, exists: 2, invalid: 1, total: 3 } } } });
        const w = mount(DlcImportDialog);
        await pick(w);
        await flushPromises();
        expect(w.get('[data-testid="dlc-import-go"]').element.disabled).toBe(true);
    });

    it('fichier refusé par le serveur : la raison s’affiche, rien n’est proposé', async () => {
        h.axios.post.mockRejectedValue({ response: { data: { message: 'x', errors: { file: ['Colonne « Produit » introuvable : utilisez le fichier-modèle (première ligne = en-têtes).'] } } } });
        const w = mount(DlcImportDialog);
        await pick(w, 'mauvais.csv');
        await flushPromises();
        expect(w.get('[data-testid="dlc-import-error"]').text()).toMatch(/fichier-modèle/);
        expect(w.find('[data-testid="dlc-import-summary"]').exists()).toBe(false);
        expect(w.get('[data-testid="dlc-import-go"]').element.disabled).toBe(true);
    });

    it('le fichier-modèle se télécharge (CSV)', async () => {
        h.axios.get.mockResolvedValue({ data: new Blob(['Produit;Famille'], { type: 'text/csv' }) });
        const create = vi.fn(() => 'blob:x');
        const revoke = vi.fn();
        globalThis.URL.createObjectURL = create;
        globalThis.URL.revokeObjectURL = revoke;
        const w = mount(DlcImportDialog);
        await w.get('[data-testid="dlc-import-template"]').trigger('click');
        await flushPromises();
        expect(h.axios.get).toHaveBeenCalledWith('admin/labels/products/import-template', { responseType: 'blob' });
        expect(create).toHaveBeenCalledTimes(1);
    });
});
