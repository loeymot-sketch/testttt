<template>
    <div class="lbl-bar" :class="`is-${info.level}`" role="status" aria-live="polite" data-testid="label-printer-bar">
        <span class="lbl-bar-dot" aria-hidden="true"></span>
        <div class="lbl-bar-text">
            <strong data-testid="label-printer-title">{{ info.title }}</strong>
            <span v-if="info.detail" class="lbl-bar-detail" data-testid="label-printer-detail">{{ info.detail }}</span>
        </div>
        <button v-if="canConnect" type="button" class="lbl-btn lbl-btn--primary" data-testid="label-printer-connect"
                @click="$emit('connect')">
            Connecter l’imprimante
        </button>
        <button v-if="detectedMedia" type="button" class="lbl-btn lbl-btn--primary" data-testid="label-printer-adopt-media"
                @click="$emit('adopt-media')">
            Utiliser le rouleau détecté : {{ detectedMedia.label }}
        </button>
        <router-link v-if="showLink" to="/admin/labels/printer" class="lbl-btn lbl-btn--ghost" data-testid="label-printer-link">
            Réglages
        </router-link>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16] Bandeau d'état de l'imprimante, en tête des écrans d'étiquettes : vert quand tout
 * est prêt, sinon il dit POURQUOI (non connectée, capot ouvert, mauvais rouleau…) et quoi faire.
 */
export default {
    name: "LabelPrinterBar",
    props: {
        info: { type: Object, required: true },
        status: { type: String, default: "disconnected" },
        supported: { type: Boolean, default: true },
        showLink: { type: Boolean, default: true },
        detectedMedia: { type: Object, default: null },
    },
    emits: ["connect", "adopt-media"],
    computed: {
        canConnect() {
            return this.supported && this.status === "disconnected";
        },
    },
};
</script>

<style scoped>
.lbl-bar {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    padding: 12px 16px; border-radius: 12px; border: 1px solid #cbd5e1; background: #f8fafc; margin-bottom: 14px;
}
.lbl-bar-dot { width: 14px; height: 14px; border-radius: 50%; background: #94a3b8; flex: 0 0 auto; }
.lbl-bar-text { flex: 1 1 240px; display: flex; flex-direction: column; min-width: 0; }
.lbl-bar-text strong { font-size: 17px; }
.lbl-bar-detail { color: #334155; font-size: 14px; }
.lbl-bar.is-ok { background: #ecfdf5; border-color: #a7f3d0; }
.lbl-bar.is-ok .lbl-bar-dot { background: #16a34a; }
.lbl-bar.is-warn { background: #fffbeb; border-color: #fcd34d; }
.lbl-bar.is-warn .lbl-bar-dot { background: #f59e0b; }
.lbl-bar.is-error { background: #fef2f2; border-color: #fca5a5; }
.lbl-bar.is-error .lbl-bar-dot { background: #dc2626; }
.lbl-bar.is-info .lbl-bar-dot { background: #2563eb; }
.lbl-btn {
    min-height: 48px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 16px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; color: inherit;
}
.lbl-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.lbl-btn--ghost { background: transparent; color: #475569; }
</style>
