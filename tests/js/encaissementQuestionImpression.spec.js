import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({ printEscPosViaCaisseBridge: vi.fn(() => Promise.resolve({ ok: true })) }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

import axios from 'axios';
import { printEscPosViaCaisseBridge } from '../../resources/js/helpers/posLocalPrinter';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import PosQuestionImpressionTicket from '../../resources/js/components/admin/pos/PosQuestionImpressionTicket.vue';

// [GOAL REMARQUES 2026-10-03 · T-2.4 R-048] Propriétaire : « je veux pas que ça imprime le ticket par
// défaut je veux vraiment garder ça dire le choix d'imprimer ou ne pas imprimer » (17/09) ; « quand je
// passerai une commande par téléphone ou quand j'arrive à l'encaisser […] je veux que ça soit optionnel »
// (24/09). La caisse posait la question ; la page Encaissement et le Suivi n'imprimaient rien et ne
// demandaient rien — et leur bouton promettait « Confirmer & Imprimer ».
const langue = (code) => JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../resources/js/languages/${code}.json`), 'utf8'));

describe('Encaissement / Suivi : la question « Imprimer le ticket client ? » après chaque encaissement', () => {
    beforeEach(() => { vi.clearAllMocks(); axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } }); });

    it('page Encaissement : la question est posée, rien ne s\'imprime tout seul', async () => {
        const ctx = { autoPrintClientReceipt: false, encaisseOrder: { id: 99 }, $t: (k) => k, fetchPending: vi.fn(), questionImpressionOrderId: null };
        await EncaissementComponent.methods.onEncaisseConfirmed.call(ctx, { orderId: 99 });
        expect(ctx.questionImpressionOrderId).toBe(99);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('Suivi des commandes : la question est posée aussi', () => {
        const ctx = { encaisseOrder: { id: 77 }, fetchOrders: vi.fn(), _olderPendingFetchedAt: 1, questionImpressionOrderId: null };
        PosOrdersTrackerComponent.methods.onEncaisseConfirmed.call(ctx, { orderId: 77 });
        expect(ctx.questionImpressionOrderId).toBe(77);
        expect(ctx.fetchOrders).toHaveBeenCalled();
    });
});

describe('PosQuestionImpressionTicket', () => {
    beforeEach(() => { vi.clearAllMocks(); axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } }); });
    const monter = () => mount(PosQuestionImpressionTicket, { props: { orderId: 5 }, global: { mocks: { $t: (k) => k } } });

    it('« Oui, imprimer » imprime le ticket CLIENT de cette commande puis se ferme', async () => {
        const w = monter();
        await w.find('[data-testid="question-impression-oui"]').trigger('click');
        await flushPromises();
        expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/5/escpos', { params: { ticket: 'client' } });
        expect(printEscPosViaCaisseBridge).toHaveBeenCalledWith('QUJD', { orderRef: 5 });
        expect(w.emitted('fermer')).toHaveLength(1);
    });

    it('« Non merci » n\'imprime rien et se ferme', async () => {
        const w = monter();
        await w.find('[data-testid="question-impression-non"]').trigger('click');
        expect(axios.get).not.toHaveBeenCalled();
        expect(w.emitted('fermer')).toHaveLength(1);
    });
});

describe('[revue vague 2 · P2-2] un pont d\'impression éteint n\'est jamais annoncé comme un succès', () => {
    it('le pont rend null (réseau coupé, HTTP 500) → échec', async () => {
        const { imprimerTicketClient } = await import('../../resources/js/helpers/posImprimerTicketClient.js');
        axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
        printEscPosViaCaisseBridge.mockResolvedValueOnce(null);
        expect(await imprimerTicketClient(5)).toEqual({ ok: false, raison: 'pont' });
    });
});

describe('libellés honnêtes', () => {
    it('le bouton de la fenêtre d\'encaissement ne promet plus « Imprimer »', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosCounterCollectModal.vue'), 'utf8');
        expect(src).not.toContain("$t('button.confirm_and_print')");
        expect(src).toContain("$t('button.confirm_collect')");
    });

    it('les libellés existent en fr, en et ar (l\'arabe affichait la clé brute de la question)', () => {
        for (const code of ['fr', 'en', 'ar']) {
            const l = langue(code);
            expect(l.button.confirm_collect, `${code}.button.confirm_collect`).toBeTruthy();
            expect(l.pos.print_decision_title, `${code}.pos.print_decision_title`).toBeTruthy();
            expect(l.pos.print_decision_yes, `${code}.pos.print_decision_yes`).toBeTruthy();
            expect(l.pos.print_decision_no, `${code}.pos.print_decision_no`).toBeTruthy();
        }
    });
});
