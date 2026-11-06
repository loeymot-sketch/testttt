/**
 * [GOAL CAISSE CONTRÔLE 2026-09-02] La file de la cuisine, vue depuis la caisse.
 *
 * POURQUOI CE MODULE EXISTE
 * -------------------------
 * Demande du propriétaire, mot pour mot : « son numéro, elle est numéro combien par rapport à la
 * cuisine, parce qu'il y a combien d'attente, que je peux l'avoir directement depuis la caisse ».
 * Aucune surface ne répondait, et surtout : le tableau de suivi ne POUVAIT pas y répondre.
 *
 * Il range toute commande à encaisser dans la voie « À encaisser » quel que soit son statut cuisine
 * (`PosOrdersTrackerComponent.vue:1384`, décision S2 F1 assumée : « le signal argent prime sur le
 * statut cuisine »). Conséquence mesurée en audit : la colonne annonçait « EN PRÉPARATION 1 »
 * pendant que QUATRE commandes cuisaient. Recopier ce bucket pour en tirer un rang aurait donc
 * produit un rang faux — et un rang faux est pire qu'aucun rang : il contamine toutes les cartes
 * derrière (la 4ᵉ se croit 2ᵉ) et il le fait en silence.
 *
 * Le rang est donc le MIROIR STRICT de la règle serveur qui décide ce que le chef a sous les yeux :
 *
 *   App\Domain\Kds\KitchenReleaseRule::itemBoardStatuses()   → ACCEPT + PREPARING
 *   App\Domain\Kds\KitchenReleaseRule::isReleasedForBoard()  → PAID | PENDING_COUNTER | (POS + CASH)
 *   App\Domain\Kds\KitchenReleaseRule::applyBoardReleaseFilter() → exclut REFUNDED
 *
 * Le point qui décide de tout : `isReleasedForBoard()` admet explicitement PENDING_COUNTER, et son
 * commentaire (`KitchenReleaseRule.php:87-91`) dit pourquoi — « the Plan B kiosk→counter encashment
 * flow, where the kitchen starts preparing while the customer pays at the till ». Une commande borne
 * en attente d'encaissement CUIT VRAIMENT. Elle appartient donc aux deux files, et les compteurs du
 * tiroir ne s'additionnent jamais (raison pour laquelle le tiroir n'affiche aucun total agrégé).
 *
 * `tests/js/fileCuisineModule.spec.js` verrouille ce miroir. Si la règle serveur bouge, il rougit.
 */

// [GOAL G1 · V-13 2026-09-03] Ces sept valeurs étaient RECOPIÉES à la main ici, avec pour
// justification que ce module est le miroir d'une règle SERVEUR. L'intention était juste, le
// moyen ne l'était pas : une recopie ne suit rien, elle fige. Changer `App\Enums\OrderStatus`
// n'aurait fait rougir AUCUN banc, et le rang cuisine annoncé au client au comptoir
// (« vous êtes le 4ᵉ ») serait devenu faux en silence.
// Le miroir est maintenant VÉRIFIÉ, pas recopié : les valeurs viennent des enums JS canoniques,
// et `tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` les épingle sur les
// interfaces PHP — un écart d'un seul nombre fait rougir avant d'atteindre un écran.
import orderStatusEnum from '../enums/modules/orderStatusEnum';
import paymentStatusEnum from '../enums/modules/paymentStatusEnum';
import orderTypeEnum from '../enums/modules/orderTypeEnum';
import posPaymentMethodEnum from '../enums/modules/posPaymentMethodEnum';

function entier(valeur) {
    const n = parseInt(valeur, 10);
    return Number.isFinite(n) ? n : null;
}

function statutDe(commande) {
    return entier(commande?.status ?? commande?.order_status);
}

/**
 * Miroir de `KitchenReleaseRule::isReleasedForBoard()` — dimension PAIEMENT uniquement.
 * Le statut est filtré séparément, exactement comme côté serveur.
 */
export function libereePourLeTableau(commande) {
    if (!commande) return false;
    const paiement = entier(commande.payment_status);
    if (paiement === paymentStatusEnum.PAID || paiement === paymentStatusEnum.PENDING_COUNTER) {
        return true;
    }
    return entier(commande.order_type) === orderTypeEnum.POS
        && entier(commande.pos_payment_method) === posPaymentMethodEnum.CASH;
}

/**
 * Une commande est « en cuisine » si le chef l'a sur son tableau par article :
 * statut ACCEPT ou PREPARING, paiement libéré, et non remboursée.
 *
 * ACCEPT compte. C'est délibéré et c'est la règle serveur (`itemBoardStatuses()`), pas une
 * approximation : une commande à ACCEPT est déjà affichée au chef, donc déjà devant vous.
 */
export function estEnCuisine(commande) {
    if (!commande) return false;
    const statut = statutDe(commande);
    if (statut !== orderStatusEnum.ACCEPT && statut !== orderStatusEnum.PREPARING) return false;
    if (entier(commande.payment_status) === paymentStatusEnum.REFUNDED) return false;
    return libereePourLeTableau(commande);
}

function horodatage(commande) {
    const brut = commande?.created_at ?? commande?.order_datetime ?? null;
    const t = brut ? Date.parse(brut) : NaN;
    return Number.isFinite(t) ? t : 0;
}

/**
 * La file, dans l'ordre où la cuisine les prépare : plus ancienne d'abord.
 * Départage par identifiant à horodatage égal, pour que le rang soit STABLE d'un rendu à l'autre
 * (un rang qui saute entre deux rafraîchissements de 5 s serait illisible en coup de feu).
 * Ne modifie jamais le tableau reçu.
 */
export function fileCuisine(commandes) {
    if (!Array.isArray(commandes)) return [];
    return commandes
        .filter((o) => estEnCuisine(o))
        .slice()
        .sort((a, b) => {
            const d = horodatage(a) - horodatage(b);
            if (d !== 0) return d;
            return String(a?.id ?? '').localeCompare(String(b?.id ?? ''), 'fr', { numeric: true });
        });
}

/**
 * « 2ᵉ sur 4 » : le rang ET la profondeur. Jamais une durée.
 * Le total est donné avec le rang parce que c'est lui qui rend l'information vérifiable par le
 * caissier (il recoupe avec le compteur de l'onglet 🍳) et actionnable à l'oral.
 *
 * @returns {{rang: number, total: number}|null} null si la commande n'est pas en cuisine.
 */
export function rangCuisine(commande, commandes) {
    if (!estEnCuisine(commande)) return null;
    const file = fileCuisine(commandes);
    const index = file.findIndex((o) => o === commande || (o?.id != null && o.id === commande.id));
    if (index < 0) return null;
    return { rang: index + 1, total: file.length };
}

/**
 * Les trois faits affichés sur le ticket en cours (§B9 de la revue adverse) :
 * combien cuisent, depuis quand cuit la plus ancienne, et quel rang aura la commande en cours de
 * saisie. Trois mesures, zéro prévision : ce dépôt n'a aucun modèle de débit cuisine, et annoncer
 * « ≈ 14 min » au client parce qu'une AUTRE commande attend depuis 14 min est un mensonge
 * commercial (rejet C1). Le caissier compose lui-même la phrase orale — il connaît son coup de feu.
 */
export function attenteCuisine(commandes, maintenant = Date.now()) {
    const file = fileCuisine(commandes);
    const plusAncienne = file.length ? horodatage(file[0]) : 0;
    const minutes = plusAncienne
        ? Math.floor(Math.max(0, maintenant - plusAncienne) / 60000)
        : 0;
    return {
        total: file.length,
        plusAncienneMinutes: minutes,
        prochainRang: file.length + 1,
    };
}
