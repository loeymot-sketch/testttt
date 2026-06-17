<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.payment_terminals") }}</h3>
            <div class="db-card-filter">
                <button type="button" class="db-btn py-2 text-white bg-primary" @click="openCreate">
                    <i class="lab lab-add"></i>
                    <span>{{ $t("button.add") }}</span>
                </button>
            </div>
        </div>

        <div class="db-table-responsive">
            <table class="db-table stripe">
                <thead class="db-table-head">
                    <tr class="db-table-head-tr">
                        <th class="db-table-head-th">{{ $t("label.name") }}</th>
                        <th class="db-table-head-th">{{ $t("label.branch") }}</th>
                        <th class="db-table-head-th">{{ $t("label.gateway") }}</th>
                        <th class="db-table-head-th">{{ $t("label.fee_percent") }}</th>
                        <th class="db-table-head-th">{{ $t("label.fee_fixed") }}</th>
                        <th class="db-table-head-th">{{ $t("label.serial_number") }}</th>
                        <th class="db-table-head-th">{{ $t("label.status") }}</th>
                        <th class="db-table-head-th">{{ $t("label.action") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body" v-if="terminals.length > 0">
                    <tr class="db-table-body-tr" v-for="terminal in terminals" :key="terminal.id">
                        <td class="db-table-body-td">{{ terminal.name }}</td>
                        <td class="db-table-body-td">{{ terminal.branch_name || '-' }}</td>
                        <td class="db-table-body-td">
                            <span class="capitalize">{{ terminal.gateway_type }}</span>
                        </td>
                        <td class="db-table-body-td">{{ Number(terminal.fee_percent).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) }} %</td>
                        <td class="db-table-body-td">{{ Number(terminal.fee_fixed).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} €</td>
                        <td class="db-table-body-td">{{ terminal.serial_number || '-' }}</td>
                        <td class="db-table-body-td">
                            <span :class="terminal.status === 1 ? 'text-green-600' : 'text-gray-400'">
                                {{ terminal.status === 1 ? $t('label.active') : $t('label.archived') }}
                            </span>
                        </td>
                        <td class="db-table-body-td">
                            <div class="flex justify-start items-center gap-1.5">
                                <button type="button" class="db-btn-outline sm primary m-0.5"
                                        @click="openEdit(terminal)">
                                    <i class="lab lab-edit"></i>
                                </button>
                                <button type="button" class="db-btn-outline sm danger m-0.5"
                                        @click="destroy(terminal.id)" v-if="terminal.status === 1">
                                    <i class="lab lab-delete"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <td colspan="8" class="db-table-body-td text-center text-gray-400 py-6">
                            {{ $t("label.no_data") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modalTerminal" class="modal" :class="{ active: modalActive }">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    {{ isEditing ? $t('button.edit') : $t('button.add') }}
                </h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                        @click="closeModal" type="button"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="t_name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="form.name" type="text" id="t_name"
                                   class="db-field-control" :class="{ invalid: errors.name }" />
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="t_gateway" class="db-field-title required">{{ $t("label.gateway") }}</label>
                            <select v-model="form.gateway_type" id="t_gateway"
                                    class="db-field-control" :class="{ invalid: errors.gateway_type }">
                                <option value="ingenico">Ingenico</option>
                                <option value="verifone">Verifone</option>
                                <option value="stripe">Stripe</option>
                                <option value="senangpay">Senangpay</option>
                                <option value="manual">Manual</option>
                            </select>
                            <small class="db-field-alert" v-if="errors.gateway_type">{{ errors.gateway_type[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="t_serial" class="db-field-title">{{ $t("label.serial_number") }}</label>
                            <input v-model="form.serial_number" type="text" id="t_serial"
                                   class="db-field-control" :class="{ invalid: errors.serial_number }" />
                            <small class="db-field-alert" v-if="errors.serial_number">{{ errors.serial_number[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="t_fee_percent" class="db-field-title">{{ $t("label.fee_percent") }} (%)</label>
                            <input v-model="form.fee_percent" type="number" step="0.001" min="0" max="99.999"
                                   id="t_fee_percent" class="db-field-control" :class="{ invalid: errors.fee_percent }" />
                            <small class="db-field-alert" v-if="errors.fee_percent">{{ errors.fee_percent[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="t_fee_fixed" class="db-field-title">{{ $t("label.fee_fixed") }} (€)</label>
                            <input v-model="form.fee_fixed" type="number" step="0.01" min="0" max="9999.99"
                                   id="t_fee_fixed" class="db-field-control" :class="{ invalid: errors.fee_fixed }" />
                            <small class="db-field-alert" v-if="errors.fee_fixed">{{ errors.fee_fixed[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title">{{ $t('label.status') }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="1" v-model.number="form.status" id="t_active"
                                               type="radio" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="t_active" class="db-field-label">{{ $t('label.active') }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="5" v-model.number="form.status" type="radio"
                                               id="t_archived" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="t_archived" class="db-field-label">{{ $t('label.archived') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline" @click="closeModal">
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
/**
 * [Wave F F-2 / Sprint 1C] Payment Terminals admin CRUD.
 *
 * Mounted under /admin/settings/payment-terminals (see settingRoutes.js).
 * Permission: `settings` (Spatie). Branch isolation enforced server-side.
 */
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "PaymentTerminalsComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            modalActive: false,
            isEditing: false,
            editingId: null,
            errors: {},
            form: this.defaultForm(),
            search: {
                paginate: 1,
                page: 1,
                per_page: 20,
                order_column: "id",
                order_type: "desc",
            },
        };
    },
    computed: {
        terminals() {
            return this.$store.getters["paymentTerminal/lists"];
        },
    },
    mounted() {
        this.list();
    },
    methods: {
        defaultForm() {
            return {
                name: "",
                gateway_type: "ingenico",
                fee_percent: 1.5,
                fee_fixed: 0.05,
                serial_number: "",
                status: 1,
            };
        },
        list(page = 1) {
            this.loading.isActive = true;
            this.search.page = page;
            this.$store.dispatch("paymentTerminal/lists", this.search)
                .then(() => { this.loading.isActive = false; })
                .catch(() => { this.loading.isActive = false; });
        },
        openCreate() {
            this.isEditing = false;
            this.editingId = null;
            this.errors = {};
            this.form = this.defaultForm();
            this.$store.dispatch("paymentTerminal/reset");
            this.modalActive = true;
        },
        openEdit(terminal) {
            this.isEditing = true;
            this.editingId = terminal.id;
            this.errors = {};
            this.form = {
                name: terminal.name,
                gateway_type: terminal.gateway_type,
                fee_percent: Number(terminal.fee_percent),
                fee_fixed: Number(terminal.fee_fixed),
                serial_number: terminal.serial_number || "",
                status: Number(terminal.status),
            };
            this.$store.dispatch("paymentTerminal/edit", terminal.id);
            this.modalActive = true;
        },
        closeModal() {
            this.modalActive = false;
            this.errors = {};
            this.$store.dispatch("paymentTerminal/reset");
        },
        save() {
            this.loading.isActive = true;
            this.$store.dispatch("paymentTerminal/save", {
                form: this.form,
                search: this.search,
            }).then(() => {
                this.loading.isActive = false;
                this.modalActive = false;
                alertService.successFlip(null, this.$t("menu.payment_terminals"));
                this.list(this.search.page);
            }).catch((err) => {
                this.loading.isActive = false;
                if (err?.response?.status === 422) {
                    this.errors = err.response.data.errors || {};
                } else {
                    alertService.error(err?.response?.data?.message || "Error");
                }
            });
        },
        destroy(id) {
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("paymentTerminal/destroy", { id, search: this.search })
                    .then(() => {
                        this.loading.isActive = false;
                        alertService.successFlip(null, this.$t("menu.payment_terminals"));
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        alertService.error(err?.response?.data?.message || "Error");
                    });
            }).catch(() => {});
        },
    },
};
</script>

<style scoped></style>
