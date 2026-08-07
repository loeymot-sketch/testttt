import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [FLYER PROMO 2026-08-07] L'imprimeur de tickets promotionnels.
//
// C'est la seule pièce qui fait réellement sortir le papier : le serveur ne
// peut pas joindre l'imprimante (mesuré), donc si ce composant se trompe,
// AUCUN ticket ne sort — ou pire, il en sort deux.
//
// Ce que ces tests verrouillent :
//   - il ne réclame RIEN là où le pont d'impression est absent (téléphone,
//     poste bureau) : sinon il consommerait des tentatives d'impression loin
//     de l'imprimante et les ferait échouer ;
//   - il accuse TOUJOURS réception, succès comme échec, sinon le ticket reste
//     verrouillé et l'exploitant ne sait pas que rien n'est sorti ;
//   - il ne lance jamais deux cycles en parallèle (impression lente = ordres
//     empilés = risque de double sortie) ;
//   - une erreur réseau reste SILENCIEUSE : ce composant tourne sur tous les
//     écrans admin, y compris pendant un encaissement.

// `vi.mock` est hissé en tête de fichier : les mocks doivent donc être créés
// DANS la fabrique, jamais dans une variable de portée supérieure.
vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
    isCaisseBridgeAvailable: vi.fn(),
    printEscPosViaCaisseBridge: vi.fn(),
}));

import axiosDefault from 'axios';
import * as printerModule from '../../resources/js/helpers/posLocalPrinter';

const axiosMock = axiosDefault;
const printerMock = printerModule;

import PromoFlyerPrintListener from '../../resources/js/components/admin/promo/PromoFlyerPrintListener.vue';

const mountListener = () => shallowMount(PromoFlyerPrintListener, {
    global: { mocks: { $t: (k) => k } },
});

const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

describe('PromoFlyerPrintListener — imprimeur des tickets promo', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axiosMock.post.mockResolvedValue({ data: { flyers: [] } });
        axiosMock.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
        printerMock.isCaisseBridgeAvailable.mockResolvedValue(true);
        printerMock.printEscPosViaCaisseBridge.mockResolvedValue({ ok: true, method: 'caisse-bridge' });
    });

    it('ne reclame AUCUN ticket quand le pont d\'impression est absent (telephone, poste bureau)', async () => {
        printerMock.isCaisseBridgeAvailable.mockResolvedValue(false);

        const w = mountListener();
        await w.vm._tick();
        await flush();

        expect(axiosMock.post).not.toHaveBeenCalled();
        expect(printerMock.printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    });

    it('imprime un ticket en attente puis accuse le SUCCES', async () => {
        axiosMock.post.mockImplementation((url) => {
            if (url.endsWith('/pending')) {
                return Promise.resolve({ data: { flyers: [{ id: 42, code: 'CAMILLE-7K2P' }] } });
            }
            return Promise.resolve({ data: {} });
        });

        const w = mountListener();
        await w.vm._tick();
        await flush();

        expect(axiosMock.get).toHaveBeenCalledWith('admin/promo-flyer/42/escpos');
        expect(printerMock.printEscPosViaCaisseBridge).toHaveBeenCalledWith('QUJD');

        const ack = axiosMock.post.mock.calls.find(c => c[0] === 'admin/promo-flyer/42/ack');
        expect(ack, 'aucun accuse de reception envoye').toBeTruthy();
        expect(ack[1].success).toBe(true);
    });

    it('accuse l\'ECHEC quand le pont refuse — sinon le ticket reste verrouille et personne ne le sait', async () => {
        axiosMock.post.mockImplementation((url) => {
            if (url.endsWith('/pending')) {
                return Promise.resolve({ data: { flyers: [{ id: 43, code: 'X-1234' }] } });
            }
            return Promise.resolve({ data: {} });
        });
        printerMock.printEscPosViaCaisseBridge.mockResolvedValue(null);

        const w = mountListener();
        await w.vm._tick();
        await flush();

        const ack = axiosMock.post.mock.calls.find(c => c[0] === 'admin/promo-flyer/43/ack');
        expect(ack).toBeTruthy();
        expect(ack[1].success).toBe(false);
        expect(ack[1].error).toBeTruthy();
    });

    it('accuse l\'ECHEC quand le serveur ne renvoie aucun contenu a imprimer', async () => {
        axiosMock.post.mockImplementation((url) => {
            if (url.endsWith('/pending')) {
                return Promise.resolve({ data: { flyers: [{ id: 44 }] } });
            }
            return Promise.resolve({ data: {} });
        });
        axiosMock.get.mockResolvedValue({ data: {} });

        const w = mountListener();
        await w.vm._tick();
        await flush();

        const ack = axiosMock.post.mock.calls.find(c => c[0] === 'admin/promo-flyer/44/ack');
        expect(ack).toBeTruthy();
        expect(ack[1].success).toBe(false);
        expect(printerMock.printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    });

    it('ne lance jamais deux cycles en parallele (impression lente = risque de double sortie)', async () => {
        let resolvePending;
        axiosMock.post.mockImplementation((url) => {
            if (url.endsWith('/pending')) {
                return new Promise((r) => { resolvePending = () => r({ data: { flyers: [] } }); });
            }
            return Promise.resolve({ data: {} });
        });

        const w = mountListener();
        w.vm._tick();            // cycle 1, bloque sur /pending
        await flush();
        await w.vm._tick();      // cycle 2 pendant que le 1 tourne
        await flush();

        const pendingCalls = axiosMock.post.mock.calls.filter(c => c[0] === 'admin/promo-flyer/pending');
        expect(pendingCalls).toHaveLength(1);

        resolvePending();
        await flush();
    });

    it('une erreur reseau reste SILENCIEUSE — ce composant tourne pendant les encaissements', async () => {
        axiosMock.post.mockRejectedValue(new Error('Network Error'));

        const w = mountListener();
        await expect(w.vm._tick()).resolves.toBeUndefined();
        await flush();
    });

    it('imprime plusieurs tickets en attente, chacun avec son accuse', async () => {
        axiosMock.post.mockImplementation((url) => {
            if (url.endsWith('/pending')) {
                return Promise.resolve({ data: { flyers: [{ id: 1 }, { id: 2 }] } });
            }
            return Promise.resolve({ data: {} });
        });

        const w = mountListener();
        await w.vm._tick();
        await flush();

        expect(printerMock.printEscPosViaCaisseBridge).toHaveBeenCalledTimes(2);
        expect(axiosMock.post.mock.calls.filter(c => /\/ack$/.test(c[0]))).toHaveLength(2);
    });
});
