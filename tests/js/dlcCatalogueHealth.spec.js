/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0.3/P0.4/P0.6] Contrôle du catalogue : la source de chaque durée et sa validation par
 * le responsable (fiche produit), l'écran « Contrôle du catalogue » (durées à valider, allergènes à renseigner, fiches sans famille,
 * fiches voisines) et son bouton sur l'écran DLC. Serveur simulé : on prouve ce que l'écran demande et ce qu'il refuse de faire seul.
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

import DlcProductEditor from '../../resources/js/components/admin/labels/DlcProductEditor.vue';
import DlcCatalogueHealth from '../../resources/js/components/admin/labels/DlcCatalogueHealth.vue';
import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';

const settings = { media: 'c62', darkness: 1 };
const P = (over) => ({
    id: 1, name: 'Oignons émincés', category: 'Légumes & crudités', shelf_life_value: 24, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 24, unit: 'hours' }], allergens: [], no_allergens: true, storage: '0 à +4 °C', thawed_storage: '', note: null,
    ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, uses_30d: 0,
    duration_source: 'Valeur de départ Cayenne', duration_review: 'starting', durations_checked_at: null, ...over,
});

beforeEach(() => { vi.useRealTimers(); Object.values(h.alert).forEach((f) => f.mockReset()); h.axios.post.mockReset(); });

describe('fiche produit — source et validation des durées', () => {
    it('une valeur de départ est annoncée « pas encore validée » ; on peut la valider sans rien changer', async () => {
        const w = mount(DlcProductEditor, { props: { product: P(), settings } });
        expect(w.get('[data-testid="dlc-ed-source"]').element.value).toBe('Valeur de départ Cayenne');
        expect(w.get('[data-testid="dlc-ed-starting"]').text()).toMatch(/pas encore validée/);
        await w.get('[data-testid="dlc-ed-validate"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        const payload = w.emitted('save')[0][0];
        expect(payload.durations_checked).toBe(true);
        // la source n'a pas été touchée : elle n'est PAS renvoyée (le serveur garde « Valeur de départ »)
        expect(payload.duration_source).toBeUndefined();
    });

    it('sans cocher « Je valide », rien n’est validé', async () => {
        const w = mount(DlcProductEditor, { props: { product: P(), settings } });
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0].durations_checked).toBeUndefined();
    });

    it('la source écrite par le responsable est envoyée, et seulement si elle a changé', async () => {
        const w = mount(DlcProductEditor, { props: { product: P(), settings } });
        await w.get('[data-testid="dlc-ed-source"]').setValue('Plan de maîtrise sanitaire');
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0].duration_source).toBe('Plan de maîtrise sanitaire');
        // effacer la source = la retirer (null), pas l’ignorer
        const cleared = mount(DlcProductEditor, { props: { product: P({ duration_source: 'GBPH' }), settings } });
        await cleared.get('[data-testid="dlc-ed-source"]').setValue('');
        await cleared.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(cleared.emitted('save')[0][0].duration_source).toBeNull();
    });

    it('fiche déjà validée et inchangée : le texte dit quand, aucune case à recocher ; une durée changée la ramène', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ duration_review: 'validated', durations_checked_at: '2026-10-17T09:00:00Z' }), settings } });
        expect(w.get('[data-testid="dlc-ed-durations-checked-at"]').text()).toMatch(/Durées validées le/);
        expect(w.find('[data-testid="dlc-ed-validate"]').exists()).toBe(false);
        expect(w.find('[data-testid="dlc-ed-starting"]').exists()).toBe(false);
        // la durée change : l'ancienne validation ne couvre plus ces chiffres, il faut la redonner explicitement
        await w.get('[data-testid="dlc-ed-value-prepared"]').setValue(48);
        expect(w.find('[data-testid="dlc-ed-durations-checked-at"]').exists()).toBe(false);
        expect(w.find('[data-testid="dlc-ed-validate"]').exists()).toBe(true);
        expect(w.get('[data-testid="dlc-ed-validate"]').element.checked).toBe(false);
    });

    it('fiche neuve : la case de validation est proposée, décochée', () => {
        const w = mount(DlcProductEditor, { props: { product: {}, settings } });
        expect(w.get('[data-testid="dlc-ed-validate"]').element.checked).toBe(false);
    });
});

describe('Contrôle du catalogue — l’écran', () => {
    const LIST = [
        P({ id: 1, name: 'Oignons émincés' }), P({ id: 2, name: 'Salade iceberg', category: 'Légumes & crudités' }),
        P({ id: 3, name: 'Poulet mariné', category: 'Viandes & volailles' }),
        P({ id: 4, name: 'Tomate', category: '', duration_review: 'own', duration_source: null, no_allergens: false }),
        P({ id: 5, name: 'Sauce maison', category: 'Sauces', duration_review: 'own', duration_source: null, no_allergens: false }),
    ];
    const HEALTH = () => ({
        starting: [1, 2, 3],
        allergens: [4, 5],
        no_category: [{ id: 4, name: 'Tomate', suggested: 'Légumes & crudités' }],
        neighbours: [{ kind: 'storage_differs', ids: [4, 1], names: ['Tomate', 'Oignons émincés'], storages: ['0 à +3 °C', '0 à +4 °C'] }],
    });
    const mountHealth = (health = HEALTH()) => mount(DlcCatalogueHealth, { props: { health, products: LIST, categories: ['Légumes & crudités', 'Sauces'] } });

    it('catalogue propre : le dit, sans rien proposer', () => {
        const w = mountHealth({ starting: [], allergens: [], no_category: [], neighbours: [] });
        expect(w.get('[data-testid="dlc-health-clean"]').text()).toMatch(/Rien à signaler/);
        expect(w.find('[data-testid="dlc-health-starting"]').exists()).toBe(false);
    });

    it('les quatre contrôles sont présents, avec leurs comptes', () => {
        const w = mountHealth();
        expect(w.get('[data-testid="dlc-health-starting"] .hc-n').text()).toBe('3');
        expect(w.findAll('[data-testid^="dlc-health-allergen-"]').map((x) => x.text())).toEqual(['Tomate', 'Sauce maison']);
        expect(w.get('[data-testid="dlc-health-cat-4"]').element.value).toBe('Légumes & crudités');
        expect(w.get('[data-testid="dlc-health-pair-0"]').text()).toMatch(/Conservation différente/);
        expect(w.get('[data-testid="dlc-health-pair-0"]').text()).toMatch(/0 à \+3 °C/);
    });

    it('valider les durées exige la confirmation du responsable, puis envoie les identifiants — jamais plus', async () => {
        h.axios.post.mockResolvedValue({ data: { data: { validated: 2 } } });
        const w = mountHealth();
        const btn = () => w.get('[data-testid="dlc-health-validate-legumes-crudites"]');
        await w.get('[data-testid="dlc-health-group-legumes-crudites"] summary').trigger('click');
        expect(btn().element.disabled).toBe(true);
        await btn().trigger('click');
        expect(h.axios.post).not.toHaveBeenCalled();
        await w.get('[data-testid="dlc-health-confirm"]').setValue(true);
        expect(btn().element.disabled).toBe(false);
        await btn().trigger('click');
        await flushPromises();
        expect(h.axios.post).toHaveBeenCalledWith('admin/labels/products/validate-durations', { ids: [1, 2] });
        expect(w.emitted('changed')).toHaveLength(1);
        // « Tout valider » regroupe toutes les familles
        await w.get('[data-testid="dlc-health-validate-all"]').trigger('click');
        await flushPromises();
        expect(h.axios.post).toHaveBeenLastCalledWith('admin/labels/products/validate-durations', { ids: [1, 2, 3] });
    });

    it('ranger : la famille proposée est pré-cochée, modifiable ; seules les fiches cochées partent', async () => {
        h.axios.post.mockResolvedValue({ data: { data: { classified: 1 } } });
        const w = mountHealth();
        expect(w.get('[data-testid="dlc-health-cat-check-4"]').element.checked).toBe(true);
        await w.get('[data-testid="dlc-health-cat-4"]').setValue('Légumes');
        await w.get('[data-testid="dlc-health-classify"]').trigger('click');
        await flushPromises();
        expect(h.axios.post).toHaveBeenCalledWith('admin/labels/products/classify', { assignments: [{ id: 4, category: 'Légumes' }] });
        // décochée ou vide : rien à ranger
        const none = mountHealth();
        await none.get('[data-testid="dlc-health-cat-check-4"]').setValue(false);
        expect(none.get('[data-testid="dlc-health-classify"]').element.disabled).toBe(true);
    });

    it('ouvrir une fiche (allergène, voisine, durée) émet « edit » avec la fiche', async () => {
        const w = mountHealth();
        await w.get('[data-testid="dlc-health-allergen-5"]').trigger('click');
        expect(w.emitted('edit')[0][0].id).toBe(5);
        await w.get('[data-testid="dlc-health-pair-0-1"]').trigger('click');
        expect(w.emitted('edit')[1][0].id).toBe(1);
        await w.get('[data-testid="dlc-health-group-legumes-crudites"] summary').trigger('click');
        await w.get('[data-testid="dlc-health-edit-2"]').trigger('click');
        expect(w.emitted('edit')[2][0].id).toBe(2);
    });
});

describe('écran DLC — bouton « Contrôle du catalogue »', () => {
    const printer = { isSupported: () => true, getState: () => ({ status: 'connected', printer: null }), onChange: () => () => {}, startKeepAlive() {}, stopKeepAlive() {}, isConnected: () => true };

    async function screen(health) {
        h.axios.get.mockReset();
        h.axios.get.mockImplementation(async (url) => {
            if (url === 'admin/labels/products') {
                return { data: { data: [P()], meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris', health, recommended: { total: 92, missing: 0, never_configured: false } } } };
            }
            return { data: { data: [], meta: { server_now: new Date().toISOString() } } };
        });
        window.localStorage.clear();
        const w = mount(LabelDlcComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': { template: '<a><slot /></a>' } } } });
        w.vm.printerInfo; // le mixin imprimante est déjà branché
        await flushPromises();
        return w;
    }

    it('le compte est affiché ; un clic ouvre le contrôle ; propre : pas de pastille', async () => {
        const dirty = await screen({ starting: [1], allergens: [], no_category: [], neighbours: [] });
        expect(dirty.get('[data-testid="dlc-health-count"]').text()).toBe('1');
        await dirty.get('[data-testid="dlc-health-open"]').trigger('click');
        expect(dirty.find('[data-testid="dlc-health"]').exists()).toBe(true);
        const clean = await screen({ starting: [], allergens: [], no_category: [], neighbours: [] });
        expect(clean.find('[data-testid="dlc-health-count"]').exists()).toBe(false);
    });

    it('la liste de gestion dit quelles durées sont à valider', async () => {
        const w = await screen({ starting: [1], allergens: [], no_category: [], neighbours: [] });
        await w.get('[data-testid="dlc-manage-toggle"]').trigger('click');
        expect(w.get('[data-testid="dlc-row-review-1"]').text()).toMatch(/à valider/);
    });
});

describe('fiche produit — les huit états', () => {
    it('congelé et repas témoin disent leur conservation imposée ; la durée se compte en mois sans fausse alerte', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ duration_review: 'own', duration_source: null }), settings } });
        await w.get('[data-testid="dlc-ed-action-frozen"]').setValue(true);
        expect(w.get('[data-testid="dlc-ed-value-frozen"]').element.value).toBe('90');
        expect(w.get('[data-testid="dlc-ed-unit-frozen"]').element.value).toBe('days');
        expect(w.text()).toMatch(/conservé à ≤ −18 °C/);
        // 90 jours au congélateur : ce n'est pas « plus de 7 jours au froid positif »
        expect(w.find('[data-testid="dlc-ed-warn-frozen"]').exists()).toBe(false);
        await w.get('[data-testid="dlc-ed-action-witness"]').setValue(true);
        expect(w.get('[data-testid="dlc-ed-value-witness"]').element.value).toBe('5');
        expect(w.text()).toMatch(/conservé à 0 à \+3 °C/);
        await w.get('[data-testid="dlc-ed-action-received"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0].actions.map((a) => a.key)).toEqual(['prepared', 'frozen', 'witness', 'received']);
    });
});
