<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Étiquettes DLC — hygiène</h3>
            </div>

            <div class="db-card-body">
                <LabelPrinterBar :info="printerInfo" :status="printerState.status" :supported="printerSupported"
                                 :detected-media="detectedMedia" @connect="connectPrinter" @adopt-media="adoptDetectedMedia" />

                <div v-if="!selectMode && hasProducts" class="dlc-copies" role="group" aria-label="Nombre d'étiquettes">
                    <span class="dlc-copies-label">Étiquettes par appui</span>
                    <button v-for="n in copyChoices" :key="n" type="button" class="dlc-chip"
                            :class="{ 'is-on': copies === n }" :aria-pressed="copies === n ? 'true' : 'false'"
                            :data-testid="`dlc-copies-${n}`" @click="copies = n">
                        {{ n }}
                    </button>
                </div>

                <div v-if="hasProducts" class="dlc-modes" role="group" aria-label="Mode d'impression">
                    <button type="button" class="dlc-mode" :class="{ 'is-on': !selectMode }" data-testid="dlc-mode-single"
                            @click="setSelectMode(false)">Un appui = une étiquette</button>
                    <button type="button" class="dlc-mode" :class="{ 'is-on': selectMode }" data-testid="dlc-mode-list"
                            @click="setSelectMode(true)">Liste du jour (plusieurs produits)</button>
                </div>

                <p v-if="!selectMode && hasProducts" class="dlc-hint">
                    Appuyez sur un produit : l'étiquette s'imprime avec la <strong>date d'ouverture</strong> et la
                    <strong>date limite de consommation</strong>, calculées à l'instant par le serveur.
                </p>
                <p v-else-if="hasProducts" class="dlc-hint">
                    Touchez les produits préparés aujourd'hui (chaque appui ajoute une étiquette), puis
                    <strong>Imprimer la liste</strong> : toutes les étiquettes sortent d'un coup.
                </p>

                <div class="dlc-layout">
                <aside v-if="watchItems.length" class="dlc-watch" data-testid="dlc-watch" aria-label="Étiquettes à surveiller">
                    <h4 class="dlc-h4">À surveiller</h4>
                    <ul>
                        <li v-for="w in watchItems" :key="w.id" :class="`is-${w.level}`" data-testid="dlc-watch-item">
                            <span v-if="w.level === 'expired'" class="dlc-watch-icon" aria-hidden="true">⚠</span>
                            <span class="dlc-watch-body"><strong>{{ w.name }}</strong> <span class="dlc-watch-n">({{ w.copies }} étiquette{{ w.copies > 1 ? "s" : "" }})</span><br />{{ w.text }}</span>
                            <button v-if="w.level === 'expired'" type="button" class="dlc-btn dlc-btn--small dlc-btn--danger" :data-testid="`dlc-discard-${w.id}`"
                                    :disabled="discarding" @click="discard(w)">Jeté ✓</button>
                        </li>
                    </ul>
                </aside>
                    <div class="dlc-main">
                <div v-if="activeProducts.length > 8" class="dlc-search">
                        <label for="dlc-search" class="dlc-search-label">Chercher un produit</label>
                        <div class="dlc-search-row">
                            <input id="dlc-search" v-model="query" type="search" class="db-field-control" placeholder="oignons, tomates…"
                                   autocomplete="off" data-testid="dlc-search" />
                            <button v-if="query" type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-search-clear" @click="query = ''">Effacer</button>
                        </div>
                    </div>

                    <p v-if="loadError" class="dlc-alert" data-testid="dlc-error">{{ loadError }}</p>

                    <p v-if="!loaded && !loadError" class="dlc-empty" role="status">Chargement des produits…</p>
                    <div v-else-if="!activeProducts.length && !loadError" class="dlc-empty dlc-empty--cta" data-testid="dlc-empty">
                        <p>
                            <strong>Aucun produit pour l'instant.</strong> Ajoutez vos produits (oignons, tomates, olives, champignons…)
                            avec leur durée de conservation : chaque appui imprimera alors son étiquette.
                        </p>
                        <button type="button" class="dlc-btn dlc-btn--primary" data-testid="dlc-add-first" @click="startAdding">
                            Ajouter mon premier produit
                        </button>
                        <div class="dlc-suggest" data-testid="dlc-suggest">
                            <span class="dlc-suggest-label">Exemples à adapter à vos règles d'hygiène :</span>
                            <button v-for="sg in suggestions" :key="sg.name" type="button" class="dlc-chip dlc-chip--wide" @click="applySuggestion(sg)">
                                {{ sg.name }} · {{ sg.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="query && !visibleProducts.length" class="dlc-empty dlc-empty--inline" data-testid="dlc-no-match">
                            <span>Aucun produit ne correspond à « {{ query }} ».</span>
                            <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-add-from-query" @click="addFromQuery">Ajouter « {{ query }} »</button>
                        </div>
                    <div class="dlc-grid" data-testid="dlc-grid">
                        <div v-for="p in visibleProducts" :key="p.id" class="dlc-cell">
                            <button type="button" class="dlc-tile"
                                    :class="{ 'is-picked': selectMode && selection[p.id], 'is-expired': expiredNames.has(p.name) }"
                                    :disabled="busyId === p.id || listBusy" :data-testid="`dlc-product-${p.id}`" @click="onTile(p)">
                                <span v-if="selectMode && selection[p.id]" class="dlc-tile-count" data-testid="dlc-tile-count">× {{ selection[p.id] }}</span>
                                <span v-else-if="expiredNames.has(p.name)" class="dlc-tile-flag">⚠ Expirée</span>
                                <span class="dlc-tile-name">{{ p.name }}</span>
                                <span class="dlc-tile-life">{{ tileLife(p) }}</span>
                                <span v-if="busyId === p.id" class="dlc-tile-busy">Impression…</span>
                            </button>
                            <button v-if="selectMode && selection[p.id]" type="button" class="dlc-minus" :aria-label="`Retirer une étiquette ${p.name}`"
                                    :data-testid="`dlc-minus-${p.id}`" @click="minus(p)">−</button>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Liste du jour : la sélection, prête à partir d'un seul appui. -->
                <div v-if="selectMode && (selectionTotal > 0 || listBusy)" class="dlc-basket" data-testid="dlc-basket">
                    <span class="dlc-basket-text">
                        <strong data-testid="dlc-basket-total">{{ selectionTotal }} étiquette{{ selectionTotal > 1 ? "s" : "" }}</strong>
                        — {{ selectionProducts }} produit{{ selectionProducts > 1 ? "s" : "" }}
                    </span>
                    <button type="button" class="dlc-btn" :disabled="!selectionTotal || listBusy" data-testid="dlc-basket-clear"
                            @click="selection = {}">Vider</button>
                    <button type="button" class="dlc-btn dlc-btn--primary" :disabled="!selectionTotal || listBusy"
                            data-testid="dlc-basket-print" @click="printSelection">
                        {{ listBusy ? "Impression…" : "🖨 Imprimer la liste" }}
                    </button>
                </div>

                <!-- Dernier résultat : on voit ce qui est parti, et la date exacte imprimée. -->
                <div v-if="last" ref="lastBox" class="dlc-last" :class="last.ok ? 'is-ok' : 'is-ko'" data-testid="dlc-last" role="status">
                    <div v-if="last.batch" class="dlc-last-text" data-testid="dlc-last-batch">
                        <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.total }} étiquette{{ last.total > 1 ? "s" : "" }} imprimée{{ last.total > 1 ? "s" : "" }}
                            — {{ last.batch.filter((b) => b.ok).map((b) => `${b.name} ×${b.copies}`).join(", ") }}</strong>
                        <span v-if="last.failed.length" class="dlc-last-warn">Non imprimé : {{ last.failed.map((b) => `${b.name} (${b.message})`).join(" ; ") }}</span>
                    </div>
                    <div v-else class="dlc-last-text">
                        <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.name }} — {{ last.copies }} étiquette{{ last.copies > 1 ? "s" : "" }}</strong>
                        <span v-if="last.ok">Ouvert le {{ last.opened }} · À consommer avant <b>{{ last.expires }}</b></span>
                        <span v-else>{{ last.message }}</span>
                        <span v-if="last.ok && last.message" class="dlc-last-warn">{{ last.message }}</span>
                    </div>
                    <img v-if="last.preview" :src="last.preview" alt="Dernière étiquette" class="dlc-last-img" />
                </div>

                <!-- ── Gérer les produits ───────────────────────────────────────────── -->
                <div class="dlc-section">
                    <button type="button" class="dlc-toggle" data-testid="dlc-manage-toggle" @click="manage = !manage">
                        {{ manage ? "▾" : "▸" }} Gérer les produits ({{ products.length }})
                    </button>

                    <div v-if="manage" class="dlc-manage" data-testid="dlc-manage">
                        <form class="dlc-form" data-testid="dlc-form" @submit.prevent="saveProduct">
                            <div class="dlc-field dlc-field--name">
                                <label for="dlc-name">Produit</label>
                                <input id="dlc-name" v-model="form.name" type="text" maxlength="120" class="db-field-control"
                                       placeholder="Oignons émincés" data-testid="dlc-form-name" />
                            </div>
                            <div class="dlc-field">
                                <label for="dlc-value">Durée</label>
                                <input id="dlc-value" v-model.number="form.shelf_life_value" type="number" min="1" inputmode="numeric"
                                       class="db-field-control" data-testid="dlc-form-value" />
                            </div>
                            <div class="dlc-field">
                                <label for="dlc-unit">Unité</label>
                                <select id="dlc-unit" v-model="form.shelf_life_unit" class="db-field-control" data-testid="dlc-form-unit">
                                    <option value="hours">heures</option>
                                    <option value="days">jours</option>
                                </select>
                            </div>
                            <div class="dlc-field">
                                <label for="dlc-storage">Conservation</label>
                                <input id="dlc-storage" v-model="form.storage" type="text" maxlength="60" class="db-field-control"
                                       placeholder="+4 °C" data-testid="dlc-form-storage" />
                            </div>
                            <div class="dlc-form-actions">
                                <button type="submit" class="dlc-btn dlc-btn--primary" :disabled="saving" data-testid="dlc-form-save">
                                    {{ form.id ? "Enregistrer" : "Ajouter" }}
                                </button>
                                <button v-if="form.id" type="button" class="dlc-btn" @click="resetForm">Annuler</button>
                            </div>
                            <p v-if="formError" class="dlc-alert dlc-form-error" data-testid="dlc-form-error">{{ formError }}</p>
                        </form>

                        <ul class="dlc-list">
                            <li v-for="p in products" :key="p.id" class="dlc-row" :class="{ 'is-off': !p.is_active }"
                                :data-testid="`dlc-row-${p.id}`">
                                <span class="dlc-row-name">{{ p.name }}</span>
                                <span class="dlc-row-life">{{ [lifeText(p), p.storage].filter(Boolean).join(" · ") }}</span>
                                <button type="button" class="dlc-btn dlc-btn--small" @click="toggleActive(p)">
                                    {{ p.is_active ? "Masquer" : "Réactiver" }}
                                </button>
                                <button type="button" class="dlc-btn dlc-btn--small" :data-testid="`dlc-edit-${p.id}`" @click="edit(p)">Modifier</button>
                                <button type="button" class="dlc-btn dlc-btn--small dlc-btn--danger" :data-testid="`dlc-delete-${p.id}`"
                                        @click="askDelete(p)">
                                    {{ deletingId === p.id ? "Confirmer" : "Supprimer" }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ── Historique ────────────────────────────────────────────────────── -->
                <div class="dlc-section">
                    <h4 class="dlc-h4">Dernières étiquettes imprimées</h4>
                    <p v-if="!history.length" class="dlc-muted">Rien d'imprimé pour l'instant.</p>
                    <ul v-else class="dlc-history" data-testid="dlc-history">
                        <li v-for="h in history.slice(0, 20)" :key="h.id" class="dlc-history-row">
                            <span class="dlc-history-when">{{ short(h.created_at) }}</span>
                            <span class="dlc-history-name">{{ h.product_name }} ×{{ h.copies }}</span>
                            <span class="dlc-history-dlc">DLC {{ short(h.expires_at) }}</span>
                            <span class="dlc-history-by">{{ h.by || "" }}</span>
                            <span class="dlc-history-state" :class="`is-${h.status}`">{{ stateText(h.status) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner] Étiquettes d'hygiène : « oignons, tomates, olives, champignons… — date
 * d'ouverture, date limite de consommation ». Un appui = une étiquette.
 *
 * Les dates ne viennent PAS de la tablette : le serveur calcule ouverture et DLC avec son horloge, les
 * journalise (traçabilité d'hygiène) et les renvoie ; le papier est ainsi identique au journal. Si le réseau est
 * coupé, on imprime quand même avec l'heure du serveur connue au dernier échange — l'étiquette prime sur le
 * journal — et on le dit.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import labelPrinterMixin from "../../../helpers/labelPrinterMixin";
import LabelPrinterBar from "./LabelPrinterBar";
import { dlcLabelBlocks, formatDlcDate, shelfLifeText, watchList } from "../../../helpers/dlcLabelBuilder";
import { printBlocks, previewUrls } from "../../../helpers/labelPrintJob";
import { labelResultQueue, labelInflight } from "../../../helpers/labelResultQueue";

const EMPTY_FORM = () => ({ id: null, name: "", shelf_life_value: 2, shelf_life_unit: "days", storage: "" });

export default {
    name: "LabelDlcComponent",
    components: { LabelPrinterBar },
    mixins: [labelPrinterMixin],
    data() {
        return {
            products: [],
            history: [],
            copies: 1,
            copyChoices: [1, 2, 3, 5, 10],
            busyId: null,
            loaded: false,
            discarding: false,
            selectMode: false,
            selection: {},
            listBusy: false,
            last: null,
            loadError: "",
            manage: false,
            form: EMPTY_FORM(),
            formError: "",
            saving: false,
            deletingId: null,
            serverOffsetMs: 0,
            query: "",
            copiesStorageKey: "label_dlc_copies",
            timezone: "Europe/Paris",
        };
    },
    computed: {
        activeProducts() {
            return this.products.filter((p) => p.is_active);
        },
        hasProducts() {
            return !this.loaded || this.activeProducts.length > 0;
        },
        /** Produits dont un lot imprimé est échu : repérés directement sur leur tuile. */
        expiredNames() {
            return new Set(this.watchItems.filter((w) => w.level === "expired").map((w) => w.name));
        },
        suggestions() {
            return [
                { name: "Oignons émincés", value: 24, unit: "hours", label: "24 h" },
                { name: "Tomates tranchées", value: 24, unit: "hours", label: "24 h" },
                { name: "Champignons", value: 24, unit: "hours", label: "24 h" },
                { name: "Olives", value: 3, unit: "days", label: "3 jours" },
                { name: "Fromage râpé", value: 3, unit: "days", label: "3 jours" },
            ];
        },
        /** Les plus utilisés (30 jours) d'abord, puis l'ordre alphabétique ; filtrés par la recherche. */
        visibleProducts() {
            const q = this.norm(this.query);
            return [...this.activeProducts]
                .filter((p) => !q || this.norm(p.name).includes(q))
                .sort((a, b) => (b.uses_30d || 0) - (a.uses_30d || 0) || a.name.localeCompare(b.name, "fr"));
        },
        watchItems() {
            return watchList(this.history, Date.now() + this.serverOffsetMs);
        },
        selectionTotal() {
            return Object.values(this.selection).reduce((n, c) => n + c, 0);
        },
        selectionProducts() {
            return Object.keys(this.selection).length;
        },
    },
    watch: {
        copies(n) {
            try { window.localStorage.setItem(this.copiesStorageKey, String(n)); } catch (_) { /* facultatif */ }
        },
    },
    mounted() {
        try {
            const saved = Number(window.localStorage.getItem(this.copiesStorageKey));
            if (this.copyChoices.includes(saved)) this.copies = saved;
        } catch (_) { /* facultatif */ }
        labelInflight(axios).recover();
        labelResultQueue(axios).flush();
        this.loadProducts();
        this.loadHistory();
    },
    methods: {
        lifeText(p) {
            return shelfLifeText(p.shelf_life_value, p.shelf_life_unit);
        },

        /** Ouvre la gestion et place le curseur sur le nom (premier produit, ou exemple choisi). */
        startAdding() {
            this.manage = true;
            this.$nextTick(() => {
                const el = document.getElementById("dlc-name");
                if (el) { el.scrollIntoView({ block: "center" }); el.focus(); }
            });
        },

        /** Pré‑remplit le formulaire avec un exemple : le restaurant valide (ou corrige) la durée, rien n'est créé tout seul. */
        applySuggestion(sg) {
            this.form = { id: null, name: sg.name, shelf_life_value: sg.value, shelf_life_unit: sg.unit, storage: "" };
            this.startAdding();
        },

        /** « Jeté » : le personnel a retiré le lot échu — l'alerte disparaît (la ligne de journal reste). */
        async discard(w) {
            this.discarding = true;
            try {
                await axios.post("admin/labels/prints/discard", { ids: w.ids });
                await this.loadHistory();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Impossible d'enregistrer : réessayez."));
            } finally {
                this.discarding = false;
            }
        },

        /** Recherche sans résultat : un appui pour AJOUTER ce produit (le nom est déjà saisi). */
        addFromQuery() {
            const name = String(this.query || "").trim();
            this.query = "";
            this.form = { id: null, name, shelf_life_value: 2, shelf_life_unit: "days", storage: "" };
            this.startAdding();
        },

        minus(p) {
            const n = (this.selection[p.id] || 0) - 1;
            if (n <= 0) {
                const { [p.id]: _gone, ...rest } = this.selection;
                this.selection = rest;
            } else {
                this.selection = { ...this.selection, [p.id]: n };
            }
        },

        norm(v) {
            return String(v || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
        },

        tileLife(p) {
            return [`DLC +${this.lifeText(p)}`, p.storage].filter(Boolean).join(" · ");
        },

        short(iso) {
            if (!iso) return "";
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return "";
            try {
                return new Intl.DateTimeFormat("fr-FR", {
                    timeZone: this.timezone, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit",
                }).format(d).replace(",", "");
            } catch (_) {
                return "";
            }
        },

        stateText(s) {
            return { printed: "imprimée", failed: "échec", unconfirmed: "non confirmée", claimed: "en cours" }[s] || s;
        },

        apiMessage(e, fallback) {
            const data = e && e.response && e.response.data;
            if (data && data.errors) {
                const first = Object.values(data.errors)[0];
                if (Array.isArray(first) && first[0]) return first[0];
            }
            return (data && data.message) || fallback;
        },

        async loadProducts() {
            try {
                const { data } = await axios.get("admin/labels/products");
                this.products = data.data || [];
                if (!this.products.length) this.manage = true;
                if (data.meta) {
                    if (data.meta.timezone) this.timezone = data.meta.timezone;
                    const server = Date.parse(data.meta.server_now);
                    if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                }
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                this.loadError = "Liste des produits indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },

        async loadHistory() {
            try {
                const { data } = await axios.get("admin/labels/history", { params: { kind: "dlc" } });
                this.history = data.data || [];
            } catch (_) {
                this.history = [];
            }
        },

        setSelectMode(on) {
            this.selectMode = on;
            this.selection = {};
        },

        /** Appui sur une tuile : imprime tout de suite, ou ajoute une étiquette à la liste du jour. */
        onTile(p) {
            if (!this.selectMode) return this.printProduct(p);
            const n = (this.selection[p.id] || 0) + 1;
            this.selection = { ...this.selection, [p.id]: Math.min(n, 20) };
            return null;
        },

        /** Appui sur un produit : imprime `copies` étiquettes. */
        async printProduct(product) {
            if (this.busyId || this.listBusy) return;
            this.busyId = product.id;
            try {
                await this.printOne(product, this.copies);
            } finally {
                this.busyId = null;
                this.loadHistory();
            }
        },

        /** Liste du jour : un travail d'impression par produit, dans l'ordre d'affichage ; on s'arrête à la première panne. */
        async printSelection() {
            if (this.listBusy || !this.selectionTotal) return;
            this.listBusy = true;
            const batch = [];
            try {
                for (const p of this.visibleProducts) {
                    const count = this.selection[p.id];
                    if (!count) continue;
                    const result = await this.printOne(p, count, { quiet: true });
                    batch.push({ name: p.name, copies: count, ok: result.ok, message: result.message });
                    if (!result.ok) break; // une panne : inutile d'enchaîner, la sélection reste pour réessayer
                    const { [p.id]: _done, ...rest } = this.selection;
                    this.selection = rest;
                }
                // UN seul récapitulatif du lot (et non le dernier produit seulement).
                const failed = batch.filter((b) => !b.ok);
                this.last = {
                    ok: failed.length === 0, batch, failed,
                    total: batch.filter((b) => b.ok).reduce((n, b) => n + b.copies, 0), preview: "",
                };
                this.revealLast();
            } finally {
                this.listBusy = false;
                this.loadHistory();
            }
        },

        /** Amène le résultat à l'écran : sans lui, on croit que rien ne s'est passé et on retape (doublon d'étiquettes). */
        revealLast() {
            this.$nextTick(() => {
                const el = this.$refs.lastBox;
                if (el && el.scrollIntoView) el.scrollIntoView({ block: "nearest", behavior: "smooth" });
            });
        },

        /** Réserve les dates côté serveur, imprime, rend compte du résultat. @returns {Promise<{ok:boolean}>} */
        async printOne(product, copies, opts = {}) {
            let data = null;
            let offline = false;
            try {
                const res = await axios.post("admin/labels/dlc/print", { product_id: product.id, copies });
                data = res.data;
            } catch (e) {
                if (e && e.response && e.response.status && e.response.status < 500) {
                    const message = this.apiMessage(e, "Impression refusée.");
                    alertService.error(message);
                    return { ok: false, message };
                }
                // Réseau coupé : on imprime avec l'horloge du serveur connue au dernier échange.
                offline = true;
                const now = new Date(Date.now() + this.serverOffsetMs);
                const expires = new Date(now);
                if (product.shelf_life_unit === "hours") expires.setHours(expires.getHours() + product.shelf_life_value);
                else expires.setDate(expires.getDate() + product.shelf_life_value);
                data = {
                    log_id: null, name: product.name, storage: product.storage,
                    shelf_life_value: product.shelf_life_value, shelf_life_unit: product.shelf_life_unit,
                    opened_at: now.toISOString(), expires_at: expires.toISOString(), copies, by: null,
                };
            }

            const blocks = dlcLabelBlocks({
                name: data.name, openedAt: data.opened_at, expiresAt: data.expires_at, storage: data.storage,
                by: data.by, shelfValue: data.shelf_life_value, shelfUnit: data.shelf_life_unit,
            });
            const resultUrl = data.log_id ? `admin/labels/prints/${data.log_id}/result` : null;
            if (resultUrl) labelInflight(axios).begin(data.log_id, resultUrl, data.attempt || null);
            const result = await printBlocks(this.printerInstance(), Array.from({ length: data.copies }, () => blocks), this.settings);

            if (data.log_id) {
                await labelResultQueue(axios).send(resultUrl, {
                    status: result.ok ? (result.unconfirmed ? "unconfirmed" : "printed") : "failed",
                    message: result.message ? String(result.message).slice(0, 290) : null,
                    printed_pages: result.ok ? data.copies : (result.pages || 0),
                    attempt: data.attempt || null,
                });
                labelInflight(axios).end(data.log_id);
            }

            let preview = "";
            try { preview = previewUrls([blocks], this.settings).urls[0] || ""; } catch (_) { preview = ""; }

            const warn = [offline ? "Hors ligne : étiquette imprimée, journal non enregistré." : "", result.ok && result.unconfirmed ? result.message : ""]
                .filter(Boolean).join(" ");
            if (!opts.quiet) {
                this.last = {
                    ok: result.ok, name: data.name, copies: data.copies,
                    opened: formatDlcDate(data.opened_at, this.timezone), expires: formatDlcDate(data.expires_at, this.timezone),
                    message: result.ok ? warn : result.message, preview,
                };
                this.revealLast();
            }
            if (!result.ok) alertService.error(result.message);
            return result;
        },

        // ── gestion des produits ─────────────────────────────────────────────
        resetForm() {
            this.form = EMPTY_FORM();
            this.formError = "";
        },

        edit(p) {
            this.form = {
                id: p.id, name: p.name, shelf_life_value: p.shelf_life_value,
                shelf_life_unit: p.shelf_life_unit, storage: p.storage || "",
            };
            this.formError = "";
        },

        async saveProduct() {
            if (this.saving) return;
            this.formError = "";
            if (!String(this.form.name).trim()) { this.formError = "Indiquez le nom du produit."; return; }
            if (!(Number(this.form.shelf_life_value) >= 1)) { this.formError = "Indiquez une durée d'au moins 1."; return; }
            this.saving = true;
            const body = {
                name: this.form.name, shelf_life_value: Number(this.form.shelf_life_value),
                shelf_life_unit: this.form.shelf_life_unit, storage: this.form.storage || null,
            };
            try {
                if (this.form.id) await axios.put(`admin/labels/products/${this.form.id}`, body);
                else await axios.post("admin/labels/products", body);
                this.resetForm();
                await this.loadProducts();
            } catch (e) {
                this.formError = this.apiMessage(e, "Enregistrement impossible.");
            } finally {
                this.saving = false;
            }
        },

        async toggleActive(p) {
            try {
                await axios.put(`admin/labels/products/${p.id}`, {
                    name: p.name, shelf_life_value: p.shelf_life_value, shelf_life_unit: p.shelf_life_unit,
                    storage: p.storage, is_active: !p.is_active,
                });
                await this.loadProducts();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Modification impossible."));
            }
        },

        async askDelete(p) {
            // Suppression en deux appuis (pas de boîte de dialogue : elle bloque la tablette).
            if (this.deletingId !== p.id) {
                this.deletingId = p.id;
                setTimeout(() => { if (this.deletingId === p.id) this.deletingId = null; }, 4000);
                return;
            }
            this.deletingId = null;
            try {
                await axios.delete(`admin/labels/products/${p.id}`);
                if (this.form.id === p.id) this.resetForm();
                await this.loadProducts();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Suppression impossible."));
            }
        },
    },
};
</script>

<style scoped>
.dlc-layout { display: flex; flex-direction: column; gap: 12px; }
.dlc-aside, .dlc-watch { order: -1; }
@media (min-width: 1100px) {
    .dlc-layout { flex-direction: row; align-items: flex-start; }
    .dlc-main { flex: 1 1 auto; min-width: 0; }
    .dlc-watch { order: 2; flex: 0 0 340px; position: sticky; top: 8px; }
}
.dlc-watch { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 12px; padding: 10px 16px; margin: 6px 0 14px; }
.dlc-watch li { display: flex; gap: 8px; align-items: flex-start; }
.dlc-watch li.is-expired { background: #fee2e2; border-radius: 8px; padding: 6px 8px; margin: 2px -8px; }
.dlc-watch-body { flex: 1 1 auto; min-width: 0; }
.dlc-empty--inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: center; }
.dlc-watch-icon { font-size: 18px; line-height: 1.2; }
.dlc-watch-n { color: #64748b; font-weight: 400; }
.dlc-cell { position: relative; }
.dlc-minus { position: absolute; right: 8px; bottom: 8px; width: 48px; height: 48px; border-radius: 12px; border: 2px solid #D63D0B; background: #fff; color: #D63D0B; font-size: 26px; font-weight: 800; cursor: pointer; }
.dlc-tile { width: 100%; height: 100%; }
.dlc-tile.is-expired { border-color: #dc2626; background: #fef2f2; }
.dlc-tile-flag { position: absolute; top: 8px; right: 10px; background: #dc2626; color: #fff; border-radius: 999px; padding: 2px 10px; font-weight: 800; font-size: 13px; }
.dlc-tile.is-expired .dlc-tile-name { padding-right: 84px; }
.dlc-tile.is-picked .dlc-tile-life { padding-right: 64px; }
.dlc-tile.is-picked .dlc-tile-name { padding-top: 22px; padding-right: 0; }
.dlc-tile.is-picked.is-expired .dlc-tile-name { padding-right: 84px; }
.dlc-search-row { display: flex; gap: 8px; align-items: center; }
.dlc-search-row .db-field-control { min-height: 48px; flex: 1 1 auto; }
.dlc-empty--cta { text-align: left; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 18px; }
.dlc-suggest { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.dlc-suggest-label { width: 100%; color: #64748b; font-size: 14px; }
.dlc-chip--wide { min-width: 0; padding: 0 14px; font-size: 15px; }
.dlc-form .db-field-control { min-height: 48px; }
.dlc-toggle { min-height: 48px; }
.dlc-watch ul { list-style: none; margin: 0; padding: 0; }
.dlc-watch li { padding: 4px 0; font-size: 15px; }
.dlc-watch li.is-expired { color: #991b1b; font-weight: 700; }
.dlc-watch li.is-today { color: #92400e; }
.dlc-search { margin: 6px 0 12px; max-width: 420px; }
.dlc-search-label { display: block; font-size: 14px; color: #334155; margin-bottom: 4px; }
.dlc-grid { padding-bottom: 8px; }
.dlc-copies { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.dlc-copies-label { color: #475569; font-weight: 600; margin-right: 4px; }
.dlc-chip {
    min-width: 52px; min-height: 48px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 18px; font-weight: 800; cursor: pointer;
}
.dlc-chip.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-hint { color: #64748b; font-size: 14px; margin: 6px 0 14px; }
.dlc-modes { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 4px; }
.dlc-mode {
    min-height: 48px; padding: 0 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 15px; font-weight: 700; cursor: pointer;
}
.dlc-mode.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-tile.is-picked { border-color: #F4501E; background: #fff7f3; }
.dlc-tile.is-picked .dlc-tile-name, .dlc-tile:disabled .dlc-tile-name { padding-right: 76px; }
.dlc-tile-count {
    position: absolute; top: 8px; left: 10px; background: #D63D0B; color: #fff; border-radius: 999px;
    padding: 2px 12px; font-weight: 800; font-size: 15px;
}
.dlc-basket {
    position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: #fff; border: 2px solid #F4501E; border-radius: 12px; padding: 12px 16px; margin: 0 0 16px;
    box-shadow: 0 -4px 14px rgba(15, 23, 42, .12);
}
.dlc-basket-text { flex: 1 1 200px; font-size: 16px; }
.dlc-alert { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 8px 0; }
.dlc-empty { color: #64748b; padding: 24px 8px; text-align: center; }
.dlc-muted { color: #94a3b8; }

.dlc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
.dlc-tile {
    position: relative; min-height: 96px; border-radius: 14px; border: 2px solid #e2e8f0; background: #fff; padding: 12px 14px;
    display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 4px; text-align: left; cursor: pointer;
}
.dlc-tile:active { border-color: #F4501E; background: #fff7f3; }
.dlc-tile:disabled { opacity: .6; cursor: progress; }
.dlc-tile-name { font-size: 20px; font-weight: 800; line-height: 1.15; color: #1a1a1a; }
.dlc-tile-life { font-size: 14px; color: #64748b; }
.dlc-tile-busy { position: absolute; top: 8px; right: 10px; font-size: 12px; color: #2563eb; font-weight: 700; }

.dlc-last { display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.dlc-last.is-ok { background: #ecfdf5; border: 1px solid #a7f3d0; }
.dlc-last.is-ko { background: #fef2f2; border: 1px solid #fca5a5; }
.dlc-last-text { display: flex; flex-direction: column; gap: 2px; font-size: 16px; }
.dlc-last-warn { color: #92400e; font-weight: 600; }
.dlc-last-img { width: 220px; max-width: 100%; border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; }

.dlc-section { border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 14px; }
.dlc-toggle { background: none; border: 0; font-size: 17px; font-weight: 800; cursor: pointer; padding: 8px 0; }
.dlc-h4 { font-size: 16px; font-weight: 800; margin: 0 0 8px; }
.dlc-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin: 10px 0 16px; }
.dlc-field { flex: 1 1 130px; }
.dlc-field--name { flex: 2 1 240px; }
.dlc-field label { display: block; font-size: 13px; color: #475569; margin-bottom: 4px; }
.dlc-form-actions { display: flex; gap: 8px; }
.dlc-form-error { flex: 1 1 100%; margin: 0; }
.dlc-btn {
    min-height: 48px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff;
    font-size: 16px; font-weight: 700; cursor: pointer;
}
.dlc-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.dlc-btn:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #64748b; cursor: not-allowed; }
.dlc-btn--small { min-height: 48px; padding: 0 12px; font-size: 14px; }
.dlc-btn--danger { color: #b91c1c; border-color: #fca5a5; }
.dlc-list { list-style: none; margin: 0; padding: 0; }
.dlc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; }
.dlc-row.is-off { opacity: .55; }
.dlc-row-name { flex: 1 1 180px; font-weight: 700; font-size: 16px; }
.dlc-row-life { color: #64748b; font-size: 14px; flex: 0 1 160px; }

.dlc-history { list-style: none; margin: 0; padding: 0; }
.dlc-history-row { display: flex; gap: 12px; flex-wrap: wrap; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; align-items: baseline; }
.dlc-history-when { color: #94a3b8; font-variant-numeric: tabular-nums; min-width: 92px; }
.dlc-history-name { font-weight: 700; flex: 1 1 160px; }
.dlc-history-dlc { color: #475569; }
.dlc-history-by { color: #94a3b8; min-width: 60px; }
.dlc-history-state { font-weight: 700; }
.dlc-history-state.is-printed { color: #166534; }
.dlc-history-state.is-failed { color: #991b1b; }
.dlc-history-state.is-unconfirmed { color: #92400e; }
</style>
