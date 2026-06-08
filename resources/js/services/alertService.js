import {useToast} from "vue-toastification";
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

    successFlip: function (status = null, message = "", position = "top-right") {
        const toast = useToast();
        // [DASH-UIUX-2026-06-08 P2] V1 LOCAL is FR-only (ADR-007). This previously appended a
        // hardcoded ENGLISH suffix (" Updated Successfully.") to an already-translated FR label
        // (callers pass this.$t(...)), so every CRUD toast across the 102 admin components read
        // half-English — e.g. "Entreprise Updated Successfully." — which made the admin feel
        // broken. Append a clean French confirmation instead. The noun-based "réussie" forms
        // are feminine-agreeing and read correctly regardless of the label's gender.
        let suffix;
        if (status != null) {
            suffix = status ? "mise à jour réussie." : "création réussie.";
        } else {
            suffix = "suppression réussie.";
        }
        message = (message ? message + " : " : "") + suffix;

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
