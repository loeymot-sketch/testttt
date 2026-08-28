<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.printers") }}</h3>
            <div class="db-card-filter">
                <button type="button" class="db-btn py-2 text-white bg-primary" @click="openCreate"
                        data-testid="printer-add-btn">
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
                        <th class="db-table-head-th">{{ $t("label.station") }}</th>
                        <th class="db-table-head-th">{{ $t("label.host") }}</th>
                        <th class="db-table-head-th">{{ $t("label.width") }}</th>
                        <th class="db-table-head-th">{{ $t("label.status") }}</th>
                        <th class="db-table-head-th">{{ $t("label.action") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body" v-if="printers.length > 0">
                    <tr class="db-table-body-tr" v-for="printer in printers" :key="printer.id">
                        <td class="db-table-body-td">{{ printer.name }}</td>
                        <td class="db-table-body-td capitalize">{{ stationLabel(printer.station) }}</td>
                        <td class="db-table-body-td">{{ printer.host }}:{{ printer.port }}</td>
                        <td class="db-table-body-td">{{ printer.width_chars }} car.</td>
                        <td class="db-table-body-td">
                            <!-- [ONB-10 2026-08-27] 5 = App\Enums\Status::ACTIVE. Cet écran testait
                                 `=== 1` et affichait donc « Archivé », en gris, pour les deux
                                 imprimantes bien actives du Cayenne. -->
                            <span :class="imprimanteActive(printer.status) ? 'text-green-600' : 'text-gray-400'">
                                {{ imprimanteActive(printer.status) ? $t('label.active') : $t('label.archived') }}
                            </span>
                        </td>
                        <td class="db-table-body-td">
                            <div class="flex justify-start items-center gap-1.5">
                                <button type="button" class="db-btn-outline sm primary m-0.5"
                                        :data-testid="`printer-test-${printer.id}`"
                                        :disabled="testingId === printer.id"
                                        :title="$t('label.test_print')"
                                        @click="testPrint(printer)">
                                    <i class="lab lab-printer"></i>
                                </button>
                                <button type="button" class="db-btn-outline sm primary m-0.5"
                                        @click="openEdit(printer)">
                                    <i class="lab lab-edit"></i>
                                </button>
                                <button type="button" class="db-btn-outline sm danger m-0.5"
                                        @click="destroy(printer.id)">
                                    <i class="lab lab-delete"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <td colspan="6" class="db-table-body-td text-center text-gray-400 py-6">
                            {{ $t("label.no_data") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal" :class="{ active: modalActive }">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ isEditing ? $t('button.edit') : $t('button.add') }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                        :aria-label="$t('button.close')" @click="closeModal" type="button"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="p_name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="form.name" type="text" id="p_name"
                                   class="db-field-control" :class="{ invalid: errors.name }" />
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="p_station" class="db-field-title required">{{ $t("label.station") }}</label>
                            <select v-model="form.station" id="p_station"
                                    class="db-field-control" :class="{ invalid: errors.station }">
                                <option value="receipt">{{ $t('label.station_receipt') }}</option>
                                <option value="kitchen_hot">{{ $t('label.station_kitchen_hot') }}</option>
                                <option value="kitchen_cold">{{ $t('label.station_kitchen_cold') }}</option>
                            </select>
                            <small class="db-field-alert" v-if="errors.station">{{ errors.station[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="p_type" class="db-field-title">{{ $t("label.type") }}</label>
                            <select v-model="form.type" id="p_type" class="db-field-control">
                                <!-- [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] this option's
                                     value was "escpos_network" -- PrinterRequest::rules() only
                                     accepts escpos_tcp/escpos_usb/browser_html, so every save with
                                     this (the default, most common) option silently 422'd with no
                                     visible error, because errors.type was never bound below. -->
                                <option value="escpos_tcp">ESC/POS réseau</option>
                                <option value="escpos_usb">ESC/POS USB</option>
                            </select>
                            <small class="db-field-alert" v-if="errors.type">{{ errors.type[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="p_host" class="db-field-title required">{{ $t("label.host") }}</label>
                            <input v-model="form.host" type="text" id="p_host" placeholder="192.168.1.50"
                                   class="db-field-control" :class="{ invalid: errors.host }" />
                            <small class="db-field-alert" v-if="errors.host">{{ errors.host[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-3">
                            <label for="p_port" class="db-field-title">{{ $t("label.port") }}</label>
                            <input v-model.number="form.port" type="number" min="1" max="65535" id="p_port"
                                   class="db-field-control" :class="{ invalid: errors.port }" />
                            <small class="db-field-alert" v-if="errors.port">{{ errors.port[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-3">
                            <label for="p_width" class="db-field-title">{{ $t("label.width") }}</label>
                            <select v-model.number="form.width_chars" id="p_width" class="db-field-control">
                                <option :value="32">32 (58 mm)</option>
                                <option :value="42">42 (80 mm SAGA)</option>
                                <option :value="48">48 (80 mm)</option>
                            </select>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title">{{ $t('label.status') }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <!-- [ONB-10 2026-08-27] 5 = App\Enums\Status::ACTIVE, la
                                             valeur que les chemins d'impression cherchent. Cet écran
                                             écrivait 1 pour « actif » et 5 pour « archivé » : les deux
                                             conventions étaient inversées sur la valeur 5. -->
                                        <input :value="5" v-model.number="form.status" id="p_active"
                                               type="radio" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="p_active" class="db-field-label">{{ $t('label.active') }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="10" v-model.number="form.status" type="radio"
                                               id="p_archived" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="p_archived" class="db-field-label">{{ $t('label.archived') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" @click="closeModal">
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
import axios from 'axios';
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import { statutImprimante, imprimanteActive } from '../../../../services/statutImprimante';

/**
 * [AUDIT-A P1-2 2026-08-06] Gestion des IMPRIMANTES — le CRUD + test-print API
 * (routes/api.php printers.*) existait au complet mais n'avait AUCUNE UI :
 * la config passait par artisan uniquement. Page CRUD + bouton test
 * d'impression par imprimante (stations : ticket caisse / cuisine chaud/froid).
 */
const EMPTY_FORM = {
    name: '', station: 'kitchen_hot', type: 'escpos_tcp',
    host: '', port: 9100, width_chars: 48, status: 5, // [ONB-10] 5 = App\Enums\Status::ACTIVE
};

export default {
    name: 'PrintersComponent',
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            printers: [],
            modalActive: false,
            isEditing: false,
            editingId: null,
            testingId: null,
            form: { ...EMPTY_FORM },
            errors: {},
        };
    },
    mounted() {
        this.fetch();
    },
    methods: {
        // Le gabarit ne voit pas les imports de module : on expose la normalisation
        // en méthode, sinon `imprimanteActive` y vaut `undefined` au rendu.
        imprimanteActive,
        statutImprimante,

        stationLabel(station) {
            const map = {
                receipt: this.$t('label.station_receipt'),
                kitchen_hot: this.$t('label.station_kitchen_hot'),
                kitchen_cold: this.$t('label.station_kitchen_cold'),
            };
            return map[station] || station || '—';
        },
        async fetch() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('admin/printers', { params: { per_page: 100 } });
                this.printers = Array.isArray(res.data?.data) ? res.data.data : [];
            } catch (e) {
                alertService.error(e.response?.data?.message || e.message);
            } finally {
                this.loading.isActive = false;
            }
        },
        openCreate() {
            this.isEditing = false;
            this.editingId = null;
            this.form = { ...EMPTY_FORM };
            this.errors = {};
            this.modalActive = true;
        },
        openEdit(printer) {
            this.isEditing = true;
            this.editingId = printer.id;
            this.form = {
                name: printer.name, station: printer.station, type: printer.type || 'escpos_tcp',
                host: printer.host, port: Number(printer.port) || 9100,
                width_chars: Number(printer.width_chars) || 48,
                // [ONB-10 2026-08-28] Normalisé : une valeur héritée (1, posée par le
                // défaut de schéma) ne correspondait à aucun bouton radio, et le
                // formulaire s'ouvrait vide.
                status: statutImprimante(printer.status),
            };
            this.errors = {};
            this.modalActive = true;
        },
        closeModal() {
            this.modalActive = false;
        },
        async save() {
            this.loading.isActive = true;
            this.errors = {};
            try {
                if (this.isEditing) {
                    await axios.put(`admin/printers/${this.editingId}`, this.form);
                } else {
                    await axios.post('admin/printers', this.form);
                }
                this.modalActive = false;
                alertService.success(this.$t('label.saved'));
                await this.fetch();
            } catch (e) {
                if (e.response?.status === 422) {
                    this.errors = e.response.data?.errors || {};
                } else {
                    alertService.error(e.response?.data?.message || e.message);
                }
            } finally {
                this.loading.isActive = false;
            }
        },
        async destroy(id) {
            this.loading.isActive = true;
            try {
                await axios.delete(`admin/printers/${id}`);
                alertService.success(this.$t('label.deleted'));
                await this.fetch();
            } catch (e) {
                alertService.error(e.response?.data?.message || e.message);
            } finally {
                this.loading.isActive = false;
            }
        },
        async testPrint(printer) {
            this.testingId = printer.id;
            try {
                await axios.post(`admin/printers/${printer.id}/test-print`);
                alertService.success(this.$t('label.test_print_sent'));
            } catch (e) {
                alertService.error(e.response?.data?.message || this.$t('label.test_print_failed'));
            } finally {
                this.testingId = null;
            }
        },
    },
};
</script>
