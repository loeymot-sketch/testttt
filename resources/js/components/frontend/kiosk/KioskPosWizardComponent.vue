<template>
  <!--
    [CV1-LOT-K07] POS-kiosk wizard convergence.
    Compatibility wrapper only: staff/POS kiosk entry points delegate to
    KioskWizardComponent so wizard state and pricing preview keep one path.
  -->
  <KioskWizardComponent v-bind="$attrs" />
</template>

<script>
import KioskWizardComponent from "./KioskWizardComponent.vue";

export default {
    name: "KioskPosWizardComponent",
    inheritAttrs: false,
    components: { KioskWizardComponent },
};
</script>
