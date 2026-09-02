/**
 * [GOAL CAISSE CONTRÔLE 2026-09-02] Les quatre files de la caisse — définition UNIQUE.
 *
 * POURQUOI CE MODULE EXISTE
 * -------------------------
 * Le tiroir de contrôle affiche quatre files ; le bouton qui l'ouvre en affiche les compteurs ;
 * le ticket en cours affiche la profondeur de la cuisine. Trois surfaces, une seule vérité — et
 * la manière la plus sûre de fabriquer un mensonge serait d'écrire le prédicat deux fois. C'est
 * exactement le défaut mesuré avant ce chantier : le badge « Suivi 3 » et le tableau « 7 actives »
 * s'affichaient à 40 px l'un de l'autre parce qu'ils ne comptaient pas la même chose.
 *
 * `tests/js/filesControleModule.spec.js` verrouille les quatre prédicats.
 */

import { fileCuisine } from './fileCuisine';

// Enums serveur (App\Enums\OrderStatus, App\Enums\PaymentStatus).
const STATUT_PREPARED = 8;
const STATUT_DELIVERED = 13;
const STATUT_CANCELED = 16;
const STATUT_REJECTED = 19;
const STATUT_RETURNED = 22;
const PAIEMENT_REFUNDED = 20;

function entier(v) {
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : null;
}

function statutDe(commande) {
    return entier(commande?.status ?? commande?.order_status);
}

/** Statut dont on ne revient pas : la commande a quitté le service. */
export function estTerminale(commande) {
    const s = statutDe(commande);
    return s === STATUT_DELIVERED || s === STATUT_CANCELED
        || s === STATUT_REJECTED || s === STATUT_RETURNED;
}

export function horodatage(commande) {
    const t = Date.parse(commande?.created_at ?? commande?.order_datetime ?? '');
    return Number.isFinite(t) ? t : 0;
}

/**
 * 💶 À ENCAISSER — l'argent qui n'est pas encore dans le tiroir-caisse.
 *
 * Le signal argent prime sur l'avancement cuisine : une commande borne « Plan B » CUIT pendant
 * que le client paie au comptoir, elle appartient donc aussi à la file cuisine (les deux
 * compteurs ne s'additionnent jamais, et c'est pour ça que le tiroir n'affiche aucun total).
 *
 * Mais JAMAIS un statut terminal : une commande annulée conserve `payment_status=PENDING_COUNTER`
 * en base — trente lignes constatées — alors que le serveur refuse de l'encaisser. L'afficher
 * serait promettre une action impossible.
 *
 * Tri : plus ancienne d'abord. C'est celle qui attend depuis le plus longtemps qui doit partir.
 */
export function fileEncaisser(commandes) {
    if (!Array.isArray(commandes)) return [];
    return commandes
        .filter((o) => o && o.is_cash_pending === true && !estTerminale(o))
        .slice()
        .sort((a, b) => horodatage(a) - horodatage(b));
}

/**
 * 🛎️ PRÊTES — un client debout attend son sac.
 *
 * TOUS les canaux. Le panneau « Prêt à livrer » de la caisse était nourri par le flux de l'écran
 * client, dont le service filtre sur BORNE + À EMPORTER : une commande COMPTOIR prête était donc
 * invisible de la caisse. Une commande prête est une commande prête.
 *
 * Le remboursement passerelle reste exclu : il garde souvent son statut cuisine, et le KDS l'a
 * déjà retirée (`KitchenReleaseRule::applyBoardReleaseFilter`).
 */
export function filePretes(commandes) {
    if (!Array.isArray(commandes)) return [];
    return commandes
        .filter((o) => statutDe(o) === STATUT_PREPARED && entier(o?.payment_status) !== PAIEMENT_REFUNDED)
        .slice()
        .sort((a, b) => horodatage(a) - horodatage(b));
}

/**
 * ✅ LIVRÉES — plus récente d'abord : on ouvre cette file pour vérifier ce qu'on VIENT de servir,
 * pas pour relire le début du service.
 */
export function fileLivrees(commandes) {
    if (!Array.isArray(commandes)) return [];
    return commandes
        .filter((o) => statutDe(o) === STATUT_DELIVERED)
        .slice()
        .sort((a, b) => horodatage(b) - horodatage(a));
}

/** Les quatre files d'un coup — ce que lisent le tiroir, son bouton et le ticket. */
export function filesDeControle(commandes) {
    return {
        encaisser: fileEncaisser(commandes),
        cuisine: fileCuisine(commandes),
        pretes: filePretes(commandes),
        livrees: fileLivrees(commandes),
    };
}
