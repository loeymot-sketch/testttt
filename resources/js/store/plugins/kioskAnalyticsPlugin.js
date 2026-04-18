/**
 * kioskAnalyticsPlugin.js — Vuex plugin pour l'instrumentation analytics kiosk.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 6.4.
 *
 * Rôle :
 *  - S'abonne aux mutations Vuex qui comptent pour l'analytics kiosk.
 *  - Transforme chaque mutation pertinente en un `kioskAnalytics.track(...)`
 *    avec un payload anonyme (item_id, quantity, category_id — JAMAIS de PII).
 *  - Laisse `kioskAnalytics` s'occuper du consent gate, de la whitelist et
 *    du transport (sendBeacon / axios / fetch keepalive + queue FIFO).
 *
 * Invariants (§1 master prompt) :
 *  - Aucun calcul de statistique ici. On envoie uniquement des events discrets.
 *  - Aucune PII. Les mutations `kioskCart/SET_LOYALTY_CUSTOMER` sont ignorées
 *    — le nom / téléphone / email ne doivent JAMAIS quitter la borne via ce
 *    canal (seul `/loyalty/register` et `/loyalty/opt-in` le font, après consent).
 *  - Silent sur toutes les erreurs — le plugin ne doit JAMAIS faire planter
 *    un dispatch Vuex.
 *
 * Extension :
 *  - Ajouter un nouvel event = ajouter une branche dans `mutationHandlers` ci-dessous.
 *    Le nom d'event DOIT appartenir à `ALLOWED_EVENTS` de `kioskAnalytics.js`
 *    ET à `ALLOWED_ANALYTICS_EVENTS` de `KioskEventController.php`.
 */

import kioskAnalytics from '../../helpers/kioskAnalytics';

/**
 * Handler par mutation — retourne `null` pour ignorer l'event, ou
 * `{eventName, payload}` à émettre.
 *
 * Note : `state` passé en 2e arg est post-mutation.
 */
const mutationHandlers = {
    /**
     * [kiosk] Ajout d'un item au panier (ADD_ITEM mute state.items).
     * L'item lui-même est dans mutation.payload — on ne transmet que des IDs
     * et quantités (pas de nom en clair, encore moins de PII).
     */
    'kioskCart/ADD_ITEM': (mutation /*, state */) => {
        const item = mutation.payload || {};
        return {
            eventName: 'add_to_cart',
            payload: {
                item_id: item.item_id || null,
                quantity: Number(item.quantity || 1),
                variations_count: Array.isArray(item.item_variations) ? item.item_variations.length : 0,
                extras_count: Array.isArray(item.item_extras) ? item.item_extras.length : 0,
            },
        };
    },

    'kioskCart/REMOVE_ITEM': (mutation, state) => {
        const idx = Number(mutation.payload);
        // Après REMOVE_ITEM, le state.items a été muté — on ne peut pas retrouver
        // l'item exact supprimé. On émet juste le signal (le backend tient le compte).
        return {
            eventName: 'remove_from_cart',
            payload: {
                remaining_items: state?.kioskCart?.items?.length ?? 0,
                removed_index: Number.isFinite(idx) ? idx : null,
            },
        };
    },

    /**
     * [kiosk] Changement de quantité. `mutation.payload` = { index, quantity }.
     */
    'kioskCart/UPDATE_QUANTITY': (mutation /*, state */) => {
        const p = mutation.payload || {};
        return {
            eventName: 'quantity_changed',
            payload: {
                quantity: Number(p.quantity || 0),
            },
        };
    },

    /**
     * [kiosk] Reset panier (soit idle reset, soit clear manuel).
     * On émet order_cancelled si des items étaient présents (abandonment signal).
     */
    'kioskCart/RESET': (mutation, state) => {
        // `items` déjà vidés par la mutation — on ne peut pas savoir s'il y avait
        // du contenu avant. Ce handler est conservé pour symétrie mais n'émet rien.
        return null;
    },

    // NOTE: `upsell_shown` est émis directement dans `KioskUpsellComponent.loadSuggestions`
    // (pas via mutation Vuex — pas de mutation dédiée dans kioskCart). Voir Phase 6.4.
};

export default function kioskAnalyticsPlugin(store) {
    store.subscribe((mutation, state) => {
        try {
            const handler = mutationHandlers[mutation.type];
            if (!handler) return;
            const result = handler(mutation, state);
            if (!result || !result.eventName) return;
            kioskAnalytics.track(result.eventName, result.payload || {});
        } catch (_) {
            // Jamais d'erreur remontée au store — silencieux.
        }
    });
}
