<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent :props="addButton" />

    <div id="categoryModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <!-- [GOAL POLISH T-P2.3] titre contextuel create/edit -->
                <h3 class="modal-title">{{ isEditing ? $t('label.edit_category_title') : $t('label.add_category_title') }}</h3>
                <button :aria-label="$t('button.close')" class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="props.form.name" v-bind:class="errors.name ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" data-testid="admin-category-form-name">
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>


                        <div class="form-col-12 sm:form-col-6">
                            <label for="parent_id" class="db-field-title">{{ $t('label.parent_category') }}</label>
                            <select v-model="props.form.parent_id" id="parent_id" class="db-field-control"
                                v-bind:class="errors.parent_id ? 'invalid' : ''"
                                data-testid="admin-category-form-parent">
                                <option :value="null">{{ $t('label.none') }}</option>
                                <option v-for="parent in parentOptions" :key="parent.id" :value="parent.id">
                                    {{ parent.name }}
                                </option>
                            </select>
                            <!-- [GOAL POLISH T-P2.5] explique le select vide (verrou depth-3) -->
                            <small v-if="parentLockedByChildren" class="db-field-hint" data-testid="admin-category-parent-locked-hint">
                                {{ $t('message.parent_locked_has_children') }}
                            </small>
                            <small v-else class="db-field-hint">{{ $t('message.subcategory_wizard_hint') }}</small>
                            <small class="db-field-alert" v-if="errors.parent_id">{{ errors.parent_id[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="image" class="db-field-title">{{ $t('label.image') }} (74px,48px)</label>
                            <!-- [GOAL POLISH T-P2.2] input natif masqué → contrôle 100% FR -->
                            <div class="db-field-control flex items-center gap-2 cursor-pointer"
                                v-bind:class="errors.image ? 'invalid' : ''"
                                role="button" tabindex="0"
                                @click="$refs.imageProperty.click()"
                                @keydown.enter.prevent="$refs.imageProperty.click()">
                                <span class="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                    {{ $t('button.choose_file') }}
                                </span>
                                <span class="truncate text-xs text-slate-500" data-testid="admin-category-form-image-name">
                                    {{ image && image.name ? image.name : $t('label.no_file_chosen') }}
                                </span>
                            </div>
                            <input @change="changeImage" id="image" type="file" class="hidden"
                                ref="imageProperty" accept="image/png, image/jpeg, image/jpg" data-testid="admin-category-form-image">
                            <small class="db-field-alert" v-if="errors.image">{{ errors.image[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="active">{{ $t('label.status') }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.ACTIVE" v-model="props.form.status" id="active"
                                            type="radio" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="active" class="db-field-label">{{ $t('label.active') }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.INACTIVE" v-model="props.form.status" type="radio"
                                            id="inactive" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="inactive" class="db-field-label">{{ $t('label.inactive') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12">
                            <label for="description" class="db-field-title">{{
                                $t("label.description")
                            }}</label>
                            <textarea v-model="props.form.description" v-bind:class="errors.description ? 'invalid' : ''"
                                id="description" class="db-field-control"></textarea>
                            <small class="db-field-alert" v-if="errors.description">{{ errors.description[0] }}</small>
                        </div>

                        <div class="form-col-12 advanced-section">
                            <button
                                type="button"
                                class="advanced-section__toggle"
                                @click="showAdvanced = !showAdvanced"
                                :aria-expanded="showAdvanced.toString()"
                                data-testid="item-category-form-advanced-toggle"
                            >
                                <span class="advanced-section__chevron">
                                    {{ showAdvanced ? '▾' : '▸' }}
                                </span>
                                <span>{{ $t('studio.advanced_settings') }}</span>
                            </button>
                            <div
                                v-show="showAdvanced"
                                class="advanced-section__body"
                                data-testid="item-category-form-advanced-body"
                            >
                                <div class="form-row">
                                <!-- [SPRINT 7] Wizard template pour le POS et la borne -->
                                <div class="form-col-12 sm:form-col-6">
                                    <label for="wizard_template" class="db-field-title">{{ $t('label.wizard_template') || 'Type de wizard' }}</label>
                                    <select v-model="props.form.wizard_template" id="wizard_template" class="db-field-control">
                                        <option value="simple">Simple (pas de wizard)</option>
                                        <option value="tacos">Tacos</option>
                                        <option value="sandwich">Sandwich</option>
                                        <option value="burger">Burger</option>
                                        <option value="assiette">Assiette</option>
                                        <option value="salade">Salade</option>
                                        <option value="omelette">Omelette</option>
                                        <option value="snacking">Snacking (Wings/Tenders)</option>
                                    </select>
                                </div>

                                <div class="form-col-12 sm:form-col-6">
                                    <label class="db-field-title">{{ $t('label.has_menu') || 'Propose un menu (frites+boisson)' }}</label>
                                    <div class="db-field-radio-group">
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="1" v-model="props.form.has_menu" type="radio" id="has_menu_yes" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="has_menu_yes" class="db-field-label">{{ $t('label.yes') }}</label>
                                        </div>
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="0" v-model="props.form.has_menu" type="radio" id="has_menu_no" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="has_menu_no" class="db-field-label">{{ $t('label.no') }}</label>
                                        </div>
                                    </div>
                                </div>

                                <!-- [Phase A] Kiosk upsell — pool + skip screen (Splash suggestion_config) -->
                                <div class="form-col-12 sm:form-col-6">
                                    <label class="db-field-title">Borne — proposer ces articles en suggestion panier</label>
                                    <div class="db-field-radio-group">
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="1" v-model="props.form.kiosk_upsell_include" type="radio" id="kiosk_upsell_inc_yes" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="kiosk_upsell_inc_yes" class="db-field-label">{{ $t('label.yes') }}</label>
                                        </div>
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="0" v-model="props.form.kiosk_upsell_include" type="radio" id="kiosk_upsell_inc_no" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="kiosk_upsell_inc_no" class="db-field-label">{{ $t('label.no') }}</label>
                                        </div>
                                    </div>
                                    <small class="db-field-alert text-slate-500">Si « Non », aucun article de cette catégorie n’apparaît dans l’écran « Et pour terminer ? ».</small>
                                </div>
                                <div class="form-col-12 sm:form-col-6">
                                    <label class="db-field-title">Borne — sauter l’écran suggestion si le panier n’a que cette catégorie</label>
                                    <div class="db-field-radio-group">
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="1" v-model="props.form.kiosk_upsell_skip_after_cart" type="radio" id="kiosk_skip_yes" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="kiosk_skip_yes" class="db-field-label">{{ $t('label.yes') }}</label>
                                        </div>
                                        <div class="db-field-radio">
                                            <div class="custom-radio">
                                                <input :value="0" v-model="props.form.kiosk_upsell_skip_after_cart" type="radio" id="kiosk_skip_no" class="custom-radio-field">
                                                <span class="custom-radio-span"></span>
                                            </div>
                                            <label for="kiosk_skip_no" class="db-field-label">{{ $t('label.no') }}</label>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" @click="reset">
                                    <i class="lab lab-close"></i>
                                    <span>{{ $t('button.close') }}</span>
                                </button>

                                <button type="submit" class="db-btn py-2 text-white bg-primary" data-testid="admin-category-form-save">
                                    <i class="lab lab-save"></i>
                                    <span>{{ $t('button.save') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
<script>
import SmModalCreateComponent from "../../components/buttons/SmModalCreateComponent";
import LoadingComponent from "../../components/LoadingComponent";
import statusEnum from "../../../../enums/modules/statusEnum";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "ItemCategoryCreateComponent",
    components: { SmModalCreateComponent, LoadingComponent },
    props: ['props'],
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                }
            },
            image: "",
            errors: {},
            showAdvanced: false,
            // [heal P2-1] full unpaginated category list for the parent
            // select (the table's store list is paginated at 50 — beyond
            // that, parents would silently vanish from the dropdown).
            allCategories: null,
        }
    },
    mounted() {
        this.refreshAllCategories();
    },
    computed: {
        addButton: function () {
            return { title: this.$t('button.add_item_category') };
        },
        isEditing: function () {
            return Boolean(this.$store.getters['itemCategory/temp'].isEditing);
        },
        // [GOAL POLISH T-P2.5] vrai uniquement quand le verrou anti-depth-3
        // a vidé la liste (catégorie éditée AVEC enfants).
        parentLockedByChildren: function () {
            const editingId = this.$store.getters['itemCategory/temp'].temp_id;
            if (editingId === null || editingId === undefined) return false;
            const byId = {};
            (Array.isArray(this.allCategories) ? this.allCategories : [])
                .concat(this.$store.getters['itemCategory/lists'] || [])
                .forEach((category) => { byId[category.id] = category; });
            return Object.values(byId).some((category) => Number(category.parent_id) === Number(editingId));
        },
        // [GOAL CMS C1.1 + heal P1-1] Eligible parents = top-level categories
        // only (2-level hierarchy enforced backend by
        // ItemCategoryHierarchyService) minus the category being edited (no
        // self-parent). A category that HAS children cannot itself get a
        // parent (depth 3) — backend rejects it, so hide the select too.
        parentOptions: function () {
            // [heal E2E A-001] UNION snapshot complet + liste du store (le
            // store est rafraîchi après chaque save : une sous-catégorie
            // créée modal-ouvert est donc TOUJOURS vue par le verrou
            // anti-depth-3, même si le snapshot mounted() est antérieur).
            const byId = {};
            (Array.isArray(this.allCategories) ? this.allCategories : [])
                .concat(this.$store.getters['itemCategory/lists'] || [])
                .forEach((category) => { byId[category.id] = category; });
            const lists = Object.values(byId);
            const editingId = this.$store.getters['itemCategory/temp'].temp_id;
            const editedHasChildren = editingId !== null && editingId !== undefined
                && lists.some((category) => Number(category.parent_id) === Number(editingId));
            if (editedHasChildren) {
                return [];
            }
            return lists.filter(
                (category) => !category.parent_id && category.id !== editingId
            );
        }
    },
    methods: {
        // [heal E2E A-001] full unpaginated snapshot, refreshed after each
        // save so the anti-depth-3 lock always sees fresh children.
        refreshAllCategories: function () {
            const http = typeof axios !== 'undefined' ? axios : (typeof window !== 'undefined' ? window.axios : null);
            if (!http) return; // test env without global axios → fallback = store lists
            http.get('admin/setting/item-category', { params: { paginate: 0, status: 5 } })
                .then((response) => {
                    this.allCategories = response.data?.data || [];
                })
                .catch(() => {
                    this.allCategories = null;
                });
        },
        changeImage: function (e) {
            this.image = e.target.files[0];
        },
        reset: function () {
            appService.modalHide();
            this.showAdvanced = false;
            this.$store.dispatch('itemCategory/reset').then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                description: "",
                status: statusEnum.ACTIVE,
                parent_id: null,
                wizard_template: 'simple',
                has_menu: 0,
                default_menu_kiosk: 0,
                sauce_included_menu: 0,
                kiosk_upsell_include: 1,
                kiosk_upsell_skip_after_cart: 0,
            }
            if (this.image) {
                this.image = "";
                this.$refs.imageProperty.value = null;
            }
        },

        save: function () {
            try {
                const fd = new FormData();
                fd.append('name', this.props.form.name);
                fd.append('status', this.props.form.status);
                fd.append('description', this.props.form.description);
                // Empty string is converted to null backend-side
                // (ConvertEmptyStringsToNull) so clearing the parent works.
                fd.append('parent_id', this.props.form.parent_id ?? '');
                fd.append('wizard_template', this.props.form.wizard_template || 'simple');
                fd.append('has_menu', this.props.form.has_menu ?? 0);
                fd.append('default_menu_kiosk', this.props.form.default_menu_kiosk ?? 0);
                fd.append('sauce_included_menu', this.props.form.sauce_included_menu ?? 0);
                fd.append('kiosk_upsell_include', this.props.form.kiosk_upsell_include ?? 1);
                fd.append('kiosk_upsell_skip_after_cart', this.props.form.kiosk_upsell_skip_after_cart ?? 0);
                if (this.image) {
                    fd.append('image', this.image);
                }

                const tempId = this.$store.getters['itemCategory/temp'].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch('itemCategory/save', {
                    form: fd,
                    search: this.props.search
                }).then((res) => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip((tempId === null ? 0 : 1), this.$t('menu.item_categories'));
                    this.refreshAllCategories();
                    this.props.form = {
                        name: "",
                        description: "",
                        status: statusEnum.ACTIVE,
                        parent_id: null,
                        wizard_template: 'simple',
                        has_menu: 0,
                        default_menu_kiosk: 0,
                        sauce_included_menu: 0,
                        kiosk_upsell_include: 1,
                        kiosk_upsell_skip_after_cart: 0,
                    }
                    this.image = "";
                    this.errors = {};
                    this.$refs.imageProperty.value = null;
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response.data.errors;
                })
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err)
            }
        }
    }
}
</script>

<style scoped>
.advanced-section {
    margin-top: 16px;
    border-top: 1px solid #e5e7eb;
    padding-top: 12px;
}
.advanced-section__toggle {
    background: transparent;
    border: 0;
    padding: 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    color: #4b5563;
    font-weight: 500;
}
.advanced-section__toggle:hover {
    color: #111827;
}
.advanced-section__chevron {
    display: inline-block;
    width: 14px;
    text-align: center;
}
.advanced-section__body {
    padding-top: 12px;
}
</style>
