<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Étiquettes — commandes en cours</h3>
            </div>

            <div class="db-card-body">
                <LabelPrinterBar :info="printerInfo" :status="printerState.status" :supported="printerSupported"
                                 :detected-media="detectedMedia" @connect="connectPrinter" @adopt-media="adoptDetectedMedia" />

                <!-- ── Réglage « automatique » ──────────────────────────────────────────
                     Semi‑automatique aujourd'hui (bouton Imprimer), automatique demain : la tablette posée
                     imprime toute commande qui ARRIVE après l'activation. -->
                <div class="lbl-auto" :class="{ 'is-on': settings.autoPrint && !blocked, 'is-paused': blocked }" data-testid="label-auto">
                    <button type="button" class="lbl-switch" role="switch" :aria-checked="settings.autoPrint ? 'true' : 'false'"
                            aria-label="Impression automatique des nouvelles commandes" data-testid="label-auto-toggle" @click="toggleAuto">
                        <span class="lbl-switch-knob"></span>
                    </button>
                    <div class="lbl-auto-text">
                        <strong>Impression automatique {{ blocked ? "en pause" : settings.autoPrint ? "activée" : "désactivée" }}</strong>
                        <span v-if="settings.autoPrint && blocked">Reprendra toute seule dès que l'imprimante sera prête.</span>
                        <span v-else-if="settings.autoPrint">
                            Les nouvelles commandes s'impriment seules, tant que cet écran reste ouvert et l'imprimante prête.
                            <em v-if="autoStatusText"> {{ autoStatusText }}</em>
                        </span>
                        <span v-else>Activez‑la pour que chaque nouvelle commande imprime ses étiquettes sans appui.</span>
                    </div>
                    <button type="button" class="lbl-btn lbl-btn--primary" :disabled="!toPrint.length || batchBusy || !printerReady"
                            :title="!printerReady ? 'Indisponible : l\'imprimante n\'est pas prête' : ''"
                            data-testid="label-print-all" @click="printAll">
                        🖨 Tout imprimer ({{ toPrint.length }})
                    </button>
                    <small v-if="!printerReady && toPrint.length" class="lbl-why" data-testid="label-print-all-why">Indisponible : imprimante non prête.</small>
                </div>

                <!-- Une tablette posée sans surveillance doit APPELER quelqu'un : bandeau rouge + signal sonore. -->
                <div v-if="blocked" class="lbl-blocked" role="alert" data-testid="label-blocked">
                    <div class="lbl-blocked-text">
                        <strong>⚠ {{ waitingCount }} commande{{ waitingCount > 1 ? "s" : "" }} en attente d'impression</strong>
                        <span>Elles partiront toutes seules dès que l'imprimante sera prête (voir ci-dessus).</span>
                    </div>
                    <button type="button" class="lbl-btn lbl-btn--light" data-testid="label-retry" :disabled="retrying" @click="retryPrinter">
                        {{ retrying ? "Vérification…" : "Réessayer" }}
                    </button>
                </div>

                <p v-if="loadError" class="lbl-alert" data-testid="label-orders-error">{{ loadError }}</p>
                <p v-if="overflow" class="lbl-alert" data-testid="label-orders-overflow">
                    Plus de 50 commandes en cuisine : seules les 50 plus anciennes sont listées ici.
                </p>

                <p v-if="!loaded && !loadError" class="lbl-empty" data-testid="label-orders-loading" role="status">Chargement des commandes…</p>
                <p v-else-if="!rows.length && !loadError" class="lbl-empty" data-testid="label-orders-empty">
                    Aucune commande en cours. Elles apparaissent ici dès qu'elles arrivent en cuisine.
                </p>

                <h4 v-if="pendingRows.length" class="lbl-section" data-testid="label-section-pending">
                    À imprimer <span class="lbl-count">{{ pendingRows.length }}</span>
                </h4>
                <ul class="lbl-orders" data-testid="label-orders">
                    <li v-for="row in pendingRows" :key="row.order.id" class="lbl-order" :class="[`is-${row.state.kind}`, { 'is-new': newIds.includes(row.order.id) }]"
                        :data-testid="`label-order-${row.order.id}`">
                        <div class="lbl-order-head">
                            <span class="lbl-order-num" data-testid="label-order-number">N° {{ row.number }}</span>
                            <span class="lbl-order-source">{{ row.source }}</span>
                            <span class="lbl-order-time">{{ row.time }}</span>
                            <span v-if="row.age && !row.state.printed" class="lbl-age" :class="`is-${row.age.level}`" data-testid="label-order-age">{{ row.age.text }}</span>
                            <span v-if="row.customer" class="lbl-order-customer">{{ row.customer }}</span>
                            <span class="lbl-chip" :class="`is-${row.state.kind}`" data-testid="label-order-state">{{ row.state.text }}</span>
                        </div>

                        <div class="lbl-order-body">
                            <p v-if="row.built.total" class="lbl-order-summary">
                                <strong>{{ row.built.total }} étiquette{{ row.built.total > 1 ? "s" : "" }}</strong> —
                                {{ row.summary }}
                            </p>
                            <p v-else class="lbl-order-summary lbl-muted">
                                Aucune étiquette
                                <span v-if="row.built.skipped.length">(boissons seules : {{ row.built.skipped.join(", ") }})</span>
                            </p>
                            <p v-if="row.state.detail" class="lbl-order-detail">{{ row.state.detail }}</p>
                        </div>

                        <div class="lbl-order-actions">
                            <button type="button" class="lbl-btn lbl-btn--ghost" :disabled="!row.built.total"
                                    :data-testid="`label-preview-${row.order.id}`" @click="togglePreview(row.order.id)">
                                {{ previewId === row.order.id ? "Masquer l'aperçu" : "Aperçu" }}
                            </button>
                            <button type="button" class="lbl-btn"
                                    :class="row.state.printed || !printerReady ? 'lbl-btn--ghost' : 'lbl-btn--primary'"
                                    :disabled="!row.built.total || busyIds.includes(row.order.id)"
                                    :data-testid="`label-print-${row.order.id}`"
                                    @click="printOrder(row.order, 'manual')">
                                {{ busyIds.includes(row.order.id) ? "Impression…" : row.state.printed || row.state.partial ? "🖨 Réimprimer" : "🖨 Imprimer" }}
                            </button>
                        </div>

                        <div v-if="previewId === row.order.id" class="lbl-previews" data-testid="label-previews">
                            <p v-if="previewOverflow" class="lbl-alert">
                                Une étiquette est trop longue pour le rouleau « {{ labelMedia.label }} » : choisissez le 62 mm continu.
                            </p>
                            <figure v-for="(u, i) in previewImages" :key="i" class="lbl-preview">
                                <img :src="u" :alt="`Étiquette ${i + 1}`" />
                                <figcaption>{{ i + 1 }}/{{ previewImages.length }}</figcaption>
                                <button type="button" class="lbl-btn lbl-btn--ghost lbl-btn--small" :disabled="busyIds.includes(row.order.id)"
                                        :data-testid="`label-print-one-${row.order.id}-${i + 1}`"
                                        @click="printOrder(row.order, 'manual', { only: [i] })">
                                    🖨 {{ row.state.printed ? "Réimprimer" : "Imprimer" }} celle‑ci
                                </button>
                            </figure>
                        </div>
                    </li>
                </ul>

                <template v-if="doneRows.length">
                    <h4 class="lbl-section">
                        <button type="button" class="lbl-section-toggle" :aria-expanded="showDone ? 'true' : 'false'"
                                data-testid="label-section-done-toggle" @click="showDone = !showDone">
                            {{ showDone ? "▾" : "▸" }} Déjà imprimées <span class="lbl-count">{{ doneRows.length }}</span>
                        </button>
                    </h4>
                    <ul v-if="showDone" class="lbl-orders lbl-orders--done" data-testid="label-orders-done">
                    <li v-for="row in doneRows" :key="row.order.id" class="lbl-order" :class="`is-${row.state.kind}`"
                        :data-testid="`label-order-${row.order.id}`">
                        <div class="lbl-order-head">
                            <span class="lbl-order-num" data-testid="label-order-number">N° {{ row.number }}</span>
                            <span class="lbl-order-source">{{ row.source }}</span>
                            <span class="lbl-order-time">{{ row.time }}</span>
                            <span v-if="row.age && !row.state.printed" class="lbl-age" :class="`is-${row.age.level}`" data-testid="label-order-age">{{ row.age.text }}</span>
                            <span v-if="row.customer" class="lbl-order-customer">{{ row.customer }}</span>
                            <span class="lbl-chip" :class="`is-${row.state.kind}`" data-testid="label-order-state">{{ row.state.text }}</span>
                        </div>

                        <div class="lbl-order-body">
                            <p v-if="row.built.total" class="lbl-order-summary">
                                <strong>{{ row.built.total }} étiquette{{ row.built.total > 1 ? "s" : "" }}</strong> —
                                {{ row.summary }}
                            </p>
                            <p v-else class="lbl-order-summary lbl-muted">
                                Aucune étiquette
                                <span v-if="row.built.skipped.length">(boissons seules : {{ row.built.skipped.join(", ") }})</span>
                            </p>
                            <p v-if="row.state.detail" class="lbl-order-detail">{{ row.state.detail }}</p>
                        </div>

                        <div class="lbl-order-actions">
                            <button type="button" class="lbl-btn lbl-btn--ghost" :disabled="!row.built.total"
                                    :data-testid="`label-preview-${row.order.id}`" @click="togglePreview(row.order.id)">
                                {{ previewId === row.order.id ? "Masquer l'aperçu" : "Aperçu" }}
                            </button>
                            <button type="button" class="lbl-btn"
                                    :class="row.state.printed || !printerReady ? 'lbl-btn--ghost' : 'lbl-btn--primary'"
                                    :disabled="!row.built.total || busyIds.includes(row.order.id)"
                                    :data-testid="`label-print-${row.order.id}`"
                                    @click="printOrder(row.order, 'manual')">
                                {{ busyIds.includes(row.order.id) ? "Impression…" : row.state.printed || row.state.partial ? "🖨 Réimprimer" : "🖨 Imprimer" }}
                            </button>
                        </div>

                        <div v-if="previewId === row.order.id" class="lbl-previews" data-testid="label-previews">
                            <p v-if="previewOverflow" class="lbl-alert">
                                Une étiquette est trop longue pour le rouleau « {{ labelMedia.label }} » : choisissez le 62 mm continu.
                            </p>
                            <figure v-for="(u, i) in previewImages" :key="i" class="lbl-preview">
                                <img :src="u" :alt="`Étiquette ${i + 1}`" />
                                <figcaption>{{ i + 1 }}/{{ previewImages.length }}</figcaption>
                                <button type="button" class="lbl-btn lbl-btn--ghost lbl-btn--small" :disabled="busyIds.includes(row.order.id)"
                                        :data-testid="`label-print-one-${row.order.id}-${i + 1}`"
                                        @click="printOrder(row.order, 'manual', { only: [i] })">
                                    🖨 {{ row.state.printed ? "Réimprimer" : "Imprimer" }} celle‑ci
                                </button>
                            </figure>
                        </div>
                    </li>
                    </ul>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner] Écran TABLETTE « commandes en cours » : une étiquette par produit, avec le
 * numéro de commande, le nom, la personnalisation en clair et « k/N ».
 *
 * Les commandes viennent du MÊME flux que l'écran cuisine (GET admin/labels/orders) : ce qui est à étiqueter
 * est ce qui est à préparer. Sondage toutes les 5 s — il fonctionne sans serveur de sockets, et c'est ce qui
 * rend l'impression automatique fiable : pas d'évènement raté si le Wi‑Fi coupe une seconde.
 *
 * Impression : le serveur n'imprime jamais (cloud ≠ Bluetooth). Il tient seulement le journal et le VERROU :
 * `claim` accorde l'impression automatique d'une commande à UNE seule tablette, une seule fois.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import labelPrinterMixin from "../../../helpers/labelPrinterMixin";
import LabelPrinterBar from "./LabelPrinterBar";
import { buildOrderLabels, orderLabelBlocks, orderNumber, orderSourceLabel } from "../../../helpers/orderLabelBuilder";
import { printBlocks, previewUrls } from "../../../helpers/labelPrintJob";
import { createWakeLock } from "../../../helpers/screenWakeLock";
import { createAlarm } from "../../../helpers/labelAlarm";
import { labelResultQueue, labelInflight } from "../../../helpers/labelResultQueue";

const POLL_MS = 5000;
/** Une commande reste visible ce délai après le début de son impression automatique, avant d'être retentée. */
const AUTO_RETRY_GAP_MS = 35000;
/** Verrou refusé (une autre tablette l'a pris) : on ne redemande qu'après le délai de reprise du serveur (120 s) + marge. */
const CLAIM_REFUSED_RETRY_MS = 125000;
/** Une commande non programmée plus ancienne que cela n'est plus imprimée seule (elle est probablement déjà servie). */
const AUTO_MAX_AGE_MS = 90 * 60 * 1000;

export default {
    name: "LabelOrdersComponent",
    components: { LabelPrinterBar },
    mixins: [labelPrinterMixin],
    data() {
        return {
            orders: [],
            states: {},
            loadError: "",
            busyIds: [],
            batchBusy: false,
            previewId: null,
            previewImages: [],
            previewOverflow: false,
            serverOffsetMs: 0,
            overflow: false,
            poller: null,
            autoRunning: false,
            wakeLockActive: false,
            showDone: true,
            retrying: false,
            loaded: false,
            nowTick: Date.now(),
            newIds: [],
            seenIds: null,
            ticker: null,
            // commandes déjà traitées (ou refusées par le verrou) pendant CETTE session d'écran
            autoHandled: {},
        };
    },
    computed: {
        buildOptions() {
            return {
                includeDrinks: this.settings.includeDrinks,
                allergenLabel: (code) => {
                    const key = `allergens.${code}`;
                    const t = this.$t(key);
                    return t === key ? String(code) : t;
                },
            };
        },
        rows() {
            return this.orders.map((order) => {
                const built = buildOrderLabels(order, this.buildOptions);
                const server = this.states[order.id] || null;
                return {
                    order,
                    built,
                    number: orderNumber(order),
                    source: orderSourceLabel(order),
                    time: this.hhmm(order.created_at_iso),
                    customer: order.customer && order.customer.name && order.source_surface !== "kiosk" ? order.customer.name : "",
                    summary: this.summaryOf(built),
                    state: this.stateOf(built, server, order.id),
                    age: this.ageOf(order),
                };
            });
        },
        /** À imprimer : les plus anciennes d'abord (ordre de la cuisine). */
        pendingRows() {
            return this.rows
                .filter((r) => !r.state.printed)
                .sort((a, b) => Date.parse(a.order.created_at_iso) - Date.parse(b.order.created_at_iso));
        },
        /** Déjà imprimées : les plus récentes d'abord. */
        doneRows() {
            return this.rows
                .filter((r) => r.state.printed)
                .sort((a, b) => Date.parse(b.order.created_at_iso) - Date.parse(a.order.created_at_iso));
        },
        /** Commandes qui attendraient l'impression automatique (hors celles déjà sur le tableau à l'activation). */
        waitingCount() {
            const baseline = new Set(Array.isArray(this.settings.autoPrintBaseline) ? this.settings.autoPrintBaseline : []);
            return this.rows.filter((r) => r.built.total > 0 && !r.state.printed && !r.state.busy && !baseline.has(r.order.id)).length;
        },
        /** Automatique actif, des commandes attendent, et l'imprimante ne peut pas imprimer. */
        blocked() {
            return this.settings.autoPrint && this.waitingCount > 0 && !this.printerReady;
        },
        /** Commandes dont les étiquettes ne sont pas sorties (hors celles en cours d'impression). */
        toPrint() {
            return this.rows.filter((r) => r.built.total > 0 && !r.state.printed && !r.state.busy).map((r) => r.order);
        },
        autoStatusText() {
            if (!this.settings.autoPrint) return "";
            if (!this.printerReady) return "En attente de l'imprimante.";
            if (!this.wakeLockActive) {
                return "Gardez l'écran allumé : réglez la mise en veille de la tablette sur « jamais » (sinon l'impression s'arrête).";
            }
            return "Écran maintenu allumé.";
        },
    },
    watch: {
        "settings.autoPrint": {
            immediate: true,
            handler(on) { this.syncWakeLock(on); },
        },
    },
    mounted() {
        labelInflight(axios).recover();
        this.alarm = createAlarm();
        this.poll();
        this.poller = setInterval(this.poll, POLL_MS);
        this.ticker = setInterval(() => { this.nowTick = Date.now(); }, 20000);
    },
    beforeUnmount() {
        this.unmounted = true;
        if (this.poller) clearInterval(this.poller);
        if (this.ticker) clearInterval(this.ticker);
        if (this.wakeLock) this.wakeLock.release();
    },
    methods: {
        /** Écran allumé tant que le mode automatique est actif (voir screenWakeLock.js). */
        async syncWakeLock(on) {
            if (!this.wakeLock) this.wakeLock = createWakeLock();
            if (on) {
                await this.wakeLock.acquire();
            } else {
                await this.wakeLock.release();
            }
            this.wakeLockActive = this.wakeLock.isActive();
        },

        /** « il y a 4 min », avec une urgence croissante : le client attend. */
        ageOf(order) {
            const created = Date.parse(order.created_at_iso);
            if (!Number.isFinite(created)) return null;
            const minutes = Math.max(0, Math.floor((this.nowTick + this.serverOffsetMs - created) / 60000));
            if (order.scheduled_at) return null;
            return {
                minutes,
                text: minutes < 1 ? "à l'instant" : `il y a ${minutes} min`,
                level: minutes >= 15 ? "danger" : minutes >= 8 ? "warn" : "ok",
            };
        },

        hhmm(iso) {
            if (!iso) return "";
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return "";
            try {
                return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" }).format(d);
            } catch (_) {
                return "";
            }
        },

        summaryOf(built) {
            const names = [];
            built.labels.forEach((l) => { if (!names.includes(l.name)) names.push(l.name); });
            return names.join(" · ");
        },

        stateOf(built, server, orderId) {
            if (this.busyIds.includes(orderId) || (server && server.in_progress)) {
                return { kind: "busy", text: "Impression…", busy: true, printed: false, detail: "" };
            }
            if (server && server.printed) {
                return { kind: "done", text: "✓\u00a0Imprimées", printed: true, detail: "" };
            }
            if (server && server.failed) {
                const partial = server.printed_pages > 0;
                return {
                    kind: "failed",
                    text: partial ? `⚠ ${server.printed_pages}/${built.total} sorties` : "⚠ Échec",
                    printed: false,
                    partial,
                    detail: server.last_message || "",
                };
            }
            return { kind: "todo", text: built.total ? "À imprimer" : "—", printed: false, detail: "" };
        },

        async poll() {
            if (this.unmounted) return;
            labelResultQueue(axios).flush();
            try {
                const { data } = await axios.get("admin/labels/orders");
                if (this.unmounted) return;
                this.overflow = Boolean(data.meta && data.meta.overflow);
                this.orders = Array.isArray(data.data) ? data.data : [];
                this.markNew(this.orders.map((o) => o.id));
                this.states = (data.meta && data.meta.states) || {};
                if (data.meta && data.meta.server_now) {
                    const server = Date.parse(data.meta.server_now);
                    if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                }
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                this.loadError = "Liste des commandes indisponible — nouvelle tentative dans quelques secondes.";
            }
            if (this.wakeLock) this.wakeLockActive = this.wakeLock.isActive();
            if (this.blocked && this.alarm) this.alarm.ring();
            this.autoTick();
        },

        /** Met en évidence (6 s) les commandes qui viennent d'arriver — pas celles du premier chargement. */
        markNew(ids) {
            if (this.seenIds === null) { this.seenIds = new Set(ids); return; }
            const fresh = ids.filter((id) => !this.seenIds.has(id));
            fresh.forEach((id) => this.seenIds.add(id));
            if (!fresh.length) return;
            this.newIds = [...this.newIds, ...fresh];
            setTimeout(() => { this.newIds = this.newIds.filter((id) => !fresh.includes(id)); }, 6000);
        },

        serverNow() {
            return Date.now() + this.serverOffsetMs;
        },

        toggleAuto() {
            if (this.settings.autoPrint) {
                this.updateSettings({ autoPrint: false, autoPrintSince: null });
                return;
            }
            // Seules les commandes ARRIVÉES APRÈS l'activation s'impriment seules : activer le mode ne doit pas
            // vomir d'un coup toute la file déjà en préparation.
            this.autoHandled = {};
            // On retient les commandes DÉJÀ sur le tableau : seules celles qui n'y étaient pas s'impriment seules
            // (une commande programmée créée plus tôt mais qui n'entre en cuisine que plus tard, elle, s'imprimera).
            this.updateSettings({
                autoPrint: true,
                autoPrintSince: this.serverNow(),
                autoPrintBaseline: this.orders.map((o) => o.id).slice(-200),
            });
            if (!this.printerReady) {
                alertService.info("Mode automatique activé. Il imprimera dès que l'imprimante sera prête.");
            }
        },

        async retryPrinter() {
            this.retrying = true;
            try {
                const printer = this.printerInstance();
                if (!printer.isConnected()) await printer.autoConnect();
                await printer.refreshStatus();
                await this.poll();
            } finally {
                this.retrying = false;
            }
        },

        togglePreview(orderId) {
            if (this.previewId === orderId) {
                this.previewId = null;
                this.previewImages = [];
                return;
            }
            const row = this.rows.find((r) => r.order.id === orderId);
            if (!row) return;
            this.previewId = orderId;
            try {
                const { urls, overflow } = previewUrls(row.built.labels.map(orderLabelBlocks), this.settings);
                this.previewImages = urls;
                this.previewOverflow = overflow;
                // L'aperçu s'ouvre sous la carte : on l'amène à l'écran, sinon « il ne se passe rien ».
                this.$nextTick(() => {
                    const el = document.querySelector(`[data-testid="label-order-${orderId}"]`);
                    if (el && el.scrollIntoView) el.scrollIntoView({ block: "start", behavior: "smooth" });
                });
            } catch (e) {
                this.previewImages = [];
                this.previewOverflow = false;
                alertService.error("Aperçu impossible : " + ((e && e.message) || e));
            }
        },

        async post(url, body) {
            const { data } = await axios.post(url, body);
            return data;
        },

        /**
         * Imprime les étiquettes d'une commande. mode « manual » : toujours (réimprimer est permis) ;
         * mode « auto » : seulement si le verrou serveur l'accorde.
         */
        async printOrder(order, mode, opts = {}) {
            const built = buildOrderLabels(order, this.buildOptions);
            // `only` : réimprimer certaines étiquettes seulement (étiquette déchirée, collée de travers…).
            const labels = Array.isArray(opts.only) ? opts.only.map((i) => built.labels[i]).filter(Boolean) : built.labels;
            const count = labels.length;
            if (!count) return { ok: false, skipped: true };
            if (this.busyIds.includes(order.id)) return { ok: false, skipped: true };

            this.busyIds = [...this.busyIds, order.id];
            let logId = null;
            let attempt = null;
            try {
                try {
                    const claim = await this.post(`admin/labels/orders/${order.id}/claim`, { mode, labels_count: count, partial: Array.isArray(opts.only) && count < built.total });
                    if (!claim.claimed) return { ok: false, skipped: true, claim: "refused" };
                    logId = claim.log_id;
                    attempt = claim.attempt || null;
                } catch (e) {
                    // Réseau coupé : à la main on imprime quand même (le papier prime sur le journal) ; en
                    // automatique on s'abstient, le prochain sondage réessaiera avec le verrou.
                    if (mode === "auto") return { ok: false, skipped: true, claim: "error" };
                }

                const resultUrl = logId ? `admin/labels/prints/${logId}/result` : null;
                // Impression « en vol » : si la page est rechargée avant le verdict, il sera déclaré « non confirmé »
                // au prochain chargement (jamais de réimpression automatique par erreur).
                if (resultUrl) labelInflight(axios).begin(logId, resultUrl, attempt);
                const result = await printBlocks(this.printerInstance(), labels.map(orderLabelBlocks), this.settings);

                if (logId) {
                    const status = result.ok ? (result.unconfirmed ? "unconfirmed" : "printed") : "failed";
                    // File locale : un verdict perdu (réseau coupé) serait un doublon 120 s plus tard.
                    await labelResultQueue(axios).send(resultUrl, {
                        status,
                        message: result.message ? String(result.message).slice(0, 290) : null,
                        labels_count: count,
                        printed_pages: result.ok ? count : (result.pages || 0),
                        attempt,
                    });
                    labelInflight(axios).end(logId);
                }

                if (result.ok && !result.unconfirmed) {
                    if (mode === "manual" && !opts.quiet) alertService.success(`Commande ${built.number} : ${count} étiquette${count > 1 ? "s" : ""} imprimée${count > 1 ? "s" : ""}.`);
                } else if (result.ok) {
                    alertService.info(result.message);
                } else {
                    alertService.error(`Commande ${built.number} : ${result.message}`);
                }
                return result;
            } finally {
                this.busyIds = this.busyIds.filter((id) => id !== order.id);
                this.poll();
            }
        },

        async printAll() {
            if (this.batchBusy) return;
            this.batchBusy = true;
            let orders = 0;
            let labels = 0;
            try {
                // du plus ancien au plus récent : l'ordre de la cuisine
                const queue = [...this.toPrint].sort((a, b) => Date.parse(a.created_at_iso) - Date.parse(b.created_at_iso));
                for (const order of queue) {
                    const r = await this.printOrder(order, "manual", { quiet: true });
                    if (r && r.ok) { orders += 1; labels += buildOrderLabels(order, this.buildOptions).total; }
                    if (r && !r.ok && !r.skipped) break; // une panne : inutile d'enchaîner (son message a déjà été affiché)
                }
                // UN seul message pour le lot, pas une pile de bandeaux.
                if (orders) alertService.success(`${labels} étiquette${labels > 1 ? "s" : ""} imprimée${labels > 1 ? "s" : ""} — ${orders} commande${orders > 1 ? "s" : ""}.`);
            } finally {
                this.batchBusy = false;
            }
        },

        /** Impression automatique : appelée après chaque sondage. */
        async autoTick() {
            if (this.unmounted || !this.settings.autoPrint || this.autoRunning || !this.printerReady) return;
            const baseline = new Set(Array.isArray(this.settings.autoPrintBaseline) ? this.settings.autoPrintBaseline : []);
            const nowMs = Date.now();
            const candidates = this.rows
                .filter((r) => {
                    if (!r.built.total || r.state.printed || r.state.busy) return false;
                    // Déjà sur le tableau à l'activation : jamais imprimée seule.
                    if (baseline.has(r.order.id)) return false;
                    const created = Date.parse(r.order.created_at_iso);
                    // Trop ancienne (probablement déjà servie) — sauf une commande programmée, dont l'heure est ailleurs.
                    if (!r.order.scheduled_at && Number.isFinite(created) && this.serverNow() - created > AUTO_MAX_AGE_MS) return false;
                    const h = this.autoHandled[r.order.id];
                    if (h) {
                        if (nowMs < h.until) return false;
                        // Déjà traitée dans cette session : jamais redemandée, même si le serveur n'a pas encore enregistré
                        // le verdict (sinon une commande se réimprimerait à chaque sondage). Seul un échec explicite SANS
                        // étiquette sortie peut être retenté.
                        if (h.final && r.state.kind !== "failed") return false;
                    }
                    if (r.state.kind === "failed") {
                        // échec sans étiquette sortie : retenté (par le verrou serveur) au plus 3 fois, espacées
                        if (r.state.partial) return false;
                        return !h || nowMs - h.at > AUTO_RETRY_GAP_MS;
                    }
                    return true;
                })
                .sort((a, b) => Date.parse(a.order.created_at_iso) - Date.parse(b.order.created_at_iso));
            if (!candidates.length) return;

            this.autoRunning = true;
            try {
                for (const row of candidates) {
                    if (this.unmounted || !this.settings.autoPrint || !this.printerReady) break;
                    const r = await this.printOrder(row.order, "auto");
                    const at = Date.now();
                    if (r && r.claim === "error") {
                        // réseau : on réessaiera au prochain sondage
                        const { [row.order.id]: _drop, ...rest } = this.autoHandled;
                        this.autoHandled = rest;
                    } else if (r && r.claim === "refused") {
                        // une autre tablette l'a prise : on ne redemande qu'après le délai de reprise du serveur
                        this.autoHandled = { ...this.autoHandled, [row.order.id]: { at, until: at + CLAIM_REFUSED_RETRY_MS } };
                    } else {
                        this.autoHandled = { ...this.autoHandled, [row.order.id]: { at, until: 0, final: true } };
                    }
                    if (r && !r.ok && !r.skipped) break;
                }
            } finally {
                this.autoRunning = false;
            }
        },
    },
};
</script>

<style scoped>
.lbl-auto {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 14px 16px; border-radius: 12px;
    border: 1px solid #cbd5e1; background: #fff; margin-bottom: 14px;
}
.lbl-auto.is-on { border-color: #86efac; background: #f0fdf4; }
.lbl-auto-text { flex: 1 1 260px; display: flex; flex-direction: column; font-size: 14px; color: #475569; }
.lbl-auto-text strong { font-size: 17px; color: #0f172a; }
.lbl-switch {
    position: relative; width: 84px; height: 52px; border-radius: 999px; border: 0; background: #cbd5e1; cursor: pointer; flex: 0 0 auto;
}
.lbl-switch[aria-checked="true"] { background: #16a34a; }
.lbl-switch-knob { position: absolute; top: 4px; left: 4px; width: 44px; height: 44px; border-radius: 50%; background: #fff; transition: transform .15s; }
.lbl-switch[aria-checked="true"] .lbl-switch-knob { transform: translateX(32px); }
.lbl-auto.is-paused { border-color: #fcd34d; background: #fffbeb; }
.lbl-auto.is-paused .lbl-switch[aria-checked="true"] { background: #f59e0b; }

.lbl-btn {
    min-height: 52px; padding: 0 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 17px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
}
.lbl-btn:disabled { opacity: 1; background: #e2e8f0; border-color: #cbd5e1; color: #64748b; cursor: not-allowed; }
.lbl-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.lbl-btn--light { background: #fff; border-color: #fff; color: #7f1d1d; }
.lbl-btn--ghost { background: transparent; color: #475569; }

.lbl-blocked {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; background: #b91c1c; color: #fff; border-radius: 12px;
    padding: 14px 18px; margin-bottom: 14px; font-size: 16px; animation: lbl-pulse 1.6s ease-in-out infinite;
}
.lbl-blocked-text { display: flex; flex-direction: column; gap: 2px; flex: 1 1 280px; }
@keyframes lbl-pulse { 50% { box-shadow: 0 0 0 6px rgba(185, 28, 28, .25); } }
.lbl-section { font-size: 17px; font-weight: 800; margin: 18px 0 8px; }
.lbl-section-toggle { background: none; border: 0; font: inherit; font-weight: 800; cursor: pointer; padding: 8px 0; min-height: 48px; }
.lbl-count { display: inline-block; min-width: 26px; text-align: center; background: #1a1a1a; color: #fff; border-radius: 999px; padding: 0 8px; font-size: 14px; margin-left: 4px; }
.lbl-orders--done .lbl-order { opacity: .88; }
.lbl-order.is-new { animation: lbl-new 6s ease-out; }
@keyframes lbl-new { 0%, 60% { box-shadow: 0 0 0 4px #F4501E; } 100% { box-shadow: none; } }
.lbl-age { font-size: 14px; font-weight: 700; border-radius: 999px; padding: 3px 12px; background: #e2e8f0; color: #334155; }
.lbl-age.is-warn { background: #fde68a; color: #78350f; }
.lbl-age.is-danger { background: #fecaca; color: #7f1d1d; }
.lbl-btn--small { min-height: 48px; padding: 0 14px; font-size: 14px; margin-top: 6px; }
.lbl-alert { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 8px 0; }
.lbl-empty { color: #64748b; padding: 28px 8px; text-align: center; font-size: 16px; }
.lbl-muted { color: #94a3b8; }

.lbl-orders { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.lbl-order {
    scroll-margin-top: 80px;
    border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; background: #fff;
    display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 16px; align-items: center;
}
.lbl-order > .lbl-order-head, .lbl-order > .lbl-order-body { grid-column: 1; }
.lbl-order > .lbl-order-actions { grid-column: 2; grid-row: 1 / span 2; align-self: center; flex-wrap: nowrap; }
.lbl-order > .lbl-previews { grid-column: 1 / -1; }
@media (max-width: 899px) {
    .lbl-order { grid-template-columns: 1fr; }
    .lbl-order > .lbl-order-actions { grid-column: 1; grid-row: auto; justify-content: stretch; margin-top: 8px; }
    .lbl-order > .lbl-order-actions .lbl-btn { flex: 1 1 0; justify-content: center; }
}
.lbl-order.is-todo { border-left: 6px solid #F4501E; }
.lbl-order.is-done { border-left: 6px solid #16a34a; background: #f8fffb; border-color: #d1fae5; }
.lbl-order.is-failed { border-left: 6px solid #dc2626; background: #fffafa; }
.lbl-order.is-busy { border-left: 6px solid #2563eb; }
.lbl-order-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.lbl-order-num { font-size: 24px; font-weight: 800; }
.lbl-order-source { background: #1a1a1a; color: #fff; border-radius: 6px; padding: 2px 10px; font-size: 13px; font-weight: 700; }
.lbl-order-time { color: #64748b; font-variant-numeric: tabular-nums; }
.lbl-order-customer { color: #334155; font-weight: 600; }
.lbl-chip { margin-left: auto; border-radius: 999px; padding: 6px 16px; font-weight: 800; font-size: 15px; background: #ffe4d6; color: #9a3412; }
.lbl-chip.is-done { background: #dcfce7; color: #166534; }
.lbl-chip.is-failed { background: #fee2e2; color: #991b1b; }
.lbl-chip.is-busy { background: #dbeafe; color: #1e40af; }
.lbl-order-body { margin: 8px 0 10px; }
.lbl-order-summary { margin: 0; font-size: 16px; color: #1e293b; }
.lbl-order-detail { margin: 4px 0 0; color: #991b1b; font-size: 14px; font-weight: 600; }
.lbl-order-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }

.lbl-previews { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; border-top: 1px dashed #e2e8f0; padding-top: 14px; }
.lbl-preview { margin: 0; width: min(100%, 348px); display: flex; flex-direction: column; }
.lbl-preview .lbl-btn { margin-top: auto; justify-content: center; }
.lbl-why { flex-basis: 100%; color: #475569; font-size: 14px; text-align: right; }
.lbl-preview img { width: 100%; height: auto; border: 1px solid #94a3b8; background: #fff; image-rendering: pixelated; display: block; }
.lbl-preview figcaption { text-align: center; color: #64748b; font-size: 13px; margin-top: 4px; }
</style>
