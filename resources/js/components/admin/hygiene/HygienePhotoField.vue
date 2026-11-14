<template>
    <div class="hy-photo" data-testid="hy-photo">
        <input ref="file" type="file" accept="image/*" capture="environment" class="hy-hidden" tabindex="-1" aria-hidden="true" data-testid="hy-photo-input" @change="onPick" />
        <img v-if="preview" :src="preview" alt="Photo jointe" data-testid="hy-photo-preview" />
        <button type="button" class="hy-btn hy-btn--small" :disabled="busy" data-testid="hy-photo-btn" @click="$refs.file.click()">
            {{ busy ? "Envoi…" : (modelValue ? "Reprendre la photo" : label) }}
        </button>
        <button v-if="modelValue && !busy" type="button" class="hy-btn hy-btn--small" data-testid="hy-photo-remove" @click="remove">Retirer</button>
        <span v-if="error" class="hy-sub" style="color:#991b1b" role="alert" data-testid="hy-photo-error">{{ error }}</span>
    </div>
</template>

<script>
/** [CAYENNE HYGIÈNE 2026-10-17] Prendre une photo (BL, étiquette, bandelette) : réduite puis envoyée ; émet l'identifiant de la pièce jointe. */
import { uploadPhoto, apiMessage } from "../../../helpers/hygieneCommon";

export default {
    name: "HygienePhotoField",
    props: {
        modelValue: { type: [Number, null], default: null },
        kind: { type: String, default: "other" },
        label: { type: String, default: "📷 Prendre une photo" },
    },
    emits: ["update:modelValue"],
    data() { return { busy: false, error: "", preview: "" }; },
    beforeUnmount() { this.revoke(); },
    methods: {
        revoke() { if (this.preview) { URL.revokeObjectURL(this.preview); this.preview = ""; } },
        async onPick(ev) {
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = "";
            if (!file) return;
            this.busy = true;
            this.error = "";
            try {
                const att = await uploadPhoto(file, this.kind);
                this.revoke();
                this.preview = URL.createObjectURL(file);
                this.$emit("update:modelValue", att.id);
            } catch (e) {
                this.error = apiMessage(e, "Photo non envoyée : réessayez.");
            } finally {
                this.busy = false;
            }
        },
        remove() { this.revoke(); this.$emit("update:modelValue", null); },
    },
};
</script>

<style src="./hygiene.css"></style>
