<template>
    <LoadingComponent :props="loading" />
    <div id="order_setup" class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t('menu.order_setup') }}</h3>
        </div>
        <div class="db-card-body">
            <form @submit.prevent="save">
                <fieldset class="p-4 mb-6 border border-[#DBDEE0]">
                    <legend class="py-1.5 px-4 text-base font-semibold capitalize border border-[#DBDEE0] text-primary">
                        {{ $t('menu.order') }}
                    </legend>
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="order_setup_food_preparation_time" class="db-field-title required">
                                {{ $t("label.food_preparation_time") }}
                                <span class="text-primary">{{ $t("label.in_minute") }}</span>
                            </label>
                            <input v-on:keypress="floatNumber($event)" v-model="form.order_setup_food_preparation_time"
                                v-bind:class="errors.order_setup_food_preparation_time ? 'invalid' : ''" type="text"
                                id="order_setup_food_preparation_time" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.order_setup_food_preparation_time">{{
                                errors.order_setup_food_preparation_time[0]
                            }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="order_setup_schedule_order_slot_duration" class="db-field-title required">
                                {{ $t("label.schedule_order_slot_duration") }}
                                <span class="text-primary">{{ $t("label.in_minute") }}</span>
                            </label>
                            <input v-on:keypress="floatNumber($event)"
                                v-model="form.order_setup_schedule_order_slot_duration"
                                v-bind:class="errors.order_setup_schedule_order_slot_duration ? 'invalid' : ''"
                                type="text" id="order_setup_schedule_order_slot_duration" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.order_setup_schedule_order_slot_duration">{{
                                errors.order_setup_schedule_order_slot_duration[0]
                            }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="enable">{{ $t("label.takeaway") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.activityEnum.ENABLE" v-model="form.order_setup_takeaway"
                                            id="takeaway-enable" type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="takeaway-enable" class="db-field-label">{{ $t("label.enable") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.activityEnum.DISABLE" v-model="form.order_setup_takeaway"
                                            type="radio" id="takeaway-disable" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="takeaway-disable" class="db-field-label">{{ $t("label.disable") }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.order_setup_takeaway">{{
                                errors.order_setup_takeaway[0]
                            }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="enable">{{ $t("label.delivery") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.activityEnum.ENABLE" v-model="form.order_setup_delivery"
                                            id="deliver-enable" type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="deliver-enable" class="db-field-label">{{ $t("label.enable") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.activityEnum.DISABLE" v-model="form.order_setup_delivery"
                                            type="radio" id="deliver-disable" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="deliver-disable" class="db-field-label">{{ $t("label.disable") }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.order_setup_delivery">{{
                                errors.order_setup_delivery[0]
                            }}</small>
                        </div>
                    </div>
                </fieldset>
                <!--
                    [DÉCISION OWNER 2026-08-14] LE BLOC « FRAIS DE LIVRAISON » A ÉTÉ RETIRÉ D'ICI.

                    Il contenait trois champs — kilomètres offerts, frais de base, prix au
                    kilomètre — que l'admin pouvait remplir et enregistrer sans que RIEN ne s'en
                    serve. Vérifié par grep sur tout `app/` : `order_setup_free_delivery_kilometer`,
                    `order_setup_basic_delivery_charge` et `order_setup_charge_per_kilo` n'avaient
                    AUCUN lecteur métier ; ils ne faisaient qu'un aller-retour entre
                    `OrderSetupRequest` et `OrderSetupResource`.

                    Le VRAI calcul des frais de livraison est `DeliveryFeeService`, qui lit des
                    colonnes de la table `branches` (`delivery_fee_base`, `delivery_fee_per_km`,
                    `delivery_fee_minimum`, `free_km`) — des colonnes qui, elles, n'ont AUCUN écran
                    d'administration à ce jour.

                    Un réglage qui a l'air de marcher et ne fait rien est pire que pas de réglage :
                    le gérant croit avoir changé ses frais de livraison, et rien ne bouge en caisse.
                    On retire donc la promesse vide plutôt que de la laisser mentir.

                    ⚠️ Les valeurs déjà enregistrées en base ne sont PAS supprimées (aucune perte de
                    donnée) : elles cessent simplement d'être proposées à l'édition.
                    ⛔ Ne pas remettre ces champs ici : le jour où l'on voudra rendre les frais de
                    livraison réglables, c'est un écran sur les colonnes `branches` qu'il faut
                    construire — pas ce formulaire-ci.
                -->

                <button type="submit" class="db-btn text-white bg-primary">
                    <i class="lab lab-save"></i>
                    <span>{{ $t("button.save") }}</span>
                </button>
            </form>
        </div>
    </div>
</template>

<script>

import activityEnum from "../../../../enums/modules/activityEnum";
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "OrderSetupComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false
            },
            form: {
                order_setup_food_preparation_time: null,
                order_setup_schedule_order_slot_duration: null,
                order_setup_takeaway: null,
                order_setup_delivery: null
                // [DÉCISION OWNER 2026-08-14] Les 3 clés « frais de livraison » ont été retirées
                // du formulaire ET de la charge utile envoyée — voir le commentaire au-dessus du
                // fieldset supprimé. Les règles `required` correspondantes ont été retirées de
                // `OrderSetupRequest` dans le même geste : les garder aurait fait échouer chaque
                // enregistrement en 422 sur des champs que plus personne ne peut remplir.
            },
            enums: {
                activityEnum: activityEnum
            },
            errors: {}
        }
    },
    computed: {
    },
    mounted() {
        try {
            this.loading.isActive = true;
            this.$store.dispatch('orderSetup/lists').then(res => {
                this.form = {
                    order_setup_food_preparation_time: res.data.data.order_setup_food_preparation_time,
                    order_setup_schedule_order_slot_duration: res.data.data.order_setup_schedule_order_slot_duration,
                    order_setup_takeaway: res.data.data.order_setup_takeaway,
                    order_setup_delivery: res.data.data.order_setup_delivery
                }
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        } catch (err) {
            this.loading.isActive = false;
        }
    },
    methods: {
        floatNumber(e) {
            return appService.floatNumber(e);
        },
        save: function () {
            try {
                this.loading.isActive = true;
                this.$store.dispatch("orderSetup/save", this.form).then((res) => {
                    this.loading.isActive = false;
                    alertService.successFlip(res.config.method === "put" ?? 0, this.$t("menu.order_setup"));
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
}
</script>
