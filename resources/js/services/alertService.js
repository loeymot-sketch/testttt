import {useToast} from "vue-toastification";
import i18n from "../i18n";
/*
 * Position
 * --------------
 * top-right
 * top-center
 * top-left
 * bottom-right
 * bottom-center
 * bottom-left
 * */
export default {
    default: function (message = "Default", position = "top-right") {
        const toast = useToast();
        toast(message, {
            position: position,
        });
    },

    success: function (message = "Success", position = "top-right") {
        const toast = useToast();
        toast.success(message, {
            position: position,
        });
    },

    info: function (message = "Info", position = "top-right") {
        const toast = useToast();
        toast.info(message, {
            position: position,
        });
    },

    warning: function (message = "Warning", position = "top-right") {
        const toast = useToast();
        toast.warning(message, {
            position: position,
        });
    },

    error: function (message = "Error", position = "top-right") {
        const toast = useToast();
        toast.error(message, {
            position: position,
        });
    },

    /**
     * [ONB-02 2026-08-28] Bulle de confirmation apres creation, modification ou
     * suppression.
     *
     * Les trois phrases etaient ECRITES EN ANGLAIS, en dur, et collees a un nom
     * traduit. Un restaurateur francais lisait donc « Categories Deleted
     * Successfully. » a chacun des 120 appels de cette fonction, repartis dans 109
     * ecrans. C'etait la fuite d'anglais la plus repandue de tout le back-office —
     * et elle etait invisible aux sentinelles de traduction, qui balaient les
     * fichiers de langue et les composants, jamais les services.
     *
     * On traduit ICI plutot que sur chaque appelant : un seul endroit a corriger,
     * et aucun des 120 sites n'a besoin de changer.
     *
     * Formulation choisie sans accord de genre ni de nombre (« Categories :
     * suppression effectuee. ») parce que le nom qui precede est fourni par
     * l'appelant : « Article », « Categories », « Taxe »... Aucune terminaison ne
     * peut convenir aux trois.
     *
     * ⚠️ PIEGE DE SIGNATURE, conserve tel quel pour ne pas toucher 120 appels :
     *   status === true  -> modification
     *   status === false -> creation
     *   status === null  -> suppression
     * Un `null` passe par erreur sur un chemin de CREATION annonce donc une
     * suppression. C'est exactement ce qui se produisait dans le Studio catalogue.
     */
    successFlip: function (status = null, message = "", position = "top-right") {
        const toast = useToast();
        const t = i18n.global.t;

        if (status != null) {
            message = status
                ? t("message.flip_updated", {item: message})
                : t("message.flip_created", {item: message});
        } else {
            message = t("message.flip_deleted", {item: message});
        }

        toast.success(message, {
            position: position,
        });
    },

    successInfo: function (status = null, message = "", position = "top-right") {
        const toast = useToast();
        toast.success(message, {
            position: position,
        });
    },
};
