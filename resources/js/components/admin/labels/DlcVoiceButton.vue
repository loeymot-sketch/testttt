<template>
    <div class="vb">
        <button type="button" class="vb-btn" :class="{ 'is-rec': state === 'recording' }" :disabled="disabled || state === 'sending' || !supported"
                :aria-pressed="state === 'recording' ? 'true' : 'false'" :title="supported ? '' : 'Micro indisponible : la connexion doit être sécurisée (https).'"
                data-testid="dlc-voice" @click="toggle">
            <span aria-hidden="true">{{ state === "recording" ? "⏹" : "🎤" }}</span>
            <template v-if="state === 'idle'">Dicter</template>
            <template v-else-if="state === 'recording'">Terminer · {{ seconds }} s</template>
            <template v-else>Analyse…</template>
        </button>
        <span class="sr-only" role="status" aria-live="polite">{{ live }}</span>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-19 · owner] « Dites ce que vous étiquetez » : un appui pour parler, un appui pour finir.
 * L'enregistrement est converti en WAV puis envoyé ; la réponse REMPLIT la sélection du parent, qui montre ce
 * qu'il a compris avant que quiconque n'imprime. Rien d'autre n'est déclenché ici.
 */
import axios from "axios";
import { blobToWav, micErrorMessage, recordVoice, SILENCE_PEAK, voiceSupported } from "../../../helpers/dlcVoice";

export default {
    name: "DlcVoiceButton",
    props: {
        disabled: { type: Boolean, default: false },
        maxSeconds: { type: Number, default: 40 },
    },
    emits: ["result", "error", "unavailable"],
    data() {
        return { state: "idle", seconds: 0, live: "", recording: null, timer: null, supported: voiceSupported() };
    },
    beforeUnmount() {
        this.clearTimer();
        if (this.recording) this.recording.cancel();
        this.recording = null;
    },
    methods: {
        clearTimer() {
            clearInterval(this.timer);
            this.timer = null;
        },
        async toggle() {
            if (this.state === "recording") return this.finish();
            if (this.state !== "idle") return null;
            try {
                this.recording = await recordVoice();
            } catch (e) {
                this.$emit("error", micErrorMessage(e));
                return null;
            }
            this.seconds = 0;
            this.state = "recording";
            this.live = "Enregistrement en cours. Appuyez de nouveau pour terminer.";
            this.timer = setInterval(() => {
                this.seconds += 1;
                if (this.seconds >= this.maxSeconds) this.finish();
            }, 1000);
            return null;
        },
        async finish() {
            if (this.state !== "recording" || !this.recording) return;
            this.clearTimer();
            const rec = this.recording;
            this.recording = null;
            this.state = "sending";
            this.live = "Analyse de l’enregistrement.";
            try {
                const blob = await rec.stop();
                const { wav, peak } = await blobToWav(blob);
                if (peak < SILENCE_PEAK) {
                    this.$emit("error", "Je n’ai rien entendu : le micro semble muet. Parlez plus près de la tablette et réessayez.");
                    return;
                }
                const form = new FormData();
                form.append("audio", new File([wav], "voix.wav", { type: "audio/wav" }));
                const { data } = await axios.post("admin/labels/dlc/voice", form);
                if (data && data.data) this.$emit("result", { ...data.data, message: data.message || "" });
                else this.$emit("error", "Réponse inattendue du serveur.");
            } catch (e) {
                const res = e && e.response;
                if (res && res.status === 503) this.$emit("unavailable", (res.data && res.data.message) || "Dictée non activée.");
                else this.$emit("error", (res && res.data && res.data.message) || "La dictée n’a pas abouti : vérifiez la connexion, ou choisissez les produits à la main.");
            } finally {
                this.state = "idle";
                this.live = "";
            }
        },
    },
};
</script>

<style scoped>
.vb { display: inline-flex; flex: none; }
.vb-btn { min-height: 52px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; display: inline-flex; gap: 8px; align-items: center; white-space: nowrap; }
.vb-btn:disabled { opacity: .55; cursor: not-allowed; }
.vb-btn.is-rec { background: #dc2626; border-color: #dc2626; color: #fff; animation: vb-pulse 1.2s ease-in-out infinite; }
@media (prefers-reduced-motion: reduce) { .vb-btn.is-rec { animation: none; } }
@keyframes vb-pulse { 50% { box-shadow: 0 0 0 6px rgba(220, 38, 38, .25); } }
</style>
