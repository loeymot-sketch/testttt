/**
 * [GOAL REMARQUES 2026-10-03 · R-048] Impression du ticket CLIENT d'une commande via le pont ESC/POS de
 * la caisse — même chemin que la caisse (PosComponent.printAEncaisserTicket) : le serveur rend le ticket
 * (SSOT NF525, aucun compteur fiscal incrémenté), le pont local l'envoie à l'imprimante.
 *
 * @param {number|string} orderId
 * @returns {Promise<{ok: boolean, raison?: 'indisponible'|'pont'|'erreur'}>}
 */
import axios from 'axios';
import { printEscPosViaCaisseBridge } from './posLocalPrinter';

export async function imprimerTicketClient(orderId) {
    try {
        const { data } = await axios.get(`admin/pos/orders/${orderId}/escpos`, { params: { ticket: 'client' } });
        const b64 = data && data.escpos_b64;
        if (!b64) return { ok: false, raison: 'indisponible' };
        const r = await printEscPosViaCaisseBridge(b64, { orderRef: orderId });
        return r && r.ok === false ? { ok: false, raison: 'pont' } : { ok: true };
    } catch (_e) {
        return { ok: false, raison: 'erreur' };
    }
}
