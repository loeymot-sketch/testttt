<template>
    <div class="hy-measure">
        <button v-if="signed" type="button" class="hy-sign" :aria-pressed="modelValue.neg ? 'true' : 'false'"
                :aria-label="modelValue.neg ? 'Valeur négative' : 'Valeur positive'" :data-testid="`${testid}-sign`" @click="set({ neg: !modelValue.neg })">
            {{ modelValue.neg ? "−" : "+" }}
        </button>
        <input :value="modelValue.raw" type="text" inputmode="decimal" autocomplete="off" class="hy-measure-input" placeholder="0,0"
               :aria-label="label" :data-testid="`${testid}-input`" @input="onInput($event)" @focus="$event.target.select()" />
        <span class="hy-measure-unit">{{ unit }}</span>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17] Saisie d'une mesure au clavier numérique (jamais préremplie : valider sans mesurer n'est pas un relevé).
 * Le signe est porté par un bouton ± (un clavier de tablette n'a pas toujours le « − »). Valeur lue par `readMeasure(entry)`.
 * `modelValue` = { raw: string, neg: boolean }.
 */
import { hasTypedMinus, stripTypedMinus } from "../../../helpers/hygieneTemperature";

export default {
    name: "HygieneMeasureInput",
    props: {
        modelValue: { type: Object, required: true },
        label: { type: String, required: true },
        testid: { type: String, required: true },
        unit: { type: String, default: "°C" },
        signed: { type: Boolean, default: true },
    },
    emits: ["update:modelValue"],
    methods: {
        set(patch) { this.$emit("update:modelValue", { ...this.modelValue, ...patch }); },
        /** Un moins tapé sur un champ signé est honoré (bouton ± sur « − »), jamais jeté en silence. */
        onInput(ev) {
            const v = ev.target.value;
            if (this.signed && hasTypedMinus(v)) {
                const stripped = stripTypedMinus(v);
                ev.target.value = stripped;
                this.set({ raw: stripped, neg: true });
            } else {
                this.set({ raw: v });
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
