<template>
    <!-- [WIZARD-STUDIO W0 2026-06-14] New WYSIWYG visual builder — COPY alongside the
         existing form-based ProductComposerEditorComponent. Reuses the SAME composer API
         + data model (item_wizard_profiles/steps -> composer_profile) so the FROZEN kiosk/POS
         wizards render the result identically. Non-frozen. Vertical portrait preview = the
         operator edits exactly what the customer sees on the borne. -->
    <div class="wizard-studio" data-testid="wizard-studio-root">
        <header class="wizard-studio__bar">
            <button type="button" class="ws-back" data-testid="wizard-studio-back" @click="goBack">‹ Retour</button>
            <div class="ws-title">
                <span class="ws-eyebrow">Wizard Studio</span>
                <strong>{{ entityName || '…' }}</strong>
                <span class="ws-source" data-testid="wizard-studio-source">{{ inheritanceLabel }}</span>
            </div>
            <span
                class="ws-badge"
                :class="{ 'ws-badge--published': isPublished }"
                :title="isPublished ? 'Visible par les clients sur la borne' : 'Brouillon — non visible des clients'"
                data-testid="wizard-studio-status"
            >
                {{ isPublished ? 'Publié — visible clients' : 'Brouillon' }}
            </span>
        </header>

        <div v-if="loading" class="ws-state" data-testid="wizard-studio-loading">Chargement…</div>
        <div v-else-if="loadError" class="ws-state ws-state--error" data-testid="wizard-studio-error">
            {{ loadError }}
        </div>

        <div v-else class="wizard-studio__body">
            <!-- [W1] Vertical portrait preview = the REAL frozen KioskWizardComponent rendering
                 the operator's DRAFT, read-only (onAddToCart no-op → no cart/order path).
                 Truth by construction: this is literally the component the customer uses, fed the
                 draft projection (is_published forced true server-side). Zero frozen edit. -->
            <section class="ws-stage" aria-label="Aperçu borne (vertical)">
                <p
                    v-if="zeroChoiceSteps.length"
                    class="ws-warn"
                    role="alert"
                    data-testid="wizard-studio-zero-choice-warn"
                >
                    ⚠ {{ zeroChoiceSteps.length }} page(s) sans option disponible
                    ({{ zeroChoiceSteps.join(', ') }}) — le client ne pourra pas valider.
                    {{ isPublished ? 'Ces pages sont DÉJÀ visibles des clients — corrigez leur source au plus vite.' : 'Corrigez la source de ces pages avant publication.' }}
                </p>
                <div class="ws-phone" data-testid="wizard-studio-preview">
                    <div class="ws-phone__notch" aria-hidden="true"></div>
                    <div class="ws-phone__screen kiosk-root">
                        <div v-if="previewLoading" class="ws-phone__hint" data-testid="wizard-studio-preview-loading">
                            <span class="ws-spinner" aria-hidden="true"></span> Préparation de l'aperçu…
                        </div>
                        <div v-else-if="previewError" class="ws-phone__hint ws-phone__hint--error" role="alert" data-testid="wizard-studio-preview-error">
                            {{ previewError }}
                        </div>
                        <KioskWizardComponent
                            v-else-if="draftItem"
                            :key="previewNonce"
                            :item="draftItem"
                            :on-add-to-cart="noop"
                            :on-close="noop"
                            data-testid="wizard-studio-live-preview"
                        />
                        <div v-else class="ws-phone__hint" data-testid="wizard-studio-preview-empty">
                            Aucune page configurée — ajoutez une page (W2) pour voir l'aperçu.
                        </div>
                    </div>
                </div>
                <p class="ws-stage__caption">Aperçu borne — écran vertical, exactement ce que voit le client</p>
            </section>

            <!-- [W2] Editable page list: drag-reorder + inline rename + delete + add → bulk PUT → live refresh. -->
            <aside class="ws-panel" aria-label="Pages du wizard">
                <div class="ws-panel__head">
                    <h2 class="ws-panel__title">Pages du wizard</h2>
                    <span v-if="savingDraft" class="ws-saving" data-testid="wizard-studio-saving">Enregistrement…</span>
                </div>
                <p class="ws-panel__meta">{{ steps.length }} page(s) · v{{ version }} · {{ isPublished ? 'publié' : 'brouillon' }}</p>

                <p v-if="conflictDetected" class="ws-warn" role="alert" data-testid="wizard-studio-conflict">
                    ⚠ Ce wizard a été modifié ailleurs. <button type="button" class="ws-link" @click="reloadAll">Recharger</button> pour repartir de la dernière version (vos modifications non enregistrées seront écartées).
                </p>
                <p v-if="isPublished && !conflictDetected" class="ws-live" data-testid="wizard-studio-live-edit">
                    ⚡ Édition en direct — ce wizard est <strong>publié</strong> : chaque changement est aussitôt visible des clients sur la borne.
                </p>

                <draggable
                    v-if="steps.length"
                    v-model="steps"
                    item-key="_uid"
                    handle=".ws-step-drag"
                    class="ws-steplist"
                    ghost-class="ws-steprow--ghost"
                    @end="onReorder"
                >
                    <div
                        v-for="(s, i) in steps"
                        :key="s._uid"
                        class="ws-steprow"
                        :class="{ 'ws-steprow--hidden': !stepRenders(s) }"
                        :data-testid="`ws-steprow-${i}`"
                    >
                        <div class="ws-steprow__head">
                            <button
                                type="button"
                                class="ws-step-drag"
                                :aria-label="`Réordonner la page ${i + 1} — flèches haut/bas`"
                                :data-testid="`ws-step-drag-${i}`"
                                @keydown.up.prevent="movePage(s, -1)"
                                @keydown.down.prevent="movePage(s, 1)"
                            >⠿</button>
                            <input
                                class="ws-step-name"
                                :value="s.label"
                                :aria-label="`Nom de la page ${i + 1}`"
                                :data-testid="`ws-step-name-${i}`"
                                @input="onRename(s, $event.target.value)"
                                @change="saveStudioDraft"
                                @keyup.enter="$event.target.blur()"
                            />
                            <span class="ws-steprow__rule">{{ ruleSummary(s) }}</span>
                            <span v-if="!stepRenders(s)" class="ws-steplist__tag" title="Page sans option : la borne ne l'affichera pas">0 option</span>
                            <button
                                type="button"
                                class="ws-step-cog"
                                :class="{ 'ws-step-cog--on': expandedUid === s._uid }"
                                :aria-label="`Règles de la page ${i + 1}`"
                                :aria-expanded="expandedUid === s._uid ? 'true' : 'false'"
                                :data-testid="`ws-step-cog-${i}`"
                                @click="toggleRule(s)"
                            >⚙</button>
                            <button type="button" class="ws-step-del" :aria-label="`Supprimer la page ${i + 1}`" :data-testid="`ws-step-del-${i}`" @click="removePage(s)">🗑</button>
                        </div>

                        <!-- [W3] selection-rule editor (+ [W6] source binding) -->
                        <div v-if="expandedUid === s._uid" class="ws-rule" :data-testid="`ws-rule-${i}`">
                            <label v-if="sourceOptions.length" class="ws-rule__field ws-rule__field--wide">
                                <span>Source</span>
                                <select :value="currentSourceKey(s)" :data-testid="`ws-rule-source-${i}`" @change="setSource(s, $event.target.value)">
                                    <option v-if="!sourceOptions.some((o) => o.key === currentSourceKey(s))" :value="currentSourceKey(s)">{{ s.source_ref ? `Source actuelle : ${s.source_ref}` : '— à lier —' }}</option>
                                    <option v-for="o in sourceOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
                                </select>
                            </label>
                            <label class="ws-rule__field">
                                <span>Choix</span>
                                <select :value="isMulti(s) ? 'multi' : 'single'" :data-testid="`ws-rule-type-${i}`" @change="setChoiceType(s, $event.target.value)">
                                    <option value="single">Un seul</option>
                                    <option value="multi">Plusieurs</option>
                                </select>
                            </label>
                            <label class="ws-rule__check">
                                <input type="checkbox" :checked="Number(s.min_select) >= 1" :data-testid="`ws-rule-required-${i}`" @change="setRequired(s, $event.target.checked)" />
                                Obligatoire
                            </label>
                            <template v-if="isMulti(s)">
                                <label class="ws-rule__field"><span>Min</span><input type="number" min="0" :value="s.min_select" :data-testid="`ws-rule-min-${i}`" @change="setBound(s, 'min_select', $event.target.value)" /></label>
                                <label class="ws-rule__field"><span>Max</span><input type="number" min="0" :value="s.max_select" :data-testid="`ws-rule-max-${i}`" @change="setBound(s, 'max_select', $event.target.value)" /></label>
                                <label class="ws-rule__check"><input type="checkbox" :checked="!!s.allow_repeat" @change="setRepeat(s, $event.target.checked)" /> Répétable</label>
                            </template>
                            <p class="ws-rule__hint">{{ ruleSummary(s) }} · {{ isMulti(s) ? 'plusieurs choix' : 'un seul choix' }}</p>

                            <!-- [W4c] read-only option inspector — what the customer will actually see -->
                            <div class="ws-options" :data-testid="`ws-options-${i}`">
                                <template v-if="optionsForStep(s).length">
                                    <span class="ws-options__title">{{ optionsForStep(s).length }} option(s) liée(s) à cette page <small>(l'aperçu borne à gauche est le rendu final)</small> :</span>
                                    <ul class="ws-options__list">
                                        <li
                                            v-for="o in optionsForStep(s)"
                                            :key="o.id"
                                            class="ws-option"
                                            :class="{ 'ws-option--off': o.is_available === false }"
                                            :title="o.is_available === false ? (o.unavailable_reason || 'Indisponible') : o.name"
                                        >
                                            <img v-if="o.thumb" :src="o.thumb" :alt="o.name" class="ws-option__img" loading="lazy" @error="onOptionImgError" />
                                            <span v-else class="ws-option__img ws-option__img--ph" aria-hidden="true"></span>
                                            <span class="ws-option__name">{{ o.name }}</span>
                                            <span v-if="o.is_available === false" class="ws-option__off">rupture</span>
                                        </li>
                                    </ul>
                                </template>
                                <p v-else class="ws-options__empty">Aucune option résolue — choisissez une « Source » ci-dessus pour que cette page propose des choix.</p>
                            </div>
                        </div>
                    </div>
                </draggable>
                <p v-else class="ws-panel__meta">Aucune page — ajoutez-en une pour commencer.</p>

                <button type="button" class="ws-add" data-testid="wizard-studio-add-page" @click="addPage">+ Ajouter une page</button>

                <p class="ws-panel__note">ℹ️ L'aperçu à gauche est le rendu RÉEL de la borne : elle peut masquer une page sans option ou ajouter un récapitulatif. Glissez pour réordonner ; renommez en cliquant le nom. Le binding des options (images, prix, règles) arrive dans les prochaines vagues.</p>
            </aside>
        </div>
    </div>
</template>

<script>
import { defineAsyncComponent } from 'vue';
import { VueDraggableNext } from 'vue-draggable-next';
import axios from 'axios';

export default {
    name: 'WizardStudioComponent',
    components: {
        // The FROZEN kiosk wizard, mounted UNCHANGED + read-only. Lazy (defineAsyncComponent,
        // Vue 3) so it shares the existing kiosk chunk and only loads when the Studio opens.
        KioskWizardComponent: defineAsyncComponent(() => import(/* webpackChunkName: "kiosk-wizard" */ '../../../frontend/kiosk/KioskWizardComponent.vue')),
        // [W2] drag-reorder the page list (same lib as the existing composer builder).
        draggable: VueDraggableNext,
    },
    props: {
        entityType: { type: String, default: 'item' },
        entityId: { type: [String, Number], required: true },
    },
    data() {
        return {
            loading: true,
            loadError: '',
            entityName: '',
            profile: null,
            steps: [],
            // [W1] Live preview state.
            draftItem: null,
            previewLoading: false,
            previewError: '',
            previewNonce: 0,
            // [W2] Edit state.
            savingDraft: false,
            conflictDetected: false,
            _uidSeq: 0,
            _pendingSave: false, // [WS-5] coalesce edits made while a save is in flight
            // [W3] which page's rule editor is open.
            expandedUid: null,
            // [W6] bindable sources for this category (item_attribute / extra_group / addon).
            sources: { item_attribute: [], extra_group: [], addon: [] },
        };
    },
    computed: {
        isCategory() {
            return this.entityType === 'category';
        },
        isPublished() {
            return !!(this.profile && this.profile.is_published);
        },
        version() {
            return this.profile ? (this.profile.version || 1) : 1;
        },
        profileEndpoint() {
            return this.isCategory
                ? `admin/composer/categories/${this.entityId}/profile`
                : `admin/composer/items/${this.entityId}/profile`;
        },
        // Where does the rendered wizard come from? (category-inherited vs item-owned).
        inheritanceLabel() {
            if (!this.profile) return '';
            if (this.profile.item_category_id && !this.profile.item_id) {
                return 'Wizard de catégorie — hérité par tous les produits de la catégorie';
            }
            return 'Wizard propre à ce produit';
        },
        // [W6] flattened bindable sources for the page Source picker.
        sourceOptions() {
            const out = [];
            (this.sources.item_attribute || []).forEach((s) => out.push({ key: `item_attribute:${s.source_ref}`, label: `Attribut · ${s.name}`, source_type: 'item_attribute', source_ref: s.source_ref, addon_role: null }));
            (this.sources.extra_group || []).forEach((s) => out.push({ key: `extra_group:${s.source_ref}`, label: `Suppléments · ${s.name}`, source_type: 'extra_group', source_ref: s.source_ref, addon_role: null }));
            (this.sources.addon || []).forEach((s) => out.push({ key: `addon:${s.source_ref}`, label: `Add-on · ${s.name}`, source_type: 'addon', source_ref: s.source_ref, addon_role: s.addon_role || s.source_ref }));
            return out;
        },
        // Steps that resolve to zero selectable options = customer cannot proceed (misconfig).
        zeroChoiceSteps() {
            const steps = this.draftItem?.composer_profile?.steps;
            if (!Array.isArray(steps)) return [];
            return steps
                .filter((s) => s.is_active !== false && (!Array.isArray(s.choices) || s.choices.length === 0))
                .map((s) => s.label || s.step_key);
        },
    },
    async created() {
        await this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            this.loadError = '';
            try {
                const entityUrl = this.isCategory
                    ? `admin/setting/item-category/show/${this.entityId}`
                    : `admin/item/show/${this.entityId}`;
                // Fire both fetches in PARALLEL (no extra round-trip), but await the entity first
                // so we can show a context-correct not-found message (the backend's generic 404
                // body says "Commande introuvable" which is wrong for a category/product).
                const entityP = axios.get(entityUrl);
                const profileP = axios.get(this.profileEndpoint)
                    .catch((e) => (e.response && e.response.status === 404 ? { data: null } : Promise.reject(e)));
                let entityRes;
                try {
                    entityRes = await entityP;
                } catch (e) {
                    profileP.catch(() => {}); // swallow the in-flight profile promise (entity failed)
                    this.loadError = (e?.response?.status === 404)
                        ? (this.isCategory ? 'Catégorie introuvable.' : 'Produit introuvable.')
                        : (e?.response?.data?.message || 'Impossible de charger le wizard.');
                    return;
                }
                const profileRes = await profileP;
                this.entityName = entityRes?.data?.data?.name ?? entityRes?.data?.name ?? '';
                const profile = profileRes?.data?.data ?? profileRes?.data ?? null;
                this.profile = profile;
                this.steps = this.hydrateSteps(profile);
                if (this.profile?.id) {
                    if (this.isCategory) await this.fetchSources();
                    await this.fetchPreview();
                } else if (!this.isCategory) {
                    // Item profile fetch 404'd (swallowed above). The per-item composer endpoint is
                    // gated by FEATURE_WIZARD_PER_ITEM_DEMO — surface that instead of a silent empty.
                    this.loadError = "Le Wizard Studio par produit nécessite l'activation de la fonctionnalité (FEATURE_WIZARD_PER_ITEM_DEMO). Les wizards par catégorie sont disponibles sans activation.";
                }
            } catch (e) {
                this.loadError = e?.response?.data?.message || 'Impossible de charger le wizard.';
            } finally {
                this.loading = false;
            }
        },
        // [W1] Fetch the DRAFT preview projection and feed it to the live kiosk render.
        async fetchPreview() {
            if (!this.profile?.id) {
                this.draftItem = null;
                return;
            }
            this.previewLoading = true;
            this.previewError = '';
            try {
                const res = await axios.get(`admin/composer/profiles/${this.profile.id}/preview-projection`);
                const item = res?.data?.data?.item ?? null;
                // Only render the wizard when the draft actually has steps.
                this.draftItem = item && item.composer_profile && Array.isArray(item.composer_profile.steps) && item.composer_profile.steps.length
                    ? item
                    : null;
            } catch (e) {
                // Distinguish "no draft yet" (404 → empty state) from a real failure (surface it,
                // so the operator isn't told "no pages" when the server actually errored).
                this.draftItem = null;
                if (!e?.response || e.response.status !== 404) {
                    this.previewError = e?.response?.data?.message || "Impossible de charger l'aperçu (erreur serveur).";
                }
            } finally {
                this.previewLoading = false;
            }
        },
        // [W1] Reload-on-edit (used by W2 step CRUD): remount the wizard with fresh draft.
        async reloadPreview() {
            await this.fetchPreview();
            this.previewNonce += 1;
        },
        noop() {},
        // A configured step that resolves to 0 options is dropped from the live borne rail.
        stepRenders(step) {
            const label = step.label || step.step_key;
            return !this.zeroChoiceSteps.includes(label);
        },
        ruleSummary(step) {
            const min = Number(step.min_select || 0);
            const max = Number(step.max_select || 0);
            if (min === 0 && max === 1) return 'optionnel · 1 max';
            if (min === max && min > 0) return `obligatoire · ${min}`;
            if (max === 0) return `min ${min} · illimité`;
            return `${min}–${max}`;
        },
        goBack() {
            // Prefer real history; fall back to the catalog studio if opened directly.
            if (window.history.length > 1) {
                this.$router.back();
            } else {
                this.$router.push({ name: 'admin.items.studio' });
            }
        },

        // ---- [W2] EDIT pillar: page CRUD/reorder, all via the bulk profile PUT + live refresh ----
        hydrateSteps(profile) {
            const arr = Array.isArray(profile?.steps)
                ? [...profile.steps].sort((a, b) => (a.position || 0) - (b.position || 0))
                : [];
            // Preserve _uid across re-hydrate (match by step_key) so the open rule editor / input
            // focus survive a save (no row remount).
            const prev = new Map((this.steps || []).map((s) => [s.step_key, s._uid]));
            return arr.map((s) => ({ ...s, _uid: prev.get(s.step_key) || `u${++this._uidSeq}` }));
        },
        onRename(step, value) {
            // local label update only; persisted on blur (@change) to avoid mid-typing re-hydrate.
            step.label = value;
        },
        addPage() {
            const n = this.steps.length + 1;
            this.steps = [...this.steps, {
                _uid: `u${++this._uidSeq}`,
                // deterministically-unique stable key (avoids UNIQUE(profile_id, step_key)); rename keeps the key.
                step_key: `page_${Date.now().toString(36)}${++this._uidSeq}`,
                label: `Nouvelle page ${n}`,
                source_type: 'item_attribute',
                source_ref: '',
                min_select: 0,
                max_select: 1,
                allow_repeat: false,
                visible_on: ['pos', 'kiosk'],
                stockable_choices: false,
                is_active: true,
                position: this.steps.length,
            }];
            this.saveStudioDraft();
        },
        async removePage(step) {
            this.steps = this.steps.filter((s) => s._uid !== step._uid);
            await this.saveStudioDraft();
        },
        onReorder() {
            // vue-draggable-next already mutated this.steps order; persist new positions.
            this.saveStudioDraft();
        },
        // [WS-4] keyboard-accessible reorder (arrow up/down on the focused drag handle).
        movePage(step, dir) {
            const i = this.steps.findIndex((s) => s._uid === step._uid);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= this.steps.length) return;
            const arr = [...this.steps];
            [arr[i], arr[j]] = [arr[j], arr[i]];
            this.steps = arr;
            this.saveStudioDraft();
        },
        // Build the NF525-safe step payload (no price — price lives on catalog constructs).
        payloadForStep(s, i) {
            const min = Number(s.min_select || 0);
            const max = Math.max(Number(s.max_select || 0), min);
            return {
                step_key: s.step_key || `page_${i + 1}`, // keep the stable key (preserves kiosk rendering)
                label: s.label || `Page ${i + 1}`,
                source_type: ['item_attribute', 'extra_group', 'addon'].includes(s.source_type) ? s.source_type : 'item_attribute',
                source_ref: s.source_ref == null ? '' : String(s.source_ref),
                min_select: min,
                max_select: max,
                allow_repeat: Boolean(s.allow_repeat),
                visible_on: Array.isArray(s.visible_on) ? s.visible_on : ['pos', 'kiosk'],
                stockable_choices: Boolean(s.stockable_choices),
                position: i,
                is_active: s.is_active !== false,
                addon_role: s.addon_role || null,
            };
        },
        async saveStudioDraft() {
            if (!this.profile?.id) return;
            // [WS-5] an edit made during an in-flight save is not dropped: queue a trailing re-save.
            if (this.savingDraft) { this._pendingSave = true; return; }
            this.savingDraft = true;
            this.conflictDetected = false;
            this.previewError = '';
            try {
                const payload = {
                    template: this.profile.template || 'custom',
                    branch_id_scope: this.profile.branch_id_scope ?? null,
                    steps: this.steps.map((s, i) => this.payloadForStep(s, i)),
                    version: this.version,
                };
                const res = await axios.put(`admin/composer/profiles/${this.profile.id}`, payload);
                const updated = res?.data?.data ?? res?.data ?? null;
                if (updated) {
                    this.profile = updated;
                    this.steps = this.hydrateSteps(updated);
                }
                await this.reloadPreview(); // refresh the live borne render
            } catch (e) {
                if (e?.response?.status === 409) {
                    this.conflictDetected = true; // optimistic-lock clash → operator reloads
                    return;
                }
                this.previewError = e?.response?.data?.message || 'Enregistrement impossible.';
            } finally {
                this.savingDraft = false;
                // flush a queued edit, unless we hit a version conflict (would just re-409).
                if (this._pendingSave && !this.conflictDetected) {
                    this._pendingSave = false;
                    this.$nextTick(() => this.saveStudioDraft());
                } else {
                    this._pendingSave = false;
                }
            }
        },
        async reloadAll() {
            this.conflictDetected = false;
            await this.load();
        },

        // ---- [W3] selection rules (single/multi · required · min/max · repeat) ----
        isMulti(step) {
            return Number(step.max_select || 0) !== 1;
        },
        toggleRule(step) {
            this.expandedUid = this.expandedUid === step._uid ? null : step._uid;
        },
        setChoiceType(step, type) {
            if (type === 'single') {
                step.max_select = 1;
                if (Number(step.min_select) > 1) step.min_select = 1;
            } else {
                step.max_select = Math.max(2, Number(step.max_select) || 2);
            }
            this.saveStudioDraft();
        },
        setRequired(step, required) {
            step.min_select = required ? Math.max(1, Number(step.min_select) || 1) : 0;
            if (Number(step.max_select) < Number(step.min_select)) step.max_select = step.min_select;
            this.saveStudioDraft();
        },
        setBound(step, field, value) {
            const n = Math.max(0, parseInt(value, 10) || 0);
            step[field] = n;
            // keep min ≤ max coherent
            if (field === 'min_select' && Number(step.max_select) < n) step.max_select = n;
            if (field === 'max_select' && n > 0 && Number(step.min_select) > n) step.min_select = n;
            this.saveStudioDraft();
        },
        setRepeat(step, value) {
            step.allow_repeat = !!value;
            this.saveStudioDraft();
        },

        // ---- [W6] source binding (fixes the turnkey source_ref='' → 0-option pages) ----
        async fetchSources() {
            try {
                const res = await axios.get(`admin/composer/categories/${this.entityId}/available-sources`);
                const d = res?.data?.data ?? {};
                this.sources = {
                    item_attribute: d.item_attribute || [],
                    extra_group: d.extra_group || [],
                    addon: d.addon || [],
                };
            } catch (e) {
                this.sources = { item_attribute: [], extra_group: [], addon: [] };
            }
        },
        currentSourceKey(step) {
            return `${step.source_type}:${step.source_ref == null ? '' : step.source_ref}`;
        },
        // [W4c] Read-only option inspector: the real choices the borne resolves for this page
        // (name + image + rupture), pulled from the live preview projection. No mutation.
        optionsForStep(step) {
            const projSteps = this.draftItem?.composer_profile?.steps;
            if (!Array.isArray(projSteps)) return [];
            const match = projSteps.find((s) => s.step_key === step.step_key);
            return Array.isArray(match?.choices) ? match.choices : [];
        },
        // [W4c] a missing/broken option image (e.g. a custom image_path with no file) must not show
        // a broken-image icon — just hide it (the chip keeps the name). No manual DOM insertion:
        // an orphaned node would survive Vue's vdom reconciliation on the next re-render.
        onOptionImgError(event) {
            if (event?.target) {
                event.target.style.display = 'none';
            }
        },
        setSource(step, key) {
            const src = this.sourceOptions.find((s) => s.key === key);
            if (!src) return;
            step.source_type = src.source_type;
            step.source_ref = src.source_ref;
            step.addon_role = src.addon_role;
            this.saveStudioDraft();
        },
    },
};
</script>

<style scoped>
.wizard-studio { display: flex; flex-direction: column; min-height: 100%; background: #faf7f2; }
.wizard-studio__bar { display: flex; align-items: center; gap: 16px; padding: 12px 20px; background: #fff; border-bottom: 2px solid #F4501E; }
.ws-back { border: 0; background: transparent; font-size: 15px; cursor: pointer; color: #333; border-radius: 6px; padding: 4px 8px; }
.ws-back:focus-visible { outline: 2px solid #F4501E; outline-offset: 2px; }
.ws-title { display: flex; flex-direction: column; line-height: 1.25; }
.ws-eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #A8370E; } /* darker brand-orange: WCAG AA (~4.6:1) on white as small text */
.ws-source { font-size: 12px; color: #6b6b6b; margin-top: 2px; }
.ws-badge { margin-left: auto; padding: 5px 12px; border-radius: 999px; background: #ececec; color: #555; font-size: 12px; font-weight: 600; }
.ws-badge--published { background: #e6f6ec; color: #0f6e38; } /* AA ≥4.5:1 on the light-green pill */
.ws-state { padding: 40px; text-align: center; color: #555; }
.ws-state--error { color: #b02a1a; }
.wizard-studio__body { display: grid; grid-template-columns: 1fr 360px; gap: 24px; padding: 24px; align-items: start; }
.ws-stage { display: flex; flex-direction: column; align-items: center; gap: 12px; }
.ws-warn { width: 100%; max-width: 420px; margin: 0; padding: 10px 14px; border-radius: 10px; background: #fff4e8; border: 1px solid #f6c89a; color: #9a4a08; font-size: 13px; line-height: 1.35; }
/* Realistic portrait device: dark shell + 9:16 screen the kiosk render is bounded to.
   transform establishes a containing block so the frozen wizard's position:fixed abandon
   overlay (z-index 120) is bounded to the device frame instead of covering the admin page (F7). */
.ws-phone { position: relative; width: 390px; background: #1a1a1a; border-radius: 36px; padding: 14px; box-shadow: 0 16px 48px rgba(20,20,20,.22); transform: translateZ(0); }
.ws-phone__notch { position: absolute; top: 14px; left: 50%; transform: translateX(-50%); width: 120px; height: 18px; background: #1a1a1a; border-radius: 0 0 14px 14px; z-index: 2; }
.ws-phone__screen { width: 362px; height: 644px; overflow: auto; background: #fff; border-radius: 24px; -webkit-overflow-scrolling: touch; }
/* The FROZEN kiosk wizard renders at 100vw (fullscreen borne). Inside the device frame we
   override it to a realistic kiosk-portrait width and scale it down with `zoom` so the
   operator sees the true borne proportions, fit-to-frame, instead of a clipped desktop slice. */
/* Override BOTH frozen viewport dims: width:100vw → 724px AND height:100vh → 1288px, so
   zoom:0.5 maps to exactly the 362×644 frame regardless of admin viewport size. Without the
   height override the wizard stays 100vh (viewport-dependent) and its sticky footer/CTA pins
   off-frame (F1). With it, the kiosk scrolls its own step-content and pins the footer in-frame. */
.ws-phone__screen :deep(.kiosk-wizard) { width: 724px !important; min-width: 724px; height: 1288px !important; max-height: 1288px !important; zoom: 0.5; }
.ws-phone__hint { display: flex; align-items: center; justify-content: center; gap: 8px; height: 100%; min-height: 200px; text-align: center; color: #777; font-size: 13px; padding: 24px; }
.ws-phone__hint--error { color: #b02a1a; }
.ws-spinner { width: 16px; height: 16px; border: 2px solid #f0d9cf; border-top-color: #F4501E; border-radius: 50%; display: inline-block; animation: ws-spin 0.8s linear infinite; }
@keyframes ws-spin { to { transform: rotate(360deg); } }
.ws-stage__caption { margin: 0; font-size: 12px; color: #6b6b6b; } /* WCAG AA ≥4.5:1 on #faf7f2 */
.ws-panel { background: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(20,20,20,.06); position: sticky; top: 24px; }
.ws-panel__title { margin: 0 0 6px; font-size: 16px; }
.ws-panel__meta { color: #555; font-size: 13px; margin: 0 0 12px; }
.ws-panel__note { color: #777; font-size: 12px; line-height: 1.4; margin-top: 12px; }
.ws-panel__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.ws-saving { font-size: 11px; color: #0f6e38; }
.ws-link { border: 0; background: transparent; color: #A8370E; text-decoration: underline; cursor: pointer; font: inherit; padding: 0; }
.ws-steplist { margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
/* [W2] editable page row */
.ws-steprow { display: flex; flex-direction: column; padding: 8px 10px; border: 1px solid #eee6d9; border-radius: 10px; background: #fff; }
.ws-steprow__head { display: flex; align-items: center; gap: 8px; }
.ws-steprow--ghost { opacity: .4; }
.ws-steprow--hidden { background: #faf6f0; }
.ws-step-cog { border: 0; background: transparent; cursor: pointer; font-size: 14px; padding: 2px 4px; border-radius: 6px; color: #66756e; }
.ws-step-cog--on { background: #fff1e4; color: #A8370E; }
.ws-step-cog:focus-visible { outline: 2px solid #F4501E; outline-offset: 1px; }
/* [W3] rule editor */
.ws-rule { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #eee6d9; }
.ws-rule__field { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #555; }
.ws-rule__field--wide { flex-basis: 100%; }
.ws-rule__field--wide select { flex: 1; }
.ws-rule__field select, .ws-rule__field input { font-size: 12px; padding: 3px 6px; border: 1px solid #d9dfdc; border-radius: 6px; }
.ws-rule__field input[type="number"] { width: 52px; }
.ws-rule__check { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #555; }
.ws-rule__hint { flex-basis: 100%; margin: 2px 0 0; font-size: 11px; color: #A8370E; }
/* [W4c] option inspector */
.ws-options { flex-basis: 100%; margin-top: 6px; }
.ws-options__title { font-size: 11px; color: #555; }
.ws-options__empty { margin: 4px 0 0; font-size: 11px; color: #777; }
.ws-options__list { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.ws-option { display: flex; align-items: center; gap: 6px; padding: 3px 8px 3px 3px; border: 1px solid #e6ddd0; border-radius: 999px; background: #fff; font-size: 12px; color: #333; }
.ws-option--off { opacity: .55; }
.ws-option__img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; background: #f1ece4; }
.ws-option__img--ph { display: inline-block; }
.ws-option__name { line-height: 1; }
.ws-option__off { font-size: 9px; color: #b02a1a; background: #fdecea; padding: 1px 5px; border-radius: 6px; }
.ws-live { margin: 0 0 10px; padding: 8px 12px; border-radius: 10px; background: #fff4e8; border: 1px solid #f6c89a; color: #9a4a08; font-size: 12px; line-height: 1.35; }
.ws-step-drag { border: 0; background: transparent; cursor: grab; color: #9aa39e; font-size: 16px; line-height: 1; padding: 2px 4px; border-radius: 6px; }
.ws-step-drag:focus-visible { outline: 2px solid #F4501E; outline-offset: 1px; }
.ws-step-name { flex: 1; min-width: 0; border: 1px solid transparent; border-radius: 6px; padding: 4px 6px; font-size: 14px; color: #222; background: transparent; }
.ws-step-name:hover { border-color: #e3ddd2; }
.ws-step-name:focus { border-color: #F4501E; outline: none; background: #fff; }
.ws-steprow__rule { color: #A8370E; font-size: 11px; white-space: nowrap; }
.ws-step-del { border: 0; background: transparent; cursor: pointer; font-size: 14px; padding: 2px 4px; border-radius: 6px; }
.ws-step-del:hover { background: #fdecea; }
.ws-step-del:focus-visible { outline: 2px solid #b02a1a; outline-offset: 1px; }
.ws-add { width: 100%; border: 1px dashed #d9c9bb; background: #fff8f1; color: #A8370E; border-radius: 10px; padding: 10px; cursor: pointer; font-size: 13px; font-weight: 600; }
.ws-add:hover { background: #fff1e4; }
.ws-add:focus-visible { outline: 2px solid #F4501E; outline-offset: 2px; }
.ws-steplist__tag { display: inline-block; padding: 1px 6px; border-radius: 6px; background: #fff4e8; color: #9a4a08; font-size: 10px; white-space: nowrap; }
@media (max-width: 1024px) {
    .wizard-studio__body { grid-template-columns: 1fr; }
    .ws-panel { order: -1; position: static; }
}
</style>
