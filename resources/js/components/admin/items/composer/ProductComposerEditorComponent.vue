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
                            <i class="lab lab-back-bold" aria-hidden="true"></i>
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
                        <!-- [GOAL CMS T-W5b] delete whole wizard — unpublished only
                             (the backend refuses published profiles with 409). -->
                        <button
                            v-if="profile && profile.id && !profile.is_published"
                            type="button"
                            class="db-btn-outline h-[42px] !border-[#c0392b] !text-[#9b2f2f]"
                            data-testid="admin-composer-delete-profile"
                            :disabled="savingDraft"
                            @click="destroyProfile"
                        >
                            <i class="lab lab-delete" aria-hidden="true"></i>
                            {{ t('label.composer.delete_profile', 'Supprimer le wizard') }}
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
                            :class="applyingTemplate ? 'lab-undo animate-spin' : 'lab-document-text'"
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
                    <button
                        type="button"
                        class="db-btn-outline h-[42px] w-full justify-center !border-[#1ab759] !text-[#138445]"
                        data-testid="admin-composer-add-personal-page"
                        @click="openPersonalPage"
                    >
                        <i class="lab lab-add-circle" aria-hidden="true"></i>
                        {{ t('label.composer.add_personal_page', 'Créer une page personnalisée') }}
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
                        <div class="flex items-center gap-3">
                            <button
                                v-if="selectedStepIsEditableGroup"
                                type="button"
                                class="db-btn-outline !h-[36px] !px-3 !text-xs !border-[#1ab759] !text-[#138445]"
                                data-testid="composer-edit-personal-page"
                                @click="editPersonalPage(selectedStep)"
                            >
                                <i class="lab lab-edit-2" aria-hidden="true"></i>
                                {{ t('label.composer.edit_options', 'Modifier les options') }}
                            </button>
                            <span
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="profile?.is_published ? 'border-[#b9e7c8] bg-[#edf9f1] text-[#14743a]' : 'border-[#e4d8b5] bg-[#fff8df] text-[#8a6812]'"
                                data-testid="admin-composer-publish-state"
                            >
                                {{ profile?.is_published ? t('label.composer.published', 'Publie') : t('label.composer.draft', 'Brouillon') }}
                            </span>
                        </div>
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

        <div
            v-if="personalPageOpen"
            class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4 py-6"
            data-testid="composer-personal-page-modal"
        >
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-[#e3e8e5] p-5">
                    <div>
                        <h3 class="text-lg font-semibold text-[#202824]" data-testid="composer-personal-page-title">
                            {{ personalPageIsEdit
                                ? t('label.composer.edit_personal_page', 'Modifier la page personnalisée')
                                : t('label.composer.add_personal_page', 'Créer une page personnalisée') }}
                        </h3>
                        <p class="mt-1 text-sm text-[#66756e]">
                            {{ t('message.composer.personal_page_hint', "Composez une page sur mesure : chaque option porte son propre prix (0 = offert). Le prix vit sur l'option, jamais sur la page.") }}
                        </p>
                    </div>
                    <button type="button" class="db-btn-outline !px-3" data-testid="composer-personal-page-close" @click="closePersonalPage">
                        <i class="lab lab-close" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <div
                        v-if="personalPageError"
                        class="mb-4 rounded-lg border border-[#e6b8b8] bg-[#fff1f1] p-3 text-sm font-medium text-[#9b2f2f]"
                        role="alert"
                        data-testid="composer-personal-page-error"
                    >
                        {{ personalPageError }}
                    </div>

                    <label class="mb-4 block">
                        <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                            {{ t('label.composer.personal_page_label', 'Titre de la page') }}
                        </span>
                        <input
                            v-model="personalPage.label"
                            type="text"
                            maxlength="50"
                            class="db-field-control"
                            data-testid="composer-personal-page-label"
                            :placeholder="t('label.composer.personal_page_label_placeholder', 'Ex. Sauces maison')"
                        />
                    </label>

                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="text-xs font-semibold uppercase tracking-[0.06em] text-[#587065]">
                            {{ t('label.composer.personal_page_options', 'Options de la page') }}
                        </span>
                        <button
                            type="button"
                            class="db-btn-outline !h-[34px] !px-3 !text-xs !border-[#1ab759] !text-[#138445]"
                            data-testid="composer-personal-page-add-option"
                            @click="addPersonalOption"
                        >
                            <i class="lab lab-add-circle" aria-hidden="true"></i>
                            {{ t('label.composer.personal_page_add_option', 'Ajouter une option') }}
                        </button>
                    </div>

                    <div
                        v-for="(option, index) in personalPage.options"
                        :key="index"
                        class="mb-3 rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-3"
                        :data-testid="`composer-personal-page-option-${index}`"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <label class="min-w-0 flex-1">
                                <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                    {{ t('label.composer.personal_page_option_name', "Nom de l'option") }}
                                </span>
                                <input
                                    v-model="option.name"
                                    type="text"
                                    maxlength="191"
                                    class="db-field-control"
                                    :data-testid="`composer-personal-page-option-${index}-name`"
                                />
                            </label>
                            <label class="w-full sm:w-[130px]">
                                <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                    {{ t('label.composer.personal_page_option_price', 'Prix (€)') }}
                                </span>
                                <input
                                    v-model.number="option.price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="db-field-control"
                                    :data-testid="`composer-personal-page-option-${index}-price`"
                                />
                            </label>
                            <button
                                type="button"
                                class="db-btn-outline !h-[42px] !px-3 self-end !border-[#e0a3a3] !text-[#9b2f2f]"
                                :data-testid="`composer-personal-page-option-${index}-remove`"
                                :disabled="personalPage.options.length <= 1"
                                @click="removePersonalOption(index)"
                            >
                                <i class="lab lab-trash" aria-hidden="true"></i>
                            </button>
                        </div>
                        <label class="mt-2 block">
                            <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                {{ t('label.composer.personal_page_option_description', 'Description (optionnel)') }}
                            </span>
                            <input
                                v-model="option.description"
                                type="text"
                                maxlength="5000"
                                class="db-field-control"
                                :data-testid="`composer-personal-page-option-${index}-description`"
                            />
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                {{ t('label.composer.min_select', 'Sélection minimale') }}
                            </span>
                            <input
                                v-model.number="personalPage.min_select"
                                type="number"
                                min="0"
                                class="db-field-control"
                                data-testid="composer-personal-page-min-select"
                            />
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                                {{ t('label.composer.max_select', 'Sélection maximale') }}
                            </span>
                            <input
                                v-model.number="personalPage.max_select"
                                type="number"
                                min="0"
                                class="db-field-control"
                                data-testid="composer-personal-page-max-select"
                            />
                        </label>
                    </div>

                    <fieldset class="mt-4">
                        <legend class="mb-1 block text-xs font-semibold text-[#5d6f66]">
                            {{ t('label.composer.visible_on', 'Visible sur') }}
                        </legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-sm text-[#405149]">
                                <input
                                    v-model="personalPage.visible_on"
                                    type="checkbox"
                                    value="pos"
                                    data-testid="composer-personal-page-visible-pos"
                                />
                                {{ t('label.composer.visible_pos', 'Caisse (POS)') }}
                            </label>
                            <label class="flex items-center gap-2 text-sm text-[#405149]">
                                <input
                                    v-model="personalPage.visible_on"
                                    type="checkbox"
                                    value="kiosk"
                                    data-testid="composer-personal-page-visible-kiosk"
                                />
                                {{ t('label.composer.visible_kiosk', 'Borne (Kiosk)') }}
                            </label>
                        </div>
                    </fieldset>
                </div>

                <div class="flex justify-end gap-2 border-t border-[#e3e8e5] p-5">
                    <button type="button" class="db-btn-outline" data-testid="composer-personal-page-cancel" @click="closePersonalPage">
                        {{ t('label.cancel', 'Annuler') }}
                    </button>
                    <button
                        type="button"
                        class="db-btn bg-[#1ab759] text-white"
                        data-testid="composer-personal-page-submit"
                        :disabled="personalPageSaving"
                        @click="submitPersonalPage"
                    >
                        <i class="lab lab-tick-circle-2" aria-hidden="true"></i>
                        {{ personalPageSaving
                            ? t('label.composer.saving', 'Enregistrement...')
                            : (personalPageIsEdit
                                ? t('label.composer.update_page', 'Mettre à jour la page')
                                : t('label.composer.create_page', 'Créer la page')) }}
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
            personalPageOpen: false,
            personalPageSaving: false,
            personalPageError: '',
            // null = create mode; a step id = re-edit mode (PUT back to that step's bound group).
            personalPageEditStepId: null,
            // Kept in sync with blankPersonalPage(); inlined here because Options-API
            // data() runs before methods are bound on the instance.
            personalPage: {
                label: '',
                options: [{ name: '', price: 0, description: '' }],
                min_select: 0,
                max_select: null,
                visible_on: ['pos', 'kiosk'],
            },
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
                return this.item?.name || this.t('label.composer.loading_category', 'Chargement catégorie');
            }
            return this.item?.name || this.t('label.composer.loading_product', 'Chargement produit');
        },
        itemCategory() {
            if (this.isCategoryComposer) {
                return this.t('message.composer.category_inheritance_scope', 'Tous les produits de cette catégorie héritent de ce wizard.');
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
        personalPageIsEdit() {
            return this.personalPageEditStepId != null;
        },
        // The selected step is an editable options group (extra_group) that already exists server-side
        // (has an id) → expose "Modifier les options". A draft step (no id) must be saved first.
        selectedStepIsEditableGroup() {
            return Boolean(this.selectedStep
                && this.selectedStep.source_type === 'extra_group'
                && this.selectedStep.id);
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
        t(key, fallback, params) {
            if (typeof this.$t !== 'function') {
                return fallback;
            }
            // [GOAL_WIZARD_DYNAMIC W7] Forward named params to vue-i18n so
            // interpolated keys (e.g. category_publish_warning {count}) resolve.
            // Keep the safe missing-key fallback: when $t echoes the key back,
            // the JS fallback (already string-interpolated) is used instead.
            const translated = params ? this.$t(key, params) : this.$t(key);
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
            // [GOAL_WIZARD_DYNAMIC W1 / GAP-E] The category builder now populates its
            // source picker too (derived from a representative item server-side). An
            // empty category answers 422 — degrade to an empty picker, never crash the
            // editor (the owner can still compose once the category has a product).
            const endpoint = this.isCategoryComposer
                ? `admin/composer/categories/${this.resolvedEntityId}/available-sources`
                : `admin/composer/items/${this.resolvedEntityId}/available-sources`;

            try {
                const response = await axios.get(endpoint);
                const data = response.data?.data || response.data || {};
                this.availableSources = {
                    item_attribute: Array.isArray(data.item_attribute) ? data.item_attribute : [],
                    extra_group: Array.isArray(data.extra_group) ? data.extra_group : [],
                    addon: Array.isArray(data.addon) ? data.addon : [],
                };
            } catch (error) {
                this.availableSources = { item_attribute: [], extra_group: [], addon: [] };
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
        blankPersonalPage() {
            // [GOAL_WIZARD_DYNAMIC_BUILDER W5] A "personal page" persists every option to a
            // catalog construct (ItemExtra). The PRICE lives per-option on the construct —
            // NEVER a single price on the page/step (NF525 SSOT). The form therefore carries
            // no top-level price field; each option row owns its own price (0 = free).
            return {
                label: '',
                options: [{ name: '', price: 0, description: '' }],
                min_select: 0,
                max_select: null,
                visible_on: ['pos', 'kiosk'],
            };
        },
        openPersonalPage() {
            this.personalPage = this.blankPersonalPage();
            this.personalPageError = '';
            this.personalPageEditStepId = null;
            this.personalPageOpen = true;
        },
        async editPersonalPage(step) {
            // Re-edit an EXISTING options page. Pre-fill from the server (the bound group's options +
            // the step's display props), keyed on the step PK — the SAME binding the PUT will edit.
            const target = step || this.selectedStep;
            if (!target?.id || !this.profile?.id) return;
            this.personalPageError = '';
            this.personalPageEditStepId = target.id;
            this.personalPage = this.blankPersonalPage();
            this.personalPageOpen = true;
            this.personalPageSaving = true;
            try {
                const response = await axios.get(
                    `admin/composer/profiles/${this.profile.id}/personal-page/${target.id}`,
                );
                const data = response.data?.data || {};
                const options = Array.isArray(data.options) && data.options.length
                    ? data.options.map((option) => ({
                        name: String(option.name || ''),
                        price: Number(option.price || 0),
                        description: option.description ? String(option.description) : '',
                    }))
                    : [{ name: '', price: 0, description: '' }];
                this.personalPage = {
                    label: String(data.label || target.label || ''),
                    options,
                    min_select: Number(data.min_select ?? target.min_select ?? 0),
                    max_select: data.max_select == null ? null : Number(data.max_select),
                    visible_on: Array.isArray(data.visible_on) && data.visible_on.length
                        ? [...data.visible_on]
                        : ['pos', 'kiosk'],
                };
            } catch (error) {
                this.personalPageError = error?.response?.data?.message
                    || this.t('message.composer.personal_page_load_failed', 'Impossible de charger la page à modifier.');
            } finally {
                this.personalPageSaving = false;
            }
        },
        closePersonalPage() {
            this.personalPageOpen = false;
            this.personalPageEditStepId = null;
        },
        addPersonalOption() {
            this.personalPage.options.push({ name: '', price: 0, description: '' });
        },
        removePersonalOption(index) {
            if (this.personalPage.options.length <= 1) return;
            this.personalPage.options.splice(index, 1);
        },
        personalPagePayload() {
            const options = this.personalPage.options.map((option) => ({
                name: String(option.name || '').trim(),
                price: Number(option.price || 0),
                description: option.description ? String(option.description).trim() : '',
            }));
            const maxSelect = Number.isFinite(Number(this.personalPage.max_select))
                && this.personalPage.max_select !== null
                && this.personalPage.max_select !== ''
                ? Number(this.personalPage.max_select)
                : options.length;
            return {
                label: String(this.personalPage.label || '').trim(),
                options,
                min_select: Number(this.personalPage.min_select || 0),
                max_select: maxSelect,
                visible_on: Array.isArray(this.personalPage.visible_on) && this.personalPage.visible_on.length
                    ? [...this.personalPage.visible_on]
                    : ['pos', 'kiosk'],
            };
        },
        async submitPersonalPage() {
            this.personalPageError = '';
            const payload = this.personalPagePayload();
            if (!payload.label) {
                this.personalPageError = this.t('message.composer.personal_page_label_required', 'Indiquez un titre de page.');
                return;
            }
            if (!payload.options.length || payload.options.some((option) => !option.name)) {
                this.personalPageError = this.t('message.composer.personal_page_option_name_required', "Chaque option doit avoir un nom.");
                return;
            }
            this.personalPageSaving = true;
            try {
                // The endpoint is route-model-bound to an EXISTING profile. A fresh
                // category/item (the live V1 path) has no profile yet — persist a draft
                // first so profile.id resolves (mirrors publish()).
                if (!this.profile?.id) {
                    await this.saveDraft();
                }
                if (!this.profile?.id) {
                    this.personalPageError = this.t('message.composer.personal_page_no_profile', "Sauvegardez d'abord le wizard.");
                    return;
                }
                if (this.personalPageEditStepId != null) {
                    // Re-edit IN PLACE: PUT to the step's own bound group (server-trusted PK).
                    await axios.put(
                        `admin/composer/profiles/${this.profile.id}/personal-page/${this.personalPageEditStepId}`,
                        payload,
                    );
                    this.personalPageOpen = false;
                    this.personalPageEditStepId = null;
                    alertService.success(this.t('message.composer.personal_page_updated', 'Page personnalisée mise à jour.'));
                } else {
                    await axios.post(`admin/composer/profiles/${this.profile.id}/personal-page`, payload);
                    this.personalPageOpen = false;
                    alertService.success(this.t('message.composer.personal_page_created', 'Page personnalisée créée.'));
                }
                await this.loadProfile();
            } catch (error) {
                if (error?.response?.status === 422) {
                    this.personalPageError = error?.response?.data?.message
                        || this.t('message.composer.personal_page_validation_error', 'Données invalides envoyées au serveur.');
                    return;
                }
                this.personalPageError = error?.response?.data?.message
                    || this.t('message.composer.personal_page_failed', 'Création de la page impossible.');
            } finally {
                this.personalPageSaving = false;
            }
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
            if (!this.profile?.id) return;
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
            if (step.id) {
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
                // [GOAL_WIZARD_DYNAMIC W7] The custom publish-confirm modal (with the
                // category warning body) is the single source of confirmation. A second
                // native window.confirm here was redundant AND silently blocked category
                // publish whenever dialogs are auto-dismissed/suppressed (headless tests,
                // kiosk-mode/locked-down browsers).
                if (!this.profile?.id) {
                    await this.saveDraft();
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
        // [GOAL CMS T-W5b] Delete the whole wizard profile (unpublished only —
        // backend guards published with 409). window.confirm mirrors the
        // category-publish confirm pattern already used in this component.
        async destroyProfile() {
            if (!this.profile?.id) return;
            const confirmed = window.confirm(
                this.t(
                    'message.composer.delete_profile_confirm',
                    'Supprimer définitivement ce wizard (pages + versions) ? Cette action est irréversible.'
                )
            );
            if (!confirmed) return;
            try {
                await axios.delete(`admin/composer/profiles/${this.profile.id}`);
                this.returnToItem();
            } catch (error) {
                this.loadError = error?.response?.data?.message
                    || this.t('label.composer.delete_profile_error', 'Suppression impossible.');
            }
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
            // [GOAL_WIZARD_DYNAMIC W7] Item-owned wizards WIN over category-owned
            // (canonical precedence — see MenuProjectionComposerProfileTest
            // "item-owned wins over category-owned"). Publishing a category wizard
            // does NOT replace per-item customizations; it only fills items that
            // have no own wizard. The old copy ("va remplacer les wizards
            // personnalisés") was factually wrong and alarming.
            const count = this.item?.product_count || this.item?.products_count || this.item?.items_count || 'N';
            return this.t(
                'message.composer.category_publish_warning',
                `Ce wizard de catégorie s'appliquera automatiquement aux produits de cette catégorie qui n'ont PAS leur propre wizard (${count} produit(s) au total). Les produits ayant déjà un wizard personnalisé conservent le leur (priorité au wizard produit). Publier ?`,
                { count }
            );
        },
    },
};
</script>
