/**
 * purchaseScanComponent.spec.js — [ARCH_STOCK_INTELLIGENT_BOM P3c]
 *
 * Écran admin de scan de facture : upload → propositions IA (cibles pré-remplies
 * + score) → édition cible → validation en stock. Couvre :
 *   - bandeau « mode démo » piloté par window.foodkingConfig.purchasing.openaiEnabled ;
 *   - chargement des options cible au montage (GET targets) ;
 *   - scan → rendu des propositions avec type pré-sélectionné + badge IA + score ;
 *   - changer une cible (raw_material → charge) vide le sous-select ;
 *   - « Valider l'entrée en stock » → POST validate avec le payload des lignes.
 *
 * axios est mocké (import direct) et URL-keyé (scan vs validate vs targets).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    return { default: { get, post } };
});

import axios from 'axios';
import PurchaseScanComponent from '../../resources/js/components/admin/purchasing/PurchaseScanComponent.vue';

const TARGETS = {
    ok: true,
    raw_materials: [
        { id: 5, name: 'Poulet', unit: 'kg' },
        { id: 6, name: 'Cheddar', unit: 'tranche' },
    ],
    drink_items: [{ id: 9, name: 'Coca 33cl' }],
};

const SCAN = {
    ok: true,
    idempotent: false,
    document: { id: 42, branch_id: 1, status: 'draft', source: 'facture' },
    proposals: [
        {
            id: 1, raw_label: 'Poulet frais 3kg', qty: 3, unit: 'kg', unit_price: 6,
            tva_rate: 5.5, target_type: 'raw_material', target_id: 5, status: 'proposed',
            score: 0.9, matched: true,
        },
        {
            id: 2, raw_label: 'Sac papier kraft 500', qty: 500, unit: 'piece', unit_price: 0.008,
            tva_rate: 20, target_type: 'charge', target_id: null, status: 'proposed',
            score: 0.75, matched: true,
        },
    ],
};

const VALIDATE = {
    ok: true,
    applied: { document_id: 42, status: 'validated', applied: { raw_material: 1, stock_item: 0, charge: 1, skipped_proposed: 0 } },
    document: { id: 42, branch_id: 1, status: 'validated', source: 'facture' },
    proposals: SCAN.proposals.map((p) => ({ ...p, status: 'validated' })),
};

function primeAxios() {
    axios.get.mockResolvedValue({ data: TARGETS });
    axios.post.mockImplementation((url) => {
        if (String(url).includes('/validate')) {
            return Promise.resolve({ data: VALIDATE });
        }
        return Promise.resolve({ data: SCAN });
    });
}

function mountCmp() {
    return mount(PurchaseScanComponent, {
        global: { mocks: { $t: (k) => k } },
    });
}

async function mountScanned() {
    const w = mountCmp();
    await flushPromises();
    await w.setData({ file: new File(['x'], 'facture.jpg', { type: 'image/jpeg' }), fileName: 'facture.jpg' });
    await w.find('[data-testid="scan-btn"]').trigger('click');
    await flushPromises();
    return w;
}

describe('PurchaseScanComponent', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.foodkingConfig = {}; // défaut : pas de clé OpenAI → mode démo
        primeAxios();
    });

    it('affiche le bandeau démo et charge les options cible au montage', async () => {
        const w = mountCmp();
        await flushPromises();

        expect(w.find('[data-testid="demo-banner"]').exists()).toBe(true);
        expect(w.find('[data-testid="demo-banner"]').text()).toContain('Mode démo');
        expect(axios.get).toHaveBeenCalledWith('admin/purchasing/targets');
        expect(w.vm.rawMaterials.length).toBe(2);
        expect(w.vm.drinkItems.length).toBe(1);
    });

    it('masque le bandeau démo quand OpenAI est activé', async () => {
        window.foodkingConfig = { purchasing: { openaiEnabled: true } };
        const w = mountCmp();
        await flushPromises();

        expect(w.find('[data-testid="demo-banner"]').exists()).toBe(false);
    });

    it('scan → rend les propositions avec cible pré-remplie + badge IA + score', async () => {
        const w = await mountScanned();

        expect(axios.post).toHaveBeenCalledWith(
            'admin/purchasing/scan',
            expect.any(FormData),
            expect.any(Object),
        );

        const rows = w.findAll('[data-testid="proposal-row"]');
        expect(rows.length).toBe(2);

        // Cible pré-sélectionnée par l'IA.
        expect(rows[0].find('[data-testid="target-type"]').element.value).toBe('raw_material');
        expect(rows[1].find('[data-testid="target-type"]').element.value).toBe('charge');

        // Badges IA + score.
        expect(rows[0].find('[data-testid="ai-badge"]').exists()).toBe(true);
        expect(rows[0].find('[data-testid="score-badge"]').text()).toContain('90');

        // Sous-select matière rendu pour une ligne raw_material ; absent pour charge.
        expect(rows[0].find('[data-testid="target-raw"]').exists()).toBe(true);
        expect(rows[1].find('[data-testid="target-raw"]').exists()).toBe(false);

        // Aucun label brut non résolu.
        expect(w.text()).not.toMatch(/Label\.|undefined|purchasing\.scan\./);
    });

    it('changer une cible (raw_material → charge) vide le sous-select', async () => {
        const w = await mountScanned();
        const rows = w.findAll('[data-testid="proposal-row"]');

        await rows[0].find('[data-testid="target-type"]').setValue('charge');

        expect(w.vm.proposals[0].target_type).toBe('charge');
        expect(w.vm.proposals[0].target_id).toBe(null);
        expect(w.findAll('[data-testid="proposal-row"]')[0].find('[data-testid="target-raw"]').exists()).toBe(false);
    });

    it('valider → POST validate avec le payload des lignes + bandeau succès', async () => {
        const w = await mountScanned();

        await w.find('[data-testid="validate-btn"]').trigger('click');
        await flushPromises();

        const validateCall = axios.post.mock.calls.find((c) => String(c[0]).includes('/validate'));
        expect(validateCall).toBeTruthy();
        expect(validateCall[0]).toBe('admin/purchasing/42/validate');
        expect(validateCall[1].lines).toHaveLength(2);
        expect(validateCall[1].lines[0]).toMatchObject({ id: 1, target_type: 'raw_material', target_id: 5 });
        // charge → target_id forcé à null dans le payload.
        expect(validateCall[1].lines[1]).toMatchObject({ id: 2, target_type: 'charge', target_id: null });

        expect(w.find('[data-testid="success-banner"]').exists()).toBe(true);
        expect(w.find('[data-testid="success-banner"]').text()).toContain('validée');
    });
});
