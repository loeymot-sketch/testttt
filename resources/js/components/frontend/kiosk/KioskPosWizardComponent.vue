<template>
  <!--
    [STAFF-ONLY-V1][V4] Wrapper POS-wizard pour la borne.
    
    Pour l'instant : délègue à KioskWizardComponent (implementation V1 stable).
    V4.1 (session dédiée) : remplacera par un vrai bridge pos-wizard.js
    ou une réécriture Vue partagée POS/Kiosk avec :
      - Layout single-page (pas de stepper)
      - Emojis sur viandes (🥩 Merguez, 🌶️ Kefta, 🔵 Cordon Bleu, ...)
      - Counters +/- par viande (et non radio)
      - Crudités pré-sélectionnées (🥬✓ Salade, 🍅✓ Tomate, 🧅✓ Oignon) retirables au tap
      - Sauce 1ère gratuite + suppléments payants (POS_WIZARD_CONFIG)
      - Section "Formule" (Menu / Frites Seules / Boisson Seule / Sans)
      - Instruction spéciale
      - Aperçu ticket en temps réel
    
    Le code source de référence : public/js/pos-wizard.js (5763 lignes)
  -->
  <KioskWizardComponent v-bind="$attrs" />
</template>

<script>
import KioskWizardComponent from "./KioskWizardComponent.vue";

export default {
    name: "KioskPosWizardComponent",
    components: { KioskWizardComponent },
    mounted() {
        // [V4] Trace diagnostique : confirme que le flag feature est bien actif côté client.
        if (window.foodkingConfig && window.foodkingConfig.kioskUsePosWizard) {
            // eslint-disable-next-line no-console
            console.info('[Kiosk V4] POS-wizard flag ON — using wrapper (V4.1 will unify with pos-wizard.js).');
        }
    },
};
</script>
