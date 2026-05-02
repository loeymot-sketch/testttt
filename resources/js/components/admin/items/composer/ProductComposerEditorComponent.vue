<template>
    <section class="min-h-[calc(100vh-120px)] bg-[#f5f7f6] pb-24" data-testid="admin-composer-root">
        <div class="mx-auto max-w-[1760px] space-y-4 px-3 py-4 sm:px-5">
            <header class="rounded-lg border border-[#d9dfdc] bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[#d9dfdc] bg-[#eef2ef]">
                            <img
                                v-if="itemPhoto"
                                :src="itemPhoto"
                                :alt="itemName"
                                class="h-full w-full object-cover"
                                data-testid="admin-composer-product-photo"
                            />
                            <span v-else class="text-2xl font-bold text-[#587065]" data-testid="admin-composer-product-photo">
                                {{ itemInitial }}
                            </span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-[#587065]">
                                {{ t('label.composer.product_context', 'Produit') }}
                            </p>
                            <h1 class="truncate text-2xl font-semibold text-[#202824]" data-testid="admin-composer-product-name">
                                {{ itemName }}
                            </h1>
                            <p class="mt-1 text-sm text-[#66756e]" data-testid="admin-composer-product-category">
                                {{ itemCategory }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="min-w-[220px]">
                            <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                {{ t('label.composer.branch_scope', 'Portee branche') }}
                            </span>
                            <select
                                v-model="branchIdScope"
                                class="db-field-control"
                                data-testid="admin-composer-branch-scope"
                                @change="onBranchScopeChange"
                            >
                                <option :value="null">{{ t('label.composer.all_branches', 'Toutes les branches') }}</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                                    {{ branch.name }}
                                </option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="db-btn-outline h-[42px] !border-[#6d7c74] !text-[#405149]"
                            data-testid="admin-composer-back"
                            @click="returnToItem"
                        >
                            <i class="lab lab-arrow-left" aria-hidden="true"></i>
                            {{ t('label.composer.back_to_product', 'Retour fiche produit') }}
                        </button>
                        <button
                            v-if="profile && profile.is_published"
                            type="button"
                            class="db-btn-outline h-[42px] !border-[#d7a546] !text-[#8d6318]"
                            data-testid="admin-composer-unpublish"
                            :disabled="savingDraft"
                            @click="unpublish"
                        >
                            <i class="lab lab-close-circle" aria-hidden="true"></i>
                            {{ t('label.composer.unpublish', 'Depublier') }}
                        </button>
                    </div>
                </div>
            </header>

            <div
                v-if="loadError"
                class="rounded-lg border border-[#e6b8b8] bg-[#fff1f1] p-3 text-sm font-medium text-[#9b2f2f]"
                role="alert"
                data-testid="admin-composer-load-error"
            >
                {{ loadError }}
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[300px_minmax(0,1fr)_390px]">
                <aside class="space-y-3 rounded-lg border border-[#d9dfdc] bg-white p-3 shadow-sm">
                    <button
                        type="button"
                        class="db-btn h-[42px] w-full justify-center bg-[#334238] text-white"
                        data-testid="admin-composer-template"
                        @click="templateModalOpen = true"
                    >
                        <i class="lab lab-document-text" aria-hidden="true"></i>
                        {{ t('label.composer.choose_template', 'Choisir un template') }}
                    </button>
                    <button
                        type="button"
                        class="db-btn-outline h-[42px] w-full justify-center !border-[#1ab759] !text-[#138445]"
                        data-testid="admin-composer-add-step"
                        @click="addStep"
                    >
                        <i class="lab lab-add-circle" aria-hidden="true"></i>
                        {{ t('label.composer.add_page', 'Ajouter une page') }}
                    </button>

                    <ComposerStepListSidebar
                        v-model="steps"
                        :selected-key="selectedStepKey"
                        :source-labels="sourceLabels"
                        @select="selectStep"
                        @remove="requestRemoveStep"
                        @reorder="onStepsReordered"
                    />
                </aside>

                <main class="min-w-0 rounded-lg border border-[#d9dfdc] bg-white p-4 shadow-sm">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-[#202824]">
                                {{ t('label.composer.edit_page', 'Edition de la page') }}
                            </h2>
                            <p class="text-sm text-[#66756e]">
                                {{ selectedStep ? selectedSourceLabel(selectedStep) : t('message.composer.no_steps', 'Ajoutez une page pour commencer.') }}
                            </p>
                        </div>
                        <span
                            class="rounded-full border px-3 py-1 text-xs font-semibold"
                            :class="profile?.is_published ? 'border-[#b9e7c8] bg-[#edf9f1] text-[#14743a]' : 'border-[#e4d8b5] bg-[#fff8df] text-[#8a6812]'"
                            data-testid="admin-composer-publish-state"
                        >
                            {{ profile?.is_published ? t('label.composer.published', 'Publie') : t('label.composer.draft', 'Brouillon') }}
                        </span>
                    </div>

                    <ComposerStepFormPanel
                        v-if="selectedStep"
                        v-model="selectedStepDraft"
                        :available-sources="availableSources"
                        :source-type-labels="sourceTypeLabels"
                        @change="schedulePreviewRefresh"
                    />
                    <div
                        v-else
                        class="flex min-h-[360px] items-center justify-center rounded-lg border border-dashed border-[#ccd5d0] bg-[#f8faf9] p-6 text-center"
                        data-testid="admin-composer-empty-state"
                    >
                        <div>
                            <p class="text-lg font-semibold text-[#405149]">
                                {{ t('message.composer.no_steps', 'Ajoutez une page pour commencer.') }}
                            </p>
                            <button type="button" class="db-btn mt-4 bg-[#1ab759] text-white" @click="addStep">
                                <i class="lab lab-add-circle" aria-hidden="true"></i>
                                {{ t('label.composer.add_page', 'Ajouter une page') }}
                            </button>
                        </div>
                    </div>
                </main>

                <aside class="rounded-lg border border-[#d9dfdc] bg-white p-4 shadow-sm">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-[#202824]">
                                {{ t('label.composer.live_preview', 'Apercu live') }}
                            </h2>
                            <p class="text-sm text-[#66756e]">
                                {{ t('message.composer.preview_refreshing', 'Rafraichi apres modification.') }}
                            </p>
                        </div>
                        <span class="rounded-full bg-[#eef2ef] px-3 py-1 text-xs font-semibold text-[#587065]">
                            500ms
                        </span>
                    </div>

                    <ItemPreviewComponent
                        v-if="item && previewBranches.length"
                        :key="previewRefreshKey"
                        ref="livePreview"
                        :item="item"
                        :branches="previewBranches"
                        data-testid="admin-composer-live-preview"
                    />
                    <div
                        v-else
                        class="rounded-lg border border-dashed border-[#ccd5d0] bg-[#f8faf9] p-5 text-sm text-[#66756e]"
                        data-testid="admin-composer-preview-empty"
                    >
                        {{ t('message.composer.preview_unavailable', 'Aucune branche disponible pour afficher la preview.') }}
                    </div>
                </aside>
            </div>
        </div>

        <footer class="fixed inset-x-0 bottom-0 z-20 border-t border-[#d9dfdc] bg-white/95 px-4 py-3 shadow-[0_-10px_24px_rgba(32,40,36,0.08)] backdrop-blur">
            <div class="mx-auto flex max-w-[1760px] flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <button
                    type="button"
                    class="db-btn-outline h-[44px] justify-center !border-[#99a69f] !text-[#405149]"
                    data-testid="admin-composer-save-draft"
                    :disabled="savingDraft"
                    @click="saveDraft"
                >
                    <i class="lab lab-document-text" aria-hidden="true"></i>
                    {{ savingDraft ? t('label.composer.saving', 'Enregistrement...') : t('label.composer.save_draft', 'Sauvegarder le brouillon') }}
                </button>
                <button
                    type="button"
                    class="db-btn h-[44px] justify-center bg-[#1ab759] text-white"
                    data-testid="admin-composer-publish"
                    :disabled="publishing"
                    @click="publishConfirmOpen = true"
                >
                    <i class="lab lab-tick-circle-2" aria-hidden="true"></i>
                    {{ publishing ? t('label.composer.publishing', 'Publication...') : t('label.composer.publish', 'Publier') }}
                </button>
            </div>
        </footer>

        <ComposerTemplatePickerModal
            :show="templateModalOpen"
            @close="templateModalOpen = false"
            @select="applyTemplate"
        />

        <div v-if="publishConfirmOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4" data-testid="composer-publish-confirm-modal">
            <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold text-[#202824]">
                    {{ t('label.composer.publish_confirm_title', 'Publier ce wizard') }}
                </h3>
                <p class="mt-2 text-sm text-[#5f6f67]">
                    {{ t('message.composer.publish_confirm_body', 'Cette modification sera visible immediatement sur POS et Kiosk pour la branche scope.') }}
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="db-btn-outline" data-testid="composer-publish-cancel" @click="publishConfirmOpen = false">
                        {{ t('label.cancel', 'Annuler') }}
                    </button>
                    <button type="button" class="db-btn bg-[#1ab759] text-white" data-testid="composer-publish-confirm" @click="publish">
                        <i class="lab lab-tick-circle-2" aria-hidden="true"></i>
                        {{ t('label.composer.publish', 'Publier') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="pendingDeleteStep" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4" data-testid="composer-delete-confirm-modal">
            <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold text-[#202824]">
                    {{ t('label.composer.remove_page', 'Supprimer la page') }}
                </h3>
                <p class="mt-2 text-sm text-[#5f6f67]">
                    {{ t('message.composer.delete_confirm', 'Cette page sera retiree du wizard de ce produit.') }}
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="db-btn-outline" data-testid="composer-delete-cancel" @click="pendingDeleteStep = null">
                        {{ t('label.cancel', 'Annuler') }}
                    </button>
                    <button type="button" class="db-btn bg-[#ef4444] text-white" data-testid="composer-delete-confirm" @click="confirmRemoveStep">
                        <i class="lab lab-trash" aria-hidden="true"></i>
                        {{ t('label.delete', 'Supprimer') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<script>
import axios from 'axios';
import alertService from '../../../../services/alertService';
import ItemPreviewComponent from '../ItemPreviewComponent.vue';
import ComposerTemplatePickerModal from './ComposerTemplatePickerModal.vue';
import ComposerStepListSidebar from './ComposerStepListSidebar.vue';
import ComposerStepFormPanel from './ComposerStepFormPanel.vue';

const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon'];

export default {
    name: 'ProductComposerEditorComponent',
    components: {
        ItemPreviewComponent,
        ComposerTemplatePickerModal,
        ComposerStepListSidebar,
        ComposerStepFormPanel,
    },
    props: {
        itemId: {
            type: [Number, String],
            required: true,
        },
    },
    data() {
        return {
            loading: false,
            savingDraft: false,
            publishing: false,
            item: null,
            profile: null,
            template: 'custom',
            branchIdScope: null,
            steps: [],
            selectedStepKey: null,
            availableSources: {
                item_attribute: [],
                extra_group: [],
                addon: [],
            },
            branches: [],
            templateModalOpen: false,
            publishConfirmOpen: false,
            pendingDeleteStep: null,
            previewRefreshKey: 0,
            previewTimer: null,
            loadError: '',
        };
    },
    computed: {
        itemName() {
            return this.item?.name || this.t('label.composer.loading_product', 'Chargement produit');
        },
        itemCategory() {
            return this.item?.category_name || this.item?.category?.name || this.t('label.category', 'Categorie');
        },
        itemPhoto() {
            return this.item?.preview || this.item?.image || this.item?.image_url || this.item?.photo || '';
        },
        itemInitial() {
            return (this.itemName || 'P').trim().charAt(0).toUpperCase();
        },
        sourceTypeLabels() {
            return {
                item_attribute: this.t('label.composer.source_item_attribute', 'Attribut produit'),
                extra_group: this.t('label.composer.source_extra_group', 'Groupe extras'),
                addon: this.t('label.composer.source_addon', 'Addon catalogue'),
            };
        },
        sourceLabels() {
            const labels = {};
            SOURCE_TYPES.forEach((type) => {
                (this.availableSources[type] || []).forEach((source) => {
                    labels[`${type}:${String(source.id)}`] = source.name;
                });
            });
            return labels;
        },
        selectedStep() {
            if (!this.steps.length) return null;
            return this.steps.find((step) => step._uid === this.selectedStepKey) || this.steps[0];
        },
        selectedStepDraft: {
            get() {
                return this.selectedStep ? { ...this.selectedStep } : null;
            },
            set(value) {
                this.updateSelectedStep(value);
            },
        },
        previewBranches() {
            if (!this.branches.length) return [];
            if (!this.branchIdScope) return this.branches;
            const scoped = this.branches.find((branch) => Number(branch.id) === Number(this.branchIdScope));
            if (!scoped) return this.branches;
            return [scoped, ...this.branches.filter((branch) => Number(branch.id) !== Number(this.branchIdScope))];
        },
    },
    mounted() {
        this.load();
    },
    beforeUnmount() {
        if (this.previewTimer) {
            clearTimeout(this.previewTimer);
        }
    },
    methods: {
        t(key, fallback) {
            return typeof this.$t === 'function' ? this.$t(key) : fallback;
        },
        async load() {
            this.loading = true;
            this.loadError = '';
            try {
                await Promise.all([
                    this.loadItem(),
                    this.loadAvailableSources(),
                    this.loadBranches(),
                ]);
                await this.loadProfile();
            } catch (error) {
                this.loadError = error?.response?.data?.message || this.t('message.composer.load_failed', 'Impossible de charger le composer.');
            } finally {
                this.loading = false;
            }
        },
        async loadItem() {
            const response = await axios.get(`/admin/item/show/${this.itemId}`);
            this.item = response.data?.data || response.data || null;
        },
        async loadBranches() {
            try {
                if (this.$store?.dispatch) {
                    await this.$store.dispatch('backendGlobalState/branches', {});
                    this.branches = this.$store.getters?.['backendGlobalState/branches'] || [];
                }
            } catch (error) {
                this.branches = [];
            }

            if (!this.branches.length && Array.isArray(this.item?.branches)) {
                this.branches = this.item.branches;
            }
        },
        async loadProfile() {
            try {
                const config = this.branchIdScope ? { params: { branch_id_scope: this.branchIdScope } } : undefined;
                const response = await axios.get(`/admin/composer/items/${this.itemId}/profile`, config);
                this.hydrateProfile(response.data?.data || null);
            } catch (error) {
                if (error?.response?.status === 404) {
                    this.profile = null;
                    this.template = 'custom';
                    this.steps = [];
                    this.selectedStepKey = null;
                    return;
                }
                throw error;
            }
        },
        async loadAvailableSources() {
            const response = await axios.get(`/admin/composer/items/${this.itemId}/available-sources`);
            const data = response.data?.data || response.data || {};
            this.availableSources = {
                item_attribute: Array.isArray(data.item_attribute) ? data.item_attribute : [],
                extra_group: Array.isArray(data.extra_group) ? data.extra_group : [],
                addon: Array.isArray(data.addon) ? data.addon : [],
            };
        },
        hydrateProfile(profile) {
            this.profile = profile;
            this.template = profile?.template || 'custom';
            this.branchIdScope = profile?.branch_id_scope ?? this.branchIdScope ?? null;
            this.steps = (profile?.steps || []).map((step, index) => this.normalizeStep(step, index));
            this.selectedStepKey = this.steps[0]?._uid || null;
            this.schedulePreviewRefresh();
        },
        normalizeStep(step = {}, index = 0) {
            const sourceType = SOURCE_TYPES.includes(step.source_type) ? step.source_type : 'item_attribute';
            const minSelect = Number.isFinite(Number(step.min_select)) ? Number(step.min_select) : 0;
            const maxSelect = Number.isFinite(Number(step.max_select)) ? Number(step.max_select) : Math.max(1, minSelect);
            return {
                id: step.id ?? null,
                profile_id: step.profile_id ?? this.profile?.id ?? null,
                step_key: step.step_key || this.makeStepKey(step.label || '', index),
                label: step.label || this.t('label.composer.new_page', 'Nouvelle page'),
                source_type: sourceType,
                source_ref: step.source_ref == null ? '' : String(step.source_ref),
                min_select: minSelect,
                max_select: Math.max(maxSelect, minSelect),
                allow_repeat: Boolean(step.allow_repeat),
                visible_on: Array.isArray(step.visible_on) && step.visible_on.length ? [...step.visible_on] : ['pos', 'kiosk'],
                stockable_choices: Boolean(step.stockable_choices),
                position: Number.isFinite(Number(step.position)) ? Number(step.position) : index,
                is_active: step.is_active !== false,
                addon_role: step.addon_role ?? null,
                _uid: step._uid || (step.id ? `step-${step.id}` : `draft-${Date.now()}-${index}`),
            };
        },
        makeStepKey(label, index) {
            const slug = String(label || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
            return slug || `page_${index + 1}`;
        },
        selectStep(step) {
            this.selectedStepKey = step?._uid || null;
        },
        addStep() {
            const next = this.normalizeStep({
                label: this.t('label.composer.new_page', 'Nouvelle page'),
                source_type: 'item_attribute',
                source_ref: '',
                min_select: 0,
                max_select: 1,
                visible_on: ['pos', 'kiosk'],
                is_active: true,
                position: this.steps.length,
            }, this.steps.length);
            this.steps = [...this.steps, next];
            this.selectedStepKey = next._uid;
            this.schedulePreviewRefresh();
        },
        updateSelectedStep(value) {
            if (!value?._uid) return;
            this.steps = this.steps.map((step, index) => {
                if (step._uid !== value._uid) return step;
                const next = this.normalizeStep({
                    ...step,
                    ...value,
                    step_key: value.step_key || this.makeStepKey(value.label, index),
                    position: index,
                }, index);
                return { ...next, _uid: step._uid };
            });
            this.schedulePreviewRefresh();
        },
        onStepsLocalChange(value) {
            this.steps = (value || []).map((step, index) => this.normalizeStep({ ...step, position: index }, index));
            if (!this.steps.some((step) => step._uid === this.selectedStepKey)) {
                this.selectedStepKey = this.steps[0]?._uid || null;
            }
            this.schedulePreviewRefresh();
        },
        async onStepsReordered(value) {
            this.onStepsLocalChange(value);
            if (!this.profile?.id) return;
            const requests = this.steps
                .filter((step) => step.id)
                .map((step) => axios.patch(`/admin/composer/steps/${step.id}`, this.payloadForStep(step)));
            if (requests.length) {
                await Promise.all(requests);
            }
        },
        requestRemoveStep(step) {
            this.pendingDeleteStep = step;
        },
        async confirmRemoveStep() {
            const step = this.pendingDeleteStep;
            if (!step) return;
            if (step.id) {
                await axios.delete(`/admin/composer/steps/${step.id}`);
            }
            this.steps = this.steps.filter((candidate) => candidate._uid !== step._uid)
                .map((candidate, index) => this.normalizeStep({ ...candidate, position: index }, index));
            this.selectedStepKey = this.steps[0]?._uid || null;
            this.pendingDeleteStep = null;
            alertService.success(this.t('message.composer.step_deleted', 'Page supprimee.'));
            this.schedulePreviewRefresh();
        },
        profilePayload() {
            return {
                template: this.template || 'custom',
                branch_id_scope: this.branchIdScope || null,
                steps: this.steps.map((step, index) => this.payloadForStep({ ...step, position: index })),
            };
        },
        payloadForStep(step) {
            const minSelect = Number(step.min_select || 0);
            const maxSelect = Math.max(Number(step.max_select || 0), minSelect);
            return {
                step_key: step.step_key || this.makeStepKey(step.label, step.position || 0),
                label: step.label || this.t('label.composer.new_page', 'Nouvelle page'),
                source_type: SOURCE_TYPES.includes(step.source_type) ? step.source_type : 'item_attribute',
                source_ref: step.source_ref == null ? '' : String(step.source_ref),
                min_select: minSelect,
                max_select: maxSelect,
                allow_repeat: Boolean(step.allow_repeat),
                visible_on: Array.isArray(step.visible_on) ? step.visible_on : ['pos', 'kiosk'],
                stockable_choices: Boolean(step.stockable_choices),
                position: Number(step.position || 0),
                is_active: step.is_active !== false,
                addon_role: step.addon_role || null,
            };
        },
        async saveDraft() {
            this.savingDraft = true;
            try {
                const payload = this.profilePayload();
                const response = this.profile?.id
                    ? await axios.put(`/admin/composer/profiles/${this.profile.id}`, payload)
                    : await axios.post(`/admin/composer/items/${this.itemId}/profile`, payload);
                this.hydrateProfile(response.data?.data || null);
                alertService.success(this.t('message.composer.draft_saved', 'Brouillon sauvegarde.'));
            } catch (error) {
                alertService.error(error?.response?.data?.message || this.t('message.composer.save_failed', 'Sauvegarde impossible.'));
                throw error;
            } finally {
                this.savingDraft = false;
            }
        },
        async applyTemplate(template) {
            const payload = { template };
            if (this.branchIdScope) {
                payload.branch_id_scope = this.branchIdScope;
            }
            const response = await axios.post(`/admin/composer/items/${this.itemId}/apply-template`, payload);
            this.templateModalOpen = false;
            this.hydrateProfile(response.data?.data || null);
            alertService.success(this.t('message.composer.template_applied', 'Template applique.'));
        },
        async publish() {
            this.publishing = true;
            try {
                if (!this.profile?.id) {
                    await this.saveDraft();
                }
                const response = await axios.post(`/admin/composer/profiles/${this.profile.id}/publish`);
                this.hydrateProfile(response.data?.data || null);
                this.publishConfirmOpen = false;
                alertService.success(this.t('message.composer.published', 'Wizard publie.'));
            } catch (error) {
                alertService.error(error?.response?.data?.message || this.t('message.composer.publish_failed', 'Publication impossible.'));
                throw error;
            } finally {
                this.publishing = false;
            }
        },
        async unpublish() {
            if (!this.profile?.id) return;
            const response = await axios.post(`/admin/composer/profiles/${this.profile.id}/unpublish`);
            this.hydrateProfile(response.data?.data || null);
        },
        onBranchScopeChange() {
            if (this.branchIdScope === '') {
                this.branchIdScope = null;
            }
            this.loadProfile();
        },
        selectedSourceLabel(step) {
            if (!step) return '';
            return this.sourceLabels[`${step.source_type}:${String(step.source_ref)}`] || this.sourceTypeLabels[step.source_type] || step.source_type;
        },
        schedulePreviewRefresh() {
            if (this.previewTimer) {
                clearTimeout(this.previewTimer);
            }
            this.previewTimer = setTimeout(() => {
                if (this.$refs.livePreview?.refreshAll) {
                    this.$refs.livePreview.refreshAll();
                } else {
                    this.previewRefreshKey += 1;
                }
            }, 500);
        },
        returnToItem() {
            if (this.$router?.push) {
                this.$router.push({ name: 'admin.item.show', params: { id: this.itemId } });
            }
        },
    },
};
</script>
