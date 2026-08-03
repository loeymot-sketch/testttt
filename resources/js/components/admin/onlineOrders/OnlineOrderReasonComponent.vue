<template>
    <LoadingComponent :props="loading"/>
    <button type="button" @click="reasonModal" :data-modal="'#' + modalId"
            :data-testid="'online-order-reason-trigger-' + status"
            class="flex items-center justify-center text-white gap-2 px-4 h-[38px] rounded shadow-db-card bg-[#FB4E4E]">
        <i class="lab lab-close"></i>
        <span class="text-sm capitalize text-white">{{ $t(labelKey) }}</span>
    </button>

    <div :id="modalId" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("label.reason") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500" :aria-label="$t('button.close')"
                        @click.prevent="resetModal"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="submitReason">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="name" class="db-field-title">
                                {{ $t("label.reason") }}
                            </label>
                            <input v-model="form.reason" v-bind:class="error ? 'invalid' : ''"
                                   type="text"
                                   id="name"
                                   class="db-field-control"
                            />
                            <small class="db-field-alert" v-if="error">
                                {{ error }}
                            </small>
                        </div>
                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" :aria-label="$t('button.close')" @click.prevent="resetModal">
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
import appService from "../../../services/appService";
import alertService from "../../../services/alertService";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import LoadingComponent from "../components/LoadingComponent";

export default {
    name: "OnlineOrderReasonComponent",
    components: {
        LoadingComponent
    },
    // [P2-2 2026-07-24] Composant motif rendu RÉUTILISABLE : le flux PENDING garde le
    // défaut REJECTED (« Refuser »), et le flux post-acceptation le réutilise avec
    // status=CANCELED (« Annuler ») + un modalId distinct. Miroir du bouton d'annulation
    // du tracker POS ; l'endpoint online-order/change-status persiste le motif (order.reason).
    props: {
        status: {
            type: Number,
            default: orderStatusEnum.REJECTED,
        },
        labelKey: {
            type: String,
            default: "button.reject",
        },
        modalId: {
            type: String,
            default: "reasonModal",
        },
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            form: {
                reason: ""
            },
            error: ""
        }
    },
    methods: {
        reasonModal: function () {
            appService.modalShow('#' + this.modalId);
        },
        resetModal: function () {
            appService.modalHide('#' + this.modalId);
            this.form.reason = '';
            this.error = "";
        },
        submitReason: function () {
            try {
                this.loading.isActive = true;
                this.$store.dispatch("onlineOrder/changeStatus", {
                    id: this.$route.params.id,
                    status: this.status,
                    reason: this.form.reason,
                }).then((res) => {
                    this.loading.isActive = false;
                    appService.modalHide('#' + this.modalId);
                    this.form = {
                        reason: "",
                    };
                    this.error = "";
                    alertService.successFlip(
                        1,
                        this.$t("label.status")
                    );
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.error = err.response.data.message;
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            }
        },
    }
}
</script>
