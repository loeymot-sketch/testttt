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

describe('relecture adverse (2026-10-17) — import', () => {
    it('une réponse lente d’un ancien fichier ne remplace pas l’aperçu du fichier retenu, et c’est le bon fichier qui part', async () => {
        const slow = [];
        h.axios.post.mockReset();
        h.axios.post.mockImplementation((url, fd) => new Promise((resolve) => {
            const name = fd.get('file').name;
            const rows = [{ line: 2, name: `Produit de ${name}`, status: 'new', errors: [], summary: 'Préparation 24 h · 0 à +4 °C' }];
            const reply = () => resolve({ data: { data: { rows, summary: { new: 1, exists: 0, invalid: 0, total: 1 }, created: fd.get('commit') === '1' ? 1 : 0, committed: fd.get('commit') === '1' } } });
            if (fd.get('commit') === '0') slow.push({ name, reply }); else reply();
        }));
        const w = mount(DlcImportDialog);
        await pick(w, 'ancien.csv');
        // le champ est verrouillé tant que l'analyse tourne
        expect(w.get('[data-testid="dlc-import-file"]').element.disabled).toBe(true);
        // le doigt a déjà choisi un autre fichier : on force le second choix (champ rouvert)
        w.vm.busy = false;
        await w.vm.$nextTick();
        await pick(w, 'corrige.csv');
        slow.find((s) => s.name === 'corrige.csv').reply();
        await flushPromises();
        slow.find((s) => s.name === 'ancien.csv').reply(); // arrive EN DERNIER
        await flushPromises();
        expect(w.get('[data-testid="dlc-import-row-2"]').text()).toMatch(/Produit de corrige\.csv/);
        await w.get('[data-testid="dlc-import-go"]').trigger('click');
        await flushPromises();
        const commit = h.axios.post.mock.calls.find((c) => c[1].get('commit') === '1');
        expect(commit[1].get('file').name).toBe('corrige.csv');
    });

    it('on ne ferme pas pendant la requête (le parent resterait périmé) ; le message dit que les lignes à corriger ne partent pas', async () => {
        let release;
        h.axios.post.mockReset();
        const preview = { rows: [{ line: 2, name: 'A', status: 'new', errors: [], summary: 'x' }, { line: 3, name: 'B', status: 'invalid', errors: ['Conservation obligatoire'], summary: null }], summary: { new: 1, exists: 0, invalid: 1, total: 2 }, created: 0, committed: false };
        h.axios.post.mockResolvedValueOnce({ data: { data: preview } });
        const w = mount(DlcImportDialog);
        await pick(w);
        await flushPromises();
        expect(w.text()).toMatch(/Les 1 ligne « à corriger » ne seront pas importées/);
        h.axios.post.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve({ data: { data: { ...preview, created: 1, committed: true } } }); }));
        await w.get('[data-testid="dlc-import-go"]').trigger('click');
        expect(w.get('[data-testid="dlc-import-close"]').element.disabled).toBe(true);
        await w.get('[data-testid="dlc-import"]').trigger('keydown', { key: 'Escape' });
        expect(w.emitted('close')).toBeUndefined();
        release();
        await flushPromises();
        expect(w.emitted('imported')).toEqual([[1]]);
    });
});
