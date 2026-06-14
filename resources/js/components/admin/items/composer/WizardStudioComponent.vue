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
                    Corrigez la source de ces pages (W2).
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

            <!-- Read-only page inspector. Visual EDITING (add/reorder/images/rules) lands in W2→W6. -->
            <aside class="ws-panel" aria-label="Pages du wizard">
                <h2 class="ws-panel__title">Pages du wizard</h2>
                <p class="ws-panel__meta">{{ steps.length }} page(s) · v{{ version }} · {{ isPublished ? 'publié' : 'brouillon' }}</p>
                <ol v-if="steps.length" class="ws-steplist">
                    <li v-for="s in steps" :key="s.id || s.step_key" class="ws-steplist__item">
                        <span class="ws-steplist__label">{{ s.label }}</span>
                        <span class="ws-steplist__rule">{{ ruleSummary(s) }}</span>
                    </li>
                </ol>
                <p v-else class="ws-panel__meta">Aucune page pour le moment.</p>
                <p class="ws-panel__note">✏️ Édition visuelle (ajouter / réordonner les pages, options, images, règles) : à venir en W2→W6. Cet écran est pour l'instant un aperçu fidèle en lecture seule.</p>
            </aside>
        </div>
    </div>
</template>

<script>
import { defineAsyncComponent } from 'vue';
import axios from 'axios';

export default {
    name: 'WizardStudioComponent',
    components: {
        // The FROZEN kiosk wizard, mounted UNCHANGED + read-only. Lazy (defineAsyncComponent,
        // Vue 3) so it shares the existing kiosk chunk and only loads when the Studio opens.
        KioskWizardComponent: defineAsyncComponent(() => import(/* webpackChunkName: "kiosk-wizard" */ '../../../frontend/kiosk/KioskWizardComponent.vue')),
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
                const [entityRes, profileRes] = await Promise.all([
                    axios.get(entityUrl),
                    axios.get(this.profileEndpoint).catch((e) => (e.response && e.response.status === 404 ? { data: null } : Promise.reject(e))),
                ]);
                this.entityName = entityRes?.data?.data?.name ?? entityRes?.data?.name ?? '';
                const profile = profileRes?.data?.data ?? profileRes?.data ?? null;
                this.profile = profile;
                this.steps = Array.isArray(profile?.steps) ? [...profile.steps].sort((a, b) => (a.position || 0) - (b.position || 0)) : [];
                if (this.profile?.id) {
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
    },
};
</script>

<style scoped>
.wizard-studio { display: flex; flex-direction: column; min-height: 100%; background: #faf7f2; }
.wizard-studio__bar { display: flex; align-items: center; gap: 16px; padding: 12px 20px; background: #fff; border-bottom: 2px solid #F4501E; }
.ws-back { border: 0; background: transparent; font-size: 15px; cursor: pointer; color: #333; border-radius: 6px; padding: 4px 8px; }
.ws-back:focus-visible { outline: 2px solid #F4501E; outline-offset: 2px; }
.ws-title { display: flex; flex-direction: column; line-height: 1.25; }
.ws-eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #F4501E; }
.ws-source { font-size: 12px; color: #6b6b6b; margin-top: 2px; }
.ws-badge { margin-left: auto; padding: 5px 12px; border-radius: 999px; background: #ececec; color: #555; font-size: 12px; font-weight: 600; }
.ws-badge--published { background: #e6f6ec; color: #137a40; }
.ws-state { padding: 40px; text-align: center; color: #555; }
.ws-state--error { color: #b02a1a; }
.wizard-studio__body { display: grid; grid-template-columns: 1fr 360px; gap: 24px; padding: 24px; align-items: start; }
.ws-stage { display: flex; flex-direction: column; align-items: center; gap: 12px; }
.ws-warn { width: 100%; max-width: 420px; margin: 0; padding: 10px 14px; border-radius: 10px; background: #fff4e8; border: 1px solid #f6c89a; color: #9a4a08; font-size: 13px; line-height: 1.35; }
/* Realistic portrait device: dark shell + 9:16 screen the kiosk render is bounded to. */
.ws-phone { position: relative; width: 390px; background: #1a1a1a; border-radius: 36px; padding: 14px; box-shadow: 0 16px 48px rgba(20,20,20,.22); }
.ws-phone__notch { position: absolute; top: 14px; left: 50%; transform: translateX(-50%); width: 120px; height: 18px; background: #1a1a1a; border-radius: 0 0 14px 14px; z-index: 2; }
.ws-phone__screen { aspect-ratio: 9 / 16; width: 100%; overflow: auto; background: #fff; border-radius: 24px; -webkit-overflow-scrolling: touch; }
.ws-phone__hint { display: flex; align-items: center; justify-content: center; gap: 8px; height: 100%; min-height: 200px; text-align: center; color: #777; font-size: 13px; padding: 24px; }
.ws-phone__hint--error { color: #b02a1a; }
.ws-spinner { width: 16px; height: 16px; border: 2px solid #f0d9cf; border-top-color: #F4501E; border-radius: 50%; display: inline-block; animation: ws-spin 0.8s linear infinite; }
@keyframes ws-spin { to { transform: rotate(360deg); } }
.ws-stage__caption { margin: 0; font-size: 12px; color: #8a8a8a; }
.ws-panel { background: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(20,20,20,.06); position: sticky; top: 24px; }
.ws-panel__title { margin: 0 0 6px; font-size: 16px; }
.ws-panel__meta { color: #555; font-size: 13px; margin: 0 0 12px; }
.ws-panel__note { color: #777; font-size: 12px; line-height: 1.4; margin-top: 12px; }
.ws-steplist { list-style: decimal; margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px; }
.ws-steplist__item { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.ws-steplist__label { color: #222; font-size: 14px; }
.ws-steplist__rule { color: #F4501E; font-size: 11px; white-space: nowrap; }
@media (max-width: 1024px) {
    .wizard-studio__body { grid-template-columns: 1fr; }
    .ws-panel { order: -1; position: static; }
}
</style>
