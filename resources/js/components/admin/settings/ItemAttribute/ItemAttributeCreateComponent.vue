<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent :props="addButton" />

    <div id="modal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.item_attributes") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500" :aria-label="$t('button.close')"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <CatalogConceptHelpComponent concept="attribute" />
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="name" class="db-field-title required">{{
                                $t("label.name")
                            }}</label>
                            <input v-model="props.form.name" v-bind:class="errors.name ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.name">{{
                                errors.name[0]
                            }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="active">{{ $t("label.status") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.ACTIVE" v-model="props.form.status" id="active"
                                            type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="active" class="db-field-label">{{ $t("label.active") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.INACTIVE" v-model="props.form.status" type="radio"
                                            id="inactive" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="inactive" class="db-field-label">{{ $t("label.inactive") }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label for="min_select" class="db-field-title">Minimum de choix</label>
                            <input
                                v-model.number="props.form.min_select"
                                v-bind:class="errors.min_select ? 'invalid' : ''"
                                type="number"
                                min="0"
                                max="99"
                                id="min_select"
                                class="db-field-control"
                            />
                            <small class="db-field-alert" v-if="errors.min_select">{{ errors.min_select[0] }}</small>
                            <small class="text-slate-400 text-xs mt-1 block">0 = optionnel.</small>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label for="max_select" class="db-field-title">Maximum de choix</label>
                            <input
                                v-model.number="props.form.max_select"
                                v-bind:class="errors.max_select ? 'invalid' : ''"
                                type="number"
                                min="0"
                                max="99"
                                id="max_select"
                                class="db-field-control"
                            />
                            <small class="db-field-alert" v-if="errors.max_select">{{ errors.max_select[0] }}</small>
                            <small class="text-slate-400 text-xs mt-1 block">Ex: 1 viande, 2 viandes, 4 viandes.</small>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label class="db-field-title">Autoriser le meme choix plusieurs fois</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="1" v-model="props.form.allow_repeat" type="radio" id="allow_repeat_yes" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="allow_repeat_yes" class="db-field-label">{{ $t("label.yes") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="0" v-model="props.form.allow_repeat" type="radio" id="allow_repeat_no" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="allow_repeat_no" class="db-field-label">{{ $t("label.no") }}</label>
                                </div>
                            </div>
                            <small class="text-slate-400 text-xs mt-1 block">Utile pour "4 viandes" avec steak x2.</small>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" :aria-label="$t('button.close')" @click="reset">
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
import CatalogConceptHelpComponent from "../../items/CatalogConceptHelpComponent.vue";
import SmModalCreateComponent from "../../components/buttons/SmModalCreateComponent";
import LoadingComponent from "../../components/LoadingComponent";
import statusEnum from "../../../../enums/modules/statusEnum";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "ItemAttributeCreateComponent",
    components: { CatalogConceptHelpComponent, SmModalCreateComponent, LoadingComponent },
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
            return { title: this.$t('button.add_item_attribute') };
        }
    },
    methods: {
        reset: function () {
            appService.modalHide();
            this.$store.dispatch("itemAttribute/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                min_select: 0,
                max_select: 1,
                allow_repeat: 0,
                status: statusEnum.ACTIVE,
            };
        },

        save: function () {
            try {
                const tempId = this.$store.getters["itemAttribute/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch("itemAttribute/save", this.props).then((res) => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip(
                        tempId === null ? 0 : 1,
                        this.$t("menu.item_attributes")
                    );
                    this.props.form = {
                        name: "",
                        min_select: 0,
                        max_select: 1,
                        allow_repeat: 0,
                        status: statusEnum.ACTIVE,
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
    },
};
</script>
