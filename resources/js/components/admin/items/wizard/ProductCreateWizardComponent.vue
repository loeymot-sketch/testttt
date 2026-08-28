<template>
    <!--
        ProductCreateWizardComponent — Mission #2 Vague 2 action 2.9.

        Single guided multi-step UI that orchestrates the existing 9 admin
        endpoints needed to create a complex composed product (e.g. a tacos
        with 4 viandes, 6 sauces, 5 crudités, 3 cheeses, photo, composer
        profile). Replaces the morcelé navigation flow described in
        Mission #2 §B and the 9-step friction atlas.

        IMPORTANT: this wizard does NOT introduce a new backend transaction.
        Each step calls the SAME endpoint as the legacy admin screens; the
        wizard merely choreographs them and saves a draft to localStorage so
        nothing is lost if the admin gets interrupted.

        Audit  : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #1 + §B
        Plan   : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 2.9
        Status : SKELETON — implementation TODO Codex.
    -->
    <section
        class="rounded border border-slate-200 bg-white"
        data-testid="product-create-wizard"
        :aria-busy="loading"
    >
        <header class="border-b border-slate-200 p-4">
            <h2 class="text-lg font-semibold text-slate-800">
                {{ $t('admin.product_wizard.title') }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ $t('admin.product_wizard.subtitle') }}
            </p>
        </header>

        <nav
            class="flex flex-wrap gap-1 border-b border-slate-200 p-2"
            aria-label="Wizard steps"
            data-testid="product-create-wizard-stepper"
        >
            <button
                v-for="(step, idx) in steps"
                :key="step.key"
                type="button"
                class="db-btn db-btn-ghost text-sm"
                :class="stepClass(idx)"
                :aria-current="idx === currentStep ? 'step' : null"
                :disabled="!isStepReachable(idx)"
                :data-testid="`wizard-step-tab-${step.key}`"
                @click="goToStep(idx)"
            >
                <span
                    class="mr-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-xs"
                    :class="stepNumberClass(idx)"
                    aria-hidden="true"
                >
                    {{ idx + 1 }}
                </span>
                {{ $t(`admin.product_wizard.steps.${step.key}`) }}
                <span v-if="step.optional" class="ml-1 text-xs text-slate-400">
                    {{ $t('label.optional') }}
                </span>
            </button>
        </nav>

        <div class="p-4">
            <component
                :is="currentStepComponent"
                v-bind="currentStepProps"
                :draft="draft"
                @persist-draft="persistDraft"
                @next="goNext"
                @prev="goPrev"
            />
        </div>

        <footer class="flex items-center justify-between border-t border-slate-200 p-4">
            <button
                type="button"
                class="db-btn db-btn-secondary"
                :disabled="currentStep === 0 || loading"
                data-testid="wizard-prev"
                @click="goPrev"
            >
                <i class="lab lab-back-bold" aria-hidden="true"></i>
                {{ $t('label.previous') }}
            </button>

            <p class="text-xs text-slate-500" aria-live="polite" data-testid="wizard-progress">
                {{ $t('admin.product_wizard.progress', { current: currentStep + 1, total: steps.length }) }}
            </p>

            <button
                v-if="currentStep < steps.length - 1"
                type="button"
                class="db-btn db-btn-primary"
                :disabled="!canAdvance || loading"
                data-testid="wizard-next"
                @click="goNext"
            >
                {{ $t('label.next') }}
                <i class="lab lab-arrow-right" aria-hidden="true"></i>
            </button>
            <button
                v-else
                type="button"
                class="db-btn bg-emerald-600 text-white"
                :disabled="loading"
                data-testid="wizard-finish"
                @click="finishWizard"
            >
                <i class="lab lab-tick-circle" aria-hidden="true"></i>
                {{ $t('admin.product_wizard.finish') }}
            </button>
        </footer>
    </section>
</template>

<script>
/**
 * ProductCreateWizardComponent — orchestrates 9 admin endpoints.
 *
 * Each WizardStep* sub-component is a thin wrapper around the existing
 * admin form (ItemCreateComponent, ItemAttributeCreateComponent, etc.)
 * with two contracts:
 *
 *   - emits 'persist-draft' (payload: object) to update wizard state.
 *   - emits 'next' / 'prev' to navigate, only when the user has met the
 *     local step requirements (does NOT validate backend; backend
 *     validation still runs on submit, see Section §B in the audit).
 *
 * Steps (mapping audit §B atlas):
 *   1. category          → POST /api/admin/item-category   (existing endpoint)
 *   2. base_item         → POST /api/admin/items
 *   3. photo             → POST /api/admin/items/{id}/image
 *   4. attributes        → POST /api/admin/item-attribute
 *   5. variations        → POST /api/admin/item-variation
 *   6. extras            → POST /api/admin/item-extra
 *   7. addons            → POST /api/admin/item-addon
 *   8. composer_publish  → POST /api/admin/composer-profile + /publish
 *   9. preview_validate  → consumes ItemPreviewComponent for cross-surface check
 *
 * Draft persistence:
 *   localStorage key `admin.product_wizard.draft.${tenantId}` — capped 24h
 *   TTL with timestamp; cleared on successful finish.
 *
 * TODO Codex (plan task 2.9):
 *   1. Implement each WizardStepCategory, WizardStepBaseItem, ... as Vue
 *      components in resources/js/components/admin/items/wizard/steps/
 *      that re-use existing form fragments instead of duplicating logic.
 *   2. Implement persistDraft(payload) merging into this.draft +
 *      writing to localStorage.
 *   3. Implement isStepReachable(idx) gating: a step is reachable iff every
 *      required upstream step has draft.<key>.completed === true.
 *   4. Implement finishWizard() flow:
 *        a) replay any pending step that did not actually POST (e.g. user
 *           filled the variation form but never clicked save) — fail-safe.
 *        b) on success, clear draft, emit('finished', {item_id}).
 *        c) on first-error, stay on the failing step, surface backend
 *           validation errors via the existing form error mechanism.
 *   5. A11y:
 *        - aria-current="step" on the active stepper button
 *        - aria-live="polite" on progress indicator
 *        - announce step transitions via the kiosk announcer pattern
 *   6. i18n keys (fr/en/ar):
 *        admin.product_wizard.title|subtitle|finish|progress
 *        admin.product_wizard.steps.{category,base_item,photo,attributes,
 *           variations,extras,addons,composer_publish,preview_validate}
 *   7. Sentinel: tests/js/productCreateWizardE2E.spec.js +
 *      tests/e2e/product-create-wizard.spec.ts (Playwright happy path).
 */
const WIZARD_STEPS = [
    { key: 'category', component: 'WizardStepCategory', optional: false },
    { key: 'base_item', component: 'WizardStepBaseItem', optional: false },
    { key: 'photo', component: 'WizardStepPhoto', optional: true },
    { key: 'attributes', component: 'WizardStepAttributes', optional: true },
    { key: 'variations', component: 'WizardStepVariations', optional: true },
    { key: 'extras', component: 'WizardStepExtras', optional: true },
    { key: 'addons', component: 'WizardStepAddons', optional: true },
    { key: 'composer_publish', component: 'WizardStepComposerPublish', optional: true },
    { key: 'preview_validate', component: 'WizardStepPreviewValidate', optional: false },
];

export default {
    name: 'ProductCreateWizardComponent',
    props: {
        tenantId: { type: [String, Number], required: true },
    },
    data() {
        return {
            steps: WIZARD_STEPS,
            currentStep: 0,
            loading: false,
            draft: {},
        };
    },
    computed: {
        currentStepComponent() {
            return this.steps[this.currentStep]?.component;
        },
        currentStepProps() {
            return {};
        },
        canAdvance() {
            const step = this.steps[this.currentStep];
            if (!step) return false;
            if (step.optional) return true;
            return Boolean(this.draft[step.key]?.completed);
        },
    },
    mounted() {
        this.loadDraft();
    },
    methods: {
        stepClass(idx) {
            if (idx === this.currentStep) return 'bg-primary text-white';
            if (this.draft[this.steps[idx].key]?.completed) return 'bg-emerald-50 text-emerald-700';
            return 'text-slate-600';
        },
        stepNumberClass(idx) {
            if (idx === this.currentStep) return 'bg-white text-primary';
            if (this.draft[this.steps[idx].key]?.completed) return 'bg-emerald-100 text-emerald-700';
            return 'bg-slate-100 text-slate-500';
        },
        isStepReachable(_idx) {
            // STUB — Codex: enforce sequential gating.
            return true;
        },
        goToStep(idx) {
            if (this.isStepReachable(idx)) {
                this.currentStep = idx;
            }
        },
        goNext() {
            if (this.canAdvance && this.currentStep < this.steps.length - 1) {
                this.currentStep += 1;
            }
        },
        goPrev() {
            if (this.currentStep > 0) {
                this.currentStep -= 1;
            }
        },
        persistDraft(_payload) {
            // STUB — Codex: merge into this.draft + localStorage.
        },
        loadDraft() {
            // STUB — Codex: read localStorage with TTL 24h check.
        },
        finishWizard() {
            // STUB — Codex: orchestrate any pending POST + emit 'finished'.
            this.$emit('finished', { item_id: this.draft?.base_item?.id });
        },
    },
};
</script>
