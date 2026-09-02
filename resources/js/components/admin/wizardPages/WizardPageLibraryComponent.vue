<template>
    <div class="wp" data-testid="wizard-pages-page">
        <header class="wp__header">
            <div>
                <p class="wp__eyebrow">Parcours de commande</p>
                <h2>Pages de wizard</h2>
                <p class="wp__subtitle">
                    Une page = une question posée au client, avec ses choix et leurs prix.
                    Les catégories réutilisent ces pages ; publier écrit les choix sur chaque produit.
                </p>
            </div>
            <button type="button" class="db-btn py-2 bg-rose-700 text-white" data-testid="wizard-pages-add"
                @click="startCreate">
                <i class="lab lab-add-circle" aria-hidden="true"></i>
                <span>Nouvelle page</span>
            </button>
        </header>

        <div v-if="feedback" class="wp__feedback" :class="`wp__feedback--${feedback.type}`" role="alert"
            data-testid="wizard-pages-feedback">
            <span>{{ feedback.text }}</span>
            <button type="button" @click="feedback = null" aria-label="Fermer">×</button>
        </div>

        <section class="wp__body">
            <aside class="wp__list">
                <div class="wp__list-head">
                    <h3>Bibliothèque</h3>
                    <span class="wp__counter">{{ libraryPages.length }}</span>
                </div>
                <p v-if="!loading && libraryPages.length === 0" class="wp__empty">
                    Aucune page pour l'instant. Créez-en une : « Pain », « Sauces », « Suppléments »…
                </p>
                <button v-for="page in libraryPages" :key="page.id" type="button" class="wp__card"
                    :class="{ 'wp__card--active': selectedId === page.id }"
                    :data-testid="`wizard-page-row-${page.id}`" @click="select(page)">
                    <span class="wp__card-title">{{ page.label }}</span>
                    <span class="wp__card-meta">
                        {{ kindLabel(page.kind) }} · {{ page.choices_count ?? 0 }} choix
                        <template v-if="page.usage_count"> · {{ page.usage_count }} catégorie(s)</template>
                    </span>
                    <span v-if="!page.is_active" class="wp__badge wp__badge--off">Désactivée</span>
                </button>

                <template v-if="privatePages.length">
                    <div class="wp__list-head wp__list-head--sub">
                        <h3>Personnalisées</h3>
                        <span class="wp__counter">{{ privatePages.length }}</span>
                    </div>
                    <button v-for="page in privatePages" :key="page.id" type="button" class="wp__card wp__card--private"
                        :class="{ 'wp__card--active': selectedId === page.id }"
                        :data-testid="`wizard-page-row-${page.id}`" @click="select(page)">
                        <span class="wp__card-title">{{ page.label }}</span>
                        <span class="wp__card-meta">
                            {{ kindLabel(page.kind) }} · {{ page.choices_count ?? 0 }} choix ·
                            {{ page.owner_category_name || 'catégorie' }}
                        </span>
                    </button>
                </template>
            </aside>

            <main class="wp__editor">
                <div v-if="!draft" class="wp__placeholder" data-testid="wizard-pages-placeholder">
                    <h3>Choisissez une page à gauche</h3>
                    <p>
                        Ou créez-en une : donnez-lui un nom, dites d'où viennent les choix, puis listez-les
                        avec leur prix. Elle sera ensuite disponible pour toutes vos catégories.
                    </p>
                </div>

                <form v-else class="wp__form" @submit.prevent="save">
                    <div class="wp__form-head">
                        <h3>{{ draft.id ? 'Modifier la page' : 'Nouvelle page' }}</h3>
                        <span v-if="draft.id && !draft.is_library" class="wp__badge">
                            Personnalisée · {{ draft.owner_category_name || 'catégorie' }}
                        </span>
                    </div>

                    <div class="wp__grid">
                        <label class="wp__field">
                            <span class="db-field-title required">Nom affiché au client</span>
                            <input v-model.trim="draft.label" type="text" class="db-field-control" required
                                data-testid="wizard-page-label" placeholder="Choisis ton pain" />
                        </label>

                        <label class="wp__field">
                            <span class="db-field-title">Type de page</span>
                            <select v-model="draft.kind" class="db-field-control" data-testid="wizard-page-kind"
                                :disabled="Boolean(draft.id)" @change="onKindChange">
                                <option v-for="(label, value) in kinds" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <small class="wp__hint">{{ kindHint }}</small>
                        </label>
                    </div>

                    <div class="wp__grid">
                        <label class="wp__field">
                            <span class="db-field-title">Choix minimum</span>
                            <input v-model.number="draft.min_select" type="number" min="0" max="20"
                                class="db-field-control" data-testid="wizard-page-min" @change="normalizeRange" />
                        </label>
                        <label class="wp__field">
                            <span class="db-field-title">Choix maximum</span>
                            <input v-model.number="draft.max_select" type="number" min="0" max="20"
                                class="db-field-control" data-testid="wizard-page-max" @change="normalizeRange" />
                        </label>
                    </div>
                    <p class="wp__rule" data-testid="wizard-page-rule">{{ ruleSummary }}</p>

                    <fieldset class="wp__surfaces">
                        <legend class="db-field-title">Visible sur</legend>
                        <label><input type="checkbox" :checked="isVisible('pos')" @change="toggleSurface('pos')" /> Caisse</label>
                        <label><input type="checkbox" :checked="isVisible('kiosk')" @change="toggleSurface('kiosk')" /> Borne</label>
                        <label class="wp__switch">
                            <input type="checkbox" v-model="draft.is_active" data-testid="wizard-page-active" />
                            Page active
                        </label>
                    </fieldset>

                    <div class="wp__choices">
                        <div class="wp__choices-head">
                            <h4>Choix proposés</h4>
                            <button type="button" class="db-btn-outline" data-testid="wizard-page-add-choice"
                                @click="addChoice">
                                <i class="lab lab-add-circle" aria-hidden="true"></i> Ajouter un choix
                            </button>
                        </div>

                        <p v-if="draft.source_type === 'addon'" class="wp__hint">
                            Une page « formule » propose des produits du catalogue : choisissez-les dans la liste.
                        </p>

                        <table class="wp__table">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th class="wp__col-price">Prix (€)</th>
                                    <th class="wp__col-state">Actif</th>
                                    <th class="wp__col-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(choice, index) in draft.choices" :key="choice._uid"
                                    :data-testid="`wizard-page-choice-${index}`">
                                    <td>
                                        <select v-if="draft.source_type === 'addon'" v-model.number="choice.addon_item_id"
                                            class="db-field-control"
                                            :aria-label="`Produit du choix ${index + 1}`"
                                            @change="onAddonPicked(choice)">
                                            <option :value="null">—</option>
                                            <option v-for="item in addonCandidates" :key="item.id" :value="item.id">
                                                {{ item.name }}
                                            </option>
                                        </select>
                                        <input v-else v-model.trim="choice.name" type="text" class="db-field-control"
                                            :aria-label="`Nom du choix ${index + 1}`"
                                            :data-testid="`wizard-page-choice-name-${index}`" placeholder="Cheddar" />
                                    </td>
                                    <td>
                                        <input v-model.number="choice.price" type="number" step="0.01" min="0"
                                            class="db-field-control"
                                            :aria-label="`Prix en euros du choix ${choice.name || index + 1}`"
                                            :data-testid="`wizard-page-choice-price-${index}`" />
                                    </td>
                                    <td class="wp__col-state">
                                        <input type="checkbox" :checked="choice.status === 5"
                                            :aria-label="`Proposer le choix ${choice.name || index + 1} aux clients`"
                                            :data-testid="`wizard-page-choice-active-${index}`"
                                            @change="choice.status = choice.status === 5 ? 10 : 5" />
                                    </td>
                                    <td class="wp__col-action">
                                        <button type="button" class="db-table-action delete" aria-label="Retirer ce choix"
                                            :data-testid="`wizard-page-choice-remove-${index}`"
                                            @click="draft.choices.splice(index, 1)">
                                            <i class="lab lab-trash" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="draft.choices.length === 0">
                                    <td colspan="4" class="wp__table-empty">
                                        Aucun choix : en caisse, cette page serait vide.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="usage.length" class="wp__usage" data-testid="wizard-page-usage">
                        <strong>Utilisée par :</strong>
                        <span v-for="entry in usage" :key="entry.id" class="wp__usage-chip">
                            {{ entry.name }}<template v-if="entry.published"> · en caisse</template>
                        </span>
                        <p class="wp__hint">
                            Modifier les choix ici les met à jour partout à la prochaine publication de ces catégories.
                        </p>
                    </div>

                    <div class="wp__actions">
                        <button type="submit" class="db-btn py-2 bg-green-700 text-white" :disabled="saving"
                            data-testid="wizard-page-save">
                            <i class="lab lab-save" aria-hidden="true"></i>
                            <span>{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</span>
                        </button>
                        <button v-if="draft.id" type="button" class="db-btn-outline" data-testid="wizard-page-delete"
                            @click="destroy">
                            <i class="lab lab-trash" aria-hidden="true"></i>
                            <span>Supprimer</span>
                        </button>
                        <button type="button" class="db-btn-outline" @click="cancel">Annuler</button>
                    </div>
                </form>
            </main>
        </section>
    </div>
</template>

<script>
import axios from 'axios';
import alertService from '../../../services/alertService';

let uid = 0;
const nextUid = () => `choice-${++uid}`;

export default {
    name: 'WizardPageLibraryComponent',
    data() {
        return {
            selectedId: null,
            draft: null,
            savedSnapshot: 'null',
            saving: false,
            usage: [],
            addonCandidates: [],
            feedback: null,
            kinds: {
                pain: 'Pain / base',
                taille: 'Taille',
                viande: 'Viande / protéine',
                sauce: 'Sauce',
                garnitures: 'Garnitures',
                supplements: 'Suppléments',
                menu: 'Formule / boisson',
                generic: 'Autre question',
            },
            kindSource: {
                pain: 'item_attribute',
                taille: 'item_attribute',
                viande: 'item_attribute',
                sauce: 'item_attribute',
                garnitures: 'extra_group',
                supplements: 'extra_group',
                menu: 'addon',
                generic: 'item_attribute',
            },
        };
    },
    computed: {
        loading() {
            return this.$store.getters['wizardPage/loading'];
        },
        pages() {
            return this.$store.getters['wizardPage/lists'];
        },
        libraryPages() {
            return this.pages.filter((page) => page.is_library);
        },
        privatePages() {
            return this.pages.filter((page) => !page.is_library);
        },
        kindHint() {
            if (!this.draft) return '';
            const source = this.draft.source_type;
            if (source === 'extra_group') return 'Les choix deviennent des suppléments payants du produit.';
            if (source === 'addon') return 'Les choix sont des produits du catalogue (boisson, frites…).';
            return 'Les choix deviennent des variantes du produit (sans coût, sauf prix saisi).';
        },
        ruleSummary() {
            if (!this.draft) return '';
            const min = Number(this.draft.min_select) || 0;
            const max = Number(this.draft.max_select) || 0;
            if (min === 0 && max === 1) return 'Le client peut choisir 1 article, ou passer.';
            if (min === 0) return `Le client peut choisir jusqu'à ${max} articles, ou passer.`;
            if (min === max) return `Le client doit choisir exactement ${min} article${min > 1 ? 's' : ''}.`;
            return `Le client doit choisir entre ${min} et ${max} articles.`;
        },
    },
    mounted() {
        this.refresh();
        this.loadAddonCandidates();
    },
    methods: {
        refresh() {
            return this.$store.dispatch('wizardPage/lists', {}).catch(() => {
                this.feedback = { type: 'error', text: 'Impossible de charger les pages.' };
            });
        },
        loadAddonCandidates() {
            axios.get('admin/item', { params: { paginate: 0, order_column: 'name', order_type: 'asc' } })
                .then((res) => {
                    this.addonCandidates = (res.data?.data || []).map((item) => ({ id: item.id, name: item.name }));
                })
                .catch(() => { this.addonCandidates = []; });
        },
        kindLabel(kind) {
            return this.kinds[kind] || kind;
        },
        /**
         * [2026-09-02 · audit adverse P1-2] Cliquer une autre page (ou « Nouvelle page ») écrasait le
         * brouillon en cours, sans un mot. On demande avant de jeter.
         */
        isDirty() {
            return Boolean(this.draft) && JSON.stringify(this.draft) !== this.savedSnapshot;
        },
        confirmDiscard() {
            if (!this.isDirty()) {
                return true;
            }
            if (typeof window === 'undefined' || typeof window.confirm !== 'function') {
                return true;
            }

            return window.confirm('Vous avez des modifications non enregistrées sur cette page. Les abandonner ?');
        },
        snapshot() {
            this.savedSnapshot = JSON.stringify(this.draft);
        },
        startCreate() {
            if (!this.confirmDiscard()) {
                return;
            }
            this.selectedId = null;
            this.usage = [];
            this.draft = {
                id: null,
                label: '',
                kind: 'generic',
                source_type: 'item_attribute',
                min_select: 0,
                max_select: 1,
                visible_on: ['pos', 'kiosk'],
                is_active: true,
                is_library: true,
                choices: [],
            };
            this.snapshot();
        },
        select(page) {
            if (!this.confirmDiscard()) {
                return;
            }
            this.selectedId = page.id;
            this.$store.dispatch('wizardPage/show', page.id).then((res) => {
                const data = res.data.data;
                this.usage = data.usage || [];
                this.draft = {
                    id: data.id,
                    label: data.label,
                    kind: data.kind,
                    source_type: data.source_type,
                    min_select: data.min_select,
                    max_select: data.max_select,
                    visible_on: Array.isArray(data.visible_on) && data.visible_on.length ? [...data.visible_on] : ['pos', 'kiosk'],
                    is_active: data.is_active,
                    is_library: data.is_library,
                    owner_category_name: data.owner_category_name,
                    choices: (data.choices || []).map((choice) => ({ ...choice, _uid: nextUid() })),
                };
                this.snapshot();
            }).catch(() => {
                this.feedback = { type: 'error', text: 'Impossible d\'ouvrir cette page.' };
            });
        },
        onKindChange() {
            this.draft.source_type = this.kindSource[this.draft.kind] || 'item_attribute';
        },
        normalizeRange() {
            const min = Math.max(0, Number(this.draft.min_select) || 0);
            const max = Math.max(min, Number(this.draft.max_select) || 0);
            this.draft.min_select = min;
            this.draft.max_select = max;
        },
        isVisible(surface) {
            return Array.isArray(this.draft?.visible_on) && this.draft.visible_on.includes(surface);
        },
        toggleSurface(surface) {
            const current = Array.isArray(this.draft.visible_on) ? [...this.draft.visible_on] : [];
            this.draft.visible_on = current.includes(surface)
                ? current.filter((entry) => entry !== surface)
                : [...current, surface];
        },
        addChoice() {
            this.draft.choices.push({ _uid: nextUid(), id: null, name: '', price: 0, status: 5, addon_item_id: null });
        },
        onAddonPicked(choice) {
            const match = this.addonCandidates.find((item) => item.id === choice.addon_item_id);
            if (match) {
                choice.name = match.name;
            }
        },
        save() {
            this.normalizeRange();
            const form = {
                label: this.draft.label,
                kind: this.draft.kind,
                source_type: this.draft.source_type,
                min_select: this.draft.min_select,
                max_select: this.draft.max_select,
                visible_on: this.draft.visible_on,
                is_active: this.draft.is_active,
                choices: this.draft.choices
                    .filter((choice) => String(choice.name || '').trim() !== '')
                    .map((choice, index) => ({
                        id: choice.id || null,
                        name: choice.name,
                        price: Number(choice.price) || 0,
                        status: choice.status === 10 ? 10 : 5,
                        addon_item_id: choice.addon_item_id || null,
                        sort: index,
                    })),
            };

            this.saving = true;
            this.$store.dispatch('wizardPage/save', { id: this.draft.id, form })
                .then((res) => {
                    const saved = res.data.data;
                    this.feedback = { type: 'success', text: `Page « ${saved.label} » enregistrée.` };
                    // Le brouillon vient d'être écrit : il redevient « propre », sinon le rechargement
                    // ci-dessous demanderait d'abandonner des modifications déjà enregistrées.
                    this.snapshot();
                    return this.refresh().then(() => this.select(saved));
                })
                .catch((err) => {
                    const errors = err?.response?.data?.errors;
                    const first = errors ? Object.values(errors).flat()[0] : null;
                    this.feedback = { type: 'error', text: first || err?.response?.data?.message || 'Enregistrement impossible.' };
                })
                .finally(() => { this.saving = false; });
        },
        destroy() {
            if (typeof window !== 'undefined' && typeof window.confirm === 'function'
                && !window.confirm(`Supprimer la page « ${this.draft.label} » ?`)) {
                return;
            }
            this.$store.dispatch('wizardPage/destroy', this.draft.id)
                .then(() => {
                    alertService.success('Page supprimée.');
                    this.draft = null;
                    this.selectedId = null;
                    return this.refresh();
                })
                .catch((err) => {
                    const errors = err?.response?.data?.errors;
                    const first = errors ? Object.values(errors).flat()[0] : null;
                    this.feedback = { type: 'error', text: first || err?.response?.data?.message || 'Suppression impossible.' };
                });
        },
        cancel() {
            this.draft = null;
            this.selectedId = null;
            this.usage = [];
        },
    },
};
</script>

<style scoped>
.wp { display: grid; gap: 14px; }
.wp__header {
    display: flex; justify-content: space-between; gap: 16px; align-items: start;
    border: 1px solid #e2e8f0; border-left: 4px solid #dc2626; background: #fff;
    border-radius: 10px; padding: 16px;
}
.wp__eyebrow { margin: 0 0 4px; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; color: #dc2626; font-weight: 700; }
.wp__header h2 { margin: 0; font-size: 22px; font-weight: 800; }
.wp__subtitle { margin: 6px 0 0; color: #475569; max-width: 70ch; }
.wp__feedback { border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; gap: 12px; }
.wp__feedback--success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.wp__feedback--error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.wp__feedback button { font-size: 18px; line-height: 1; }
.wp__body { display: grid; grid-template-columns: minmax(240px, 320px) 1fr; gap: 14px; align-items: start; }
.wp__list, .wp__editor { border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: 12px; }
.wp__list { display: grid; gap: 8px; align-content: start; }
.wp__list-head { display: flex; justify-content: space-between; align-items: center; }
.wp__list-head--sub { margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px; }
.wp__list-head h3 { margin: 0; font-size: 15px; font-weight: 800; }
.wp__counter { background: #e2e8f0; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; }
.wp__empty { color: #64748b; font-size: 13px; margin: 0; }
.wp__card {
    border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; text-align: left;
    padding: 10px; display: grid; gap: 3px; width: 100%;
}
.wp__card--private { background: #fffbeb; border-color: #fde68a; }
.wp__card--active { border-color: #dc2626; background: #fff1f2; }
.wp__card-title { font-size: 13px; font-weight: 700; color: #0f172a; }
.wp__card-meta { font-size: 12px; color: #475569; }
.wp__badge { align-self: start; font-size: 11px; font-weight: 700; background: #e2e8f0; color: #334155; border-radius: 999px; padding: 2px 8px; }
.wp__badge--off { background: #fee2e2; color: #991b1b; }
.wp__editor { min-height: 420px; }
.wp__placeholder { padding: 32px; text-align: center; color: #64748b; }
.wp__placeholder h3 { margin: 0 0 8px; font-size: 16px; font-weight: 800; color: #0f172a; }
.wp__placeholder p { margin: 0 auto; max-width: 52ch; }
.wp__form { display: grid; gap: 14px; }
.wp__form-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.wp__form-head h3 { margin: 0; font-size: 16px; font-weight: 800; }
.wp__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.wp__field { display: grid; gap: 4px; }
.wp__hint { color: #64748b; font-size: 12px; }
.wp__rule { margin: -6px 0 0; color: #334155; font-size: 13px; font-weight: 600; }
.wp__surfaces { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.wp__surfaces label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #334155; }
.wp__switch { margin-left: auto; }
.wp__choices { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; display: grid; gap: 10px; }
.wp__choices-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.wp__choices-head h4 { margin: 0; font-size: 14px; font-weight: 800; }
.wp__table { width: 100%; border-collapse: collapse; }
.wp__table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: 4px 6px; }
.wp__table td { padding: 4px 6px; vertical-align: middle; }
.wp__col-price { width: 130px; }
.wp__col-state { width: 70px; text-align: center; }
.wp__col-action { width: 60px; text-align: right; }
.wp__table-empty { color: #64748b; font-size: 13px; padding: 12px 6px; }
.wp__usage { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 10px 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.wp__usage-chip { background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
.wp__usage .wp__hint { flex-basis: 100%; margin: 0; }
.wp__actions { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 1024px) { .wp__body { grid-template-columns: 1fr; } }
</style>
