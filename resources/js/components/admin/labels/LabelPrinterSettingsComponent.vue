<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Imprimante Brother QL‑820NWB</h3>
            </div>

            <div class="db-card-body">
                <LabelPrinterBar :info="printerInfo" :status="printerState.status" :supported="printerSupported"
                                 :show-link="false" :detected-media="detectedMedia" @connect="connectPrinter" @adopt-media="adoptDetectedMedia" />

                <!-- ── Mise en service : numérotée, ouverte tant que l'imprimante n'est pas prête, repliée ensuite ── -->
                <details class="pr-guide" :open="guideOpen" data-testid="pr-guide" @toggle="guideOpen = $event.target.open">
                    <summary class="pr-guide-summary">
                        <span class="pr-chevron" aria-hidden="true">{{ guideOpen ? "▾" : "▸" }}</span>
                        <strong>Mise en service (une seule fois)</strong>
                        <span v-if="printerReady" class="pr-ok">✓ terminée</span>
                    </summary>
                    <ol class="pr-steps">
                        <li>
                            <span class="pr-step-n" aria-hidden="true">1</span>
                            <div><strong>Sur l'imprimante</strong> (écran tactile) : <em>Menu › Bluetooth › Bluetooth (On/Off) › On</em>, puis
                                <em>Reconnexion automatique › On</em>.<br />Ensuite <em>Menu › Réglages › Arrêt automatique › Adaptateur › Off</em> :
                                sinon elle s'éteint seule et la tablette la perd.</div>
                        </li>
                        <li>
                            <span class="pr-step-n" aria-hidden="true">2</span>
                            <div><strong>Sur la tablette Android</strong> : Réglages › Bluetooth › « QL‑820NWB… ». Si un code s'affiche, il doit être
                                <b>identique</b> sur l'imprimante : validez les deux.</div>
                        </li>
                        <li>
                            <span class="pr-step-n" aria-hidden="true">3</span>
                            <div><strong>Dans Chrome</strong> (pas un autre navigateur) : appuyez sur « Connecter l'imprimante » et choisissez‑la.
                                Elle reste connectée ; la tablette la retrouve seule après une coupure.</div>
                        </li>
                        <li>
                            <span class="pr-step-n" aria-hidden="true">4</span>
                            <div><strong>Vérifiez</strong> avec « Imprimer une étiquette test ». Une imprimante ne se connecte qu'à
                                <b>un seul appareil à la fois</b> : déconnectez l'application Brother d'un téléphone si besoin.</div>
                        </li>
                    </ol>
                </details>

                <div class="pr-actions">
                    <button v-if="printerState.status === 'disconnected' || printerState.status === 'unsupported'" type="button"
                            class="pr-btn pr-btn--primary" :disabled="!printerSupported" data-testid="pr-connect" @click="connectPrinter">
                        Connecter l'imprimante
                    </button>
                    <button v-else type="button" class="pr-btn" data-testid="pr-disconnect" @click="disconnectPrinter">
                        Déconnecter
                    </button>
                    <button type="button" class="pr-btn pr-btn--primary" :disabled="testing || !printerReady" :aria-describedby="printerReady ? null : 'pr-test-why'" data-testid="pr-test" @click="printTest">
                        {{ testing ? "Impression…" : "🖨 Imprimer une étiquette test" }}
                    </button>
                    <button type="button" class="pr-btn" data-testid="pr-fallback" @click="fallbackPrint">
                        Secours : imprimer par Android
                    </button>
                </div>
                <p class="pr-hint pr-hint--block">
                    L'étiquette test vérifie le sens, les accents et la coupe. <b>Secours</b> : ouvre la boîte d'impression d'Android
                    (à utiliser seulement si la connexion directe ci‑dessus ne marche pas).
                    <span v-if="!printerReady" id="pr-test-why"><br />Le test est disponible dès que l'imprimante est prête.</span>
                </p>

                <p v-if="testMessage" class="pr-msg" :class="testOk ? 'is-ok' : 'is-ko'" data-testid="pr-test-message">{{ testMessage }}</p>
                <div v-if="testPreview" class="pr-preview">
                    <img :src="testPreview" alt="Étiquette test" data-testid="pr-test-preview" />
                    <p class="pr-hint">
                        Sur le papier : le <b>G</b> doit être à gauche et le <b>D</b> à droite, les accents (éèàçù Œ) lisibles.
                        Si le texte est inversé comme dans un miroir, ouvrez « Réglages avancés » et basculez « Sens miroir ».
                    </p>
                </div>

                <!-- ── Ce que le navigateur sait faire ─────────────────────────────────── -->
                <h4 class="pr-h4">Diagnostic de la tablette</h4>
                <ul class="pr-diag" data-testid="pr-diag">
                    <li v-for="d in diagnostic" :key="d.key" :class="d.ok ? 'is-ok' : 'is-ko'" :data-testid="`pr-diag-${d.key}`">
                        <span class="pr-diag-mark">{{ d.ok ? "✓" : "✗" }}</span>
                        <span><strong>{{ d.label }}</strong> — {{ d.ok ? d.okText : d.koText }}</span>
                    </li>
                </ul>

                <!-- ── Réglages ────────────────────────────────────────────────────────── -->
                <h4 class="pr-h4">Réglages de cette tablette</h4>
                <div class="pr-form">
                    <div class="pr-field">
                        <label for="pr-media">Rouleau dans l'imprimante</label>
                        <select id="pr-media" class="db-field-control" :value="settings.mediaId" data-testid="pr-media"
                                @change="updateSettings({ mediaId: $event.target.value })">
                            <option v-for="m in mediaChoices" :key="m.id" :value="m.id">{{ m.label }} ({{ m.ref }})</option>
                        </select>
                        <small class="pr-hint">
                            Le réglage doit correspondre au rouleau posé : sinon l'imprimante refuse et le dit.
                            Le <b>62 mm continu</b> convient à tout (longueur adaptée au contenu).
                        </small>
                    </div>

                    <label class="pr-check">
                        <input type="checkbox" :checked="settings.autoCut" data-testid="pr-autocut"
                               @change="updateSettings({ autoCut: $event.target.checked })" />
                        Couper automatiquement
                    </label>
                    <div v-if="settings.autoCut" class="pr-field" data-testid="pr-cutmode-field">
                        <label for="pr-cutmode">Tickets d'une même commande</label>
                        <select id="pr-cutmode" class="db-field-control" :value="settings.cutMode" data-testid="pr-cutmode"
                                @change="updateSettings({ cutMode: $event.target.value })">
                            <option value="each">Couper chaque ticket</option>
                            <option value="order">Garder les tickets d'une commande attachés (une coupe par commande)</option>
                        </select>
                        <small class="pr-hint">
                            Attachés : les tickets de la commande sortent en une bande, dans l'ordre, et la lame coupe une seule fois après le dernier.
                            Sur le ruban continu, une ligne pointillée montre où séparer deux tickets.
                            L'imprimante ne sait pas faire de demi-coupe (un « petit point » qui relie) : la séparation se fait à la main.
                        </small>
                    </div>
                    <label class="pr-check">
                        <input type="checkbox" :checked="settings.includeDrinks" data-testid="pr-drinks"
                               @change="updateSettings({ includeDrinks: $event.target.checked })" />
                        Imprimer aussi une étiquette pour les boissons vendues seules
                        <small class="pr-hint">(la boisson d'un menu figure toujours sur l'étiquette du menu)</small>
                    </label>

                    <details class="pr-advanced">
                        <summary>Réglages avancés</summary>
                        <label class="pr-check">
                            <input type="checkbox" :checked="settings.mirror" data-testid="pr-mirror"
                                   @change="updateSettings({ mirror: $event.target.checked })" />
                            Sens miroir (activé par défaut — à décocher seulement si le texte sort inversé)
                        </label>
                        <label class="pr-check">
                            <input type="checkbox" :checked="settings.turn180" data-testid="pr-turn180"
                                   @change="updateSettings({ turn180: $event.target.checked })" />
                            Retourner de 180° (si les étiquettes sortent à l'envers)
                        </label>
                    </details>
                </div>

            </div>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner] Connexion Bluetooth de la Brother QL‑820NWB à la tablette, diagnostic,
 * format du rouleau, étiquette test.
 *
 * Le navigateur ne peut pas « chercher » une imprimante Bluetooth : Android doit l'avoir APPAIRÉE d'abord, puis
 * Chrome (≥ 138) la propose dans une liste après un appui — c'est une règle de sécurité du navigateur, d'où le
 * pas à pas ci‑dessus. La page de diagnostic dit exactement ce qui manque si rien ne se connecte.
 */
import alertService from "../../../services/alertService";
import labelPrinterMixin, { chromeMajor } from "../../../helpers/labelPrinterMixin";
import LabelPrinterBar from "./LabelPrinterBar";
import { mediaList } from "../../../helpers/brotherQl";
import { testLabelBlocks } from "../../../helpers/dlcLabelBuilder";
import { printBlocks, previewUrls } from "../../../helpers/labelPrintJob";
import { printViaBrowser } from "../../../helpers/labelBrowserPrint";

export default {
    name: "LabelPrinterSettingsComponent",
    components: { LabelPrinterBar },
    mixins: [labelPrinterMixin],
    data() {
        return { testing: false, testMessage: "", testOk: false, testPreview: "", guideOpen: true, guideTouched: false };
    },
    watch: {
        // Ouvert tant que l'imprimante n'est pas prête ; replié dès qu'elle l'est (sauf si on l'a ouvert exprès).
        // Le guide de PREMIÈRE installation ne s'ouvre que si l'imprimante n'est pas connectée : une imprimante connectée
        // mais en panne (capot, rouleau) n'a pas besoin qu'on lui redise d'appuyer sur « Connecter ».
        printerState: {
            deep: true,
            handler() { if (!this.guideTouched) this.guideOpen = this.guideShouldBeOpen; },
        },
        printerReady: {
            immediate: true,
            handler() { if (!this.guideTouched) this.guideOpen = this.guideShouldBeOpen; },
        },
        guideOpen(open, before) {
            if (before !== undefined && open !== this.guideShouldBeOpen) this.guideTouched = true;
        },
    },
    computed: {
        guideShouldBeOpen() {
            return !this.printerSupported || this.printerState.status === "disconnected" || this.printerState.status === "connecting";
        },
        mediaChoices() {
            return mediaList();
        },
        diagnostic() {
            const ua = typeof navigator !== "undefined" ? navigator.userAgent : "";
            const major = chromeMajor(ua);
            const isEdgeOrSamsung = /EdgA|SamsungBrowser|OPR\//.test(ua);
            return [
                {
                    key: "secure", label: "Page sécurisée (HTTPS)",
                    ok: typeof window !== "undefined" && window.isSecureContext !== false,
                    okText: "oui", koText: "Chrome n'autorise le Bluetooth que sur une adresse https://.",
                },
                {
                    key: "browser", label: "Navigateur",
                    ok: Boolean(major) && !isEdgeOrSamsung,
                    okText: `Chrome ${major}`,
                    koText: "utilisez Google Chrome (pas Samsung Internet, Edge ni une application intégrée).",
                },
                {
                    key: "version", label: "Version de Chrome",
                    ok: major === null ? this.printerSupported : major >= 138,
                    okText: major ? `${major} (138 ou plus requis)` : "compatible",
                    koText: `${major || "inconnue"} — mettez Chrome à jour (138 ou plus requis pour le Bluetooth).`,
                },
                {
                    key: "serial", label: "Accès à l'imprimante (Web Serial)",
                    ok: this.printerSupported,
                    okText: "disponible", koText: "indisponible sur ce navigateur — l'impression « via Android » reste possible.",
                },
            ];
        },
    },
    methods: {
        async printTest() {
            this.testing = true;
            this.testMessage = "";
            try {
                const blocks = testLabelBlocks(this.settings.mediaId, new Date());
                try { this.testPreview = previewUrls([blocks], this.settings).urls[0] || ""; } catch (_) { this.testPreview = ""; }
                const result = await printBlocks(this.printerInstance(), [blocks], this.settings);
                this.testOk = result.ok;
                this.testMessage = result.ok
                    ? (result.unconfirmed ? result.message : "Étiquette test envoyée : vérifiez le sens et la coupe.")
                    : result.message;
                if (!result.ok) alertService.error(result.message);
            } finally {
                this.testing = false;
            }
        },

        fallbackPrint() {
            try {
                const blocks = testLabelBlocks(this.settings.mediaId, new Date());
                const { urls } = previewUrls([blocks], this.settings);
                this.testPreview = urls[0] || "";
                const ok = printViaBrowser(urls, this.settings.mediaId);
                this.testOk = ok;
                this.testMessage = ok
                    ? "Boîte d'impression d'Android ouverte : choisissez l'imprimante Brother."
                    : "Impossible d'ouvrir la boîte d'impression sur ce navigateur.";
            } catch (e) {
                this.testOk = false;
                this.testMessage = "Impression de secours impossible : " + ((e && e.message) || e);
            }
        },
    },
};
</script>

<style scoped>
.pr-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.pr-btn {
    min-height: 52px; padding: 0 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 17px; font-weight: 700; cursor: pointer;
}
.pr-btn:disabled { opacity: .45; cursor: not-allowed; }
.pr-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.pr-btn:disabled { opacity: 1; background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
.pr-msg { border-radius: 8px; padding: 10px 12px; margin: 6px 0 12px; font-weight: 600; }
.pr-msg.is-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #166534; }
.pr-msg.is-ko { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
.pr-preview img { width: min(100%, 348px); border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; display: block; }
.pr-hint { color: #475569; font-size: 14px; display: block; margin-top: 4px; }
.pr-h4 { font-size: 17px; font-weight: 800; margin: 22px 0 8px; border-top: 1px solid #e2e8f0; padding-top: 14px; }
.pr-diag { list-style: none; margin: 0; padding: 0; }
.pr-diag li { display: flex; gap: 10px; padding: 6px 0; align-items: baseline; }
.pr-diag-mark { font-weight: 800; width: 20px; }
.pr-diag li.is-ok .pr-diag-mark { color: #16a34a; }
.pr-diag li.is-ko .pr-diag-mark { color: #dc2626; }
.pr-form { display: flex; flex-direction: column; gap: 14px; max-width: 640px; }
.pr-field label { display: block; font-weight: 700; margin-bottom: 4px; }
.pr-check { flex-wrap: wrap; display: flex; gap: 10px; align-items: flex-start; font-size: 16px; line-height: 1.35; min-height: 44px; }
.pr-check input { width: 28px; height: 28px; margin-top: 0; flex: 0 0 auto; }
.pr-check { min-height: 52px; align-items: center; cursor: pointer; flex-wrap: wrap; }
.pr-check .pr-hint { display: block; flex-basis: 100%; margin-left: 38px; }
.pr-field .db-field-control { min-height: 48px; }
.pr-hint--block { margin: 0 0 12px; font-size: 14px; color: #475569; }
.pr-guide { border: 1px solid #cbd5e1; border-radius: 12px; padding: 4px 16px; margin: 0 0 16px; background: #f8fafc; }
.pr-guide-summary { cursor: pointer; padding: 12px 0; font-size: 17px; display: flex; gap: 12px; align-items: center; min-height: 48px; }
.pr-ok { color: #166534; font-weight: 700; font-size: 14px; }
.pr-steps { list-style: none; padding: 0; margin: 0 0 12px; display: flex; flex-direction: column; gap: 14px; }
.pr-steps li { display: flex; gap: 12px; align-items: flex-start; line-height: 1.45; }
.pr-step-n { flex: 0 0 34px; height: 34px; border-radius: 50%; background: #1a1a1a; color: #fff; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
.pr-advanced summary { cursor: pointer; font-weight: 700; padding: 8px 0; min-height: 48px; display: flex; align-items: center; }
.pr-chevron { font-size: 18px; width: 20px; }
.pr-steps { padding-left: 22px; display: flex; flex-direction: column; gap: 12px; max-width: 760px; line-height: 1.45; }
</style>
