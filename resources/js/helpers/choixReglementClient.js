// [RÈGLEMENT CLIENT 2026-11-15 · propriétaire] « Le client choisit carte bancaire à la livraison sur le site, et ma
// caisse affiche toujours "à régler en espèces". » Le choix ARRIVE bien au serveur (`orders.door_payment_mode='card'`,
// posé par FrontendOrderService depuis `door_payment=card`) ; ce sont les écrans de caisse qui ne le lisaient pas : la
// fenêtre « Encaisser » repartait toujours sur Espèces, la carte de la commande et la fiche « commande en ligne » ne
// disaient pas le moyen choisi.
//
// Une seule vérité, partagée avec le ticket imprimé (App\Support\CoordonneesClientTicket::reglementALaPorte), le livreur
// (DeliveryHandoverService) et le site (funnel.jsx lcfReglementLivraison) :
//   - déjà payée, ou paiement en ligne (4 carte en ligne…) → rien à annoncer (null) ;
//   - 5 = titre-restaurant ;
//   - livraison réglée à la remise (1) : `door_payment_mode='card'` = carte bancaire, sinon espèces — SAUF une livraison
//     prise par téléphone à la caisse (source POS = 15) : le caissier n'a pas noté de moyen, on ne l'invente pas ;
//   - à emporter réglée au comptoir : le client n'a pas choisi de moyen sur le site → rien à annoncer.
// Les libellés sont écrits EN TOUTES LETTRES (jamais « ESP » / « CB »).

const PAYEE = 5; // paymentStatusEnum.PAID
const LIVRAISON = 5; // orderTypeEnum.DELIVERY
const A_LA_REMISE = 1; // PaymentGateway::CASH_ON_DELIVERY
const TITRE_RESTAURANT = 5; // PaymentGateway::TICKET_RESTAURANT
const SOURCE_CAISSE = 15; // sourceEnum.POS

export function choixReglementClient(order) {
    if (!order) return null;
    if (Number(order.payment_status) === PAYEE) return null;
    const methode = Number(order.payment_method);
    if (methode === TITRE_RESTAURANT) {
        return { mode: 'TICKET', icone: '🎟️', libelle: 'Titre-restaurant' };
    }
    if (methode !== A_LA_REMISE || Number(order.order_type) !== LIVRAISON) return null;
    const surface = String(order.source_surface || '');
    if (surface.startsWith('uber')) return null;
    // Champ absent (ressource qui ne le transmet pas) : on ne SAIT pas — ne jamais supposer « espèces ».
    if (!Object.prototype.hasOwnProperty.call(order, 'door_payment_mode')) {
        return { mode: null, icone: '🛵', libelle: 'À régler à la livraison' };
    }
    if (String(order.door_payment_mode || '') === 'card') {
        return { mode: 'CARD', icone: '💳', libelle: 'Carte bancaire à la livraison' };
    }
    if (Number(order.source) === SOURCE_CAISSE) {
        return { mode: null, icone: '🛵', libelle: 'Au livreur (espèces ou carte)' };
    }
    return { mode: 'CASH', icone: '💶', libelle: 'Espèces à la livraison' };
}
