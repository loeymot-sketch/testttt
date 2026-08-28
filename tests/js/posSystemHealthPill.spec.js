import fs from 'fs';
import path from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

// [CAISSE-HEALTH 2026-07-30] La pastille santé système : vert discret quand tout va bien,
// ambre/rouge + message honnête et actionnable dès qu'une dégradation apparaît (surtout le cas silencieux
// « temps réel connecté mais périmé » = worker DOWN). Best-effort : ne casse jamais l'écran caisse.

const mockGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => mockGet(...a) } }));

import PosSystemHealthPill from '../../resources/js/components/admin/pos/PosSystemHealthPill.vue';

const health = (overall, sync, fiscal, stock, aging, timestamp = new Date().toISOString()) => ({
    data: {
        overall,
        timestamp,
        checks: {
            sync: sync || { status: 'ok', message: 'Temps réel actif.' },
            fiscal: fiscal || { status: 'ok', message: 'Chaîne fiscale intègre.' },
            stock: stock || { status: 'ok', count: 0, message: 'Stock complet.' },
            aging: aging || { status: 'ok', count: 0, message: 'Aucune commande en retard.' },
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

    it('ROUGE : une sonde annexe inconnue ne rétrograde pas une panne temps réel', async () => {
        // [REPLAN_8 2026-08-24] `hasUnknownCheck` était évalué AVANT `overall === 'down'` : socket
        // coupé (rang 2) + sonde stock tombée (rang 1) — la combinaison exacte d'une vraie panne —
        // s'affichait en AMBRE « Contrôle dégradé », sans plus mentionner « Temps réel coupé ».
        // On masquait la panne opérationnelle avec l'incertitude d'à côté.
        const w = await mountWith(health(
            'down',
            { status: 'down', message: 'Le tableau bascule sur un rafraîchissement automatique.' },
            null,
            { status: 'unknown', count: null, message: 'Contrôle stock momentanément indisponible.' },
        ));
        expect(w.vm.tone, 'la sévérité a été rétrogradée en ambre').toBe('down');
        expect(w.vm.label).toBe('Temps réel coupé');
        // Le message de la sonde inconnue reste visible : on priorise, on ne cache pas.
        expect(w.find('[data-testid="pos-health-unknown-message"]').exists()).toBe(true);
        expect(w.vm.nonSyncUnknownMessage).toContain('stock');
        // Et le bouton de relance reste offert.
        expect(w.find('.pos-health-pill-retry').exists()).toBe(true);
    });

    it('un overall inconnu du contrat ne retombe jamais en vert', async () => {
        // Contrat serveur élargi demain (« maintenance », « partial »…) : on ne connaît pas cet
        // état, donc on l'affiche comme dégradé plutôt que de rassurer à tort.
        const w = await mountWith(health('maintenance'));
        expect(w.vm.tone).toBe('warn');
    });

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

    it('temps réel OK + alerte fiscale → libellé « Alerte fiscale » (ambre), pas de message sync trompeur', async () => {
        const w = await mountWith(health(
            'degraded',
            { status: 'ok', message: 'Les commandes arrivent en direct.' },
            { status: 'alert', message: 'Anomalie sur la chaîne fiscale — préviens le support.' },
        ));
        expect(w.vm.tone).toBe('warn'); // ambre, pas rouge : non-opérationnel
        expect(w.vm.label).toBe('Alerte fiscale');
        expect(w.vm.syncMessage).toBe(''); // le sync est ok → pas de message sync
        expect(w.vm.fiscalAlert).toBe(true);
        expect(w.vm.detailText).toContain('Anomalie sur la chaîne fiscale');
    });

    it('remonte les ruptures de stock (compteur info, sans dégrader le ton système)', async () => {
        const w = await mountWith(health('ok', null, null, { status: 'info', count: 3, message: '3 produits en rupture' }));
        expect(w.vm.tone).toBe('ok'); // stock = info → ne change pas le ton (vert reste vert)
        expect(w.vm.stockRuptures).toBe(3);
        expect(w.vm.detailText).toContain('3 produits en rupture');
    });

    it('0 rupture → pas de compteur stock', async () => {
        const w = await mountWith(health('ok'));
        expect(w.vm.stockRuptures).toBe(0);
    });

    it('remonte les commandes en retard (compteur info, sans dégrader le ton)', async () => {
        const w = await mountWith(health('ok', null, null, null, { status: 'info', count: 2, message: '2 commandes en attente > 15 min' }));
        expect(w.vm.tone).toBe('ok');
        expect(w.vm.agingOrders).toBe(2);
        expect(w.vm.detailText).toContain('2 commandes en attente');
    });

    it('0 commande en retard → pas de compteur âge', async () => {
        const w = await mountWith(health('ok'));
        expect(w.vm.agingOrders).toBe(0);
    });

    it('un premier poll en échec rend la supervision indisponible au lieu de masquer la pastille', async () => {
        mockGet.mockRejectedValueOnce(new Error('network down'));
        currentWrapper = shallowMount(PosSystemHealthPill);
        await flushPromises();
        expect(currentWrapper.vm.loaded).toBe(true);
        expect(currentWrapper.vm.tone).toBe('warn');
        expect(currentWrapper.vm.label).toBe('Contrôle indisponible');
        expect(currentWrapper.find('[data-testid="pos-health-pill"]').exists()).toBe(true);
        expect(currentWrapper.find('button[aria-label="Relancer le contrôle de santé de la caisse"]').exists()).toBe(true);
    });

    it('succès puis panne : ne conserve jamais un faux « Système OK » vert', async () => {
        const w = await mountWith(health('ok'));
        expect(w.vm.label).toBe('Système OK');
        mockGet.mockRejectedValueOnce(new Error('endpoint down'));
        await w.vm.poll();
        await flushPromises();
        expect(w.vm.tone).toBe('warn');
        expect(w.vm.label).toBe('Contrôle indisponible');
        expect(w.vm.detailText).toContain('ne répond plus');
    });

    it('une réponse trop ancienne devient périmée et reste actionnable', async () => {
        const old = new Date(Date.now() - 180000).toISOString();
        const w = await mountWith(health('ok', null, null, null, null, old));
        expect(w.vm.isStale).toBe(true);
        expect(w.vm.tone).toBe('warn');
        expect(w.vm.label).toBe('Contrôle périmé');
        expect(w.find('.pos-health-pill-retry').exists()).toBe(true);
    });

    it('un contrôle backend unknown dégrade la pastille sans fausse alerte fiscale', async () => {
        const w = await mountWith(health(
            'degraded',
            { status: 'unknown', message: 'Contrôle temps réel indisponible.' },
            { status: 'unknown', message: 'Contrôle fiscal indisponible.' },
        ));
        expect(w.vm.tone).toBe('warn');
        expect(w.vm.label).toBe('Contrôle dégradé');
        expect(w.vm.fiscalAlert).toBe(false);
        expect(w.vm.detailText).toContain('indisponibles');
        expect(w.vm.detailText).toContain('Contrôle fiscal indisponible.');
        expect(w.get('[data-testid="pos-health-unknown-message"]').text()).toContain('Contrôle fiscal indisponible.');
        expect(w.get('button[aria-label="Relancer le contrôle de santé de la caisse"]').text()).toBe('Réessayer');
    });

    it('rend visibles tous les messages unknown non-sync et garde le contrôle actionnable', async () => {
        const w = await mountWith(health(
            'degraded',
            { status: 'ok', message: 'Les commandes arrivent en direct.' },
            { status: 'unknown', message: 'Fiscal indisponible.' },
            { status: 'unknown', count: null, message: 'Stock indisponible.' },
            { status: 'unknown', count: null, message: 'Commandes indisponibles.' },
        ));

        const visible = w.get('[data-testid="pos-health-unknown-message"]').text();
        expect(visible).toContain('Fiscal indisponible.');
        expect(visible).toContain('Stock indisponible.');
        expect(visible).toContain('Commandes indisponibles.');
        expect(w.vm.detailText).toContain('Fiscal indisponible.');
        expect(w.vm.detailText).toContain('Stock indisponible.');
        expect(w.vm.detailText).toContain('Commandes indisponibles.');
        expect(w.find('.pos-health-pill-retry').exists()).toBe(true);
    });

    it('désactive le pulse sous prefers-reduced-motion', () => {
        const source = fs.readFileSync(path.resolve('resources/js/components/admin/pos/PosSystemHealthPill.vue'), 'utf8');
        expect(source).toContain('@media (prefers-reduced-motion: reduce)');
        expect(source).toContain('animation: none !important');
    });
});
