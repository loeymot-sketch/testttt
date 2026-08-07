<template>
    <div class="col-12">
        <BreadcrumbComponent />
    </div>

    <LoadingComponent :props="loading" />

    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t("label.connected_devices") }}</h3>
                <button type="button" class="db-btn py-2 text-white bg-primary" @click="fetchDevices">
                    <!-- `lab-refresh` / `lab-logout` n'existent pas dans la police
                         d'icônes du projet (vérifié : aucune occurrence). On
                         n'utilise que des glyphes réellement présents. -->
                    <i class="lab lab-reset"></i>
                    <span>{{ $t("button.refresh") }}</span>
                </button>
            </div>

            <div class="db-card-body">
                <p class="mb-4 text-sm text-slate-500">
                    {{ $t("label.connected_devices_help", { max: maxDevices }) }}
                </p>

                <div v-if="!loading.isActive && devices.length === 0" class="py-6 text-center text-slate-500">
                    {{ $t("label.no_connected_device") }}
                </div>

                <div v-else class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ $t("label.device") }}</th>
                                <th>{{ $t("label.type") }}</th>
                                <th>{{ $t("label.ip_address") }}</th>
                                <th>{{ $t("label.last_activity") }}</th>
                                <th class="text-right">{{ $t("label.action") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="device in devices" :key="device.id">
                                <td>
                                    <div v-if="renamingId === device.id" class="flex items-center gap-2">
                                        <input
                                            v-model="renameValue"
                                            type="text"
                                            maxlength="120"
                                            class="db-field-control"
                                            :aria-label="$t('label.device')"
                                            @keyup.enter="confirmRename(device)"
                                            @keyup.esc="cancelRename"
                                        />
                                        <button type="button" class="db-btn py-1 text-white bg-primary"
                                            @click="confirmRename(device)">
                                            {{ $t("button.save") }}
                                        </button>
                                        <button type="button" class="db-btn py-1" @click="cancelRename">
                                            {{ $t("button.cancel") }}
                                        </button>
                                    </div>
                                    <div v-else>
                                        <span class="font-medium">{{ device.device_label }}</span>
                                        <span v-if="device.is_current"
                                            class="ml-2 rounded px-2 py-0.5 text-xs text-white bg-primary">
                                            {{ $t("label.this_device") }}
                                        </span>
                                        <button type="button" class="ml-2 text-xs underline text-slate-500"
                                            @click="startRename(device)">
                                            {{ $t("button.rename") }}
                                        </button>
                                    </div>
                                </td>
                                <td>{{ kindLabel(device.kind) }}</td>
                                <td>{{ device.last_ip || "—" }}</td>
                                <td>{{ formatDate(device.last_used_at || device.created_at) }}</td>
                                <td class="text-right">
                                    <!-- `bg-danger` n'est défini nulle part dans le projet :
                                         le bouton s'affichait en texte blanc sur fond
                                         transparent, donc invisible. `bg-rose-700` est la
                                         classe destructive réellement utilisée ailleurs. -->
                                    <button type="button" class="db-btn py-2 bg-rose-700 text-white"
                                        @click="revoke(device)">
                                        {{ device.is_current ? $t("button.logout") : $t("button.disconnect") }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [MULTI-DEVICE 2026-08-07] Écran « Appareils connectés ».
 *
 * Contrepartie du multi-terminaux : maintenant que plusieurs postes restent
 * connectés en même temps sur un compte, il faut pouvoir les VOIR et en
 * COUPER un à distance (tablette perdue, poste d'un ancien employé).
 *
 * Volontairement en lecture directe de l'API et non du store : c'est un écran
 * de sécurité, il doit toujours refléter l'état réel du serveur, jamais un
 * instantané mis en cache.
 */
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import { setDeviceLabel } from "../../../shared/device-id";

export default {
    name: "ProfileDevicesComponent",
    components: {
        BreadcrumbComponent,
        LoadingComponent,
    },
    data() {
        return {
            loading: { isActive: false },
            devices: [],
            maxDevices: 10,
            renamingId: null,
            renameValue: "",
        };
    },
    mounted() {
        this.fetchDevices();
    },
    methods: {
        fetchDevices() {
            this.loading.isActive = true;
            this.$store.dispatch("listDevices")
                .then((res) => {
                    this.devices = res.devices || [];
                    this.maxDevices = res.max_devices || 10;
                })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },

        kindLabel(kind) {
            if (kind === "kiosk-token") return this.$t("label.kiosk");
            return this.$t("label.staff_session");
        },

        formatDate(value) {
            if (!value) return "—";
            try {
                return new Date(value).toLocaleString("fr-FR", {
                    dateStyle: "short",
                    timeStyle: "short",
                });
            } catch (_) {
                return value;
            }
        },

        startRename(device) {
            this.renamingId = device.id;
            this.renameValue = device.device_label || "";
        },

        cancelRename() {
            this.renamingId = null;
            this.renameValue = "";
        },

        confirmRename(device) {
            const label = (this.renameValue || "").trim();
            if (label === "") return;

            this.loading.isActive = true;
            this.$store.dispatch("renameDevice", { id: device.id, device_label: label })
                .then((res) => {
                    device.device_label = res.device_label || label;
                    // L'appareil courant garde son nom localement pour que la
                    // prochaine connexion le renvoie au serveur.
                    if (device.is_current) setDeviceLabel(device.device_label);
                    this.cancelRename();
                    alertService.success(res.message);
                })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },

        revoke(device) {
            const question = device.is_current
                ? this.$t("label.confirm_revoke_current_device")
                : this.$t("label.confirm_revoke_device", { device: device.device_label });

            if (!window.confirm(question)) return;

            this.loading.isActive = true;
            this.$store.dispatch("revokeDevice", device.id)
                .then((res) => {
                    alertService.success(res.message);
                    // Se couper soi-même est légitime (poste qu'on quitte) :
                    // dans ce cas on sort proprement au lieu d'attendre le 401
                    // du prochain appel.
                    if (res.is_current) {
                        this.$store.dispatch("logout").catch(() => {});
                        window.location.href = "/login";
                        return;
                    }
                    this.fetchDevices();
                })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },
    },
};
</script>
