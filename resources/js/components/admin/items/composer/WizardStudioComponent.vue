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
            </div>
            <span class="ws-badge" :class="{ 'ws-badge--published': isPublished }">
                {{ isPublished ? 'Publié' : 'Brouillon' }}
            </span>
        </header>

        <div v-if="loading" class="ws-state" data-testid="wizard-studio-loading">Chargement…</div>
        <div v-else-if="loadError" class="ws-state ws-state--error" data-testid="wizard-studio-error">
            {{ loadError }}
        </div>

        <div v-else class="wizard-studio__body">
            <!-- Vertical portrait preview (W1 will replace this placeholder with the
                 frozen-faithful kiosk render). -->
            <section class="ws-stage" aria-label="Aperçu borne (vertical)">
                <div class="ws-phone" data-testid="wizard-studio-preview">
                    <p class="ws-phone__hint">Aperçu vertical de la borne (W1)</p>
                    <ol class="ws-steps">
                        <li v-for="step in steps" :key="step.id || step.step_key" class="ws-step">
                            <span class="ws-step__label">{{ step.label }}</span>
                            <span class="ws-step__rule">{{ ruleSummary(step) }}</span>
                        </li>
                        <li v-if="!steps.length" class="ws-step ws-step--empty">Aucune page configurée</li>
                    </ol>
                </div>
            </section>

            <!-- Contextual settings panel (right) — populated in later waves. -->
            <aside class="ws-panel" aria-label="Réglages">
                <h2 class="ws-panel__title">Réglages</h2>
                <p class="ws-panel__meta">{{ steps.length }} page(s) · v{{ version }}</p>
                <p class="ws-panel__note">Édition visuelle des pages, options, images et règles : vagues W2→W6.</p>
            </aside>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'WizardStudioComponent',
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
            } catch (e) {
                this.loadError = e?.response?.data?.message || 'Impossible de charger le wizard.';
            } finally {
                this.loading = false;
            }
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
            this.$router.push({ name: this.isCategory ? 'admin.items.studio' : 'admin.items.studio' });
        },
    },
};
</script>

<style scoped>
.wizard-studio { display: flex; flex-direction: column; min-height: 100%; background: #faf7f2; }
.wizard-studio__bar { display: flex; align-items: center; gap: 16px; padding: 12px 20px; background: #fff; border-bottom: 2px solid #F4501E; }
.ws-back { border: 0; background: transparent; font-size: 15px; cursor: pointer; color: #444; }
.ws-title { display: flex; flex-direction: column; line-height: 1.2; }
.ws-eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #F4501E; }
.ws-badge { margin-left: auto; padding: 4px 12px; border-radius: 999px; background: #eee; font-size: 12px; }
.ws-badge--published { background: #e6f6ec; color: #1b8a4b; }
.ws-state { padding: 40px; text-align: center; color: #666; }
.ws-state--error { color: #c0392b; }
.wizard-studio__body { display: grid; grid-template-columns: 1fr 360px; gap: 24px; padding: 24px; align-items: start; }
.ws-stage { display: flex; justify-content: center; }
.ws-phone { width: 380px; min-height: 680px; background: #fff; border-radius: 28px; box-shadow: 0 10px 40px rgba(20,20,20,.12); padding: 20px; }
.ws-phone__hint { text-align: center; color: #aaa; font-size: 12px; }
.ws-steps { list-style: none; margin: 16px 0 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.ws-step { display: flex; justify-content: space-between; padding: 12px 14px; border: 1px solid #eee6d9; border-radius: 12px; }
.ws-step--empty { justify-content: center; color: #bbb; }
.ws-step__rule { color: #F4501E; font-size: 12px; }
.ws-panel { background: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(20,20,20,.06); }
.ws-panel__title { margin: 0 0 6px; font-size: 16px; }
.ws-panel__meta { color: #666; font-size: 13px; margin: 0 0 12px; }
.ws-panel__note { color: #999; font-size: 12px; }
</style>
