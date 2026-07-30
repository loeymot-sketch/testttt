import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

// [CAISSE-HEALTH 2026-07-30] La pastille santé système : vert discret quand tout va bien,
// ambre/rouge + message rassurant dès qu'une dégradation apparaît (surtout le cas silencieux
// « temps réel connecté mais périmé » = worker DOWN). Best-effort : ne casse jamais l'écran caisse.

const mockGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => mockGet(...a) } }));

import PosSystemHealthPill from '../../resources/js/components/admin/pos/PosSystemHealthPill.vue';

const health = (overall, sync, fiscal) => ({
    data: {
        overall,
        checks: {
            sync: sync || { status: 'ok', message: 'Temps réel actif.' },
            fiscal: fiscal || { status: 'ok', message: 'Chaîne fiscale intègre.' },
        },
    },
});

let currentWrapper = null;
const mountWith = async (payload) => {
    mockGet.mockResolvedValueOnce(payload);
    currentWrapper = shallowMount(PosSystemHealthPill);
    await flushPromises();
    return currentWrapper;
};

describe('PosSystemHealthPill', () => {
    beforeEach(() => { mockGet.mockReset(); });
    afterEach(() => { if (currentWrapper) { currentWrapper.unmount(); currentWrapper = null; } });

    it('vert « Système OK » quand tout va bien', async () => {
        const w = await mountWith(health('ok'));
        expect(w.vm.loaded).toBe(true);
        expect(w.vm.tone).toBe('ok');
        expect(w.vm.label).toBe('Système OK');
    });

    it('ambre « Temps réel dégradé » quand le worker est en retard (connecté mais périmé)', async () => {
        const w = await mountWith(health('degraded', { status: 'warn', message: 'Temps réel dégradé — traitement en retard.' }));
        expect(w.vm.tone).toBe('warn');
        expect(w.vm.label).toContain('dégradé');
    });

    it('rouge « Temps réel coupé » quand le socket est mort', async () => {
        const w = await mountWith(health('down', { status: 'down', message: 'Temps réel coupé — rafraîchissement automatique.' }));
        expect(w.vm.tone).toBe('down');
        expect(w.vm.label).toContain('coupé');
    });

    it('surfacer l\'alerte chaîne fiscale', async () => {
        const w = await mountWith(health('degraded', { status: 'ok', message: 'ok' }, { status: 'alert', message: 'Anomalie sur la chaîne fiscale — préviens le support.' }));
        expect(w.vm.fiscalAlert).toBe(true);
        expect(w.vm.detailText).toContain('Anomalie sur la chaîne fiscale');
    });

    it('best-effort : un poll en échec ne casse pas l\'écran (rien chargé, pas d\'exception)', async () => {
        mockGet.mockRejectedValueOnce(new Error('network down'));
        currentWrapper = shallowMount(PosSystemHealthPill);
        await flushPromises();
        expect(currentWrapper.vm.loaded).toBe(false);
        expect(currentWrapper.find('[data-testid="pos-health-pill"]').exists()).toBe(false);
    });
});
