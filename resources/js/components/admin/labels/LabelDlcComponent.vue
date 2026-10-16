<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Étiquettes DLC — hygiène</h3>
            </div>

            <div class="db-card-body">
                <LabelPrinterBar :info="printerInfo" :status="printerState.status" :supported="printerSupported"
                                 :detected-media="detectedMedia" @connect="connectPrinter" @adopt-media="adoptDetectedMedia" />

                <!-- Dernier résultat : on voit ce qui est parti, et la date exacte imprimée. -->
                <div v-if="last" ref="lastBox" class="dlc-last" :class="last.ok ? 'is-ok' : 'is-ko'" data-testid="dlc-last" role="status">
                    <div v-if="last.batch" class="dlc-last-text" data-testid="dlc-last-batch">
                        <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.total }} étiquette{{ last.total > 1 ? "s" : "" }} imprimée{{ last.total > 1 ? "s" : "" }}
                            — {{ last.batch.filter((b) => b.ok).map((b) => `${b.name} ×${b.copies}`).join(", ") }}</strong>
                        <span v-if="last.failed.length" class="dlc-last-warn">Non imprimé : {{ last.failed.map((b) => `${b.name} (${b.message})`).join(" ; ") }}</span>
                        <span v-if="last.skipped.length" class="dlc-last-warn" data-testid="dlc-last-skipped">
                            À imprimer un par un (DLC du fabricant à saisir) : {{ last.skipped.join(", ") }}
                        </span>
                    </div>
                    <div v-else class="dlc-last-text">
                        <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.name }} — {{ last.copies }} étiquette{{ last.copies > 1 ? "s" : "" }}<span v-if="last.actionLabel"> · {{ last.actionLabel }}</span></strong>
                        <span v-if="last.ok">{{ last.verb }} {{ last.opened }} · À consommer jusqu’au <b>{{ last.expires }}</b><span v-if="last.lot"> · Lot {{ last.lot }}</span></span>
                        <span v-else>{{ last.message }}</span>
                        <span v-if="last.ok && last.capped" class="dlc-last-warn">DLC limitée à celle du fabricant.</span>
                        <span v-if="last.ok && last.message" class="dlc-last-warn">{{ last.message }}</span>
                    </div>
                    <img v-if="last.preview" :src="last.preview" alt="Dernière étiquette" class="dlc-last-img" />
                </div>

                <div v-if="undo" class="dlc-undo" role="status" data-testid="dlc-undo">
                    <span>Lot « {{ undo.name }} » marqué jeté.</span>
                    <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-undo-btn" @click="undoDiscard">Annuler</button>
                </div>

                <p v-if="loadError" class="dlc-alert" data-testid="dlc-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="dlc-empty" role="status">Chargement des produits…</p>

                <!-- ── Aucun produit : on aide à démarrer ─────────────────────────────────── -->
                <div v-else-if="loaded && !activeProducts.length && !loadError" class="dlc-empty dlc-empty--cta" data-testid="dlc-empty">
                    <p>
                        <strong>Aucun produit pour l'instant.</strong> Créez vos fiches (oignons, tomates, olives, champignons…) : famille,
                        durées après ouverture ou décongélation, conservation, allergènes. Chaque appui imprimera ensuite l'étiquette.
                    </p>
                    <button type="button" class="dlc-btn dlc-btn--primary" data-testid="dlc-add-first" @click="openEditor(null)">
                        ＋ Créer mon premier produit
                    </button>
                    <div class="dlc-suggest" data-testid="dlc-suggest">
                        <span class="dlc-suggest-label">Ou partez d'un exemple (durées à adapter à votre plan de maîtrise sanitaire) :</span>
                        <button v-for="sg in suggestions" :key="sg.name" type="button" class="dlc-chip dlc-chip--wide"
                                :data-testid="`dlc-suggest-${sg.slug}`" @click="openEditor(sg)">
                            {{ sg.name }}
                        </button>
                    </div>
                </div>

                <template v-else-if="loaded">
                    <!-- ── Barre d'outils ─────────────────────────────────────────────────── -->
                    <div class="dlc-toolbar">
                        <div class="dlc-search-row">
                            <label for="dlc-search" class="sr-only">Chercher un produit</label>
                            <input id="dlc-search" v-model="query" type="search" class="db-field-control" placeholder="🔍 Chercher un produit…"
                                   autocomplete="off" data-testid="dlc-search" />
                            <button v-if="query" type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-search-clear" @click="query = ''">Effacer</button>
                        </div>
                        <button type="button" class="dlc-btn dlc-btn--primary" data-testid="dlc-new" @click="openEditor(null)">＋ Nouveau produit</button>
                    </div>

                    <div class="dlc-cats" role="tablist" aria-label="Familles">
                        <button type="button" class="dlc-cat" role="tab" :aria-selected="category === '' ? 'true' : 'false'" :class="{ 'is-on': category === '' }"
                                data-testid="dlc-cat-all" @click="category = ''">Tous <span class="dlc-cat-n">{{ activeProducts.length }}</span></button>
                        <button v-for="c in categoryTabs" :key="c.name" type="button" class="dlc-cat" role="tab"
                                :aria-selected="category === c.name ? 'true' : 'false'" :class="{ 'is-on': category === c.name }"
                                :data-testid="`dlc-cat-${c.slug}`" @click="category = c.name">
                            <span class="dlc-dot" :style="{ background: colorOf(c.name) }" aria-hidden="true"></span>{{ c.name }}
                            <span class="dlc-cat-n">{{ c.count }}</span>
                        </button>
                    </div>

                    <div class="dlc-modes" role="group" aria-label="Mode d'impression">
                        <button type="button" class="dlc-mode" :class="{ 'is-on': !selectMode }" data-testid="dlc-mode-single"
                                @click="setSelectMode(false)">Un appui = une étiquette</button>
                        <button type="button" class="dlc-mode" :class="{ 'is-on': selectMode }" data-testid="dlc-mode-list"
                                @click="setSelectMode(true)">☑ Sélection multiple</button>
                        <template v-if="selectMode">
                            <button type="button" class="dlc-btn dlc-btn--small" :disabled="!visibleProducts.length" data-testid="dlc-select-all"
                                    @click="selectAll">Tout sélectionner ({{ visibleProducts.length }})</button>
                            <button type="button" class="dlc-btn dlc-btn--small" :disabled="!selectionTotal" data-testid="dlc-select-none"
                                    @click="selection = {}">Aucun</button>
                            <label class="dlc-batch-action">
                                <span>Événement</span>
                                <select v-model="batchAction" class="db-field-control" data-testid="dlc-batch-action">
                                    <option value="">Celui de chaque produit</option>
                                    <option v-for="a in actionChoices" :key="a.key" :value="a.key">{{ a.label }}</option>
                                </select>
                            </label>
                        </template>
                    </div>

                    <p class="dlc-hint">
                        <template v-if="!selectMode">
                            Touchez un produit : l'étiquette sort aussitôt (événement et nombre habituels). <b>⋯</b> pour choisir
                            l'événement, le nombre ou saisir la DLC du fabricant.
                        </template>
                        <template v-else>
                            Touchez les produits préparés (chaque appui ajoute une étiquette), ou <b>Tout sélectionner</b>, puis
                            <b>Imprimer la sélection</b>.
                        </template>
                    </p>

                    <div class="dlc-layout">
                        <aside v-if="watchItems.length" class="dlc-watch" data-testid="dlc-watch" aria-label="Étiquettes à surveiller">
                            <h4 class="dlc-h4">À surveiller</h4>
                            <ul>
                                <li v-for="w in watchItems" :key="w.key" :class="`is-${w.level}`" data-testid="dlc-watch-item">
                                    <span v-if="w.level === 'expired'" class="dlc-watch-icon" aria-hidden="true">⚠</span>
                                    <span class="dlc-watch-body"><strong>{{ w.name }}</strong> <span class="dlc-watch-n">({{ w.copies }} étiquette{{ w.copies > 1 ? "s" : "" }})</span><br />{{ w.text }}</span>
                                    <button v-if="w.level === 'expired'" type="button" class="dlc-btn dlc-btn--small dlc-btn--danger" :data-testid="`dlc-discard-${w.id}`"
                                            :disabled="discarding" @click="discard(w)">Jeté ✓</button>
                                </li>
                            </ul>
                        </aside>

                        <div class="dlc-main">
                            <div v-if="query && !visibleProducts.length" class="dlc-empty dlc-empty--inline" data-testid="dlc-no-match">
                                <span>Aucun produit ne correspond à « {{ query }} ».</span>
                                <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-add-from-query" @click="openEditor({ name: query })">Créer « {{ query }} »</button>
                            </div>

                            <div class="dlc-grid" data-testid="dlc-grid">
                                <div v-for="p in visibleProducts" :key="p.id" class="dlc-cell">
                                    <button type="button" class="dlc-tile"
                                            :class="{ 'is-picked': selectMode && selection[p.id], 'is-expired': expiredNames.has(p.name) }"
                                            :style="{ '--cat': colorOf(p.category) }"
                                            :disabled="busyId === p.id || listBusy" :data-testid="`dlc-product-${p.id}`" @click="onTile(p)">
                                        <span class="dlc-tile-top">
                                            <span v-if="selectMode" class="dlc-check" :class="{ 'is-on': selection[p.id] }" aria-hidden="true">{{ selection[p.id] ? "✓" : "" }}</span>
                                            <span class="dlc-tile-cat">{{ p.category || "Sans famille" }}</span>
                                            <span v-if="selectMode && selection[p.id]" class="dlc-tile-count" data-testid="dlc-tile-count">× {{ selection[p.id] }}</span>
                                            <span v-if="expiredNames.has(p.name)" class="dlc-tile-flag">⚠ Expirée</span>
                                        </span>
                                        <span class="dlc-tile-name">{{ p.name }}</span>
                                        <span class="dlc-tile-life">{{ tileLife(p) }}</span>
                                        <span class="dlc-tile-meta">
                                            <span v-if="p.actions.length > 1">{{ p.actions.length }} événements</span>
                                            <span v-if="p.allergens.length">⚠ {{ p.allergens.length }} allergène{{ p.allergens.length > 1 ? "s" : "" }}</span>
                                            <span v-if="p.ask_original_dlc">DLC fabricant</span>
                                            <span v-if="p.default_copies > 1 && !selectMode">{{ p.default_copies }} par appui</span>
                                        </span>
                                        <span v-if="busyId === p.id" class="dlc-tile-busy">Impression…</span>
                                    </button>
                                    <button v-if="selectMode && selection[p.id]" type="button" class="dlc-corner" :aria-label="`Retirer une étiquette ${p.name}`"
                                            :data-testid="`dlc-minus-${p.id}`" @click="minus(p)">−</button>
                                    <button v-else-if="!selectMode" type="button" class="dlc-corner dlc-corner--more" :aria-label="`Options d'impression : ${p.name}`"
                                            :data-testid="`dlc-more-${p.id}`" @click="openSheet(p)">⋯</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sélection : prête à partir d'un seul appui. -->
                    <div v-if="selectMode && (selectionTotal > 0 || listBusy)" class="dlc-basket" data-testid="dlc-basket">
                        <span class="dlc-basket-text">
                            <strong data-testid="dlc-basket-total">{{ selectionTotal }} étiquette{{ selectionTotal > 1 ? "s" : "" }}</strong>
                            — {{ selectionProducts }} produit{{ selectionProducts > 1 ? "s" : "" }}
                        </span>
                        <button type="button" class="dlc-btn" :disabled="listBusy" data-testid="dlc-basket-clear" @click="selection = {}">Vider</button>
                        <button type="button" class="dlc-btn dlc-btn--primary" :disabled="listBusy" data-testid="dlc-basket-print" @click="printSelection">
                            {{ listBusy ? "Impression…" : "🖨 Imprimer la sélection" }}
                        </button>
                    </div>
                </template>

                <!-- ── Fiches produits ───────────────────────────────────────────────── -->
                <div v-if="products.length" class="dlc-section">
                    <button type="button" class="dlc-toggle" :aria-expanded="manage ? 'true' : 'false'" data-testid="dlc-manage-toggle" @click="manage = !manage">
                        {{ manage ? "▾" : "▸" }} Fiches produits ({{ products.length }})
                    </button>
                    <ul v-if="manage" class="dlc-list" data-testid="dlc-manage">
                        <li v-for="p in sortedProducts" :key="p.id" class="dlc-row" :class="{ 'is-off': !p.is_active }" :data-testid="`dlc-row-${p.id}`">
                            <span class="dlc-dot" :style="{ background: colorOf(p.category) }" aria-hidden="true"></span>
                            <span class="dlc-row-name">{{ p.name }}<small v-if="p.category"> · {{ p.category }}</small></span>
                            <span class="dlc-row-life">{{ p.actions.map((a) => `${actionLabel(a.key)} ${lifeOf(a)}`).join(" · ") }}</span>
                            <button type="button" class="dlc-btn dlc-btn--small" @click="toggleActive(p)">{{ p.is_active ? "Masquer" : "Réactiver" }}</button>
                            <button type="button" class="dlc-btn dlc-btn--small" :data-testid="`dlc-edit-${p.id}`" @click="openEditor(p)">Modifier</button>
                        </li>
                    </ul>
                </div>

                <!-- ── Historique ──────────────────────────────────────────────────────── -->
                <div class="dlc-section">
                    <h4 class="dlc-h4">Dernières étiquettes imprimées</h4>
                    <p v-if="!history.length" class="dlc-muted">Rien d'imprimé pour l'instant.</p>
                    <ul v-else class="dlc-history" data-testid="dlc-history">
                        <li v-for="h in history.slice(0, 20)" :key="h.id" class="dlc-history-row">
                            <span class="dlc-history-when">{{ short(h.created_at) }}</span>
                            <span class="dlc-history-name">{{ h.product_name }} ×{{ h.copies }}<small v-if="h.action"> · {{ actionLabel(h.action) }}</small></span>
                            <span class="dlc-history-dlc">DLC {{ short(h.expires_at) }}<small v-if="h.capped"> (fabricant)</small></span>
                            <span class="dlc-history-lot">{{ h.lot || "" }}</span>
                            <span class="dlc-history-by">{{ h.by || "" }}</span>
                            <span class="dlc-history-state" :class="`is-${h.discarded_at ? 'discarded' : h.status}`">{{ h.discarded_at ? "jeté" : stateText(h.status) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <DlcProductEditor v-if="editing" :key="editorKey" :product="editing" :categories="knownCategories" :settings="settings"
                          :saving="saving" :error="formError" @save="saveProduct" @cancel="closeEditor" @delete="deleteProduct" />
        <DlcPrintSheet v-if="sheetProduct" :product="sheetProduct" :settings="settings" :server-offset-ms="serverOffsetMs" :busy="busyId === sheetProduct.id"
                       @print="printFromSheet" @close="sheetProduct = null" />
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner « comme les concurrents (Komia…) : très configuré selon chaque produit, imprimer tout
 * ensemble ou un par un, date d'ouverture et date limite »] Étiquettes d'hygiène.
 *
 *   · un appui sur un produit = son étiquette, tout de suite (événement et nombre habituels du produit) ;
 *   · « ⋯ » = choisir l'événement (ouverture, décongélation…), le nombre, saisir la DLC du fabricant ;
 *   · « Sélection multiple » = cocher plusieurs produits (ou « Tout sélectionner », par famille) et tout imprimer ;
 *   · familles en onglets, recherche, plus utilisés en tête, « À surveiller » avec « Jeté » annulable.
 *
 * Les dates ne viennent PAS de la tablette : le serveur calcule événement et DLC avec son horloge (et la limite à la DLC du
 * fabricant), les journalise avec un numéro de lot et les renvoie ; le papier est identique au journal. Réseau coupé : on
 * imprime quand même avec l'heure du serveur connue au dernier échange — l'étiquette prime — et on le dit.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import labelPrinterMixin from "../../../helpers/labelPrinterMixin";
import LabelPrinterBar from "./LabelPrinterBar";
import DlcProductEditor from "./DlcProductEditor";
import DlcPrintSheet from "./DlcPrintSheet";
import { DLC_ACTIONS, dlcLabelBlocks, formatDlcDate, previewExpiry, shelfLifeText, watchList } from "../../../helpers/dlcLabelBuilder";
import { printBlocks, previewUrls } from "../../../helpers/labelPrintJob";
import { labelResultQueue, labelInflight } from "../../../helpers/labelResultQueue";

const PALETTE = ["#16a34a", "#dc2626", "#2563eb", "#d97706", "#7c3aed", "#0891b2", "#db2777", "#65a30d", "#ea580c", "#475569"];

function slug(v) {
    return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

const SUGGESTIONS = [
    { name: "Oignons émincés", category: "Légumes & crudités", actions: [{ key: "prepared", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Tomates tranchées", category: "Légumes & crudités", actions: [{ key: "prepared", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Olives", category: "Épicerie", actions: [{ key: "opened", value: 3, unit: "days" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Champignons", category: "Légumes & crudités", actions: [{ key: "opened", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Poulet mariné", category: "Viandes & volailles", actions: [{ key: "thawed", value: 3, unit: "days" }, { key: "opened", value: 48, unit: "hours" }], storage: "0 à +3 °C", allergens: ["moutarde"] },
    { name: "Fromage râpé", category: "Fromages & produits laitiers", actions: [{ key: "opened", value: 3, unit: "days" }], storage: "0 à +4 °C", allergens: ["lait"], ask_original_dlc: true },
    { name: "Sauce blanche maison", category: "Sauces", actions: [{ key: "prepared", value: 48, unit: "hours" }], storage: "0 à +4 °C", allergens: ["lait", "oeufs"] },
].map((s) => ({ ...s, slug: slug(s.name) }));

export default {
    name: "LabelDlcComponent",
    components: { LabelPrinterBar, DlcProductEditor, DlcPrintSheet },
    mixins: [labelPrinterMixin],
    data() {
        return {
            products: [],
            history: [],
            busyId: null,
            loaded: false,
            discarding: false,
            undo: null,
            undoTimer: null,
            selectMode: false,
            selection: {},
            batchAction: "",
            listBusy: false,
            last: null,
            loadError: "",
            manage: false,
            editing: null,
            editorKey: 0,
            sheetProduct: null,
            formError: "",
            saving: false,
            serverOffsetMs: 0,
            query: "",
            category: "",
            timezone: "Europe/Paris",
        };
    },
    computed: {
        activeProducts() {
            return this.products.filter((p) => p.is_active);
        },
        sortedProducts() {
            return [...this.products].sort((a, b) => a.name.localeCompare(b.name, "fr"));
        },
        knownCategories() {
            return [...new Set(this.products.map((p) => p.category).filter(Boolean))];
        },
        categoryTabs() {
            const counts = new Map();
            this.activeProducts.forEach((p) => { if (p.category) counts.set(p.category, (counts.get(p.category) || 0) + 1); });
            return [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0], "fr")).map(([name, count]) => ({ name, count, slug: slug(name) }));
        },
        actionChoices() {
            const keys = new Set();
            this.activeProducts.forEach((p) => p.actions.forEach((a) => keys.add(a.key)));
            return Object.values(DLC_ACTIONS).filter((a) => keys.has(a.key));
        },
        /** Produits dont un lot imprimé est échu : repérés directement sur leur tuile. */
        expiredNames() {
            return new Set(this.watchItems.filter((w) => w.level === "expired").map((w) => w.name));
        },
        suggestions() { return SUGGESTIONS; },
        /** Famille, recherche ; les plus utilisés (30 jours) d'abord, puis l'ordre alphabétique. */
        visibleProducts() {
            const q = this.norm(this.query);
            return this.activeProducts
                .filter((p) => !this.category || p.category === this.category)
                .filter((p) => !q || this.norm(p.name).includes(q) || this.norm(p.category).includes(q))
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
    mounted() {
        labelInflight(axios).recover();
        labelResultQueue(axios).flush();
        this.loadProducts();
        this.loadHistory();
    },
    beforeUnmount() {
        clearTimeout(this.undoTimer);
    },
    methods: {
        norm(v) {
            return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();
        },
        colorOf(category) {
            if (!category) return "#94a3b8";
            let h = 0;
            for (const ch of String(category)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
            return PALETTE[h % PALETTE.length];
        },
        actionLabel(key) { return (DLC_ACTIONS[key] || DLC_ACTIONS.opened).label; },
        lifeOf(a) { return shelfLifeText(a.value, a.unit); },
        tileLife(p) {
            const a = p.actions[0];
            // espaces insécables : « +4 °C » ou « 2 jours » ne se coupent jamais en fin de ligne
            const nb = (t) => String(t || "").replace(/ (°C|jours?|h)\b/g, "\u00a0$1");
            return [`${this.actionLabel(a.key)} · DLC +${nb(this.lifeOf(a))}`, nb(p.storage)].filter(Boolean).join(" · ");
        },
        short(iso) {
            if (!iso) return "";
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return "";
            try {
                return new Intl.DateTimeFormat("fr-FR", { timeZone: this.timezone, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })
                    .format(d).replace(",", "");
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

        // ── chargement ─────────────────────────────────────────────────────────
        normalizeProduct(p) {
            const actions = Array.isArray(p.actions) && p.actions.length
                ? p.actions
                : [{ key: "opened", value: p.shelf_life_value, unit: p.shelf_life_unit }];
            return {
                ...p,
                actions,
                allergens: Array.isArray(p.allergens) ? p.allergens : [],
                default_copies: Number(p.default_copies) || 1,
                print_operator: p.print_operator === undefined ? true : Boolean(p.print_operator),
                ask_original_dlc: Boolean(p.ask_original_dlc),
            };
        },
        async loadProducts() {
            try {
                const { data } = await axios.get("admin/labels/products");
                this.products = (data.data || []).map(this.normalizeProduct);
                if (data.meta) {
                    if (data.meta.timezone) this.timezone = data.meta.timezone;
                    const server = Date.parse(data.meta.server_now);
                    if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                }
                if (this.category && !this.categoryTabs.some((c) => c.name === this.category)) this.category = "";
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

        // ── impression ─────────────────────────────────────────────────────────
        setSelectMode(on) {
            this.selectMode = on;
            this.selection = {};
        },
        /** Un appui : imprime tout de suite (ou ouvre la fiche si une saisie est obligatoire) ; en sélection, ajoute. */
        onTile(p) {
            if (this.selectMode) {
                const n = (this.selection[p.id] || 0) + (this.selection[p.id] ? 1 : p.default_copies);
                this.selection = { ...this.selection, [p.id]: Math.min(n, 20) };
                return null;
            }
            if (p.ask_original_dlc) return this.openSheet(p);
            return this.printProduct(p, { copies: p.default_copies });
        },
        selectAll() {
            const next = { ...this.selection };
            this.visibleProducts.forEach((p) => { if (!next[p.id]) next[p.id] = p.default_copies; });
            this.selection = next;
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
        openSheet(p) {
            this.sheetProduct = p;
        },
        async printFromSheet(choice) {
            const p = this.sheetProduct;
            const result = await this.printProduct(p, choice);
            if (result && result.ok) this.sheetProduct = null;
        },
        async printProduct(product, opts = {}) {
            if (this.busyId || this.listBusy) return null;
            this.busyId = product.id;
            try {
                return await this.printOne(product, opts);
            } finally {
                this.busyId = null;
                this.loadHistory();
            }
        },
        /** Sélection : un travail d'impression par produit, dans l'ordre d'affichage ; on s'arrête à la première panne. */
        async printSelection() {
            if (this.listBusy || !this.selectionTotal) return;
            this.listBusy = true;
            const batch = [];
            const skipped = [];
            try {
                const order = this.activeProducts.filter((x) => this.selection[x.id]);
                const rank = (p) => { const i = this.visibleProducts.indexOf(p); return i < 0 ? 1e6 : i; };
                order.sort((a, b) => rank(a) - rank(b));
                for (const p of order) {
                    const count = this.selection[p.id];
                    if (p.ask_original_dlc) { skipped.push(p.name); continue; } // la DLC du fabricant se saisit produit par produit
                    const action = this.batchAction && p.actions.some((a) => a.key === this.batchAction) ? this.batchAction : null;
                    const result = await this.printOne(p, { copies: count, action, quiet: true });
                    batch.push({ name: p.name, copies: count, ok: result.ok, message: result.message });
                    if (!result.ok) break; // une panne : inutile d'enchaîner, la sélection reste pour réessayer
                    const { [p.id]: _done, ...rest } = this.selection;
                    this.selection = rest;
                }
                const failed = batch.filter((b) => !b.ok);
                this.last = {
                    ok: failed.length === 0, batch, failed, skipped,
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
        /** Réserve les dates côté serveur, imprime, rend compte du résultat. @returns {Promise<{ok:boolean, message?:string}>} */
        async printOne(product, opts = {}) {
            const copies = Number(opts.copies) || product.default_copies || 1;
            let data = null;
            let offline = false;
            try {
                const res = await axios.post("admin/labels/dlc/print", {
                    product_id: product.id, copies, action: opts.action || undefined, original_dlc: opts.original_dlc || undefined,
                });
                data = res.data;
            } catch (e) {
                if (e && e.response && e.response.status && e.response.status < 500) {
                    const message = this.apiMessage(e, "Impression refusée.");
                    alertService.error(message);
                    return { ok: false, message };
                }
                if (product.ask_original_dlc) {
                    const message = "Hors ligne : la DLC du fabricant ne peut pas être vérifiée, réessayez quand le réseau revient.";
                    alertService.error(message);
                    return { ok: false, message };
                }
                // Réseau coupé : on imprime avec l'horloge du serveur connue au dernier échange.
                offline = true;
                const action = product.actions.find((a) => a.key === opts.action) || product.actions[0];
                const now = new Date(Date.now() + this.serverOffsetMs);
                data = {
                    log_id: null, name: product.name, category: product.category, note: product.note, storage: product.storage,
                    allergens: product.allergens, action: action.key, opened_at: now.toISOString(),
                    expires_at: previewExpiry(action, now).toISOString(), copies, by: null, lot: null, capped: false,
                };
            }

            const blocks = dlcLabelBlocks({
                name: data.name, category: data.category, note: data.note, action: data.action,
                openedAt: data.opened_at, expiresAt: data.expires_at, storage: data.storage, by: data.by, lot: data.lot,
                allergens: data.allergens, capped: data.capped, originalDlc: data.original_dlc,
            });
            const resultUrl = data.log_id ? `admin/labels/prints/${data.log_id}/result` : null;
            if (resultUrl) labelInflight(axios).begin(data.log_id, resultUrl, data.attempt || null);
            const result = await printBlocks(this.printerInstance(), Array.from({ length: data.copies }, () => blocks), this.settings);

            if (resultUrl) {
                await labelResultQueue(axios).send(resultUrl, {
                    status: result.ok ? (result.unconfirmed ? "unconfirmed" : "printed") : "failed",
                    message: result.message ? String(result.message).slice(0, 290) : null,
                    printed_pages: result.ok ? data.copies : (result.pages || 0),
                    attempt: data.attempt || null,
                });
                labelInflight(axios).end(data.log_id);
            }

            if (!opts.quiet) {
                let preview = "";
                try { preview = previewUrls([blocks], this.settings).urls[0] || ""; } catch (_) { preview = ""; }
                const warn = [offline ? "Hors ligne : étiquette imprimée, journal non enregistré." : "", result.ok && result.unconfirmed ? result.message : ""]
                    .filter(Boolean).join(" ");
                const action = DLC_ACTIONS[data.action] || DLC_ACTIONS.opened;
                this.last = {
                    ok: result.ok, name: data.name, copies: data.copies, actionLabel: action.label, verb: action.verb,
                    opened: formatDlcDate(data.opened_at, this.timezone), expires: formatDlcDate(data.expires_at, this.timezone),
                    lot: data.lot, capped: data.capped, message: result.ok ? warn : result.message, preview,
                };
                this.revealLast();
            }
            if (!result.ok) alertService.error(result.message);
            return result;
        },

        // ── « Jeté » ───────────────────────────────────────────────────────────
        async discard(w) {
            this.discarding = true;
            try {
                await axios.post("admin/labels/prints/discard", { ids: w.ids });
                await this.loadHistory();
                this.undo = { ids: w.ids, name: w.name };
                clearTimeout(this.undoTimer);
                this.undoTimer = setTimeout(() => { this.undo = null; }, 8000);
            } catch (e) {
                alertService.error(this.apiMessage(e, "Impossible d'enregistrer : réessayez."));
            } finally {
                this.discarding = false;
            }
        },
        async undoDiscard() {
            if (!this.undo) return;
            const { ids } = this.undo;
            this.undo = null;
            clearTimeout(this.undoTimer);
            try {
                await axios.post("admin/labels/prints/discard", { ids, restore: true });
                await this.loadHistory();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Annulation impossible : réessayez."));
            }
        },

        // ── fiches produits ────────────────────────────────────────────────────
        openEditor(product) {
            this.formError = "";
            this.editorKey += 1;
            this.editing = product ? { ...product } : {};
        },
        closeEditor() {
            this.editing = null;
            this.formError = "";
        },
        async saveProduct(payload) {
            if (this.saving) return;
            this.formError = "";
            if (!String(payload.name || "").trim()) { this.formError = "Indiquez le nom du produit."; return; }
            if (!payload.actions.length) { this.formError = "Cochez au moins un événement (ouverture, décongélation…) avec sa durée."; return; }
            this.saving = true;
            try {
                if (payload.id) await axios.put(`admin/labels/products/${payload.id}`, payload);
                else await axios.post("admin/labels/products", payload);
                this.closeEditor();
                await this.loadProducts();
                alertService.success(`« ${payload.name.trim()} » enregistré.`);
            } catch (e) {
                this.formError = this.apiMessage(e, "Enregistrement impossible.");
            } finally {
                this.saving = false;
            }
        },
        async deleteProduct(id) {
            try {
                await axios.delete(`admin/labels/products/${id}`);
                this.closeEditor();
                await this.loadProducts();
            } catch (e) {
                this.formError = this.apiMessage(e, "Suppression impossible.");
            }
        },
        async toggleActive(p) {
            try {
                await axios.put(`admin/labels/products/${p.id}`, { name: p.name, actions: p.actions, is_active: !p.is_active });
                await this.loadProducts();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Modification impossible."));
            }
        },
    },
};
</script>

<style scoped>
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.dlc-alert { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 8px 0; }
.dlc-empty { color: #475569; padding: 24px 8px; text-align: center; }
.dlc-empty--cta { text-align: left; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 18px; }
.dlc-empty--inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: center; }
.dlc-suggest { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.dlc-suggest-label { width: 100%; color: #475569; font-size: 14px; }
.dlc-muted { color: #94a3b8; }
.dlc-hint { color: #475569; font-size: 14px; margin: 8px 0 12px; }

.dlc-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 4px 0 10px; }
.dlc-search-row { display: flex; gap: 8px; align-items: center; flex: 1 1 320px; }
.dlc-search-row .db-field-control { min-height: 52px; flex: 1 1 auto; font-size: 16px; }

.dlc-cats { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0 8px; scrollbar-width: thin; }
.dlc-cat { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 16px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; font-size: 15px; font-weight: 700; color: #334155; cursor: pointer; white-space: nowrap; }
.dlc-cat.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-cat-n { background: #e2e8f0; color: #334155; border-radius: 999px; padding: 0 8px; font-size: 13px; }
.dlc-cat.is-on .dlc-cat-n { background: #fff; color: #1a1a1a; }
.dlc-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex: 0 0 auto; }

.dlc-modes { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 6px 0 2px; }
.dlc-mode { min-height: 48px; padding: 0 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.dlc-mode.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-batch-action { display: flex; align-items: center; gap: 8px; margin: 0; font-weight: 700; color: #334155; font-size: 14px; }
.dlc-batch-action .db-field-control { min-height: 48px; min-width: 220px; }

.dlc-layout { display: flex; flex-direction: column; gap: 12px; }
.dlc-watch { order: -1; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 12px; padding: 10px 16px; margin: 0 0 6px; }
@media (min-width: 1100px) {
    .dlc-layout { flex-direction: row; align-items: flex-start; }
    .dlc-main { flex: 1 1 auto; min-width: 0; }
    .dlc-watch { order: 2; flex: 0 0 340px; position: sticky; top: 8px; }
}
.dlc-watch ul { list-style: none; margin: 0; padding: 0; }
.dlc-watch li { display: flex; gap: 8px; align-items: flex-start; padding: 6px 0; font-size: 15px; }
.dlc-watch li.is-expired { background: #fee2e2; border-radius: 8px; padding: 6px 8px; margin: 2px -8px; color: #991b1b; font-weight: 700; }
.dlc-watch li.is-today { color: #92400e; }
.dlc-watch-icon { font-size: 18px; line-height: 1.2; }
.dlc-watch-body { flex: 1 1 auto; min-width: 0; }
.dlc-watch-n { color: #64748b; font-weight: 400; }
.dlc-h4 { font-size: 16px; font-weight: 800; margin: 0 0 8px; color: #111827; }

.dlc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; padding-bottom: 8px; }
.dlc-cell { position: relative; }
.dlc-tile {
    position: relative; width: 100%; height: 100%; min-height: 128px; border-radius: 14px; border: 2px solid #e2e8f0; border-left: 8px solid var(--cat, #94a3b8);
    background: #fff; padding: 10px 12px 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; text-align: left; cursor: pointer;
}
.dlc-tile:active { background: #fff7f3; }
.dlc-tile:disabled { opacity: .6; cursor: progress; }
.dlc-tile.is-picked { border-color: #D63D0B; border-left-color: var(--cat, #94a3b8); background: #fff7f3; }
.dlc-tile.is-expired { border-color: #dc2626; border-left-color: #dc2626; background: #fef2f2; }
.dlc-tile-top { display: flex; align-items: center; gap: 6px; width: 100%; min-height: 24px; flex-wrap: wrap; }
.dlc-check { width: 24px; height: 24px; border-radius: 6px; border: 2px solid #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; }
.dlc-check.is-on { background: #D63D0B; border-color: #D63D0B; }
.dlc-tile-cat { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; flex: 1 1 auto; }
.dlc-tile-count { background: #D63D0B; color: #fff; border-radius: 999px; padding: 1px 10px; font-weight: 800; font-size: 14px; }
.dlc-tile-flag { background: #dc2626; color: #fff; border-radius: 999px; padding: 1px 10px; font-weight: 800; font-size: 12px; }
.dlc-tile-name { font-size: 20px; font-weight: 800; line-height: 1.15; color: #111827; padding-right: 44px; }
.dlc-tile-life { font-size: 14px; color: #334155; padding-right: 44px; }
.dlc-tile-meta { display: flex; flex-wrap: wrap; gap: 6px; font-size: 12px; color: #475569; padding-right: 44px; }
.dlc-tile-meta span { background: #f1f5f9; border-radius: 6px; padding: 1px 6px; }
.dlc-tile-busy { position: absolute; bottom: 8px; left: 12px; font-size: 12px; color: #1d4ed8; font-weight: 700; background: #dbeafe; border-radius: 6px; padding: 1px 6px; }
.dlc-corner { position: absolute; right: 8px; bottom: 8px; width: 48px; height: 48px; border-radius: 12px; border: 2px solid #D63D0B; background: #fff; color: #D63D0B; font-size: 26px; font-weight: 800; cursor: pointer; line-height: 1; }
.dlc-corner--more { border-color: #cbd5e1; color: #334155; font-size: 24px; }

.dlc-basket {
    position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: #fff; border: 2px solid #D63D0B; border-radius: 12px; padding: 12px 16px; margin: 8px 0 16px;
    box-shadow: 0 -4px 14px rgba(15, 23, 42, .12);
}
.dlc-basket-text { flex: 1 1 200px; font-size: 16px; }

.dlc-last { display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; scroll-margin-top: 80px; }
.dlc-last.is-ok { background: #ecfdf5; border: 1px solid #a7f3d0; }
.dlc-last.is-ko { background: #fef2f2; border: 1px solid #fca5a5; }
.dlc-last-text { display: flex; flex-direction: column; gap: 2px; font-size: 16px; }
.dlc-last-warn { color: #92400e; font-weight: 600; }
.dlc-last-img { width: 220px; max-width: 100%; border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; }
.dlc-undo { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #1a1a1a; color: #fff; border-radius: 12px; padding: 8px 8px 8px 16px; margin: 0 0 12px; }
.dlc-undo .dlc-btn { background: #fff; color: #1a1a1a; }

.dlc-section { border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 14px; }
.dlc-toggle { background: none; border: 0; font-size: 17px; font-weight: 800; cursor: pointer; padding: 8px 0; min-height: 48px; color: #111827; }
.dlc-chip { min-width: 52px; min-height: 48px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.dlc-chip--wide { padding: 0 14px; font-size: 15px; }
.dlc-btn { min-height: 52px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.dlc-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.dlc-btn--small { min-height: 48px; padding: 0 12px; font-size: 14px; }
.dlc-btn--danger { color: #b91c1c; border-color: #fca5a5; }
.dlc-btn:disabled { opacity: 1; background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
.dlc-list { list-style: none; margin: 0; padding: 0; }
.dlc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; }
.dlc-row.is-off { opacity: .55; }
.dlc-row-name { flex: 1 1 220px; font-weight: 700; font-size: 16px; }
.dlc-row-name small { color: #64748b; font-weight: 400; }
.dlc-row-life { color: #475569; font-size: 14px; flex: 1 1 220px; }

.dlc-history { list-style: none; margin: 0; padding: 0; }
.dlc-history-row { display: flex; gap: 12px; flex-wrap: wrap; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; align-items: baseline; }
.dlc-history-when { color: #64748b; font-variant-numeric: tabular-nums; min-width: 92px; }
.dlc-history-name { font-weight: 700; flex: 1 1 200px; }
.dlc-history-name small, .dlc-history-dlc small { color: #64748b; font-weight: 400; }
.dlc-history-dlc { color: #334155; }
.dlc-history-lot { color: #64748b; font-variant-numeric: tabular-nums; }
.dlc-history-by { color: #64748b; min-width: 60px; }
.dlc-history-state { font-weight: 700; }
.dlc-history-state.is-printed { color: #166534; }
.dlc-history-state.is-failed { color: #991b1b; }
.dlc-history-state.is-unconfirmed { color: #92400e; }
.dlc-history-state.is-discarded { color: #475569; }
</style>
