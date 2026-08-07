import axios from "axios";

/**
 * [FLYER PROMO UBER 2026-08-07] Ticket promotionnel nominatif.
 *
 * Module volontairement NON namespacé, comme `auth` : les écrans appellent
 * `dispatch('promoFlyerCreate')` sans préfixe, cohérent avec le reste du store.
 *
 * Aucun état n'est conservé ici. Un ticket est un ordre ponctuel : le garder en
 * mémoire ferait afficher un ticket déjà imprimé comme encore en attente après
 * un changement d'écran.
 */
export const promoFlyer = {
    state: {},
    getters: {},
    mutations: {},
    actions: {
        promoFlyerSettings() {
            return axios.get("admin/promo-flyer/settings").then((res) => res.data);
        },
        promoFlyerSaveSettings(context, payload) {
            return axios.patch("admin/promo-flyer/settings", payload).then((res) => res.data);
        },
        promoFlyerList() {
            return axios.get("admin/promo-flyer").then((res) => res.data);
        },
        promoFlyerCreate(context, payload) {
            return axios.post("admin/promo-flyer", payload).then((res) => res.data);
        },
    },
};
