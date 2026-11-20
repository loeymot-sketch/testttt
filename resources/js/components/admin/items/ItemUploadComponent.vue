<template>
    <LoadingComponent :props="loading" />
    <div id="itemUpload" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.items") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500" :aria-label="$t('button.close')"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label class="db-field-title required">{{ $t("label.upload_file") }} ({{
                                $t("label.xlsx") }})</label>
                            <input @change="changeFile" v-bind:class="errors.file ? 'invalid' : ''" id="file"
                                type="file" class="db-field-control" ref="fileProperty" accept=".xlsx, .xls" />
                            <small class="db-field-alert" v-if="errors.file">{{ errors.file[0] }}</small>
                        </div>

                        <!-- [ONB-02 2026-08-28] Le compte rendu de l'import.
                             Avant, l'ecran affichait une bulle verte figee et fermait
                             la fenetre : le commercant ne savait pas si 0, 12 ou 45 de
                             ses lignes etaient passees, ni laquelle corriger. -->
                        <div class="form-col-12" v-if="echecs.length">
                            <div class="db-alert-warning">
                                <p class="font-medium">{{ resume }}</p>
                                <ul class="list-disc pl-6 mt-2 max-h-60 overflow-y-auto">
                                    <li v-for="(e, i) in echecs" :key="i">
                                        {{ $t('label.import_line', { n: e.ligne }) }} — {{ e.raison }}
                                    </li>
                                </ul>
                            </div>
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

import LoadingComponent from "../components/LoadingComponent.vue";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";

export default {
    name: "ItemUploadComponent",
    components: { LoadingComponent },
    emits:['list'],
    data() {
        return {
            echecs: [],
            resume: "",
            loading: {
                isActive: false,
            },
            file: "",
            search: {
                paginate: 1,
                page: 1,
                per_page: 10,
                order_column: "id",
                order_type: "desc",
            },
            errors: {},
        };
    },
    methods: {
        reset: function () {
            appService.modalHide();
            this.file = "";
            this.errors = {};
            // [ONB-02 2026-08-28] Sans ces deux lignes, le compte rendu de l'import
            // precedent reapparaitrait a la reouverture de la fenetre, au-dessus d'un
            // fichier qui n'a pas encore ete depose.
            this.echecs = [];
            this.resume = "";
            if (this.$refs.fileProperty) {
                this.$refs.fileProperty.value = null;
            }
        },
        changeFile: function (e) {
            this.file = e.target.files[0];

        },
        save: function () {
            try {
                const fd = new FormData();
                if (this.file) {
                    fd.append('file', this.file);
                }
                this.loading.isActive = true;
                this.$store.dispatch('item/import', {
                    form: fd,
                    search: this.search
                }).then((res) => {
                    this.loading.isActive = false;

                    // [ONB-02 2026-08-28] La bulle etait FIGEE : `successFlip(0, ...)`
                    // annoncait un succes quoi qu'il arrive, et le serveur repondait
                    // « 202 » vide. Le commercant deposait 45 lignes et lisait
                    // « succes » meme quand rien n'avait ete cree.
                    //
                    // Le serveur dit maintenant ce qu'il a fait. On le repete, et on
                    // GARDE LA FENETRE OUVERTE tant qu'il reste des lignes a corriger :
                    // fermer la fenetre sur une liste d'erreurs reviendrait a les
                    // cacher.
                    this.echecs = res?.data?.echecs || [];
                    this.resume = res?.data?.message || "";
                    this.$emit('list');

                    if (this.echecs.length === 0) {
                        appService.modalHide();
                        alertService.successInfo(0, this.resume || this.$t("menu.items"));
                        this.file = "";
                        this.errors = {};
                        if (this.$refs.fileProperty) {
                            this.$refs.fileProperty.value = null;
                        }
                    } else {
                        alertService.warning(this.resume);
                    }
                }).catch((err) => {
                    this.loading.isActive = false;
                    if(err.response.data?.message){
                        alertService.error(err.response.data.message);
                    }else{
                        this.errors = err.response.data.errors;
                    }
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
};
</script>