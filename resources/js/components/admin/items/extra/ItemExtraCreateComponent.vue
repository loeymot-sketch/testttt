<template>
    <LoadingComponent :props="loading" />

    <button type="button" @click="add" data-modal="#extraModal" class="db-btn h-[37px] text-white bg-primary" data-testid="admin-extra-add-button">
        <i class="lab lab-add-circle-line"></i>
        <span>{{ addButton.title }}</span>
    </button>

    <div id="extraModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.extras") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="props.form.name" v-bind:class="errors.name ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" data-testid="admin-extra-form-name" />
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="price" class="db-field-title required">{{
                                $t("label.additional_price")
                            }}</label>
                            <input v-on:keypress="numberOnly($event)" v-model="props.form.price"
                                v-bind:class="errors.price ? 'invalid' : ''" type="text" id="price"
                                class="db-field-control" data-testid="admin-extra-form-price" />
                            <small class="db-field-alert" v-if="errors.price">{{ errors.price[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="extraActive">{{ $t("label.status") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.ACTIVE" v-model="props.form.status" id="extraActive"
                                            type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="extraActive" class="db-field-label">{{ $t("label.active") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.INACTIVE" v-model="props.form.status" type="radio"
                                            id="extraInactive" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="extraInactive" class="db-field-label">{{ $t("label.inactive") }}</label>
                                </div>
                            </div>
                        </div>

                        <!-- Groupe d'affichage (ex: Sauce, Supplément, Garniture) -->
                        <div class="form-col-12 sm:form-col-6">
                            <label for="group_label" class="db-field-title">Groupe (optionnel)</label>
                            <input v-model="props.form.group_label" type="text" id="group_label"
                                class="db-field-control" placeholder="ex: Sauce, Supplément, Garniture" maxlength="50" />
                            <small class="text-slate-400 text-xs">Utilisé pour grouper visuellement sur la borne</small>
                        </div>

                        <!-- Visibilité par surface -->
                        <div class="form-col-12">
                            <label class="db-field-title">Visible sur</label>
                            <div class="flex flex-wrap gap-4 mt-1">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" class="w-4 h-4 accent-primary"
                                        :checked="isSurfaceChecked('kiosk')"
                                        @change="toggleSurface('kiosk', $event.target.checked)" />
                                    <span class="text-sm">🖥️ Borne (Kiosk)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" class="w-4 h-4 accent-primary"
                                        :checked="isSurfaceChecked('pos')"
                                        @change="toggleSurface('pos', $event.target.checked)" />
                                    <span class="text-sm">🖨️ Caisse (POS)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" class="w-4 h-4 accent-primary"
                                        :checked="isSurfaceChecked('web')"
                                        @change="toggleSurface('web', $event.target.checked)" />
                                    <span class="text-sm">🌐 Site web</span>
                                </label>
                            </div>
                            <small class="text-slate-400 text-xs mt-1 block">
                                Laisser tout décoché = visible partout (comportement par défaut)
                            </small>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" @click="reset">
                                    <i class="lab lab-close"></i>
                                    <span>{{ $t("button.close") }}</span>
                                </button>

                                <button type="submit" class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-save"></i>
                                    <span>{{ $t("button.save") }}</span>
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
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import statusEnum from "../../../../enums/modules/statusEnum";

export default {
    name: "ItemExtraCreateComponent",
    components: { LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            errors: {},
        };
    },
    computed: {
        addButton: function () {
            return { title: this.$t('button.add_extra') };
        }
    },
    mounted() { },
    methods: {
        add: function () {
            appService.modalShow('#extraModal');
        },
        numberOnly: function (e) {
            return appService.floatNumber(e);
        },
        reset: function () {
            appService.modalHide('#extraModal');
            this.$store.dispatch("itemExtra/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                name:        "",
                price:       null,
                status:      statusEnum.ACTIVE,
                visible_on:  null,
                group_label: "",
            };
        },
        // Returns true if the given surface is in visible_on (or if visible_on is null = all)
        isSurfaceChecked(surface) {
            const v = this.props.form.visible_on;
            if (!v || v.length === 0) return false;
            return v.includes(surface);
        },
        // Toggle a surface in the visible_on array.
        // If all 3 surfaces are checked → set to null (= all surfaces, no restriction).
        toggleSurface(surface, checked) {
            let current = Array.isArray(this.props.form.visible_on)
                ? [...this.props.form.visible_on]
                : [];
            if (checked) {
                if (!current.includes(surface)) current.push(surface);
            } else {
                current = current.filter(s => s !== surface);
            }
            // null means "no restriction" — use it when nothing is selected or all 3 are selected
            const allSurfaces = ['kiosk', 'pos', 'web'];
            const allSelected = allSurfaces.every(s => current.includes(s));
            this.props.form.visible_on = (current.length === 0 || allSelected) ? null : current;
        },
        save: function () {
            try {
                const tempId = this.$store.getters["itemExtra/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch("itemExtra/save", this.props).then((res) => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip(
                        tempId === null ? 0 : 1,
                        this.$t("label.extra")
                    );
                    this.props.form = {
                            name:        "",
                            price:       null,
                            status:      statusEnum.ACTIVE,
                            visible_on:  null,
                            group_label: "",
                        };
                        this.errors = {};
                    }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response.data.errors;
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    }
};
</script>
