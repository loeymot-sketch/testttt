import orderTypeEnum from '../enums/modules/orderTypeEnum';
import posPaymentMethodEnum from '../enums/modules/posPaymentMethodEnum';

/**
 * [AUDIT-SUPERVISEUR 2026-08-25 · C-017] LE facteur commun des libellés d'énumération
 * d'une commande.
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * Le même tableau de correspondance était recopié à la main dans huit composants.
 * Chaque copie oubliait des valeurs, et pas les mêmes :
 *   · la FICHE ignorait POS (15) et KIOSK (25) — les deux SEULS types du parc V1,
 *     donc « Type de commande: » était vide sur 100 % des commandes ;
 *   · les modes de paiement ignoraient TICKET_RESTAURANT (5) et COUNTER_DEFERRED (6),
 *     ce dernier étant le mode de TOUTE commande borne en attente d'encaissement ;
 *   · la FACTURE REMISE AU CLIENT portait les deux lacunes à la fois — elle a été
 *     corrigée en QUATRIÈME, après trois passages qui avaient réparé les copies
 *     voisines sans la voir.
 *
 * Trois audits successifs ont trouvé « un libellé qui survit à sa valeur » sur ces
 * écrans. La cause n'était pas l'inattention : c'était la duplication. On ne corrige
 * pas une cinquième copie, on supprime la raison qu'il y en ait une.
 *
 * COMMENT S'EN SERVIR
 * -------------------
 *   import { orderTypeLabels, posPaymentMethodLabels } from '@/helpers/orderEnumLabels';
 *   ...
 *   data() { return { orderTypeEnumArray: orderTypeLabels(this.$t) }; }
 *
 * On passe `$t` plutôt que d'importer i18n : le composant reste maître de sa locale,
 * et la fonction reste testable sans monter d'application.
 *
 * RÈGLE — toute valeur ajoutée à `orderTypeEnum` ou `posPaymentMethodEnum` DOIT
 * apparaître ici. Les sentinelles `tests/js/orderEnumLabels.spec.js` balaient
 * l'énumération entière et rougissent sur la moindre valeur non nommée : une
 * nouvelle valeur ne peut plus atteindre un écran sans libellé.
 */

/**
 * Libellés des types de commande, pour TOUTES les valeurs de l'énumération.
 *
 * @param {(cle: string) => string} t la fonction de traduction du composant ($t)
 * @returns {Record<number, string>}
 */
export function orderTypeLabels(t) {
    return {
        [orderTypeEnum.DELIVERY]: t('label.delivery'),
        [orderTypeEnum.TAKEAWAY]: t('label.takeaway'),
        [orderTypeEnum.POS]: t('label.pos'),
        [orderTypeEnum.DINING_TABLE]: t('label.dining_table'),
        [orderTypeEnum.KIOSK]: t('label.kiosk'),
    };
}

/**
 * Libellés des modes de paiement au comptoir, pour TOUTES les valeurs.
 *
 * `COUNTER_DEFERRED` mérite un mot : ce n'est pas un moyen de paiement mais un ÉTAT —
 * la commande est enregistrée, l'argent n'est pas encore pris. Le libellé dit donc ce
 * qu'il reste à faire (« À encaisser »), pas un instrument qui n'existe pas.
 *
 * @param {(cle: string) => string} t
 * @returns {Record<number, string>}
 */
export function posPaymentMethodLabels(t) {
    return {
        [posPaymentMethodEnum.CASH]: t('label.cash'),
        [posPaymentMethodEnum.CARD]: t('label.card'),
        [posPaymentMethodEnum.MOBILE_BANKING]: t('label.mobile_banking'),
        [posPaymentMethodEnum.OTHER]: t('label.other'),
        [posPaymentMethodEnum.TICKET_RESTAURANT]: t('label.ticket_restaurant'),
        // `label.pending_counter` existe déjà et dit « À encaisser » : on réutilise
        // plutôt que d'ajouter une deuxième clé disant la même chose — c'est la
        // duplication qui a créé ce défaut, on ne la recommence pas dans le catalogue.
        [posPaymentMethodEnum.COUNTER_DEFERRED]: t('label.pending_counter'),
    };
}
