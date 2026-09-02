<template>
    <section class="min-h-[calc(100vh-120px)] bg-[#f5f7f6] pb-24" data-testid="admin-composer-root">
        <ComposerVersionConflictBanner
            :is-visible="conflictDetected"
            :current-version="version"
            :expected-version="expectedVersion"
            @reload="reloadProfile"
        />

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
                                {{ composerContextLabel }}
                            </p>
                            <h1 class="truncate text-2xl font-semibold text-[#202824]" data-testid="admin-composer-product-name">
                                {{ composerHeaderTitle }}
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
                            {{ isCategoryComposer
                                ? t('label.composer.back_to_category', 'Retour à la catégorie')
                                : t('label.composer.back_to_product', 'Retour fiche produit') }}
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

            <div
                v-if="applyTemplateError"
                role="alert"
                class="flex items-start gap-3 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                data-testid="admin-composer-apply-template-error"
            >
                <span class="flex-1 leading-relaxed">{{ applyTemplateError }}</span>
                <button
                    type="button"
                    class="font-semibold text-rose-900 hover:underline"
                    @click="applyTemplateError = null"
                >
                    {{ t('label.dismiss', 'Fermer') }}
                </button>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[300px_minmax(0,1fr)_390px]">
                <aside class="space-y-3 rounded-lg border border-[#d9dfdc] bg-white p-3 shadow-sm">
                    <button
                        type="button"
                        class="db-btn h-[42px] w-full justify-center bg-[#334238] text-white"
                        data-testid="admin-composer-template"
                        :disabled="applyingTemplate"
                        @click="templateModalOpen = true"
                    >
                        <i
                            class="lab"
                            :class="applyingTemplate ? 'lab-refresh animate-spin' : 'lab-document-text'"
                            aria-hidden="true"
                        ></i>
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
                        <p
                            v-if="profile && !profile.is_published"
                            class="w-full text-xs text-[#8a6812]"
                            data-testid="admin-composer-draft-not-till"
                        >
                            {{ t('message.composer.draft_not_on_till', 'La caisse lit encore la version publiee. Ceci est un brouillon.') }}
                        </p>
                    </div>

                    <ComposerStepFormPanel
                        v-if="selectedStep"
                        v-model="selectedStepDraft"
                        :available-sources="availableSources"
                        :source-type-labels="sourceTypeLabels"
                        @change="schedulePreviewRefresh"
                    />
                    <div
                        v-if="steps.length === 0"
                        class="mx-auto my-8 max-w-xl rounded-lg border border-amber-300 bg-amber-50 p-6"
                        data-testid="admin-composer-empty-state"
                    >
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-200 text-amber-900">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h3 class="mb-2 text-base font-semibold text-amber-900">
                                    {{ t('message.composer.guidance_zero_steps_title', 'Comment fonctionne le wizard ?') }}
                                </h3>
                                <p class="mb-3 text-sm leading-relaxed text-amber-900">
                                    {{ t('message.composer.guidance_zero_steps_intro', 'Le wizard est le parcours que ton client suit pour personnaliser ce produit (choix de la viande, sauce, taille, etc.). Chaque page = une étape de choix.') }}
                                </p>
                                <ol class="mb-4 list-decimal space-y-2 pl-5 text-sm text-amber-900">
                                    <li>
                                        {{ t('message.composer.guidance_zero_steps_option_template', "Préférable : choisis un template (Tacos, Sandwich…) pour partir d'une base prête.") }}
                                    </li>
                                    <li>
                                        {{ t('message.composer.guidance_zero_steps_option_manual', 'Sinon : ajoute une page manuelle pour configurer ton propre parcours.') }}
                                    </li>
                                </ol>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800"
                                        :disabled="applyingTemplate"
                                        @click="templateModalOpen = true"
                                    >
                                        {{ t('button.composer.choose_template_v2', 'Choisir un template') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-md border border-amber-400 bg-white px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100"
                                        @click="addStep"
                                    >
                                        {{ t('button.composer.add_page_manual', 'Ajouter une page manuellement') }}
                                    </button>
                                </div>
                            </div>
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
                        :steps="steps"
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
                    class="db-btn-outline h-[44px] justify-center !border-[#99a69f] !text-[#405149]"
                    data-testid="admin-composer-show-diff"
                    :disabled="!profile || !profile.id || publishing"
                    @click="diffModalOpen = true"
                >
                    {{ t('studio.composer.diff.title', 'Differences a publier') }}
                </button>
                <button
                    type="button"
                    class="db-btn h-[44px] justify-center bg-[#1ab759] text-white"
                    data-testid="admin-composer-publish"
                    :disabled="conflictDetected || publishing"
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
                    {{ publishConfirmBody }}
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

        <ComposerPublishDiffModal
            v-if="profile && profile.id"
            :profile-id="profile.id"
            :is-open="diffModalOpen"
            @update:is-open="diffModalOpen = $event"
            @confirm-publish="publish"
        />

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
import ComposerPublishDiffModal from './ComposerPublishDiffModal.vue';
import ComposerVersionConflictBanner from './ComposerVersionConflictBanner.vue';
import ComposerStepListSidebar from './ComposerStepListSidebar.vue';
import ComposerStepFormPanel from './ComposerStepFormPanel.vue';

const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon'];

export default {
    name: 'ProductComposerEditorComponent',
    components: {
        ItemPreviewComponent,
        ComposerTemplatePickerModal,
        ComposerPublishDiffModal,
        ComposerVersionConflictBanner,
        ComposerStepListSidebar,
        ComposerStepFormPanel,
    },
    props: {
        itemId: {
            type: [Number, String],
            default: null,
        },
        entityId: {
            type: [Number, String],
            default: null,
        },
        entityType: {
            type: String,
            default: 'item',
            validator: (value) => ['item', 'category'].includes(value),
        },
    },
    data() {
        return {
            loading: false,
            savingDraft: false,
            publishing: false,
            item: null,
            // [2026-09-02] La CATÉGORIE éditée, distincte de `item` (qui porte désormais un
            // article représentatif servant l'aperçu). Elle était assignée sans être déclarée
            // ici : non réactive, donc l'en-tête restait bloqué sur « Chargement catégorie ».
            categoryRecord: null,
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
            diffModalOpen: false,
            applyingTemplate: false,
            applyTemplateError: null,
            version: 0,
            conflictDetected: false,
            expectedVersion: null,
            pendingDeleteStep: null,
            previewRefreshKey: 0,
            previewTimer: null,
            loadError: '',
        };
    },
    computed: {
        resolvedEntityType() {
            const route = this.currentRoute();
            const routeType = route.meta?.entityType || route.query?.entityType || route.query?.entity_type;
            if (routeType === 'category' || route.name === 'admin.categories.composer' || String(route.path || '').includes('/admin/categories/')) {
                return 'category';
            }
            if (routeType === 'item' || route.name === 'admin.items.composer' || String(route.path || '').includes('/admin/items/')) {
                return 'item';
            }

            const searchParams = this.locationSearchParams();
            const queryType = searchParams.get('entityType') || searchParams.get('entity_type');
            if (queryType === 'category' || queryType === 'item') {
                return queryType;
            }

            return this.entityType;
        },
        resolvedEntityId() {
            const route = this.currentRoute();
            const routeParams = route.params || {};
            const routeQuery = route.query || {};
            const searchParams = this.locationSearchParams();
            const candidate = this.entityId
                || this.itemId
                || routeParams.id
                || routeParams.itemId
                || routeParams.categoryId
                || routeQuery.entityId
                || routeQuery.entity_id
                || routeQuery.itemId
                || routeQuery.item_id
                || routeQuery.categoryId
                || routeQuery.category_id
                || searchParams.get('entityId')
                || searchParams.get('entity_id')
                || searchParams.get('itemId')
                || searchParams.get('item_id')
                || searchParams.get('categoryId')
                || searchParams.get('category_id');

            return candidate == null ? null : candidate;
        },
        isCategoryComposer() {
            return this.resolvedEntityType === 'category';
        },
        composerContextLabel() {
            return this.isCategoryComposer
                ? this.t('label.composer.category_context', 'Wizard de la catégorie')
                : this.t('label.composer.product_context', 'Wizard du produit');
        },
        composerHeaderTitle() {
            return this.isCategoryComposer
                ? `${this.t('label.composer.category_context', 'Wizard de la catégorie')} : ${this.itemName}`
                : this.itemName;
        },
        profileEndpoint() {
            return this.isCategoryComposer
                ? `admin/composer/categories/${this.resolvedEntityId}/profile`
                : `admin/composer/items/${this.resolvedEntityId}/profile`;
        },
        createProfileEndpoint() {
            return this.profileEndpoint;
        },
        applyTemplateEndpoint() {
            return this.isCategoryComposer
                ? `admin/composer/categories/${this.resolvedEntityId}/apply-template`
                : `admin/composer/items/${this.resolvedEntityId}/apply-template`;
        },
        publishConfirmBody() {
            return this.isCategoryComposer
                ? this.categoryPublishWarning()
                : this.t('message.composer.publish_confirm_body', 'Cette modification sera visible immediatement sur POS et Kiosk pour la branche scope.');
        },
        itemName() {
            if (this.isCategoryComposer) {
                // Le nom vient de la CATÉGORIE, pas de `item` : en mode catégorie, `item`
                // porte un article représentatif chargé pour l'aperçu, dont le nom écrirait
                // « Wizard de la catégorie : Tacos XL » au lieu de « … : Tacos ».
                return this.categoryRecord?.name || this.t('label.composer.loading_category', 'Chargement catégorie');
            }
            return this.item?.name || this.t('label.composer.loading_product', 'Chargement produit');
        },
        itemCategory() {
            if (this.isCategoryComposer) {
                return this.t(
                    'message.composer.category_inheritance_scope',
                    'Les produits déjà dans la catégorie reçoivent ce wizard au moment de Publier. Un produit ajouté ensuite : republier. Dépublier le retire de la caisse.'
                );
            }
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
            const cayenne = this.branches.find((branch) => Number(branch.id) === 1);
            if (cayenne) {
                return [cayenne];
            }
            if (!this.branchIdScope) return this.branches;
            const scoped = this.branches.find((branch) => Number(branch.id) === Number(this.branchIdScope));
            if (!scoped) return this.branches;
            return [scoped];
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
            if (typeof this.$t !== 'function') {
                return fallback;
            }
            const translated = this.$t(key);
            return translated === key ? fallback : translated;
        },
        currentRoute() {
            const routerRoute = this.$router?.currentRoute;
            if (routerRoute?.value) {
                return routerRoute.value;
            }
            if (routerRoute) {
                return routerRoute;
            }
            return Object.prototype.hasOwnProperty.call(this, '$route') ? this.$route : {};
        },
        locationSearchParams() {
            if (typeof window === 'undefined' || !window.location?.search) {
                return new URLSearchParams('');
            }
            return new URLSearchParams(window.location.search);
        },
        async load() {
            this.loading = true;
            this.loadError = '';
            try {
                await Promise.all([
                    this.loadEntity(),
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
        async loadEntity() {
            if (this.isCategoryComposer) {
                return this.loadCategory();
            }
            return this.loadItem();
        },
        async loadItem() {
            const response = await axios.get(`admin/item/show/${this.resolvedEntityId}`);
            this.item = response.data?.data || response.data || null;
        },
        async loadCategory() {
            const response = await axios.get(`admin/setting/item-category/show/${this.resolvedEntityId}`);
            this.categoryRecord = response.data?.data || response.data || null;
            // Avant : this.item = la CATÉGORIE. L'aperçu cherchait item.id === cat.id
            // dans le menu → « Article non disponible » alors que Tacos XL est en vente.
            this.item = null;
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
                const response = await axios.get(this.profileEndpoint, config);
                this.hydrateProfile(response.data?.data || null);
            } catch (error) {
                if (error?.response?.status === 404) {
                    this.profile = null;
                    this.template = 'custom';
                    this.version = 0;
                    this.conflictDetected = false;
                    this.expectedVersion = null;
                    this.steps = [];
                    this.selectedStepKey = null;
                    return;
                }
                throw error;
            }
        },
        async loadAvailableSources() {
            // Avant : wizard catégorie = sélecteur vide. Le restaurateur
            // voyait « Choisir » sans Viande / Sauce, même avec un tacos dans
            // la catégorie. On reprend les sources du 1er produit.
            const url = this.isCategoryComposer
                ? `admin/composer/categories/${this.resolvedEntityId}/available-sources`
                : `admin/composer/items/${this.resolvedEntityId}/available-sources`;

            try {
                const response = await axios.get(url);
                const data = response.data?.data || response.data || {};
                this.availableSources = {
                    item_attribute: Array.isArray(data.item_attribute) ? data.item_attribute : [],
                    extra_group: Array.isArray(data.extra_group) ? data.extra_group : [],
                    addon: Array.isArray(data.addon) ? data.addon : [],
                };
                const previewId = Number(data.item_id || 0);
                if (this.isCategoryComposer && previewId > 0) {
                    const itemRes = await axios.get(`admin/item/show/${previewId}`);
                    this.item = itemRes.data?.data || itemRes.data || null;
                }
            } catch (error) {
                this.availableSources = {
                    item_attribute: [],
                    extra_group: [],
                    addon: [],
                };
                if (error?.response?.status === 422) {
                    this.loadError = error.response.data?.message
                        || this.t(
                            'message.composer.sources_unavailable',
                            'Ajoute un produit dans la catégorie avant de relier les pages du wizard.'
                        );
                }
            }
        },
        hydrateProfile(profile) {
            this.profile = profile;
            this.template = profile?.template || 'custom';
            this.version = profile?.version ?? 0;
            this.conflictDetected = false;
            this.expectedVersion = null;
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
            let key = slug || `page_${index + 1}`;
            // "New page" / "Nouvelle page" slug identically for every new row → DB unique (profile_id, step_key).
            if (key === 'new_page' || key === 'nouvelle_page') {
                key = `page_${index + 1}`;
            }
            return key;
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
                    // Form panel edits label only — always derive key from label so slug tracks renames.
                    step_key: this.makeStepKey(value.label || '', index),
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
            // Wizard publié : on ne PATCH pas les étapes live. Enregistrer
            // brouillon forke une copie ; Publier envoie en caisse.
            if (!this.profile?.id || this.profile.is_published) return;
            const requests = this.steps
                .filter((step) => step.id)
                .map((step) => axios.patch(`admin/composer/steps/${step.id}`, this.payloadForStep(step)));
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
            if (step.id && !this.profile?.is_published) {
                await axios.delete(`admin/composer/steps/${step.id}`);
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
                step_key: (() => {
                    const pos = Number(step.position || 0);
                    const fromLabel = this.makeStepKey(step.label || '', pos);
                    if (fromLabel && !['new_page', 'nouvelle_page'].includes(fromLabel)) {
                        return fromLabel;
                    }
                    if (step.step_key && !['new_page', 'nouvelle_page'].includes(step.step_key)) {
                        return step.step_key;
                    }
                    return `page_${pos + 1}`;
                })(),
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
                const payload = {
                    ...this.profilePayload(),
                    version: this.version,
                };
                const response = this.profile?.id
                    ? await axios.put(`admin/composer/profiles/${this.profile.id}`, payload)
                    : await axios.post(this.createProfileEndpoint, payload);
                this.hydrateProfile(response.data?.data || null);
                alertService.success(this.t('message.composer.draft_saved', 'Brouillon sauvegarde.'));
            } catch (error) {
                if (error?.response?.status === 409) {
                    this.conflictDetected = true;
                    this.expectedVersion = error.response.data?.expected ?? null;
                    return;
                }
                alertService.error(error?.response?.data?.message || this.t('message.composer.save_failed', 'Sauvegarde impossible.'));
                throw error;
            } finally {
                this.savingDraft = false;
            }
        },
        reloadProfile() {
            this.conflictDetected = false;
            this.expectedVersion = null;
            return this.loadProfile();
        },
        async applyTemplate(template) {
            if (!template) return;
            this.applyingTemplate = true;
            this.applyTemplateError = null;
            try {
                const payload = { template };
                if (this.branchIdScope) {
                    payload.branch_id_scope = this.branchIdScope;
                }
                const response = await axios.post(
                    this.applyTemplateEndpoint,
                    payload
                );
                const profileData = response.data?.data || null;

                if (!profileData) {
                    this.applyTemplateError = this.t(
                        'message.composer.apply_template_empty_response',
                        "Le serveur n'a pas renvoyé le profil mis à jour. Réessaye ou contacte le support."
                    );
                    this.templateModalOpen = false;
                    return;
                }

                this.templateModalOpen = false;
                this.hydrateProfile(profileData);

                if (template !== 'custom' && Array.isArray(profileData.steps) && profileData.steps.length === 0) {
                    this.applyTemplateError = this.t(
                        'message.composer.apply_template_no_steps',
                        "Le template a été appliqué mais aucune étape n'a été créée. C'est inattendu."
                    );
                }

                await this.loadProfile();
                alertService.success(this.t('message.composer.template_applied', 'Template applique.'));
            } catch (error) {
                const status = error?.response?.status;
                const serverMessage = error?.response?.data?.message;
                this.templateModalOpen = false;
                if (status === 422) {
                    this.applyTemplateError = serverMessage || this.t(
                        'message.composer.apply_template_validation_error',
                        'Données invalides envoyées au serveur.'
                    );
                } else if (status === 401 || status === 419) {
                    this.applyTemplateError = this.t(
                        'message.composer.apply_template_auth_error',
                        'Session expirée. Recharge la page et reconnecte-toi.'
                    );
                } else if (status === 500) {
                    this.applyTemplateError = this.t(
                        'message.composer.apply_template_server_error',
                        'Erreur serveur. Réessaye dans un instant.'
                    );
                } else {
                    this.applyTemplateError = serverMessage || this.t(
                        'message.composer.apply_template_unknown_error',
                        "Échec inattendu lors de l'application du template."
                    );
                }
                console.error('[applyTemplate] failed', { status, error });
            } finally {
                this.applyingTemplate = false;
            }
        },
        async publish() {
            this.publishing = true;
            try {
                if (this.isCategoryComposer && !this.confirmCategoryPublish()) {
                    this.publishConfirmOpen = false;
                    return;
                }
                // Avant : Publier sans d'abord sauver le brouillon envoyait
                // l'ancien wizard en caisse. Le toast disait « publié ».
                await this.saveDraft();
                if (this.conflictDetected || !this.profile?.id) {
                    return;
                }
                const response = await axios.post(`admin/composer/profiles/${this.profile.id}/publish`);
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
            const response = await axios.post(`admin/composer/profiles/${this.profile.id}/unpublish`);
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
                if (this.isCategoryComposer) {
                    this.$router.push({ name: 'admin.items.studio', query: { item_category_id: this.resolvedEntityId } });
                    return;
                }
                this.$router.push({ name: 'admin.item.show', params: { id: this.resolvedEntityId } });
            }
        },
        categoryPublishWarning() {
            const count = this.item?.product_count || this.item?.products_count || this.item?.items_count || 'N';
            return `Cette opération va remplacer les wizards personnalisés de ${count} produits dans cette catégorie. Continuer ?`;
        },
        confirmCategoryPublish() {
            if (typeof window === 'undefined' || typeof window.confirm !== 'function') {
                return true;
            }
            return window.confirm(this.categoryPublishWarning());
        },
    },
};
</script>
