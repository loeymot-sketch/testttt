<template>
    <section class="space-y-4" data-testid="admin-composer-root">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-heading">Composition produit</h3>
                <p class="text-xs text-gray-500">Profil de wizard sans prix duplique</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="db-btn bg-primary text-white" data-testid="admin-composer-save-draft" @click="save">Publier brouillon</button>
                <button v-if="profile && !profile.is_published" type="button" class="db-btn bg-[#1AB759] text-white" data-testid="admin-composer-publish" @click="publish">Publier</button>
                <button v-if="profile && profile.is_published" type="button" class="db-btn-outline" data-testid="admin-composer-unpublish" @click="unpublish">Depublier</button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <select v-model="form.template" class="db-field-control" data-testid="admin-composer-template">
                <option value="simple">simple</option>
                <option value="sandwich">sandwich</option>
                <option value="tacos">tacos</option>
                <option value="assiette">assiette</option>
                <option value="snacking">snacking</option>
                <option value="menu">menu</option>
                <option value="custom">custom</option>
            </select>
            <input v-model.number="form.branch_id_scope" type="number" min="1" class="db-field-control" data-testid="admin-composer-branch-scope" placeholder="Branch scope optionnel" />
        </div>

        <div class="space-y-3">
            <StepEditorComponent
                v-for="(step, index) in form.steps"
                :key="index"
                v-model="form.steps[index]"
                :index="index"
            />
            <button type="button" class="db-btn-outline" data-testid="admin-composer-add-step" @click="addStep">Ajouter une etape</button>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <StepPreviewComponent v-for="(step, index) in form.steps" :key="`preview-${index}`" :step="step" :index="index" />
        </div>
    </section>
</template>

<script>
import StepEditorComponent from './StepEditorComponent';
import StepPreviewComponent from './StepPreviewComponent';
import alertService from '../../../../services/alertService';
import axios from 'axios';

export default {
    name: 'ProductComposerEditorComponent',
    components: {
        StepEditorComponent,
        StepPreviewComponent,
    },
    props: {
        itemId: {
            type: [Number, String],
            required: true,
        },
    },
    data() {
        return {
            profile: null,
            form: {
                template: 'custom',
                branch_id_scope: null,
                steps: [],
            },
        };
    },
    mounted() {
        this.load();
    },
    methods: {
        load() {
            axios.get(`/admin/composer/items/${this.itemId}/profile`)
                .then((res) => {
                    this.profile = res.data.data;
                    if (this.profile) {
                        this.form.template = this.profile.template;
                        this.form.branch_id_scope = this.profile.branch_id_scope;
                        this.form.steps = (this.profile.steps || []).map((step) => ({ ...step }));
                    }
                })
                .catch(() => {});
        },
        addStep() {
            this.form.steps.push({
                step_key: `step_${this.form.steps.length + 1}`,
                label: 'Nouvelle etape',
                source_type: 'item_attribute',
                source_ref: '',
                min_select: 0,
                max_select: 1,
                allow_repeat: false,
                visible_on: ['pos', 'kiosk'],
                stockable_choices: false,
                position: this.form.steps.length,
                is_active: true,
                addon_role: null,
            });
        },
        save() {
            axios.post(`/admin/composer/items/${this.itemId}/profile`, this.form)
                .then((res) => {
                    this.profile = res.data.data;
                    alertService.success('Composition enregistree');
                })
                .catch((err) => alertService.error(err.response?.data?.message || 'Composition refusee'));
        },
        publish() {
            axios.post(`/admin/composer/profiles/${this.profile.id}/publish`)
                .then((res) => {
                    this.profile = res.data.data;
                    alertService.success('Composition publiee');
                });
        },
        unpublish() {
            axios.post(`/admin/composer/profiles/${this.profile.id}/unpublish`)
                .then((res) => {
                    this.profile = res.data.data;
                    alertService.success('Composition depubliee');
                });
        },
    },
};
</script>
