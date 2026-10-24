<template>
    <div class="hq">
        <div v-if="pending.length" class="hq-offline" role="status" data-testid="tmp-offline">
            <strong>⏳ {{ readingsCount }} relevé{{ readingsCount > 1 ? "s" : "" }} gardé{{ readingsCount > 1 ? "s" : "" }} sur cette tablette, en attente de réseau.</strong>
            Ils partent tout seuls dès que la connexion revient (tant que l'Accueil ou l'écran Températures d'hygiène est ouvert, même après un rechargement)
            et gardent l'heure de la mesure.
            <ul class="hq-lines" data-testid="tmp-offline-lines">
                <li v-for="l in pendingLines" :key="l.key">{{ l.text }}</li>
            </ul>
            <button type="button" class="hq-btn" :disabled="flushing" data-testid="tmp-offline-retry" @click="flush">{{ flushing ? "Envoi…" : "Réessayer maintenant" }}</button>
        </div>
        <div v-for="r in rejected" :key="r.qid" class="hq-refused" role="alert" data-testid="tmp-refused">
            <strong>Relevés refusés par le serveur :</strong> {{ r.reason }}
            <ul class="hq-lines" data-testid="tmp-refused-lines">
                <li v-for="l in lines(r)" :key="l.key">{{ l.text }}</li>
            </ul>
            <em>Ressaisissez-les : rien n'a été enregistré pour cette mesure.</em>
            <button type="button" class="hq-btn" data-testid="tmp-refused-ok" @click="dismiss(r.qid)">J'ai compris</button>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-18 · revue adverse] Ce qui est gardé sur la tablette faute de réseau, ce que le serveur a refusé, et le rejeu : un seul
 * composant pour l'Accueil et l'écran Températures (la file se vide dès que l'un des deux est ouvert, pas seulement sur Températures).
 * Chaque ligne dit CE qui est gardé (équipement, valeur, heure de la mesure) : un refus ne laisse jamais « 6 relevés » sans les valeurs.
 */
import { hygieneQueue } from "../../../helpers/hygieneOfflineQueue";
import { formatTemperature } from "../../../helpers/hygieneTemperature";
import axios from "axios";
import alertService from "../../../services/alertService";

export default {
    name: "HygieneQueueBanner",
    props: { names: { type: Object, default: () => ({}) }, timezone: { type: String, default: "Europe/Paris" } },
    emits: ["sent", "changed"],
    data() { return { items: [], flushing: false, timer: null, onlineHandler: null }; },
    computed: {
        pending() { return this.items.filter((i) => i.state === "pending"); },
        rejected() { return this.items.filter((i) => i.state === "rejected"); },
        readingsCount() { return this.pending.reduce((n, i) => n + ((i.body && i.body.readings) || []).length, 0); },
        pendingLines() { return this.pending.flatMap((i) => this.lines(i)); },
    },
    created() {
        this.refresh();
        this.flush();
        this.onlineHandler = () => this.flush();
        if (typeof window !== "undefined") window.addEventListener("online", this.onlineHandler);
        this.timer = setInterval(() => { if (this.pending.length) this.flush(); }, 30000);
    },
    beforeUnmount() {
        if (this.timer) clearInterval(this.timer);
        if (typeof window !== "undefined" && this.onlineHandler) window.removeEventListener("online", this.onlineHandler);
    },
    methods: {
        queue() { return hygieneQueue(axios); },
        refresh() {
            this.items = this.queue().items().filter((i) => i.kind === "temperature");
            this.$emit("changed", this.items);
        },
        hm(iso) {
            try { return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: this.timezone }).format(new Date(iso)); } catch (_) { return ""; }
        },
        lines(entry) {
            return ((entry.body && entry.body.readings) || []).map((r, i) => ({
                key: `${entry.qid}-${i}`,
                text: `${r.equipment_name || this.names[r.equipment_id] || "Équipement"} : ${formatTemperature(r.value)} °C${r.measured_at ? ` à ${this.hm(r.measured_at)}` : ""}`,
            }));
        },
        dismiss(qid) { this.queue().dismiss(qid); this.refresh(); },
        async flush() {
            if (this.flushing) return;
            this.refresh();
            if (!this.pending.length) return;
            this.flushing = true;
            try {
                const r = await this.queue().flush();
                this.refresh();
                if (r.sent > 0) {
                    this.$emit("sent", r);
                    alertService.success(`${r.sent} envoi${r.sent > 1 ? "s" : ""} gardé${r.sent > 1 ? "s" : ""} hors ligne ${r.sent > 1 ? "ont" : "a"} été enregistré${r.sent > 1 ? "s" : ""}.`);
                }
            } finally {
                this.flushing = false;
            }
        },
    },
};
</script>

<style scoped>
.hq-offline { border: 2px solid #f59e0b; background: #fffbeb; color: #78350f; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; line-height: 1.5; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
.hq-refused { border: 2px solid #dc2626; background: #fef2f2; color: #7f1d1d; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; line-height: 1.5; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
.hq-lines { flex: 1 1 100%; margin: 0; padding-left: 20px; font-size: 14px; }
.hq-btn { min-height: 44px; padding: 0 14px; border-radius: 10px; border: 2px solid #cbd5e1; background: #fff; color: #111827; font-size: 15px; font-weight: 800; cursor: pointer; }
.hq-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
