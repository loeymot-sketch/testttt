import { describe, it, expect, vi } from 'vitest';
import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

/**
 * [Root cause 2026-09-21, owner : « reprends la caisse : demande imprimer ou non après
 * paiement »] Avant ce correctif, le ticket client ne s'imprimait déjà jamais AUTOMATIQUEMENT
 * (RECEIPT-NO-AUTO 2026-07-24), mais le choix restait IMPLICITE : le caissier devait remarquer
 * de lui-même le bouton « Ticket client » dans la barre d'outils. Le propriétaire veut une VRAIE
 * question posée après CHAQUE encaissement frais (carte ET espèces — le composant ne distingue
 * pas le moyen de paiement), avant de continuer.
 *
 * showPrintDecisionPrompt (computed) + choosePrintClient/choosePrintDecline (methods) implémentent
 * cette question. Portée : uniquement le ticket CLIENT, uniquement un encaissement FRAIS
 * (clearCartOnClose=true, jamais un re-print du tracker), une seule fois par commande.
 */
const showPrompt = ReceiptComponent.computed.showPrintDecisionPrompt;
const choosePrintClient = ReceiptComponent.methods.choosePrintClient;
const choosePrintDecline = ReceiptComponent.methods.choosePrintDecline;

function ctx(overrides = {}) {
  return {
    order: { id: 7001 },
    clearCartOnClose: true,
    autoPrintClientReceipt: false,
    printDecisionAnsweredForOrderId: null,
    handlePrintClientClick: vi.fn(),
    ...overrides,
  };
}

describe('ReceiptComponent — question explicite « imprimer ou non » après un encaissement frais', () => {
  it('encaissement frais, jamais répondu : la question doit être posée', () => {
    expect(showPrompt.call(ctx())).toBe(true);
  });

  it('carte ET espèces : le composant ne distingue pas le moyen de paiement, la question est posée dans les deux cas', () => {
    // posPaymentMethod n'entre dans aucune condition de showPrintDecisionPrompt — vérifié
    // en passant explicitement les deux valeurs, la réponse doit être identique (true).
    expect(showPrompt.call(ctx({ order: { id: 1, pos_payment_method: 1 } }))).toBe(true); // CASH
    expect(showPrompt.call(ctx({ order: { id: 2, pos_payment_method: 2 } }))).toBe(true); // CARD
  });

  it('re-print depuis le tracker (clearCartOnClose=false) : ne redemande PAS', () => {
    expect(showPrompt.call(ctx({ clearCartOnClose: false }))).toBe(false);
  });

  it('déjà répondu pour CETTE commande : ne redemande pas une 2e fois', () => {
    expect(showPrompt.call(ctx({ printDecisionAnsweredForOrderId: 7001 }))).toBe(false);
  });

  it('nouvelle commande après une réponse précédente : redemande (id différent)', () => {
    expect(showPrompt.call(ctx({ printDecisionAnsweredForOrderId: 6999 }))).toBe(true);
  });

  it('auto-print déjà actif (flag legacy ON) : ne pose pas une question redondante', () => {
    expect(showPrompt.call(ctx({ autoPrintClientReceipt: true }))).toBe(false);
  });

  it('pas de commande : rien à demander', () => {
    expect(showPrompt.call(ctx({ order: null }))).toBe(false);
  });

  it('choisir "Oui, imprimer" : imprime ET marque la commande comme répondue', () => {
    const c = ctx();
    choosePrintClient.call(c);
    expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    expect(c.printDecisionAnsweredForOrderId).toBe(7001);
    expect(showPrompt.call(c)).toBe(false);
  });

  it('choisir "Non merci" : N\'imprime PAS, marque quand même la commande comme répondue', () => {
    const c = ctx();
    choosePrintDecline.call(c);
    expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    expect(c.printDecisionAnsweredForOrderId).toBe(7001);
    expect(showPrompt.call(c)).toBe(false);
  });
});
