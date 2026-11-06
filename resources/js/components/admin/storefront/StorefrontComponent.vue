<template>
    <div class="col-12">
        <div class="db-card sf">
            <div class="db-card-header sf-head">
                <h3 class="db-card-title sf-title">Vitrine de l'application</h3>
                <p v-if="loaded" class="sf-status" data-testid="sf-status">
                    <span v-if="state.published.version > 0">
                        Version publiée : <b>{{ state.published.version }}</b>, le {{ when(state.published.published_at) }}<template v-if="state.published.published_by"> par {{ state.published.published_by }}</template>.
                    </span>
                    <span v-else>Rien n'est encore publié : l'application affiche son contenu embarqué.</span>
                    <span v-if="state.has_unpublished_changes || settingsDirty" class="sf-tag sf-tag--warn" data-testid="sf-unpublished">Modifications non publiées</span>
                    <span v-else-if="state.published.version > 0" class="sf-tag sf-tag--ok">À jour</span>
                </p>
            </div>

            <div class="db-card-body">
                <p v-if="forbidden" class="sf-alert" role="alert" data-testid="sf-forbidden">
                    La vitrine est réservée au responsable (droit « Paramètres »). Demandez-lui de la modifier, ou de vous donner ce droit.
                </p>
                <p v-else-if="loadError" class="sf-alert" role="alert" data-testid="sf-load-error">{{ loadError }}</p>
                <p v-else-if="!loaded" class="sf-muted" role="status">Chargement…</p>

                <div v-if="loaded" class="sf-grid">
                    <div class="sf-main">
                        <p class="sf-muted sf-intro">
                            Ce que vous changez ici arrive dans l'application <b>sans nouvelle version</b> : bandeaux, offres flash, couleurs, message de livraison.
                            Rien ne part tant que vous n'avez pas cliqué sur <b>Publier</b>. Les prix ne changent jamais ici : une offre renvoie vers un code promo.
                        </p>

                        <!-- 1. Interrupteur général + livraison -->
                        <section class="sf-block" aria-labelledby="sf-h-general">
                            <h4 id="sf-h-general" class="sf-h">Vitrine</h4>
                            <button type="button" class="sf-toggle" :aria-pressed="form.enabled ? 'true' : 'false'" data-testid="sf-enabled" @click="form.enabled = !form.enabled">
                                <span class="sf-box" aria-hidden="true">{{ form.enabled ? "✓" : "" }}</span>
                                <span><b>Vitrine active</b> <small class="sf-muted">{{ form.enabled ? "les bandeaux et les couleurs s'affichent" : "éteinte : l'application garde son contenu embarqué" }}</small></span>
                            </button>

                            <div class="sf-delivery" data-testid="sf-delivery">
                                <span class="sf-dot" :class="state.delivery.enabled ? 'is-on' : 'is-off'" aria-hidden="true"></span>
                                <span>
                                    Livraison <b>{{ state.delivery.enabled ? "active" : "suspendue" }}</b> en ce moment.
                                    <small class="sf-muted">Elle se suspend et se remet depuis la caisse (bouton « Livraison ») ; l'application le voit tout de suite, sans publier.</small>
                                </span>
                            </div>
                            <div class="sf-row2">
                                <label class="sf-field">
                                    <span class="sf-label">Message quand la livraison est active</span>
                                    <input v-model="form.delivery_on_message" maxlength="140" class="sf-input" :placeholder="meta.delivery_messages.on" data-testid="sf-msg-on" />
                                </label>
                                <label class="sf-field">
                                    <span class="sf-label">Message quand elle est suspendue</span>
                                    <input v-model="form.delivery_off_message" maxlength="140" class="sf-input" :placeholder="meta.delivery_messages.off" data-testid="sf-msg-off" />
                                </label>
                            </div>
                        </section>

                        <!-- 2. Couleurs -->
                        <section class="sf-block" aria-labelledby="sf-h-colors">
                            <h4 id="sf-h-colors" class="sf-h">Couleurs</h4>
                            <button type="button" class="sf-toggle" :aria-pressed="customTheme ? 'true' : 'false'" data-testid="sf-custom-theme" @click="toggleTheme">
                                <span class="sf-box" aria-hidden="true">{{ customTheme ? "✓" : "" }}</span>
                                <span><b>Personnaliser les couleurs</b> <small class="sf-muted">{{ customTheme ? "les couleurs ci-dessous remplacent celles de l'application" : "l'application garde ses couleurs" }}</small></span>
                            </button>
                            <template v-if="customTheme">
                                <div class="sf-tokens" data-testid="sf-tokens">
                                    <label v-for="t in meta.tokens" :key="t.key" class="sf-token">
                                        <input type="color" :value="form.theme[t.key] || '#000000'" :aria-label="t.label" class="sf-swatch" @input="setColor(t.key, $event.target.value)" />
                                        <span class="sf-token-text">
                                            <span class="sf-label">{{ t.label }}</span>
                                            <input :value="form.theme[t.key]" maxlength="7" spellcheck="false" class="sf-input sf-hex" :class="{ 'is-bad': !isHex(form.theme[t.key]) }"
                                                   :data-testid="`sf-hex-${t.key}`" @input="setColor(t.key, $event.target.value)" />
                                        </span>
                                    </label>
                                </div>
                                <ul class="sf-contrast" data-testid="sf-contrast">
                                    <li v-for="p in contrastRows" :key="p.fg + p.bg" :class="p.ok ? 'is-ok' : 'is-bad'">
                                        <span class="sf-sample" :style="{ color: p.fgHex, background: p.bgHex }" aria-hidden="true">Aa</span>
                                        <span>{{ p.label }} : <b>{{ p.text }}</b> {{ p.ok ? "— lisible" : `— trop faible, il faut au moins ${minText}` }}</span>
                                    </li>
                                </ul>
                                <button type="button" class="sf-btn sf-btn--small" data-testid="sf-preset" @click="usePreset">Revenir aux couleurs actuelles du site</button>
                            </template>
                        </section>

                        <div class="sf-bar">
                            <p v-if="settingsMsg" class="sf-msg" :class="settingsOk ? 'is-ok' : 'is-alert'" role="status" data-testid="sf-settings-msg">{{ settingsMsg }}</p>
                            <ul v-if="settingsErrors.length" class="sf-errors" role="alert" data-testid="sf-settings-errors">
                                <li v-for="e in settingsErrors" :key="e">{{ e }}</li>
                            </ul>
                            <button type="button" class="sf-btn" :disabled="busy !== '' || !settingsDirty || !contrastOk" data-testid="sf-save-settings" @click="saveSettings()">
                                {{ busy === "settings" ? "Enregistrement…" : "Enregistrer le brouillon" }}
                            </button>
                        </div>

                        <!-- 3. Bandeaux -->
                        <section class="sf-block" aria-labelledby="sf-h-banners">
                            <h4 id="sf-h-banners" class="sf-h">Bandeaux et offres flash <small class="sf-muted">({{ state.banners.length }}/{{ meta.max_banners }})</small></h4>
                            <p v-if="!state.banners.length && !editor" class="sf-muted" data-testid="sf-banners-empty">Aucun bandeau. Créez-en un : il ne s'affichera qu'après publication.</p>
                            <ul v-if="state.banners.length" class="sf-list" data-testid="sf-banners">
                                <li v-for="b in state.banners" :key="b.id" class="sf-item" data-testid="sf-banner-row">
                                    <span class="sf-tag" :class="stateClass(b.state)">{{ stateLabel(b.state) }}</span>
                                    <span class="sf-item-main">
                                        <b>{{ b.title }}</b> <small v-if="b.type === 'flash'" class="sf-tag sf-tag--flash">Offre flash</small>
                                        <small class="sf-muted sf-item-sub">{{ period(b) }} · priorité {{ b.priority }}<template v-if="b.coupon_code"> · code {{ b.coupon_code }}</template></small>
                                    </span>
                                    <span class="sf-item-actions">
                                        <button type="button" class="sf-btn sf-btn--small" :disabled="busy !== ''" :aria-label="`Modifier ${b.title}`" @click="edit(b)">Modifier</button>
                                        <button type="button" class="sf-btn sf-btn--small sf-btn--ghost" :disabled="busy !== ''" :aria-label="`Supprimer ${b.title}`" @click="remove(b)">Supprimer</button>
                                    </span>
                                </li>
                            </ul>

                            <form v-if="editor" class="sf-editor" data-testid="sf-editor" @submit.prevent="saveBanner">
                                <h5 class="sf-h5">{{ editor.id ? "Modifier le bandeau" : "Nouveau bandeau" }}</h5>
                                <div class="sf-row2">
                                    <label class="sf-field"><span class="sf-label">Titre *</span>
                                        <input v-model="editor.title" maxlength="80" class="sf-input" data-testid="sf-b-title" required /></label>
                                    <label class="sf-field"><span class="sf-label">Type</span>
                                        <select v-model="editor.type" class="sf-input" data-testid="sf-b-type">
                                            <option value="info">Information</option>
                                            <option value="flash">Offre flash (compte à rebours)</option>
                                        </select></label>
                                </div>
                                <label class="sf-field"><span class="sf-label">Texte</span>
                                    <textarea v-model="editor.body" maxlength="240" rows="2" class="sf-input" data-testid="sf-b-body"></textarea></label>
                                <div class="sf-row2">
                                    <label class="sf-field"><span class="sf-label">Début <small class="sf-muted">(heure de Paris, vide = tout de suite)</small></span>
                                        <input v-model="editor.starts_at" type="datetime-local" class="sf-input" data-testid="sf-b-start" /></label>
                                    <label class="sf-field"><span class="sf-label">Fin <small class="sf-muted">{{ editor.type === "flash" ? "(obligatoire)" : "(vide = sans fin)" }}</small></span>
                                        <input v-model="editor.ends_at" type="datetime-local" class="sf-input" data-testid="sf-b-end" /></label>
                                </div>
                                <div class="sf-row2">
                                    <label class="sf-field"><span class="sf-label">Libellé du bouton</span>
                                        <input v-model="editor.button_label" maxlength="30" class="sf-input" placeholder="Voir la carte" data-testid="sf-b-label" /></label>
                                    <label class="sf-field"><span class="sf-label">Le bouton mène à</span>
                                        <select v-model="editor.destination" class="sf-input" data-testid="sf-b-dest">
                                            <option value="">Pas de bouton</option>
                                            <option v-for="r in meta.link_routes" :key="r.link" :value="r.link">{{ r.label }}</option>
                                            <option value="product">Un produit précis</option>
                                        </select></label>
                                </div>
                                <label v-if="editor.destination === 'product'" class="sf-field"><span class="sf-label">Identifiant du produit (tel qu'il apparaît après #menu/ dans l'adresse du site)</span>
                                    <input v-model.trim="editor.product" maxlength="80" class="sf-input" placeholder="tacos-xl" data-testid="sf-b-product" /></label>
                                <div class="sf-row3">
                                    <label class="sf-field"><span class="sf-label">Code promo <small class="sf-muted">(doit exister dans « Coupons »)</small></span>
                                        <input v-model.trim="editor.coupon_code" maxlength="40" class="sf-input" data-testid="sf-b-coupon" /></label>
                                    <label class="sf-field"><span class="sf-label">Priorité <small class="sf-muted">(0-100, le plus grand passe en premier)</small></span>
                                        <input v-model.number="editor.priority" type="number" min="0" max="100" class="sf-input" data-testid="sf-b-priority" /></label>
                                    <label class="sf-check"><input v-model="editor.active" type="checkbox" data-testid="sf-b-active" /> Actif</label>
                                </div>
                                <ul v-if="bannerErrors.length" class="sf-errors" role="alert" data-testid="sf-banner-errors">
                                    <li v-for="e in bannerErrors" :key="e">{{ e }}</li>
                                </ul>
                                <div class="sf-editor-actions">
                                    <button type="button" class="sf-btn sf-btn--ghost" :disabled="busy !== ''" @click="cancelEdit">Annuler</button>
                                    <button type="submit" class="sf-btn" :disabled="busy !== ''" data-testid="sf-b-save">{{ busy === "banner" ? "Enregistrement…" : "Enregistrer le bandeau" }}</button>
                                </div>
                            </form>
                            <button v-else type="button" class="sf-btn" :disabled="busy !== '' || state.banners.length >= meta.max_banners" data-testid="sf-new-banner" @click="newBanner">Nouveau bandeau</button>
                        </section>

                        <!-- 4. Publication -->
                        <section class="sf-block sf-publish" aria-labelledby="sf-h-publish">
                            <h4 id="sf-h-publish" class="sf-h">Publier</h4>
                            <p class="sf-muted">Publier envoie le brouillon à l'application (version {{ state.published.version + 1 }}). Chaque publication est gardée : vous pouvez revenir en arrière d'un clic.</p>
                            <p v-if="publishMsg" class="sf-msg" :class="publishOk ? 'is-ok' : 'is-alert'" role="status" data-testid="sf-publish-msg">{{ publishMsg }}</p>
                            <ul v-if="publishErrors.length" class="sf-errors" role="alert" data-testid="sf-publish-errors">
                                <li v-for="e in publishErrors" :key="e">{{ e }}</li>
                            </ul>
                            <div class="sf-publish-actions">
                                <button type="button" class="sf-btn sf-btn--primary" :disabled="busy !== '' || !!editor || !hasChanges" :title="editor ? 'Terminez d\'abord le bandeau en cours' : ''" data-testid="sf-publish" @click="publish">
                                    {{ busy === "publish" ? "Publication…" : `Publier (version ${state.published.version + 1})` }}
                                </button>
                                <button type="button" class="sf-btn sf-btn--ghost" :disabled="busy !== '' || state.history.length < 2" data-testid="sf-rollback" @click="rollback(null)">
                                    {{ busy === "rollback" ? "Retour…" : "Retour à la version précédente" }}
                                </button>
                            </div>
                            <p v-if="editor" class="sf-muted" data-testid="sf-publish-blocked">Terminez ou annulez le bandeau en cours avant de publier.</p>
                            <p v-else-if="!hasChanges && state.published.version > 0" class="sf-muted" data-testid="sf-nothing-to-publish">Rien de nouveau à publier : l'application a déjà la dernière version.</p>
                        </section>

                        <!-- 5. Historique -->
                        <section class="sf-block" aria-labelledby="sf-h-history">
                            <h4 id="sf-h-history" class="sf-h">Historique</h4>
                            <p v-if="!state.history.length" class="sf-muted">Aucune publication.</p>
                            <ul v-else class="sf-list" data-testid="sf-history">
                                <li v-for="h in state.history" :key="h.version" class="sf-item">
                                    <span class="sf-tag" :class="h.version === state.published.version ? 'sf-tag--ok' : ''">v{{ h.version }}</span>
                                    <span class="sf-item-main">
                                        {{ when(h.published_at) }}<template v-if="h.published_by"> · {{ h.published_by }}</template>
                                        <small class="sf-muted sf-item-sub">
                                            {{ h.action === "rollback" ? `retour à la version ${h.source_version}` : "publication" }} · {{ h.enabled ? "vitrine active" : "vitrine éteinte" }} · {{ h.banners }} bandeau{{ h.banners > 1 ? "x" : "" }}
                                        </small>
                                    </span>
                                    <button v-if="h.version !== state.published.version" type="button" class="sf-btn sf-btn--small sf-btn--ghost" :disabled="busy !== ''" :aria-label="`Republier la version ${h.version}`" @click="rollback(h.version)">Republier</button>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <!-- Aperçu téléphone -->
                    <aside class="sf-side" aria-label="Aperçu dans l'application">
                        <p class="sf-label sf-side-title">Aperçu (brouillon, maintenant)</p>
                        <div class="sf-phone" :style="phoneStyle" data-testid="sf-phone">
                            <div class="sf-phone-notch" aria-hidden="true"></div>
                            <div class="sf-phone-screen">
                                <p class="sf-phone-brand">Le Cayenne</p>
                                <p v-if="!form.enabled" class="sf-phone-off" data-testid="sf-phone-off">Vitrine éteinte : contenu embarqué de l'application.</p>
                                <template v-else>
                                    <div v-for="b in state.preview.banners" :key="b.id" class="sf-phone-banner" data-testid="sf-phone-banner">
                                        <p class="sf-phone-banner-title">{{ b.title }}</p>
                                        <p v-if="b.body" class="sf-phone-banner-body">{{ b.body }}</p>
                                        <p v-if="b.type === 'flash' && b.ends_at" class="sf-phone-banner-body">Jusqu'au {{ when(b.ends_at) }}</p>
                                        <p v-if="b.coupon_code" class="sf-phone-code">Code : {{ b.coupon_code }}</p>
                                        <!-- [W6 cycle E · E4] Le code saisi n'est pas servi : on dit pourquoi, au lieu de le faire disparaître sans un mot. -->
                                        <p v-else-if="codeSaisi(b)" class="sf-phone-code-off" data-testid="sf-phone-code-off">Code {{ codeSaisi(b) }} non proposé en ce moment : désactivé, hors de ses dates ou de ses heures, épuisé, limité à un canal ou à un restaurant, ou codes promo coupés dans les réglages.</p>
                                        <span v-if="b.button" class="sf-phone-btn">{{ b.button.label }}</span>
                                    </div>
                                    <p v-if="!state.preview.banners.length" class="sf-phone-off">Aucun bandeau affiché en ce moment.</p>
                                </template>
                                <p class="sf-phone-delivery">{{ deliveryPreview }}</p>
                                <div class="sf-phone-card">
                                    <p class="sf-phone-card-title">Tacos L</p>
                                    <p class="sf-phone-card-text">Exemple de carte produit</p>
                                    <span class="sf-phone-btn sf-phone-btn--primary">Ajouter</span>
                                </div>
                            </div>
                        </div>
                        <p class="sf-muted sf-side-note">Les bandeaux montrés sont ceux du brouillon <b>enregistré</b>, filtrés comme l'application le fera maintenant (heure de Paris).</p>
                    </aside>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [APP HYBRIDE W2a · 2026-11-03] Écran « Vitrine » : le propriétaire change bandeaux, offres flash et couleurs de l'application
 * pendant les fêtes, sans nouvelle version. Le serveur décide tout (permission `settings`, liste blanche de couleurs, contraste,
 * liens internes, dates) ; l'écran affiche chaque refus À CÔTÉ du bouton qui l'a provoqué (mémoire formulaire-admin-echec-muet).
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import { buildIdempotencyHeaders } from "../../../helpers/idempotencyHeaders";

const PARIS = "Europe/Paris";
const EMPTY_STATE = () => ({
    draft: { enabled: false, theme: null, delivery_on_message: null, delivery_off_message: null },
    banners: [], published: { version: 0, published_at: null, published_by: null }, history: [],
    has_unpublished_changes: false, draft_hash: null, preview: { banners: [], delivery: { enabled: true, message: "" } }, delivery: { enabled: true },
    meta: { tokens: [], contrast_pairs: [], min_contrast: 4.5, default_theme: {}, link_routes: [], max_banners: 20, delivery_messages: { on: "", off: "" }, timezone: PARIS },
});

function luminance(hex) {
    const c = [1, 3, 5].map((i) => {
        const s = parseInt(hex.substr(i, 2), 16) / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
export function contrastRatio(a, b) {
    const la = luminance(a), lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}
const isHex = (v) => typeof v === "string" && /^#[0-9A-Fa-f]{6}$/.test(v);
const frRatio = (r) => `${(Math.floor(r * 100) / 100).toFixed(2).replace(".", ",")}:1`;

export default {
    name: "StorefrontComponent",
    data() {
        return {
            loaded: false, loadError: "", forbidden: false, busy: "",
            state: EMPTY_STATE(),
            form: { enabled: false, theme: {}, delivery_on_message: "", delivery_off_message: "" },
            customTheme: false, savedForm: "",
            settingsMsg: "", settingsOk: true, settingsErrors: [],
            editor: null, bannerErrors: [],
            publishMsg: "", publishOk: true, publishErrors: [],
        };
    },
    computed: {
        meta() { return this.state.meta; },
        minText() { return `${String(this.meta.min_contrast).replace(".", ",")}:1`; },
        formSnapshot() { return this.snap(this.form, this.customTheme); },
        settingsDirty() { return this.loaded && this.formSnapshot !== this.savedForm; },
        hasChanges() { return this.settingsDirty || this.state.has_unpublished_changes; },
        labels() { return Object.fromEntries(this.meta.tokens.map((t) => [t.key, t.label])); },
        contrastRows() {
            return this.meta.contrast_pairs.map(([fg, bg]) => {
                const fgHex = this.form.theme[fg], bgHex = this.form.theme[bg];
                const valid = isHex(fgHex) && isHex(bgHex);
                const r = valid ? contrastRatio(fgHex, bgHex) : 0;
                return { fg, bg, fgHex, bgHex, label: `${this.labels[fg]} / ${this.labels[bg]}`, ok: valid && r >= this.meta.min_contrast, text: valid ? frRatio(r) : "couleur invalide" };
            });
        },
        contrastOk() { return !this.customTheme || (this.contrastRows.every((r) => r.ok) && this.meta.tokens.every((t) => isHex(this.form.theme[t.key]))); },
        phoneTheme() {
            return this.form.enabled && this.customTheme && this.contrastOk ? this.normalizedTheme() : this.meta.default_theme;
        },
        phoneStyle() {
            const t = this.phoneTheme || {};
            return { "--p": t.primary, "--op": t.on_primary, "--ac": t.accent, "--bg": t.background, "--sf": t.surface, "--tx": t.text, "--bb": t.banner_bg, "--bt": t.banner_text };
        },
        deliveryPreview() {
            const on = this.state.delivery.enabled;
            const custom = this.form.enabled ? (on ? this.form.delivery_on_message : this.form.delivery_off_message) : "";
            return (custom || "").trim() || (on ? this.meta.delivery_messages.on : this.meta.delivery_messages.off);
        },
    },
    mounted() { this.load(); },
    methods: {
        isHex,
        normalizedTheme(theme = this.form.theme) {
            const out = {};
            this.meta.tokens.forEach((t) => { out[t.key] = String((theme || {})[t.key] || "").toUpperCase(); });
            return out;
        },
        snap(form, custom) {
            return JSON.stringify({
                enabled: !!form.enabled,
                theme: custom ? this.normalizedTheme(form.theme) : null,
                on: (form.delivery_on_message || "").trim() || null,
                off: (form.delivery_off_message || "").trim() || null,
            });
        },
        when(iso) {
            if (!iso) return "";
            try { return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: PARIS }).format(new Date(iso)); } catch (_) { return ""; }
        },
        period(b) {
            if (!b.starts_at && !b.ends_at) return "toujours";
            if (!b.starts_at) return `jusqu'au ${this.when(b.ends_at)}`;
            if (!b.ends_at) return `à partir du ${this.when(b.starts_at)}`;
            return `du ${this.when(b.starts_at)} au ${this.when(b.ends_at)}`;
        },
        codeSaisi(previewBanner) {
            const d = (this.state.banners || []).find((x) => x.id === previewBanner.id);
            return d && d.coupon_code ? d.coupon_code : "";
        },
        stateLabel(s) { return { live: "En cours", scheduled: "Programmé", ended: "Terminé", off: "Désactivé" }[s] || s; },
        stateClass(s) { return { live: "sf-tag--ok", scheduled: "sf-tag--info", ended: "", off: "" }[s] || ""; },
        isState(res) { const d = res && res.data; return d && d.status === true && d.data && d.data.meta && Array.isArray(d.data.banners); },
        /**
         * `resetForm` = le serveur vient d'écrire les RÉGLAGES (chargement, enregistrement, retour arrière) : le formulaire les reprend.
         * Sinon (bandeau enregistré, supprimé, publication refusée) on GARDE ce que l'utilisateur a modifié sans l'enregistrer :
         * constaté en navigateur le 2026-11-03 — enregistrer un bandeau effaçait « Vitrine active » et les couleurs non
         * enregistrées, et « Publier » publiait alors une vitrine éteinte.
         */
        adopt(data, resetForm) {
            const keepEdits = !resetForm && this.loaded && this.settingsDirty;
            this.state = data;
            const d = data.draft;
            const server = {
                enabled: !!d.enabled,
                theme: { ...(d.theme || data.meta.default_theme) },
                delivery_on_message: d.delivery_on_message || "",
                delivery_off_message: d.delivery_off_message || "",
            };
            this.savedForm = this.snap(server, !!d.theme);
            if (!keepEdits) {
                this.customTheme = !!d.theme;
                this.form = server;
            }
        },
        errorList(e, fallback) {
            const d = e && e.response && e.response.data;
            if (d && d.errors) return [...new Set(Object.values(d.errors).flat())];
            return [(d && d.message) || fallback];
        },
        // [IDEMPOTENCE 2026-11-04] `cle` : X-Idempotency-Key des trois écritures qui portent l'intergiciel
        // (bandeau créé, publication, retour arrière). Sans clé, l'intergiciel laissait tout passer : la
        // protection « double envoi rejoué, pas recréé » annoncée dans routes/api.php était inerte.
        async call(method, url, body, resetForm = false, cle = null) {
            const res = await axios({ method, url, data: body, headers: cle ? { "X-Idempotency-Key": cle } : undefined });
            if (!this.isState(res)) { const err = new Error("Réponse inattendue"); err.unexpected = true; throw err; }
            this.adopt(res.data.data, resetForm);
            return res.data.data;
        },
        async load() {
            try {
                await this.call("get", "admin/storefront", undefined, true);
                this.loaded = true;
                this.loadError = "";
            } catch (e) {
                if (e && e.response && e.response.status === 403) this.forbidden = true;
                else this.loadError = "La vitrine est indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
        toggleTheme() {
            this.customTheme = !this.customTheme;
            if (this.customTheme && !this.meta.tokens.every((t) => isHex(this.form.theme[t.key]))) this.form.theme = { ...this.meta.default_theme };
        },
        usePreset() { this.form.theme = { ...this.meta.default_theme }; },
        setColor(key, value) { this.form.theme = { ...this.form.theme, [key]: String(value).trim() }; },
        async saveSettings(silent = false) {
            if (this.busy && !silent) return false;
            if (!silent) this.busy = "settings";
            this.settingsMsg = ""; this.settingsErrors = [];
            try {
                await this.call("put", "admin/storefront/settings", {
                    enabled: this.form.enabled,
                    theme: this.customTheme ? this.normalizedTheme() : null,
                    delivery_on_message: this.form.delivery_on_message || null,
                    delivery_off_message: this.form.delivery_off_message || null,
                }, true);
                if (!silent) { this.settingsOk = true; this.settingsMsg = "Brouillon enregistré. Cliquez sur « Publier » pour l'envoyer à l'application."; }
                return true;
            } catch (e) {
                this.settingsOk = false;
                this.settingsErrors = this.errorList(e, "Enregistrement impossible : vérifiez la connexion puis réessayez.");
                return false;
            } finally {
                if (!silent) this.busy = "";
            }
        },
        blankEditor() {
            return { id: null, title: "", body: "", type: "info", starts_at: "", ends_at: "", button_label: "", destination: "", product: "", coupon_code: "", priority: 0, active: true };
        },
        newBanner() { this.bannerErrors = []; this.editor = this.blankEditor(); },
        edit(b) {
            const link = b.button_link || "";
            const isProduct = link.startsWith("#menu/");
            this.bannerErrors = [];
            this.editor = {
                id: b.id, title: b.title, body: b.body || "", type: b.type,
                starts_at: b.starts_at ? b.starts_at.slice(0, 16) : "", ends_at: b.ends_at ? b.ends_at.slice(0, 16) : "",
                button_label: b.button_label || "", destination: isProduct ? "product" : link, product: isProduct ? link.slice(6) : "",
                coupon_code: b.coupon_code || "", priority: b.priority, active: !!b.active,
            };
        },
        cancelEdit() { this.editor = null; this.bannerErrors = []; },
        async saveBanner() {
            if (this.busy || !this.editor) return;
            const e = this.editor;
            const link = e.destination === "product" ? (e.product ? `#menu/${e.product}` : "") : e.destination;
            const body = {
                title: e.title, body: e.body || null, type: e.type,
                starts_at: e.starts_at || null, ends_at: e.ends_at || null,
                button_label: link ? (e.button_label || null) : (e.button_label ? e.button_label : null),
                button_link: link || null,
                coupon_code: e.coupon_code || null, priority: Number.isFinite(e.priority) ? e.priority : 0, active: !!e.active,
            };
            this.busy = "banner"; this.bannerErrors = [];
            try {
                // Une clé par bandeau en cours de création, GARDÉE si l'envoi échoue : le second essai rejoue
                // le premier au lieu de créer un doublon si la réponse s'était seulement perdue en route.
                if (!e.id && !e.cleCreation) e.cleCreation = buildIdempotencyHeaders()["X-Idempotency-Key"];
                await this.call(e.id ? "put" : "post", e.id ? `admin/storefront/banners/${e.id}` : "admin/storefront/banners", body, false, e.id ? null : `storefront-banner-${e.cleCreation}`);
                this.editor = null;
                alertService.success("Bandeau enregistré dans le brouillon.");
            } catch (err) {
                this.bannerErrors = this.errorList(err, "Enregistrement impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = "";
            }
        },
        async remove(b) {
            if (this.busy) return;
            if (!window.confirm(`Supprimer le bandeau « ${b.title} » du brouillon ?`)) return;
            this.busy = "banner";
            try { await this.call("delete", `admin/storefront/banners/${b.id}`); } catch (err) { this.publishOk = false; this.publishMsg = this.errorList(err, "Suppression impossible.")[0]; } finally { this.busy = ""; }
        },
        async publish() {
            if (this.busy || this.editor) return;
            this.busy = "publish"; this.publishMsg = ""; this.publishErrors = [];
            try {
                if (this.settingsDirty) {
                    if (!this.contrastOk) { this.publishErrors = ["Les couleurs ne sont pas assez contrastées : corrigez-les (voir « Couleurs ») avant de publier."]; return; }
                    if (!(await this.saveSettings(true))) { this.publishErrors = [...this.settingsErrors]; return; }
                }
                // [RED-W2a P2-2] l'empreinte du brouillon AFFICHÉ : si un autre administrateur l'a modifié entre-temps, le serveur
                // refuse (409) et l'écran se recharge pour montrer ses changements avant toute publication.
                const base = { base_version: this.state.published.version, draft_hash: this.state.draft_hash };
                // Clé = ce qu'on publie (version de départ + empreinte du brouillon) : le même geste rejoué ne
                // publie pas deux versions ; un autre brouillon a une autre clé.
                const data = await this.call("post", "admin/storefront/publish", base, false, `storefront-publish-${base.base_version}-${base.draft_hash}`);
                this.publishOk = true;
                this.publishMsg = `Version ${data.published.version} publiée : l'application l'affichera à sa prochaine lecture (au plus tard dans 5 minutes).`;
                alertService.success(`Vitrine publiée (version ${data.published.version}).`);
            } catch (err) {
                this.publishOk = false;
                this.publishErrors = this.errorList(err, "Publication impossible : vérifiez la connexion puis réessayez.");
                if (err && err.response && err.response.status === 409) { try { await this.call("get", "admin/storefront"); } catch (_) { /* l'erreur reste affichée */ } }
            } finally {
                this.busy = "";
            }
        },
        async rollback(version) {
            if (this.busy) return;
            const target = version || (this.state.published.version - 1);
            if (!window.confirm(`Republier la version ${target} ? Le brouillon actuel sera remplacé par cette version.`)) return;
            this.busy = "rollback"; this.publishMsg = ""; this.publishErrors = [];
            try {
                const data = await this.call("post", "admin/storefront/rollback", { base_version: this.state.published.version, version }, true,
                    `storefront-rollback-${this.state.published.version}-${version || "precedente"}`);
                this.editor = null;
                this.publishOk = true;
                this.publishMsg = `Version ${target} republiée sous le numéro ${data.published.version}.`;
            } catch (err) {
                this.publishOk = false;
                this.publishErrors = this.errorList(err, "Retour impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = "";
            }
        },
    },
};
</script>

<style scoped>
.sf { color: #1A1A1A; }
.sf-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px 16px; }
.sf-title { font-size: 20px; font-weight: 800; text-transform: none; } /* .db-card-title met des majuscules à chaque mot : « Vitrine De L'application » */
.sf-status { margin: 0; font-size: 14px; color: #374151; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.sf-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; }
.sf-main { min-width: 0; }
.sf-intro { margin: 0 0 8px; line-height: 1.5; }
.sf-muted { color: #4B5563; }
.sf-block { margin-top: 20px; padding-top: 16px; border-top: 1px solid #E5E7EB; }
.sf-h { font-size: 17px; font-weight: 800; margin: 0 0 10px; }
.sf-h5 { font-size: 15px; font-weight: 800; margin: 0 0 10px; }
.sf-label { display: block; font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 4px; }
.sf-field { display: block; margin-bottom: 10px; min-width: 0; }
.sf-input { width: 100%; border: 1px solid #9CA3AF; border-radius: 8px; padding: 8px 10px; font-size: 15px; background: #fff; color: #111827; min-height: 40px; }
.sf-input:focus, .sf-btn:focus-visible, .sf-toggle:focus-visible, .sf-swatch:focus-visible { outline: 3px solid #F4501E; outline-offset: 2px; }
.sf-input.is-bad { border-color: #B91C1C; background: #FEF2F2; }
.sf-row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.sf-row3 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 12px; align-items: end; }
.sf-check { display: flex; gap: 6px; align-items: center; font-weight: 700; min-height: 40px; margin-bottom: 10px; }
.sf-toggle { display: flex; gap: 10px; align-items: center; width: 100%; text-align: left; border: 1px solid #D1D5DB; border-radius: 10px; padding: 10px 12px; background: #fff; min-height: 48px; }
.sf-toggle[aria-pressed="true"] { border-color: #1A1A1A; background: #FFF7ED; }
.sf-box { width: 22px; height: 22px; border: 2px solid #1A1A1A; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; flex: none; }
.sf-delivery { display: flex; gap: 10px; align-items: flex-start; margin: 12px 0; padding: 10px 12px; background: #F9FAFB; border-radius: 10px; }
.sf-dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; flex: none; }
.sf-dot.is-on { background: #15803D; }
.sf-dot.is-off { background: #B91C1C; }
.sf-tokens { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-top: 12px; }
.sf-token { display: flex; gap: 10px; align-items: center; border: 1px solid #E5E7EB; border-radius: 10px; padding: 8px; }
.sf-swatch { width: 44px; height: 44px; border: 1px solid #9CA3AF; border-radius: 8px; padding: 0; background: none; flex: none; cursor: pointer; }
.sf-token-text { min-width: 0; flex: 1; }
.sf-hex { font-family: ui-monospace, Menlo, monospace; text-transform: uppercase; }
.sf-contrast { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 6px; }
.sf-contrast li { display: flex; gap: 10px; align-items: center; font-size: 14px; padding: 6px 8px; border-radius: 8px; }
.sf-contrast li.is-ok { background: #F0FDF4; color: #14532D; }
.sf-contrast li.is-bad { background: #FEF2F2; color: #7F1D1D; }
.sf-sample { width: 40px; height: 28px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; border: 1px solid #D1D5DB; flex: none; }
.sf-btn { border: 2px solid #1A1A1A; background: #1A1A1A; color: #fff; border-radius: 10px; padding: 9px 16px; font-weight: 800; font-size: 15px; min-height: 44px; cursor: pointer; }
.sf-btn:disabled { opacity: .45; cursor: not-allowed; }
.sf-btn--small { padding: 5px 10px; font-size: 13px; min-height: 36px; }
.sf-btn--ghost { background: #fff; color: #1A1A1A; }
.sf-btn--primary { background: #C2410C; border-color: #C2410C; }
.sf-bar, .sf-publish-actions, .sf-editor-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; margin-top: 12px; }
.sf-bar { flex-direction: column; align-items: flex-end; }
.sf-publish-actions { justify-content: flex-start; }
.sf-publish .sf-muted { margin: 8px 0 0; }
.sf-msg { margin: 0; padding: 8px 12px; border-radius: 8px; font-size: 14px; width: 100%; }
.sf-msg.is-ok { background: #F0FDF4; color: #14532D; }
.sf-msg.is-alert { background: #FEF2F2; color: #7F1D1D; }
.sf-errors { margin: 0; padding: 8px 12px 8px 28px; border-radius: 8px; background: #FEF2F2; color: #7F1D1D; font-size: 14px; width: 100%; list-style: disc; }
.sf-alert { padding: 10px 12px; border-radius: 8px; background: #FEF2F2; color: #7F1D1D; }
.sf-list { list-style: none; padding: 0; margin: 0 0 12px; display: grid; gap: 8px; }
.sf-item { display: flex; gap: 10px; align-items: center; border: 1px solid #E5E7EB; border-radius: 10px; padding: 8px 10px; flex-wrap: wrap; }
.sf-item-main { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
.sf-item-sub { display: block; }
.sf-item-actions { display: flex; gap: 6px; }
.sf-tag { display: inline-block; font-size: 12px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: #F3F4F6; color: #374151; }
.sf-tag--ok { background: #DCFCE7; color: #14532D; }
.sf-tag--warn { background: #FEF3C7; color: #78350F; }
.sf-tag--info { background: #DBEAFE; color: #1E3A8A; }
.sf-tag--flash { background: #FFEDD5; color: #7C2D12; }
.sf-editor { border: 2px solid #1A1A1A; border-radius: 12px; padding: 14px; margin-bottom: 12px; background: #FFFBF5; }
.sf-side { position: sticky; top: 80px; }
.sf-side-title { margin-bottom: 8px; }
.sf-side-note { font-size: 13px; margin-top: 8px; }
.sf-phone { width: 300px; max-width: 100%; border: 10px solid #1A1A1A; border-radius: 36px; overflow: hidden; background: var(--bg); position: relative; margin: 0 auto; }
.sf-phone-notch { width: 110px; height: 18px; background: #1A1A1A; border-radius: 0 0 12px 12px; margin: 0 auto; }
.sf-phone-screen { padding: 10px 12px 16px; min-height: 480px; color: var(--tx); }
.sf-phone-brand { font-weight: 900; font-size: 18px; margin: 4px 0 10px; color: var(--tx); }
.sf-phone-banner { background: var(--bb); color: var(--bt); border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; }
.sf-phone-banner-title { font-weight: 900; margin: 0; overflow-wrap: anywhere; }
.sf-phone-banner-body { margin: 4px 0 0; font-size: 13px; overflow-wrap: anywhere; }
.sf-phone-code { margin: 6px 0 0; font-weight: 800; font-family: ui-monospace, Menlo, monospace; }
.sf-phone-code-off { margin: 6px 0 0; font-size: 12px; line-height: 1.4; opacity: .85; }
.sf-phone-btn { display: inline-block; margin-top: 8px; padding: 6px 12px; border-radius: 999px; border: 2px solid currentColor; font-weight: 800; font-size: 13px; }
.sf-phone-btn--primary { background: var(--p); color: var(--op); border-color: var(--p); }
.sf-phone-off { font-size: 13px; opacity: .9; margin: 8px 0; }
.sf-phone-delivery { margin: 10px 0; font-size: 13px; font-weight: 700; }
.sf-phone-card { background: var(--sf); color: var(--tx); border-radius: 12px; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.sf-phone-card-title { margin: 0; font-weight: 900; }
.sf-phone-card-text { margin: 2px 0 0; font-size: 13px; }
@media (max-width: 1100px) {
    .sf-grid { grid-template-columns: minmax(0, 1fr); }
    .sf-side { position: static; }
}
@media (max-width: 640px) {
    .sf-row2, .sf-row3 { grid-template-columns: minmax(0, 1fr); }
    .sf-tokens { grid-template-columns: minmax(0, 1fr); }
    .sf-item-actions { width: 100%; }
}
</style>
