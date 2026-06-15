<template>
    <div class="wizard-studio" data-testid="wizard-studio-root">
        <!-- [WS-INT-01] The root MUST be the literal first node of <template> (no leading comment):
             a leading comment makes the SFC a multi-root fragment so this.$el becomes the comment node
             (no querySelector) and movePage/removePage focus handoffs silently no-op. Keep doc comments INSIDE.
             [WIZARD-STUDIO W0 2026-06-14] New WYSIWYG visual builder — COPY alongside the existing
             form-based ProductComposerEditorComponent. Reuses the SAME composer API + data model
             (item_wizard_profiles/steps -> composer_profile) so the FROZEN kiosk/POS wizards render
             identically. Non-frozen. Vertical portrait preview = exactly what the customer sees. -->
        <header class="wizard-studio__bar">
            <button type="button" class="ws-back" data-testid="wizard-studio-back" @click="goBack">‹ Retour</button>
            <div class="ws-title">
                <span class="ws-eyebrow">Composition produit · Borne &amp; Caisse</span>
                <strong>{{ entityName || '…' }}</strong>
                <span class="ws-source" :title="isCategory ? 'Hérité par tous les produits de la catégorie' : 'Spécifique à ce produit'" data-testid="wizard-studio-source">{{ inheritanceLabel }}</span>
            </div>
            <span
                class="ws-badge"
                :class="{ 'ws-badge--published': isPublished }"
                :title="isPublished ? 'Visible par les clients sur la borne' : 'Brouillon — non visible des clients'"
                data-testid="wizard-studio-status"
            >
                {{ isPublished ? 'Publié — visible clients' : 'Brouillon' }}
            </span>
            <button
                v-if="hasProfile"
                type="button"
                class="ws-pub"
                :class="{ 'ws-pub--unpub': isPublished }"
                :disabled="publishing || savingDraft || _pendingSave"
                :aria-busy="(publishing || savingDraft || _pendingSave) ? 'true' : 'false'"
                :title="(savingDraft || _pendingSave) ? 'Enregistrement en cours…' : ''"
                data-testid="wizard-studio-publish-toggle"
                @click="togglePublish"
            >
                {{ publishing ? '…' : (isPublished ? 'Retirer de la borne' : 'Mettre en ligne') }}
            </button>
        </header>

        <div v-if="loading" class="ws-state" data-testid="wizard-studio-loading">Chargement…</div>
        <div v-else-if="loadError" class="ws-state ws-state--error" data-testid="wizard-studio-error">
            {{ loadError }}
        </div>

        <!-- [W8] category without a wizard yet → create-from-scratch. -->
        <div v-else-if="isCategory && !hasProfile" class="ws-state" data-testid="wizard-studio-create">
            <p>Cette catégorie n'a pas encore d'étapes de composition.</p>
            <button type="button" class="ws-add ws-add--cta" :disabled="creating" data-testid="wizard-studio-create-btn" @click="createStudioProfile">
                {{ creating ? 'Création…' : '+ Créer la composition de cette catégorie' }}
            </button>
            <p v-if="publishError" class="ws-state--error" data-testid="wizard-studio-create-error">{{ publishError }}</p>
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
                        <!-- [VIS-PREVIEW-STABILITY] crossfade between renders instead of a hard remount blink.
                             [A11Y-E1] the preview is the FROZEN kiosk wizard mounted read-only (noop handlers):
                             aria-hidden + inert keep keyboard/SR users out of its dead controls — the section
                             label + caption are the human-readable substitute. Zero frozen-file edit. -->
                        <transition name="ws-preview" mode="out-in">
                            <KioskWizardComponent
                                v-if="draftItem && !previewError"
                                :key="previewNonce"
                                :item="draftItem"
                                :on-add-to-cart="noop"
                                :on-close="noop"
                                aria-hidden="true"
                                inert
                                data-testid="wizard-studio-live-preview"
                            />
                            <div v-else-if="previewError" key="ws-err" class="ws-phone__hint ws-phone__hint--error" role="alert" data-testid="wizard-studio-preview-error">
                                {{ previewError }}
                            </div>
                            <!-- [WS-PREVIEW-EMPTY-BLEED] gate on !previewLoading so the empty hint can't bleed
                                 through the translucent loading overlay during an initial fetch / save refresh. -->
                            <div v-else-if="!previewLoading" key="ws-empty" class="ws-phone__hint" data-testid="wizard-studio-preview-empty">
                                Aucune page configurée — ajoutez une page pour voir l'aperçu.
                            </div>
                        </transition>
                        <!-- loading sits as an overlay so a save-refresh never blanks the frame. -->
                        <div v-if="previewLoading" class="ws-phone__hint ws-phone__hint--overlay" data-testid="wizard-studio-preview-loading">
                            <span class="ws-spinner" aria-hidden="true"></span> Préparation de l'aperçu…
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
                    <span v-else-if="justSaved" class="ws-saved" data-testid="wizard-studio-saved">✓ Enregistré</span>
                </div>
                <!-- [A11Y-04] persistent live region: announces save/publish state to screen readers.
                     [A11Y-E2] a SEPARATE assertive region announces structure changes (reorder/add/delete)
                     so they don't clobber, and aren't clobbered by, the save-state string. -->
                <span class="ws-sr-only" role="status" aria-live="polite">{{ saveAnnounce }}</span>
                <span class="ws-sr-only" role="status" aria-live="assertive">{{ structureAnnounce }}</span>
                <p class="ws-panel__meta">{{ steps.length }} page(s) · v{{ version }} · {{ isPublished ? 'publié' : 'brouillon' }}</p>
                <p class="ws-panel__lede">Chaque page = une étape que le client parcourt sur la borne.</p>

                <p v-if="conflictDetected" class="ws-warn" role="alert" data-testid="wizard-studio-conflict">
                    ⚠ Ce wizard a été modifié ailleurs. <button type="button" class="ws-link" @click="reloadAll">Recharger</button> pour repartir de la dernière version (vos modifications non enregistrées seront écartées).
                </p>
                <p v-if="saveError && !conflictDetected" class="ws-warn" role="alert" data-testid="wizard-studio-save-error">⚠ {{ saveError }} — réessayez votre modification.</p>
                <p v-if="publishError && !conflictDetected" class="ws-warn" role="alert" data-testid="wizard-studio-publish-error">{{ publishError }}</p>
                <p v-if="isPublished && !conflictDetected && !publishError" class="ws-live" data-testid="wizard-studio-live-edit">
                    ⚡ <strong>En ligne</strong> — chaque modification est immédiatement visible des clients sur la borne.
                </p>

                <draggable
                    v-if="steps.length"
                    v-model="steps"
                    item-key="_uid"
                    handle=".ws-step-drag"
                    class="ws-steplist"
                    role="list"
                    :animation="180"
                    ghost-class="ws-steprow--ghost"
                    drag-class="ws-steprow--dragging"
                    @end="onReorder"
                >
                    <div
                        v-for="(s, i) in steps"
                        :key="s._uid"
                        class="ws-steprow"
                        :class="{ 'ws-steprow--hidden': !stepRenders(s) }"
                        role="listitem"
                        :aria-setsize="steps.length"
                        :aria-posinset="i + 1"
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
                            ><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" fill="currentColor"><circle cx="6" cy="4" r="1.25"/><circle cx="10" cy="4" r="1.25"/><circle cx="6" cy="8" r="1.25"/><circle cx="10" cy="8" r="1.25"/><circle cx="6" cy="12" r="1.25"/><circle cx="10" cy="12" r="1.25"/></svg></button>
                            <input
                                class="ws-step-name"
                                :value="s.label"
                                :ref="(el) => setRowRef(s._uid, el)"
                                :aria-label="`Nom de la page ${i + 1}`"
                                :data-testid="`ws-step-name-${i}`"
                                @input="onRename(s, $event.target.value)"
                                @change="saveStudioDraft"
                                @keyup.enter="$event.target.blur()"
                            />
                            <button
                                type="button"
                                class="ws-step-cog"
                                :class="{ 'ws-step-cog--on': expandedUid === s._uid }"
                                :aria-label="`Règles de la page ${i + 1}`"
                                :aria-expanded="expandedUid === s._uid ? 'true' : 'false'"
                                :aria-controls="`ws-rule-panel-${s._uid}`"
                                :data-testid="`ws-step-cog-${i}`"
                                @click="toggleRule(s)"
                            ><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2.5 5h6M11 5h2.5M2.5 11h2.5M7.5 11h6"/><circle cx="9.5" cy="5" r="1.7"/><circle cx="5.5" cy="11" r="1.7"/></svg></button>
                            <button type="button" class="ws-step-del" :aria-label="`Supprimer la page ${i + 1}`" :data-testid="`ws-step-del-${i}`" @click="removePage(s)"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8a1 1 0 0 0 1 .9h3.8a1 1 0 0 0 1-.9l.6-8"/></svg></button>
                        </div>

                        <!-- [VIS-05] rule chip + 0-option flag on their own meta line so the page NAME keeps full width. -->
                        <div class="ws-steprow__meta">
                            <span class="ws-steprow__rule">{{ ruleSummary(s) }}</span>
                            <button v-if="!stepRenders(s)" type="button" class="ws-steplist__tag ws-steplist__tag--btn" :data-testid="`ws-steprow-fix-${i}`" title="Aucune option : choisissez une source pour réparer" @click="toggleRule(s)">Sans option — corriger</button>
                        </div>

                        <!-- [W3] selection-rule editor (+ [W6] source binding); [VIS-MOTION] expand/collapse fade -->
                        <transition name="ws-rule" @after-enter="focusRulePanel">
                        <div v-if="expandedUid === s._uid" class="ws-rule" :id="`ws-rule-panel-${s._uid}`" :data-testid="`ws-rule-${i}`">
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
                            <p class="ws-rule__hint">{{ ruleSummary(s) }}</p>

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
                        </transition>
                    </div>
                </draggable>
                <p v-else class="ws-panel__meta">Aucune page — ajoutez-en une pour commencer.</p>

                <button type="button" class="ws-add" data-testid="wizard-studio-add-page" @click="addPage">+ Ajouter une page</button>

                <p class="ws-panel__note">ℹ️ L'aperçu à gauche est le rendu réel de la borne — une page sans option y est masquée. Glissez pour réordonner, cliquez le nom pour renommer.</p>
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
            saveAnnounce: '', // [A11Y-04] screen-reader announcement for save/publish state
            structureAnnounce: '', // [A11Y-E2] assertive SR announcement for reorder/add/delete
            saveError: '', // [WS-SAVEERR-IN-PREVIEW] panel-side save failure (NOT routed to the preview frame)
            justSaved: false, // [VIS-PREVIEW] brief visible "✓ Enregistré" confirmation for sighted operators
            // [W8] lifecycle state.
            creating: false,
            publishing: false,
            publishError: '',
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
        // [W8] same URL for POST-create (storeForCategory / store).
        createProfileEndpoint() {
            return this.profileEndpoint;
        },
        hasProfile() {
            return !!(this.profile && this.profile.id);
        },
        // Where does the rendered wizard come from? (category-inherited vs item-owned).
        inheritanceLabel() {
            if (!this.profile) return '';
            if (this.profile.item_category_id && !this.profile.item_id) {
                return 'Hérité de la catégorie';
            }
            return 'Propre à ce produit';
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
        // [WS-STEPRENDERS-LABEL-KEY] match by the UNIQUE step_key (label is operator-editable + non-unique →
        // duplicate labels would false-flag a well-configured page as "Sans option"). Labels stay for the banner.
        zeroChoiceStepKeys() {
            const steps = this.draftItem?.composer_profile?.steps;
            if (!Array.isArray(steps)) return [];
            return steps
                .filter((s) => s.is_active !== false && (!Array.isArray(s.choices) || s.choices.length === 0))
                .map((s) => s.step_key);
        },
    },
    async created() {
        await this.load();
    },
    beforeUnmount() {
        // [WS-JUSTSAVED-TIMER-LEAK] don't let the "✓ Enregistré" timer fire on a torn-down instance
        // (operator saves then clicks ‹ Retour within 1.8s).
        clearTimeout(this._justSavedT);
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
                        : (e?.response?.data?.message || 'Impossible de charger la composition.');
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
                    this.loadError = "La composition par produit nécessite l'activation de la fonctionnalité (FEATURE_WIZARD_PER_ITEM_DEMO). Les compositions par catégorie sont disponibles sans activation.";
                }
            } catch (e) {
                this.loadError = e?.response?.data?.message || 'Impossible de charger la composition.';
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
            // [WS-STEPRENDERS-LABEL-KEY] match on step_key (unique), not the editable label.
            return !this.zeroChoiceStepKeys.includes(step.step_key);
        },
        ruleSummary(step) {
            const min = Number(step.min_select || 0);
            // [WS-MAX] use the EFFECTIVE max actually persisted by payloadForStep (Math.max(raw,min)):
            // the backend rejects max<min (ComposerStepService), so "min≥1 + unlimited" is unsupported and
            // is saved as "exactly min". Display the saved truth, not a "sans limite" the borne won't honour.
            const max = Math.max(Number(step.max_select || 0), min);
            if (max === 0) return 'Facultatif · sans limite'; // max===0 ⇒ min===0 (optional, no upper bound)
            if (min === 0 && max === 1) return 'Facultatif · 1 choix max';
            if (min === max) return min === 1 ? 'Obligatoire · 1 choix' : `Obligatoire · ${min} choix`;
            return `De ${min} à ${max} choix`;
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
        // [A11Y-STRUCT-ANNOUNCE-REPEAT] aria-live only re-announces when the text CHANGES, so identical
        // consecutive structure ops (2nd delete, 2nd reorder) would be silent. Clear first, then set.
        announceStructure(msg) {
            this.structureAnnounce = '';
            this.$nextTick(() => { this.structureAnnounce = msg; });
        },
        // [A11Y-E3] track each row's name input so CRUD can move focus deterministically.
        setRowRef(uid, el) {
            (this._rowRefs ||= {});
            if (el) this._rowRefs[uid] = el; else delete this._rowRefs[uid];
        },
        addPage() {
            const n = this.steps.length + 1;
            const uid = `u${++this._uidSeq}`;
            this.steps = [...this.steps, {
                _uid: uid,
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
            // [A11Y-E3] move focus onto the new row's name field + announce it (no silent append).
            this.$nextTick(() => {
                this._rowRefs?.[uid]?.focus?.();
                this.announceStructure('Page ajoutée — saisissez son nom.');
            });
        },
        async removePage(step) {
            // [A11Y-E3] capture the neighbour BEFORE removal so focus doesn't fall to <body>.
            const i = this.steps.findIndex((s) => s._uid === step._uid);
            const neighbour = this.steps[i + 1] || this.steps[i - 1] || null;
            this.steps = this.steps.filter((s) => s._uid !== step._uid);
            await this.saveStudioDraft();
            this.$nextTick(() => {
                const target = (neighbour && this._rowRefs?.[neighbour._uid])
                    || this.$el?.querySelector?.('[data-testid="wizard-studio-add-page"]');
                target?.focus?.();
                this.announceStructure('Page supprimée.');
            });
        },
        onReorder() {
            // vue-draggable-next already mutated this.steps order; persist new positions.
            this.saveStudioDraft();
            this.announceStructure('Ordre des pages mis à jour.');
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
            // [A11Y-E2] announce the outcome (where it landed) — the visual move is invisible to SR.
            this.announceStructure(`Page « ${step.label || i + 1} » déplacée en position ${j + 1} sur ${this.steps.length}.`);
            // keep the handle focused so the operator can keep arrowing.
            this.$nextTick(() => this.$el?.querySelector?.(`[data-testid="ws-step-drag-${j}"]`)?.focus?.());
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
            this.saveError = '';
            this.saveAnnounce = 'Enregistrement…'; // [A11Y-04]
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
                    // Adopt the bumped version so the trailing save chains the optimistic lock.
                    this.profile = updated;
                    // [STATE-01] A queued edit changed this.steps AFTER this PUT was snapshotted.
                    // Re-hydrating from this (now-stale) echo would silently DROP that edit — the
                    // trailing _pendingSave flush must persist the operator's pending steps, not the
                    // server copy of the previous save. Keep local steps when a save is queued.
                    if (!this._pendingSave) {
                        this.steps = this.hydrateSteps(updated);
                    }
                }
                if (!this._pendingSave) {
                    await this.reloadPreview(); // refresh the live borne render (the queued flush will reload)
                    this.saveAnnounce = 'Modifications enregistrées'; // [A11Y-04]
                    // [VIS-PREVIEW] brief visible "✓ Enregistré" so sighted operators get positive confirmation.
                    this.justSaved = true;
                    clearTimeout(this._justSavedT);
                    this._justSavedT = setTimeout(() => { this.justSaved = false; }, 1800);
                }
            } catch (e) {
                if (e?.response?.status === 409) {
                    this.conflictDetected = true; // optimistic-lock clash → operator reloads
                    this.saveAnnounce = 'Conflit : ce wizard a été modifié ailleurs'; // [A11Y-04]
                    return;
                }
                // [WS-SAVEERR-IN-PREVIEW] Non-409 failure (500/network/422): surface on the PANEL side
                // (saveError), NOT previewError — routing it to previewError would unmount the still-valid
                // live preview and show the save error inside the device frame. The next edit safely
                // RE-ATTEMPTS the save (the server's version lock + validation keep retries safe).
                this.saveError = e?.response?.data?.message || 'Enregistrement impossible.';
                this.saveAnnounce = 'Échec de l’enregistrement — réessayez'; // [A11Y-04]
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
            this.previewError = '';
            this.saveError = '';
            await this.load();
        },

        // ---- [W8] lifecycle: create from scratch + publish/unpublish ----
        async createStudioProfile() {
            if (this.creating || this.hasProfile) return;
            this.creating = true;
            this.publishError = '';
            try {
                const res = await axios.post(this.createProfileEndpoint, {
                    template: 'custom',
                    branch_id_scope: null,
                    steps: [],
                });
                this.profile = res?.data?.data ?? res?.data ?? null;
                this.steps = this.hydrateSteps(this.profile);
                if (this.profile?.id) {
                    if (this.isCategory) await this.fetchSources();
                    await this.fetchPreview();
                }
            } catch (e) {
                this.publishError = e?.response?.data?.message || 'Création impossible.';
            } finally {
                this.creating = false;
            }
        },
        async togglePublish() {
            // Block while a draft save is in flight OR queued: publish reads this.version, which the
            // in-flight/trailing save bumps on success → a concurrent publish would 409 on a stale
            // version. [STATE-04] _pendingSave covers the microtask window after savingDraft clears
            // but before the queued flush re-sets it.
            if (!this.profile?.id || this.publishing || this.savingDraft || this._pendingSave) return;
            this.publishing = true;
            this.publishError = '';
            const action = this.isPublished ? 'unpublish' : 'publish';
            try {
                const res = await axios.post(`admin/composer/profiles/${this.profile.id}/${action}`, { version: this.version });
                this.profile = res?.data?.data ?? res?.data ?? this.profile;
                await this.reloadPreview();
            } catch (e) {
                const status = e?.response?.status;
                if (status === 409) {
                    // [WS-PUBLISH-409] optimistic-lock clash (another tab/session bumped the version).
                    // Route to the same 'Recharger' recovery as the save path, else this.version stays
                    // stale and every retry re-409s forever. reloadAll re-fetches the fresh profile+version.
                    this.conflictDetected = true;
                    this.saveAnnounce = 'Conflit : ce wizard a été modifié ailleurs';
                } else if (status === 422) {
                    // assertPublishable: empty/invalid wizard cannot go live.
                    const errs = e.response.data?.errors;
                    const first = errs ? Object.values(errs)[0] : null;
                    const msg = Array.isArray(first) ? first[0] : (typeof first === 'string' ? first : null);
                    this.publishError = msg || e.response.data?.message
                        || "Impossible de publier : une page n'a pas d'option, ou une page obligatoire est vide.";
                } else if (status === 403) {
                    this.publishError = "Vous n'avez pas la permission de mettre en ligne / retirer cette composition.";
                } else {
                    this.publishError = e?.response?.data?.message || 'Action impossible.';
                }
            } finally {
                this.publishing = false;
            }
        },

        // ---- [W3] selection rules (single/multi · required · min/max · repeat) ----
        isMulti(step) {
            return Number(step.max_select || 0) !== 1;
        },
        toggleRule(step) {
            this.expandedUid = this.expandedUid === step._uid ? null : step._uid;
        },
        // [A11Y-E4] move focus into the rule panel only AFTER its enter-transition completes (it isn't
        // reliably focusable mid-enter from $nextTick/rAF). The <transition> @after-enter hook passes the
        // entered panel element, so focusing its first control here lands every time.
        focusRulePanel(el) {
            el?.querySelector?.('select, input, button')?.focus?.();
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
.ws-source { font-size: 12px; color: #6b6b6b; margin-top: 2px; } /* [IA-06] terse provenance token */
.ws-source:empty { display: none; } /* no profile (create state) → no stray breadcrumb */
.ws-source:not(:empty)::before { content: '↳ '; color: #A8370E; }
.ws-badge { margin-left: auto; padding: 5px 12px; border-radius: 999px; background: #ececec; color: #555; font-size: 12px; font-weight: 600; }
.ws-badge--published { background: #e6f6ec; color: #0f6e38; } /* AA ≥4.5:1 on the light-green pill */
.ws-pub { border: 0; border-radius: 999px; padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer; background: #137a40; color: #fff; }
.ws-pub--unpub { background: #fff; color: #b02a1a; border: 1px solid #e7c3bd; }
.ws-pub:disabled { opacity: .6; cursor: default; }
.ws-pub:focus-visible { outline: 2px solid #F4501E; outline-offset: 2px; }
.ws-add--cta { max-width: 360px; margin: 14px auto 0; }
.ws-state { padding: 40px; text-align: center; color: #555; }
.ws-state--error { color: #b02a1a; }
.wizard-studio__body { display: grid; grid-template-columns: minmax(0, 520px) 380px; justify-content: center; gap: 24px; padding: 24px; align-items: start; }
/* [VIS-01] cap the preview track + sticky-center the device on a deliberate "lit canvas" so the
   frame reads as intentional, not floating in dead beige. */
.ws-stage { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; position: sticky; top: 24px; align-self: start; min-height: calc(100vh - 150px); padding: 28px; border-radius: 20px; background: radial-gradient(120% 120% at 50% 0%, #fffaf4 0%, #f1e8da 100%); box-shadow: inset 0 1px 0 rgba(255,255,255,.7), 0 2px 14px rgba(20,20,20,.05); border: 1px solid #efe6d8; }
.ws-warn { width: 100%; max-width: 420px; margin: 0; padding: 10px 14px; border-radius: 10px; background: #fff4e8; border: 1px solid #f6c89a; color: #9a4a08; font-size: 13px; line-height: 1.35; }
/* Realistic portrait device: dark shell + 9:16 screen the kiosk render is bounded to.
   transform establishes a containing block so the frozen wizard's position:fixed abandon
   overlay (z-index 120) is bounded to the device frame instead of covering the admin page (F7). */
/* [VIS-03] premium device: graphite bezel + highlight rim + faint brand ambient glow on a stand. */
.ws-phone { position: relative; width: 420px; background: linear-gradient(150deg, #2a2a2a 0%, #141414 60%); border-radius: 42px; padding: 16px; box-shadow: 0 24px 60px rgba(20,20,20,.28), 0 0 0 1px rgba(255,255,255,.06) inset, 0 -2px 0 rgba(255,255,255,.04) inset; transform: translateZ(0); }
.ws-phone::after { content: ''; position: absolute; inset: -28px; border-radius: 60px; background: radial-gradient(60% 50% at 50% 38%, rgba(244,80,30,.10), transparent 70%); z-index: -1; }
.ws-phone__notch { position: absolute; top: 16px; left: 50%; transform: translateX(-50%); width: 124px; height: 18px; background: #141414; border-radius: 0 0 14px 14px; z-index: 2; }
.ws-phone__screen { position: relative; width: 388px; height: 690px; overflow: auto; background: #fff; border-radius: 30px; box-shadow: 0 0 0 2px #000 inset; -webkit-overflow-scrolling: touch; }
.ws-phone__hint--overlay { position: absolute; inset: 0; background: rgba(255,255,255,.82); }
/* The FROZEN kiosk wizard renders at 100vw (fullscreen borne). Inside the device frame we
   override it to a realistic kiosk-portrait width and scale it down with `zoom` so the
   operator sees the true borne proportions, fit-to-frame, instead of a clipped desktop slice. */
/* Override BOTH frozen viewport dims: width:100vw → 724px AND height:100vh → 1288px, so
   zoom:0.5 maps to exactly the 362×644 frame regardless of admin viewport size. Without the
   height override the wizard stays 100vh (viewport-dependent) and its sticky footer/CTA pins
   off-frame (F1). With it, the kiosk scrolls its own step-content and pins the footer in-frame. */
.ws-phone__screen :deep(.kiosk-wizard) { width: 776px !important; min-width: 776px; height: 1380px !important; max-height: 1380px !important; zoom: 0.5; } /* 776×.5=388, 1380×.5=690 → fits the screen 9:16 */
.ws-phone__hint { display: flex; align-items: center; justify-content: center; gap: 8px; height: 100%; min-height: 200px; text-align: center; color: #6b6b6b; font-size: 13px; padding: 24px; } /* [A11Y-03] ≥4.5:1 AA */
.ws-phone__hint--error { color: #b02a1a; }
.ws-spinner { width: 16px; height: 16px; border: 2px solid #f0d9cf; border-top-color: #F4501E; border-radius: 50%; display: inline-block; animation: ws-spin 0.8s linear infinite; }
@keyframes ws-spin { to { transform: rotate(360deg); } }
.ws-stage__caption { margin: 0; font-size: 12px; color: #6b6b6b; } /* WCAG AA ≥4.5:1 on #faf7f2 */
.ws-panel { background: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(20,20,20,.06); position: sticky; top: 24px; }
.ws-panel__title { margin: 0 0 6px; font-size: 16px; }
.ws-panel__meta { color: #555; font-size: 13px; margin: 0 0 4px; }
.ws-panel__lede { margin: 0 0 12px; font-size: 13px; color: #1A1A1A; font-weight: 600; } /* [IA-01] state the mental model once */
.ws-panel__note { color: #6b6b6b; font-size: 12px; line-height: 1.4; margin-top: 12px; } /* [A11Y-03] ≥4.5:1 AA */
.ws-panel__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.ws-saving { font-size: 11px; color: #0f6e38; }
.ws-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; } /* [A11Y-04] visually-hidden persistent live region */
.ws-link { border: 0; background: transparent; color: #A8370E; text-decoration: underline; cursor: pointer; font: inherit; padding: 0; }
.ws-steplist { margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 10px; }
/* [W2] editable page row — [VIS-05] rhythm + hover, [VIS-MOTION] reorder tween */
.ws-steprow { display: flex; flex-direction: column; padding: 10px 12px; border: 1px solid #eee6d9; border-radius: 12px; background: #fff; transition: border-color .12s ease, box-shadow .12s ease, transform .18s ease; }
.ws-steprow:hover { border-color: #e3d5c2; box-shadow: 0 2px 8px rgba(20,20,20,.05); }
.ws-steprow__head { display: flex; align-items: center; gap: 8px; }
.ws-steprow__meta { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin: 6px 0 0 36px; } /* [VIS-05] aligns under the name, past the 28px drag handle */
/* [VIS-07] drag ghost lifts instead of just fading */
.ws-steprow--ghost { opacity: .5; background: #fff8f1; border-style: dashed; }
.ws-steprow--dragging { box-shadow: 0 8px 24px rgba(20,20,20,.18); transform: scale(1.02); }
/* [VIS-04] 0-option page = the customer literally cannot proceed → brand-yellow rail + warm bg (not color-only). */
.ws-steprow--hidden { background: #fffaf0; border-color: #f6c89a; box-shadow: inset 3px 0 0 #FFB800; }
.ws-step-cog { border: 0; background: transparent; cursor: pointer; font-size: 14px; border-radius: 8px; color: #66756e; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; transition: background .12s ease, transform .08s ease; }
.ws-step-cog:hover { background: #fff1e4; }
.ws-step-cog--on { background: #fff1e4; color: #A8370E; }
.ws-step-cog:focus-visible { outline: 2px solid #F4501E; outline-offset: 1px; }
/* [W3] rule editor — [VIS-05] contained nested surface */
.ws-rule { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 10px; padding: 12px; border: 1px solid #efe6d8; border-radius: 10px; background: #faf7f2; }
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
.ws-options__empty { margin: 4px 0 0; font-size: 11px; color: #6b6b6b; } /* [A11Y-03] ≥4.5:1 AA */
.ws-options__list { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.ws-option { display: flex; align-items: center; gap: 6px; padding: 3px 8px 3px 3px; border: 1px solid #e6ddd0; border-radius: 999px; background: #fff; font-size: 12px; color: #333; }
.ws-option--off { opacity: .55; }
.ws-option__img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; background: #f1ece4; }
.ws-option__img--ph { display: inline-block; }
.ws-option__name { line-height: 1; }
.ws-option__off { font-size: 9px; color: #b02a1a; background: #fdecea; padding: 1px 5px; border-radius: 6px; }
.ws-live { margin: 0 0 10px; padding: 8px 12px; border-radius: 10px; background: #fff4e8; border: 1px solid #f6c89a; color: #9a4a08; font-size: 12px; line-height: 1.35; }
/* [A11Y-02] ≥3:1 on #fff (WCAG 1.4.11); [VIS-02/07] balanced hit-target + hover/active parity */
.ws-step-drag { border: 0; background: transparent; cursor: grab; color: #6b756e; line-height: 1; border-radius: 8px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; transition: background .12s ease, transform .08s ease; }
.ws-step-drag:hover { background: #f4efe7; }
.ws-step-drag:focus-visible { outline: 2px solid #F4501E; outline-offset: 1px; }
.ws-step-name { flex: 1; min-width: 0; border: 1px solid transparent; border-radius: 8px; padding: 4px 6px; font-size: 15px; font-weight: 600; color: #222; background: transparent; }
.ws-step-name:hover { border-color: #e3ddd2; }
.ws-step-name:focus { border-color: #F4501E; outline: none; background: #fff; }
/* [VIS-05] rule summary as a quiet pill, anchored on "choix" (IA-02) */
.ws-steprow__rule { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 999px; background: #fdf2ea; color: #A8370E; font-size: 11px; font-weight: 600; white-space: nowrap; }
.ws-step-del { border: 0; background: transparent; cursor: pointer; border-radius: 8px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; color: #8a7f73; transition: background .12s ease, color .12s ease, transform .08s ease; }
.ws-step-del:hover { background: #fdecea; color: #b02a1a; }
.ws-step-del:focus-visible { outline: 2px solid #b02a1a; outline-offset: 1px; }
.ws-add { width: 100%; border: 1px dashed #d9c9bb; background: #fff8f1; color: #A8370E; border-radius: 12px; padding: 11px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background .12s ease; }
.ws-add:hover { background: #fff1e4; }
.ws-add:focus-visible { outline: 2px solid #F4501E; outline-offset: 2px; }
/* [VIS-04] 0-option flag promoted to brand yellow + actionable (click → open the rule editor to fix). */
.ws-steplist__tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #FFB800; color: #1A1A1A; font-size: 11px; font-weight: 700; white-space: nowrap; }
.ws-steplist__tag--btn { border: 0; cursor: pointer; font-family: inherit; }
.ws-steplist__tag--btn:hover { background: #f0ab00; }
.ws-steplist__tag--btn:focus-visible { outline: 2px solid #1A1A1A; outline-offset: 1px; }
/* [VIS-02] inline-SVG icons render pixel-identically + inherit color; kill baseline gap. */
.ws-step-drag svg, .ws-step-cog svg, .ws-step-del svg { display: block; flex: none; }
/* [VIS-07] press feedback + [VIS-PREVIEW] visible save confirmation */
.ws-pub:active:not(:disabled), .ws-add:active { transform: translateY(1px); }
.ws-step-cog:active, .ws-step-del:active, .ws-step-drag:active { transform: scale(.92); }
.ws-saved { font-size: 11px; color: #0f6e38; font-weight: 600; }
/* [VIS-PREVIEW-STABILITY] crossfade the preview instead of a hard remount blink; [VIS-MOTION] rule expand */
.ws-preview-enter-active, .ws-preview-leave-active { transition: opacity .18s ease; }
.ws-preview-enter-from, .ws-preview-leave-to { opacity: 0; }
.ws-rule-enter-active, .ws-rule-leave-active { transition: opacity .16s ease, transform .16s ease; }
.ws-rule-enter-from, .ws-rule-leave-to { opacity: 0; transform: translateY(-4px); }
/* [VIS-MOTION] WCAG 2.3.3 — one guard disables every animation the component ships. */
@media (prefers-reduced-motion: reduce) {
    .ws-spinner { animation: none; border-top-color: #F4501E; }
    .ws-preview-enter-active, .ws-preview-leave-active, .ws-rule-enter-active, .ws-rule-leave-active,
    .ws-steprow, .ws-step-cog, .ws-step-del, .ws-step-drag, .ws-pub, .ws-add, .ws-saved { transition: none !important; animation: none !important; }
}
@media (max-width: 1024px) {
    .wizard-studio__body { grid-template-columns: 1fr; justify-content: stretch; }
    /* edit list first on narrow screens; the preview follows as a compact, non-sticky stage. */
    .ws-panel { order: -1; position: static; }
    .ws-stage { position: static; min-height: 0; padding: 16px; }
    .ws-phone { width: 300px; }
    .ws-phone__screen { width: 268px; height: 477px; }
    .ws-phone__screen :deep(.kiosk-wizard) { width: 776px !important; min-width: 776px; height: 1380px !important; max-height: 1380px !important; zoom: 0.3454; }
}
</style>
