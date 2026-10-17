<template>
    <span class="hy-photo-view">
        <button type="button" class="hy-btn hy-btn--small" :data-testid="`hy-photo-view-${id}`" @click="toggle">{{ shown ? "Masquer la photo" : label }}</button>
        <img v-if="shown && url" :src="url" alt="Photo jointe" style="display:block;max-width:100%;max-height:360px;margin-top:8px;border-radius:10px;border:1px solid #cbd5e1" />
        <span v-if="error" class="hy-sub" style="color:#991b1b" role="alert">{{ error }}</span>
    </span>
</template>

<script>
/** [CAYENNE HYGIÈNE 2026-10-17] Affiche une pièce jointe protégée (chargée avec le jeton, exposée en blob, libérée à la fermeture). */
import { loadPhotoUrl } from "../../../helpers/hygieneCommon";

export default {
    name: "HygienePhotoView",
    props: { id: { type: Number, required: true }, label: { type: String, default: "📷 Voir la photo" } },
    data() { return { shown: false, url: "", error: "" }; },
    beforeUnmount() { this.release(); },
    methods: {
        release() { if (this.url) { URL.revokeObjectURL(this.url); this.url = ""; } },
        async toggle() {
            if (this.shown) { this.shown = false; this.release(); return; }
            this.error = "";
            try {
                this.url = await loadPhotoUrl(this.id);
                this.shown = true;
            } catch (_) {
                this.error = "Photo indisponible.";
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
